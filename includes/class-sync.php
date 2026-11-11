<?php
/**
 * Synchronization between post content and the task tables.
 *
 * @package ActionItems
 */

namespace ActionItems;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the task tables in sync with the Task blocks found in post content.
 *
 * The block attributes stored in post content are authoritative for the
 * title, the assignees and the due date of a task. The tasks table is
 * authoritative for its status: saving a post never overwrites the status
 * of an existing task. See docs/data-model.md.
 */
class Sync {

	const BLOCK_NAME = 'action-items/task';

	/**
	 * Matches the opening delimiter of a Task block, mirroring the block parser.
	 * Group 1 holds the JSON attributes, when present.
	 */
	const BLOCK_PATTERN = '/<!--\s+wp:action-items\/task\s+(\{(?:(?:[^}]+|\}+(?=\})|(?!\}\s+\/?-->).)*+)?\}\s+)?(\/)?-->/s';

	/**
	 * Identifiers replaced during this request, as new => original.
	 *
	 * A task created from a duplicated block starts with the current status
	 * of the task it was copied from.
	 *
	 * @var array<string,string>
	 */
	private static $replaced_ids = array();

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'wp_insert_post_data', array( self::class, 'ensure_unique_ids' ), 20, 2 );
		add_action( 'save_post', array( self::class, 'sync_post' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'delete_post_tasks' ) );
		add_action( 'deleted_user', array( Tasks::class, 'remove_user' ) );
	}

	/**
	 * Gives every Task block of a post about to be saved a unique identifier.
	 *
	 * A new identifier is assigned when a block has none, when an earlier
	 * block of the same post already uses it (duplicated block) or when it
	 * belongs to a task of another post (copied block or duplicated post).
	 * Only the `taskId` attribute of the affected blocks is rewritten; the
	 * rest of the content is left byte for byte as it was.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post data.
	 * @return array
	 */
	public static function ensure_unique_ids( $data, $postarr ) {
		if ( empty( $data['post_content'] )
			|| ! in_array( $data['post_type'], get_supported_post_types(), true )
			|| false === strpos( $data['post_content'], 'wp:' . self::BLOCK_NAME )
		) {
			return $data;
		}

		$content = wp_unslash( $data['post_content'] );
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( ! preg_match_all( self::BLOCK_PATTERN, $content, $matches ) ) {
			return $data;
		}

		// Find the identifiers that already belong to another post.
		$ids = array();
		foreach ( $matches[1] as $json ) {
			$attributes = json_decode( $json, true );
			if ( is_array( $attributes ) && isset( $attributes['taskId'] ) ) {
				$ids[] = $attributes['taskId'];
			}
		}

		Schema::maybe_install();
		$existing = Tasks::get_many( $ids );
		$seen     = array();
		$changed  = false;

		$content = preg_replace_callback(
			self::BLOCK_PATTERN,
			static function ( $block ) use ( $existing, $post_id, &$seen, &$changed ) {
				$json       = isset( $block[1] ) ? trim( $block[1] ) : '';
				$attributes = '' === $json ? array() : json_decode( $json, true );

				// Leave blocks with unreadable attributes alone.
				if ( ! is_array( $attributes ) ) {
					return $block[0];
				}

				$id      = $attributes['taskId'] ?? null;
				$is_free = Tasks::is_uuid( $id )
					&& ! isset( $seen[ $id ] )
					&& ( ! isset( $existing[ $id ] ) || $existing[ $id ]->post_id === $post_id );

				if ( $is_free ) {
					$seen[ $id ] = true;
					return $block[0];
				}

				$new_id          = wp_generate_uuid4();
				$seen[ $new_id ] = true;
				$changed         = true;

				if ( Tasks::is_uuid( $id ) ) {
					self::$replaced_ids[ $new_id ] = $id;
				}

				$attributes['taskId'] = $new_id;
				$closer               = empty( $block[2] ) ? '' : '/';

				return '<!-- wp:' . self::BLOCK_NAME . ' ' . self::replace_task_id( $json, $attributes ) . ' ' . $closer . '-->';
			},
			$content
		);

		if ( $changed && is_string( $content ) ) {
			$data['post_content'] = wp_slash( $content );
		}

		return $data;
	}

	/**
	 * Sets the `taskId` attribute in serialized block attributes.
	 *
	 * Edits the JSON in place when possible so that the other attributes keep
	 * their exact serialization.
	 *
	 * @param string $json       Serialized attributes, possibly empty.
	 * @param array  $attributes Decoded attributes, including the new `taskId`.
	 * @return string
	 */
	private static function replace_task_id( $json, array $attributes ) {
		$member = '"taskId":"' . $attributes['taskId'] . '"';

		if ( '' === $json || '{}' === $json ) {
			return '{' . $member . '}';
		}

		$replaced = preg_replace( '/"taskId"\s*:\s*(?:"(?:[^"\\\\]|\\\\.)*"|[^,}\s]+)/', $member, $json, 1, $count );
		if ( null !== $replaced && 1 === $count ) {
			$decoded = json_decode( $replaced, true );
			if ( is_array( $decoded ) && $decoded === $attributes ) {
				return $replaced;
			}
		}

		if ( ! array_key_exists( 'taskId', (array) json_decode( $json, true ) ) ) {
			return '{' . $member . ',' . substr( $json, 1 );
		}

		return serialize_block_attributes( $attributes );
	}

	/**
	 * Mirrors the Task blocks of a saved post into the task tables.
	 *
	 * Creates tasks for new blocks, updates the details of existing tasks and
	 * deletes the tasks whose block is gone. Revisions, which include the
	 * autosaves of published posts, are ignored.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function sync_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post ) || ! in_array( $post->post_type, get_supported_post_types(), true ) ) {
			return;
		}

		Schema::maybe_install();

		$blocks   = self::find_task_blocks( $post->post_content );
		$existing = Tasks::get_by_post( $post_id );

		if ( ! $blocks && ! $existing ) {
			return;
		}

		$seen = array();

		foreach ( $blocks as $position => $attributes ) {
			$uuid = $attributes['taskId'] ?? null;
			if ( ! Tasks::is_uuid( $uuid ) || isset( $seen[ $uuid ] ) ) {
				continue;
			}
			$seen[ $uuid ] = true;

			$title     = Tasks::sanitize_title( $attributes['title'] ?? '' );
			$due_date  = Tasks::sanitize_due_date( $attributes['dueDate'] ?? null );
			$assignees = Users::filter_assignable( $attributes['assignees'] ?? array() );

			if ( isset( $existing[ $uuid ] ) ) {
				self::update_task( $existing[ $uuid ], $title, $due_date, $position, $assignees );
				continue;
			}

			$data = array(
				'uuid'      => $uuid,
				'post_id'   => $post_id,
				'title'     => $title,
				'status'    => $attributes['status'] ?? Tasks::STATUS_NOT_STARTED,
				'due_date'  => $due_date,
				'position'  => $position,
				'assignees' => $assignees,
			);

			// A duplicated block starts from the live state of its original.
			$original = isset( self::$replaced_ids[ $uuid ] ) ? Tasks::get( self::$replaced_ids[ $uuid ] ) : null;
			if ( $original ) {
				$data['status']       = $original->status;
				$data['completed_at'] = $original->completed_at;
			}

			// Never take over an identifier that belongs to another post.
			$task = Tasks::get( $uuid ) ? null : Tasks::insert( $data );
			if ( $task ) {
				/**
				 * Fires after a task was created from a Task block.
				 *
				 * The post may not be published yet; check its status before
				 * notifying anyone.
				 *
				 * @param object $task The new task.
				 */
				do_action( 'action_items_task_created', $task );
			}
		}//end foreach

		Tasks::delete( array_values( array_diff_key( $existing, $seen ) ) );
	}

	/**
	 * Applies the details of a Task block to its existing task.
	 *
	 * @param object      $task      Existing task.
	 * @param string      $title     Sanitized title.
	 * @param string|null $due_date  Due date.
	 * @param int         $position  Position of the block in the post.
	 * @param int[]       $assignees Assignee user IDs.
	 */
	private static function update_task( $task, $title, $due_date, $position, array $assignees ) {
		$changes = array();

		if ( $title !== $task->title ) {
			$changes['title'] = $title;
		}
		if ( $due_date !== $task->due_date ) {
			$changes['due_date'] = $due_date;
		}
		if ( $position !== $task->position ) {
			$changes['position'] = $position;
		}

		Tasks::update_details( $task->id, $changes );

		$added   = array_values( array_diff( $assignees, $task->assignees ) );
		$removed = array_values( array_diff( $task->assignees, $assignees ) );

		if ( $added || $removed ) {
			Tasks::set_assignees( $task->id, $assignees );

			/**
			 * Fires after the assignees of an existing task changed.
			 *
			 * @param object $task    The task, as it was before the change.
			 * @param int[]  $added   User IDs added to the task.
			 * @param int[]  $removed User IDs removed from the task.
			 */
			do_action( 'action_items_assignees_changed', $task, $added, $removed );
		}
	}

	/**
	 * Deletes the tasks of a permanently deleted post.
	 *
	 * Trashing a post keeps its tasks: they are hidden while the post is in
	 * the trash and come back, with their status, when it is restored.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_post_tasks( $post_id ) {
		if ( Schema::DB_VERSION !== get_option( Schema::OPTION ) ) {
			return;
		}

		Tasks::delete( array_values( Tasks::get_by_post( $post_id ) ) );
	}

	/**
	 * Returns the attributes of every Task block in some content, in document order.
	 *
	 * @param string $content Post content.
	 * @return array[] List of block attributes.
	 */
	public static function find_task_blocks( $content ) {
		if ( false === strpos( (string) $content, 'wp:' . self::BLOCK_NAME ) ) {
			return array();
		}

		$found = array();
		self::collect_task_blocks( parse_blocks( $content ), $found );

		return $found;
	}

	/**
	 * Recursively collects the attributes of Task blocks.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @param array[] $found  Collected attributes, passed by reference.
	 */
	private static function collect_task_blocks( array $blocks, array &$found ) {
		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				$found[] = (array) $block['attrs'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_task_blocks( $block['innerBlocks'], $found );
			}
		}
	}
}

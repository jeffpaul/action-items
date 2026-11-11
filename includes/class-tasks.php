<?php
/**
 * Task storage.
 *
 * @package ActionItems
 */

namespace ActionItems;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows of the task tables.
 *
 * This class performs no permission checks. Callers are responsible for
 * checking capabilities before writing and for restricting reads to public
 * tasks where needed.
 *
 * A task is a plain object with these properties:
 * id (int), uuid (string), post_id (int), title (string, sanitized HTML),
 * status (string), due_date (string Y-m-d or null), position (int),
 * created_at / updated_at / completed_at (GMT MySQL datetimes),
 * status_changed_by (int or null) and assignees (int[]).
 */
class Tasks {

	const STATUS_NOT_STARTED = 'not-started';
	const STATUS_IN_PROGRESS = 'in-progress';
	const STATUS_COMPLETED   = 'completed';

	const CACHE_GROUP = 'action-items';

	/**
	 * Returns the task statuses and their labels.
	 *
	 * @return array<string,string>
	 */
	public static function get_statuses() {
		return array(
			self::STATUS_NOT_STARTED => __( 'Not started', 'action-items' ),
			self::STATUS_IN_PROGRESS => __( 'In progress', 'action-items' ),
			self::STATUS_COMPLETED   => __( 'Completed', 'action-items' ),
		);
	}

	/**
	 * Whether a value is a known task status.
	 *
	 * @param mixed $status Value to check.
	 * @return bool
	 */
	public static function is_status( $status ) {
		return is_string( $status ) && array_key_exists( $status, self::get_statuses() );
	}

	/**
	 * Whether a value is a well-formed task identifier.
	 *
	 * @param mixed $uuid Value to check.
	 * @return bool
	 */
	public static function is_uuid( $uuid ) {
		return is_string( $uuid ) && wp_is_uuid( $uuid, 4 ) && strtolower( $uuid ) === $uuid;
	}

	/**
	 * Validates a due date.
	 *
	 * @param mixed $date Value to check.
	 * @return string|null The date as Y-m-d, or null when missing or invalid.
	 */
	public static function sanitize_due_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return null;
		}

		return wp_checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1], $date ) ? $date : null;
	}

	/**
	 * Sanitizes a task title, keeping a small set of inline formats.
	 *
	 * @param mixed $title Raw title.
	 * @return string
	 */
	public static function sanitize_title( $title ) {
		if ( ! is_string( $title ) ) {
			return '';
		}

		$allowed = array(
			'a'      => array(
				'href'   => true,
				'rel'    => true,
				'target' => true,
			),
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
			's'      => array(),
		);

		return trim( wp_kses( $title, $allowed ) );
	}

	/**
	 * Fetches a task by its identifier.
	 *
	 * @param string $uuid Task identifier.
	 * @return object|null
	 */
	public static function get( $uuid ) {
		$tasks = self::get_many( array( $uuid ) );
		return $tasks[ $uuid ] ?? null;
	}

	/**
	 * Fetches several tasks by their identifiers.
	 *
	 * @param string[] $uuids Task identifiers.
	 * @return array<string,object> Tasks keyed by identifier.
	 */
	public static function get_many( array $uuids ) {
		global $wpdb;

		$uuids = array_values( array_unique( array_filter( $uuids, array( self::class, 'is_uuid' ) ) ) );
		if ( ! $uuids ) {
			return array();
		}

		$table        = Schema::tasks_table();
		$placeholders = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom table; placeholders are generated above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE task_uuid IN ({$placeholders})", $uuids ) );

		$tasks = array();
		foreach ( self::hydrate( $rows ) as $task ) {
			$tasks[ $task->uuid ] = $task;
		}
		return $tasks;
	}

	/**
	 * Fetches every task of a post, whatever the post status.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,object> Tasks keyed by identifier, in document order.
	 */
	public static function get_by_post( $post_id ) {
		global $wpdb;

		$table = Schema::tasks_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY position ASC, id ASC", $post_id ) );

		$tasks = array();
		foreach ( self::hydrate( $rows ) as $task ) {
			$tasks[ $task->uuid ] = $task;
		}
		return $tasks;
	}

	/**
	 * Queries tasks.
	 *
	 * Incomplete tasks come first, ordered by due date (tasks without a due
	 * date last) and then by creation date. Completed tasks follow, most
	 * recently completed first.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type string[] $status      Statuses to include. Default: every status.
	 *     @type int      $assignee    Only tasks assigned to this user ID.
	 *     @type int      $post_id     Only tasks of this post.
	 *     @type string[] $include     Only tasks with these identifiers.
	 *     @type bool     $public_only Only tasks whose post is published, not password
	 *                                 protected and of a supported type. Default true.
	 *     @type int      $page        Page number. Default 1.
	 *     @type int      $per_page    Tasks per page. Default 20.
	 * }
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'      => array(),
				'assignee'    => 0,
				'post_id'     => 0,
				'include'     => null,
				'public_only' => true,
				'page'        => 1,
				'per_page'    => 20,
			)
		);

		$tasks_table     = Schema::tasks_table();
		$assignees_table = Schema::assignees_table();
		$where           = array( '1=1' );
		$values          = array();

		if ( $args['public_only'] ) {
			$post_types = get_supported_post_types();
			if ( ! $post_types ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
			$where[] = "p.post_status = 'publish' AND p.post_password = ''";
			$where[] = 'p.post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
			$values  = array_merge( $values, $post_types );
		}

		$statuses = array_values( array_filter( (array) $args['status'], array( self::class, 'is_status' ) ) );
		if ( $statuses ) {
			$where[] = 't.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$values  = array_merge( $values, $statuses );
		}

		if ( $args['post_id'] ) {
			$where[]  = 't.post_id = %d';
			$values[] = (int) $args['post_id'];
		}

		if ( $args['assignee'] ) {
			$where[]  = "EXISTS ( SELECT 1 FROM {$assignees_table} a WHERE a.task_id = t.id AND a.user_id = %d )";
			$values[] = (int) $args['assignee'];
		}

		if ( null !== $args['include'] ) {
			$include = array_values( array_unique( array_filter( (array) $args['include'], array( self::class, 'is_uuid' ) ) ) );
			if ( ! $include ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
			$where[] = 't.task_uuid IN (' . implode( ',', array_fill( 0, count( $include ), '%s' ) ) . ')';
			$values  = array_merge( $values, $include );
		}

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$from     = "FROM {$tasks_table} t INNER JOIN {$wpdb->posts} p ON p.ID = t.post_id WHERE " . implode( ' AND ', $where );
		$order    = "ORDER BY ( t.status = 'completed' ) ASC,
			CASE WHEN t.status = 'completed' THEN 0 ELSE ( t.due_date IS NULL ) END ASC,
			CASE WHEN t.status = 'completed' THEN NULL ELSE t.due_date END ASC,
			CASE WHEN t.status = 'completed' THEN t.completed_at ELSE NULL END DESC,
			t.created_at ASC, t.position ASC, t.id ASC";

		// Results are cached until a task or a post changes.
		$cache_key = 'query:' . md5( (string) wp_json_encode( array( $from, $values, $per_page, $offset ) ) ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP ) . ':' . wp_cache_get_last_changed( 'posts' );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- Custom tables; the SQL above only interpolates table names and generated placeholders.
		$count_sql = "SELECT COUNT(*) {$from}";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $count_sql, $values ) : $count_sql );
		$rows      = array();
		if ( $total > $offset ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.* {$from} {$order} LIMIT %d OFFSET %d", array_merge( $values, array( $per_page, $offset ) ) ) );
		}
		// phpcs:enable

		$result = array(
			'items' => self::hydrate( $rows ),
			'total' => $total,
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, DAY_IN_SECONDS );

		return $result;
	}

	/**
	 * Returns the IDs of the users who are assigned to at least one public task.
	 *
	 * @return int[]
	 */
	public static function get_public_assignee_ids() {
		global $wpdb;

		$post_types = get_supported_post_types();
		if ( ! $post_types ) {
			return array();
		}

		$cache_key = 'assignees:' . md5( implode( ',', $post_types ) ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP ) . ':' . wp_cache_get_last_changed( 'posts' );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tasks_table     = Schema::tasks_table();
		$assignees_table = Schema::assignees_table();
		$placeholders    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- Custom tables; placeholders are generated above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT a.user_id FROM {$assignees_table} a
				INNER JOIN {$tasks_table} t ON t.id = a.task_id
				INNER JOIN {$wpdb->posts} p ON p.ID = t.post_id
				WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ({$placeholders})",
				$post_types
			)
		);
		// phpcs:enable

		$ids = array_map( 'intval', $ids );
		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, DAY_IN_SECONDS );

		return $ids;
	}

	/**
	 * Inserts a task.
	 *
	 * @param array $data Task data: uuid, post_id, title, status, due_date, position, assignees
	 *                    and optionally completed_at and status_changed_by.
	 * @return object|null The new task, or null when the insert failed, for instance
	 *                     because the identifier is already taken.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now    = current_time( 'mysql', true );
		$status = self::is_status( $data['status'] ?? null ) ? $data['status'] : self::STATUS_NOT_STARTED;
		$row    = array(
			'task_uuid'         => $data['uuid'],
			'post_id'           => (int) $data['post_id'],
			'title'             => (string) $data['title'],
			'status'            => $status,
			'due_date'          => $data['due_date'] ?? null,
			'position'          => (int) ( $data['position'] ?? 0 ),
			'created_at'        => $now,
			'updated_at'        => $now,
			'completed_at'      => self::STATUS_COMPLETED === $status ? ( $data['completed_at'] ?? $now ) : null,
			'status_changed_by' => $data['status_changed_by'] ?? null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$inserted = $wpdb->insert( Schema::tasks_table(), $row );
		if ( ! $inserted ) {
			return null;
		}

		self::set_assignees( (int) $wpdb->insert_id, $data['assignees'] ?? array() );
		self::flush_cache();

		return self::get( $data['uuid'] );
	}

	/**
	 * Updates the properties of a task that mirror its block: title, due date and position.
	 *
	 * The status is deliberately left out; see {@see Tasks::set_status()}.
	 *
	 * @param int   $id   Task row ID.
	 * @param array $data Any of title, due_date and position.
	 */
	public static function update_details( $id, array $data ) {
		global $wpdb;

		$row = array_intersect_key( $data, array_flip( array( 'title', 'due_date', 'position' ) ) );
		if ( ! $row ) {
			return;
		}
		$row['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->update( Schema::tasks_table(), $row, array( 'id' => (int) $id ) );
		self::flush_cache();
	}

	/**
	 * Replaces the assignees of a task.
	 *
	 * @param int   $id       Task row ID.
	 * @param int[] $user_ids User IDs.
	 */
	public static function set_assignees( $id, array $user_ids ) {
		global $wpdb;

		$table    = Schema::assignees_table();
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$current = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE task_id = %d", $id ) ) );

		foreach ( array_diff( $current, $user_ids ) as $user_id ) {
			$wpdb->delete(
				$table,
				array(
					'task_id' => (int) $id,
					'user_id' => $user_id,
				)
			);
		}
		foreach ( array_diff( $user_ids, $current ) as $user_id ) {
			$wpdb->insert(
				$table,
				array(
					'task_id' => (int) $id,
					'user_id' => $user_id,
				)
			);
		}
		// phpcs:enable

		self::flush_cache();
	}

	/**
	 * Changes the status of a task.
	 *
	 * @param object $task    Task.
	 * @param string $status  New status.
	 * @param int    $user_id User making the change.
	 * @return object The updated task.
	 */
	public static function set_status( $task, $status, $user_id = 0 ) {
		global $wpdb;

		if ( ! self::is_status( $status ) || $status === $task->status ) {
			return $task;
		}

		$old_status = $task->status;
		$now        = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->update(
			Schema::tasks_table(),
			array(
				'status'            => $status,
				'updated_at'        => $now,
				'completed_at'      => self::STATUS_COMPLETED === $status ? $now : null,
				'status_changed_by' => $user_id ? (int) $user_id : null,
			),
			array( 'id' => $task->id )
		);
		self::flush_cache();

		$task = self::get( $task->uuid );

		/**
		 * Fires after the status of a task changed.
		 *
		 * @param object $task       The updated task.
		 * @param string $old_status Previous status.
		 * @param int    $user_id    User who made the change, 0 when unknown.
		 */
		do_action( 'action_items_status_changed', $task, $old_status, (int) $user_id );

		return $task;
	}

	/**
	 * Deletes tasks.
	 *
	 * @param object[] $tasks Tasks to delete.
	 */
	public static function delete( array $tasks ) {
		global $wpdb;

		if ( ! $tasks ) {
			return;
		}

		$ids = implode( ',', array_map( 'intval', wp_list_pluck( $tasks, 'id' ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Custom tables; IDs are cast to integers above.
		$wpdb->query( 'DELETE FROM ' . Schema::assignees_table() . " WHERE task_id IN ({$ids})" );
		$wpdb->query( 'DELETE FROM ' . Schema::tasks_table() . " WHERE id IN ({$ids})" );
		// phpcs:enable

		self::flush_cache();

		foreach ( $tasks as $task ) {
			/**
			 * Fires after a task was deleted.
			 *
			 * @param object $task The deleted task.
			 */
			do_action( 'action_items_task_deleted', $task );
		}
	}

	/**
	 * Removes a user from every task of the current site.
	 *
	 * @param int $user_id User ID.
	 */
	public static function remove_user( $user_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->delete( Schema::assignees_table(), array( 'user_id' => (int) $user_id ) );
		self::flush_cache();
	}

	/**
	 * Invalidates cached query results.
	 */
	public static function flush_cache() {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Converts database rows to task objects and attaches their assignees.
	 *
	 * @param object[]|null $rows Database rows.
	 * @return object[]
	 */
	private static function hydrate( $rows ) {
		global $wpdb;

		if ( ! $rows ) {
			return array();
		}

		$table = Schema::assignees_table();
		$ids   = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Custom table; IDs are cast to integers above.
		$assignee_rows = $wpdb->get_results( "SELECT task_id, user_id FROM {$table} WHERE task_id IN ({$ids}) ORDER BY user_id ASC" );
		$assignees     = array();
		foreach ( $assignee_rows as $assignee_row ) {
			$assignees[ (int) $assignee_row->task_id ][] = (int) $assignee_row->user_id;
		}

		$tasks = array();
		foreach ( $rows as $row ) {
			$tasks[] = (object) array(
				'id'                => (int) $row->id,
				'uuid'              => $row->task_uuid,
				'post_id'           => (int) $row->post_id,
				'title'             => $row->title,
				'status'            => self::is_status( $row->status ) ? $row->status : self::STATUS_NOT_STARTED,
				'due_date'          => $row->due_date,
				'position'          => (int) $row->position,
				'created_at'        => $row->created_at,
				'updated_at'        => $row->updated_at,
				'completed_at'      => $row->completed_at,
				'status_changed_by' => null === $row->status_changed_by ? null : (int) $row->status_changed_by,
				'assignees'         => $assignees[ (int) $row->id ] ?? array(),
			);
		}

		return $tasks;
	}
}

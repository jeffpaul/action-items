<?php
/**
 * Block registration and server-side rendering.
 *
 * @package ActionItems
 */

namespace ActionItems;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the blocks and renders tasks.
 *
 * Block output never depends on the current user, so pages can be cached
 * publicly. Personalized state (the viewer's own tasks, enabled controls) is
 * only ever delivered through authenticated REST requests.
 */
class Renderer {

	const VIEW_HANDLE = 'action-items-view';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'init', array( self::class, 'register_blocks' ) );
	}

	/**
	 * Registers both blocks and their shared frontend script.
	 */
	public static function register_blocks() {
		$asset_file = ACTION_ITEMS_DIR . '/build/view.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			// The plugin was installed from source without running the build.
			add_action( 'admin_notices', array( self::class, 'render_missing_build_notice' ) );
			return;
		}

		$asset = require $asset_file;

		wp_register_script(
			self::VIEW_HANDLE,
			plugins_url( 'build/view.js', ACTION_ITEMS_FILE ),
			$asset['dependencies'],
			$asset['version'],
			array( 'strategy' => 'defer' )
		);
		wp_set_script_translations( self::VIEW_HANDLE, 'action-items' );

		register_block_type(
			ACTION_ITEMS_DIR . '/build/task',
			array( 'render_callback' => array( self::class, 'render_task_block' ) )
		);
		register_block_type(
			ACTION_ITEMS_DIR . '/build/task-list',
			array( 'render_callback' => array( self::class, 'render_task_list_block' ) )
		);
	}

	/**
	 * Tells administrators that the plugin was installed without its built assets.
	 */
	public static function render_missing_build_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Action Items: the built assets are missing. Run “npm install” and “npm run build” in the plugin directory, or install a release zip.', 'action-items' ) . '</p></div>';
		}
	}

	/**
	 * Renders the Task block.
	 *
	 * Title, assignees and due date come from the block attributes. The
	 * status comes from the tasks table once the post has been saved, and
	 * from the block attributes before that (previews, unsaved posts).
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Block content, unused.
	 * @param \WP_Block $block      Block instance.
	 * @return string
	 */
	public static function render_task_block( $attributes, $content = '', $block = null ) {
		$title = Tasks::sanitize_title( $attributes['title'] ?? '' );
		if ( '' === $title ) {
			return '';
		}

		$uuid    = $attributes['taskId'] ?? '';
		$post_id = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
		$task    = Tasks::is_uuid( $uuid ) && Schema::DB_VERSION === get_option( Schema::OPTION ) ? Tasks::get( $uuid ) : null;

		// A copy of the block that was not saved yet must not show the state of its original.
		if ( $task && $post_id && $task->post_id !== $post_id ) {
			$task = null;
		}

		$status = $attributes['status'] ?? null;
		$item   = array(
			'uuid'      => $task ? $task->uuid : '',
			'post_id'   => $task ? $task->post_id : $post_id,
			'title'     => $title,
			'status'    => $task ? $task->status : ( Tasks::is_status( $status ) ? $status : Tasks::STATUS_NOT_STARTED ),
			'due_date'  => Tasks::sanitize_due_date( $attributes['dueDate'] ?? null ),
			'assignees' => array_map( 'intval', array_filter( (array) ( $attributes['assignees'] ?? array() ), 'is_numeric' ) ),
		);

		return self::render_item(
			$item,
			array(
				'anchor'       => Tasks::is_uuid( $uuid ) ? 'task-' . $uuid : '',
				'block_markup' => true,
			)
		);
	}

	/**
	 * Renders the Task List block.
	 *
	 * The server-rendered list is the same for everyone: the first page of
	 * all incomplete public tasks. The frontend script then loads the view
	 * of the current visitor through the REST API.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_task_list_block( $attributes ) {
		$per_page     = min( 100, max( 1, (int) ( $attributes['perPage'] ?? 20 ) ) );
		$default_view = 'all' === ( $attributes['defaultView'] ?? 'mine' ) ? 'all' : 'mine';
		$show_filters = ! isset( $attributes['showFilters'] ) || $attributes['showFilters'];

		$results = self::render_results(
			array(
				'view'     => 'all',
				'status'   => 'incomplete',
				'per_page' => $per_page,
			),
			0
		);

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class'             => 'action-items-list',
				'data-per-page'     => (string) $per_page,
				'data-default-view' => $default_view,
			)
		);

		$html  = '<div ' . $wrapper_attributes . '>';
		$html .= self::render_controls( $show_filters );
		$html .= '<div class="action-items-list__results">' . $results['html'] . '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Renders the view toggle and the filters of a task list.
	 *
	 * @param bool $show_filters Whether to include the contributor and status filters.
	 * @return string
	 */
	private static function render_controls( $show_filters ) {
		$html  = '<div class="action-items-list__controls">';
		$html .= '<div class="action-items-list__views" role="group" aria-label="' . esc_attr__( 'Tasks to show', 'action-items' ) . '">';
		$html .= '<button type="button" class="action-items-list__view" data-view="mine" aria-pressed="false">' . esc_html__( 'My Tasks', 'action-items' ) . '</button>';
		$html .= '<button type="button" class="action-items-list__view" data-view="all" aria-pressed="true">' . esc_html__( 'All Tasks', 'action-items' ) . '</button>';
		$html .= '</div>';

		if ( $show_filters ) {
			$users = array();
			$ids   = Tasks::get_public_assignee_ids();
			if ( $ids ) {
				$users = get_users(
					array(
						'include' => $ids,
						'orderby' => 'display_name',
						'blog_id' => 0,
					)
				);
			}

			$html .= '<label class="action-items-list__filter"><span class="action-items-list__filter-label">' . esc_html__( 'Contributor', 'action-items' ) . '</span> ';
			$html .= '<select data-filter="assignee"><option value="">' . esc_html__( 'All contributors', 'action-items' ) . '</option>';
			foreach ( $users as $user ) {
				$html .= '<option value="' . esc_attr( (string) $user->ID ) . '">' . esc_html( Users::get_label( $user ) ) . '</option>';
			}
			$html .= '</select></label>';

			$html .= '<label class="action-items-list__filter"><span class="action-items-list__filter-label">' . esc_html__( 'Status', 'action-items' ) . '</span> ';
			$html .= '<select data-filter="status">';
			foreach ( self::get_status_filters() as $value => $filter ) {
				$html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $filter['label'] ) . '</option>';
			}
			$html .= '</select></label>';
		}//end if

		$html .= '</div>';

		return $html;
	}

	/**
	 * Returns the choices of the status filter and the statuses each one matches.
	 *
	 * @return array<string,array{label: string, statuses: string[]}>
	 */
	public static function get_status_filters() {
		$statuses = Tasks::get_statuses();
		$filters  = array(
			'incomplete' => array(
				'label'    => __( 'Incomplete', 'action-items' ),
				'statuses' => array( Tasks::STATUS_NOT_STARTED, Tasks::STATUS_IN_PROGRESS ),
			),
		);

		foreach ( $statuses as $status => $label ) {
			$filters[ $status ] = array(
				'label'    => $label,
				'statuses' => array( $status ),
			);
		}

		$filters['all'] = array(
			'label'    => __( 'All, including completed', 'action-items' ),
			'statuses' => array_keys( $statuses ),
		);

		return $filters;
	}

	/**
	 * Renders one page of a task list for a given viewer.
	 *
	 * @param array $args {
	 *     List arguments.
	 *
	 *     @type string $view     'mine' or 'all'. 'mine' falls back to 'all' for anonymous viewers.
	 *     @type int    $assignee Only tasks assigned to this user. Ignored when the view is 'mine'.
	 *     @type string $status   Key of a status filter. Default 'incomplete'.
	 *     @type int    $page     Page number.
	 *     @type int    $per_page Tasks per page.
	 * }
	 * @param int   $viewer_id User the list is rendered for, 0 for the public, read-only list.
	 * @return array{html: string, total: int, total_pages: int, page: int, view: string}
	 */
	public static function render_results( array $args, $viewer_id ) {
		$args = wp_parse_args(
			$args,
			array(
				'view'     => 'all',
				'assignee' => 0,
				'status'   => 'incomplete',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$filters  = self::get_status_filters();
		$status   = isset( $filters[ $args['status'] ] ) ? $args['status'] : 'incomplete';
		$view     = 'mine' === $args['view'] && $viewer_id ? 'mine' : 'all';
		$assignee = 'mine' === $view ? (int) $viewer_id : (int) $args['assignee'];
		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );

		$query_args = array(
			'status'      => $filters[ $status ]['statuses'],
			'assignee'    => $assignee,
			'public_only' => true,
			'page'        => $page,
			'per_page'    => $per_page,
		);
		$result     = Tasks::query( $query_args );

		// Fall back to the last page when the requested one no longer exists.
		$total_pages = (int) ceil( $result['total'] / $per_page );
		if ( $total_pages && $page > $total_pages ) {
			$page               = $total_pages;
			$query_args['page'] = $page;
			$result             = Tasks::query( $query_args );
		}

		self::prime_caches( $result['items'] );

		if ( ! $result['items'] ) {
			$html = '<p class="action-items-list__empty">' . esc_html( self::get_empty_message( $view, $status, $assignee ) ) . '</p>';
		} else {
			// The explicit role keeps list semantics in browsers that drop them for unstyled lists.
			$html = '<ul class="action-items-list__items" role="list">';
			foreach ( $result['items'] as $task ) {
				$html .= self::render_item(
					array(
						'uuid'      => $task->uuid,
						'post_id'   => $task->post_id,
						'title'     => $task->title,
						'status'    => $task->status,
						'due_date'  => $task->due_date,
						'assignees' => $task->assignees,
					),
					array(
						'tag'         => 'li',
						'interactive' => $viewer_id && Permissions::can_update_status( $task, $viewer_id ),
						'show_source' => true,
					)
				);
			}
			$html .= '</ul>';
		}//end if

		if ( $total_pages > 1 ) {
			$html .= '<nav class="action-items-list__pagination" aria-label="' . esc_attr__( 'Tasks pagination', 'action-items' ) . '">';
			$html .= '<button type="button" class="action-items-list__page" data-page="' . esc_attr( (string) ( $page - 1 ) ) . '"' . disabled( $page <= 1, true, false ) . '>' . esc_html__( 'Previous', 'action-items' ) . '</button>';
			$html .= '<span class="action-items-list__page-status">' . esc_html(
				sprintf(
					/* translators: 1: Current page number. 2: Total number of pages. */
					__( 'Page %1$s of %2$s', 'action-items' ),
					number_format_i18n( $page ),
					number_format_i18n( $total_pages )
				)
			) . '</span>';
			$html .= '<button type="button" class="action-items-list__page" data-page="' . esc_attr( (string) ( $page + 1 ) ) . '"' . disabled( $page >= $total_pages, true, false ) . '>' . esc_html__( 'Next', 'action-items' ) . '</button>';
			$html .= '</nav>';
		}

		return array(
			'html'        => $html,
			'total'       => $result['total'],
			'total_pages' => $total_pages,
			'page'        => $page,
			'view'        => $view,
		);
	}

	/**
	 * Returns the message shown when a list has no tasks.
	 *
	 * @param string $view     'mine' or 'all'.
	 * @param string $status   Key of the active status filter.
	 * @param int    $assignee Active contributor filter.
	 * @return string
	 */
	private static function get_empty_message( $view, $status, $assignee ) {
		if ( 'mine' === $view ) {
			return 'incomplete' === $status
				? __( 'No incomplete tasks assigned to you.', 'action-items' )
				: __( 'No matching tasks assigned to you.', 'action-items' );
		}

		if ( 'incomplete' === $status && ! $assignee ) {
			return __( 'No incomplete tasks.', 'action-items' );
		}

		return __( 'No tasks match these filters.', 'action-items' );
	}

	/**
	 * Renders a single task.
	 *
	 * The markup always contains the status controls. They are disabled and
	 * hidden unless `interactive` is set; the frontend script enables them
	 * for visitors who may change the status.
	 *
	 * @param array $item Task data: uuid, post_id, title, status, due_date, assignees.
	 * @param array $args {
	 *     Rendering options.
	 *
	 *     @type string $tag          Wrapper element. Default 'div'.
	 *     @type bool   $interactive  Whether the status controls are enabled. Default false.
	 *     @type bool   $show_source  Whether to link to the originating post. Default false.
	 *     @type string $anchor       HTML id of the wrapper. Default none.
	 *     @type bool   $block_markup Whether to add the block wrapper attributes. Default false.
	 * }
	 * @return string
	 */
	public static function render_item( array $item, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'tag'          => 'div',
				'interactive'  => false,
				'show_source'  => false,
				'anchor'       => '',
				'block_markup' => false,
			)
		);

		$statuses    = Tasks::get_statuses();
		$status      = $item['status'];
		$completed   = Tasks::STATUS_COMPLETED === $status;
		$plain_title = wp_strip_all_tags( $item['title'] );
		$overdue     = ! $completed && $item['due_date'] && $item['due_date'] < wp_date( 'Y-m-d' );
		$tag         = 'li' === $args['tag'] ? 'li' : 'div';

		$wrapper = array(
			'class' => 'action-items-task is-status-' . $status . ( $overdue ? ' is-overdue' : '' ),
		);
		if ( $args['anchor'] ) {
			$wrapper['id'] = $args['anchor'];
		}
		if ( $item['uuid'] ) {
			$wrapper['data-task-id'] = $item['uuid'];
			$wrapper['data-post-id'] = (string) $item['post_id'];
		}

		if ( $args['block_markup'] ) {
			$wrapper_attributes = get_block_wrapper_attributes( $wrapper );
		} else {
			$wrapper_attributes = '';
			foreach ( $wrapper as $name => $value ) {
				$wrapper_attributes .= ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}

		// Feeds get plain markup: form controls are useless there.
		$is_static = is_feed();

		$html = '<' . $tag . ' ' . trim( $wrapper_attributes ) . '>';

		if ( ! $is_static ) {
			$html .= sprintf(
				'<input type="checkbox" class="action-items-task__checkbox" aria-label="%s"%s%s />',
				/* translators: %s: Task title. */
				esc_attr( sprintf( __( 'Mark “%s” as completed', 'action-items' ), $plain_title ) ),
				checked( $completed, true, false ),
				disabled( ! $args['interactive'], true, false )
			);
		}

		$html .= '<div class="action-items-task__body">';
		$html .= '<div class="action-items-task__title">' . Tasks::sanitize_title( $item['title'] ) . '</div>';
		$html .= '<div class="action-items-task__meta">';
		$html .= '<span class="action-items-task__status"' . ( $args['interactive'] ? ' hidden' : '' ) . '>' . esc_html( $statuses[ $status ] ) . '</span>';

		if ( ! $is_static ) {
			$html .= sprintf(
				'<select class="action-items-task__status-select" aria-label="%s"%s>',
				/* translators: %s: Task title. */
				esc_attr( sprintf( __( 'Status of “%s”', 'action-items' ), $plain_title ) ),
				$args['interactive'] ? '' : ' hidden disabled'
			);
			foreach ( $statuses as $value => $label ) {
				$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select>';
		}

		$html .= self::render_assignees( $item['assignees'] );

		if ( $item['due_date'] ) {
			$html .= '<time class="action-items-task__due" datetime="' . esc_attr( $item['due_date'] ) . '">' . esc_html( self::format_due_date( $item['due_date'] ) );
			if ( $overdue ) {
				$html .= ' <span class="action-items-task__overdue">' . esc_html__( '(overdue)', 'action-items' ) . '</span>';
			}
			$html .= '</time>';
		}

		if ( $args['show_source'] ) {
			$post = get_post( $item['post_id'] );
			if ( $post ) {
				$html .= '<a class="action-items-task__source" href="' . esc_url( get_permalink( $post ) . '#task-' . $item['uuid'] ) . '">' . esc_html( self::get_post_title( $post ) ) . '</a>';
			}
		}

		$html .= '</div></div></' . $tag . '>';

		return $html;
	}

	/**
	 * Renders the assignees of a task.
	 *
	 * @param int[] $user_ids Assignee user IDs.
	 * @return string
	 */
	private static function render_assignees( array $user_ids ) {
		$names = array();

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$avatar = get_avatar(
				$user->ID,
				20,
				'',
				'',
				array( 'class' => 'action-items-task__avatar' )
			);
			$label  = esc_html( Users::get_label( $user ) );
			$url    = Users::get_url( $user );
			if ( $url ) {
				$label = '<a href="' . esc_url( $url ) . '">' . $label . '</a>';
			}

			$names[] = '<span class="action-items-task__assignee">' . $avatar . $label . '</span>';
		}//end foreach

		if ( ! $names ) {
			return '';
		}

		return '<span class="action-items-task__assignees"><span class="action-items-sr-only">' . esc_html__( 'Assigned to:', 'action-items' ) . ' </span>' . implode( '<span class="action-items-sr-only">, </span>', $names ) . '</span>';
	}

	/**
	 * Formats a due date for display.
	 *
	 * @param string $date Date as Y-m-d.
	 * @return string
	 */
	public static function format_due_date( $date ) {
		$timezone  = new \DateTimeZone( 'UTC' );
		$timestamp = strtotime( $date . ' 12:00:00 UTC' );
		$same_year = substr( $date, 0, 4 ) === wp_date( 'Y' );
		$format    = $same_year
			/* translators: Due date format without the year, see https://www.php.net/manual/datetime.format.php */
			? _x( 'M j', 'due date format', 'action-items' )
			/* translators: Due date format with the year, see https://www.php.net/manual/datetime.format.php */
			: _x( 'M j, Y', 'due date format', 'action-items' );

		/* translators: %s: Due date. */
		return sprintf( __( 'Due %s', 'action-items' ), wp_date( $format, $timestamp, $timezone ) );
	}

	/**
	 * Returns the title of a post, with a fallback for untitled posts.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function get_post_title( $post ) {
		$title = wp_strip_all_tags( get_the_title( $post ) );

		return '' === $title ? __( '(no title)', 'action-items' ) : html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Primes the post and user caches for a list of tasks.
	 *
	 * @param object[] $tasks Tasks.
	 */
	public static function prime_caches( array $tasks ) {
		if ( ! $tasks ) {
			return;
		}

		_prime_post_caches( array_unique( wp_list_pluck( $tasks, 'post_id' ) ), false, false );

		$user_ids = array();
		foreach ( $tasks as $task ) {
			$user_ids = array_merge( $user_ids, $task->assignees );
		}
		if ( $user_ids ) {
			cache_users( array_unique( $user_ids ) );
		}
	}
}

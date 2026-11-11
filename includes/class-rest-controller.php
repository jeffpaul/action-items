<?php
/**
 * REST API.
 *
 * @package ActionItems
 */

namespace ActionItems;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes of the `action-items/v1` namespace.
 *
 * Reading public tasks requires no authentication. Changing a status and
 * searching users require a logged-in user; cookie authentication is
 * protected by the standard REST nonce.
 */
class REST_Controller extends WP_REST_Controller {

	const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'action-items/v1';
		$this->rest_base = 'tasks';
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . self::UUID_PATTERN . ')',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier of the task.', 'action-items' ),
						'type'        => 'string',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'status' => array(
							'description' => __( 'Status of the task.', 'action-items' ),
							'type'        => 'string',
							'enum'        => array_keys( Tasks::get_statuses() ),
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/task-list',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rendered_list' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'view'     => array(
							'description' => __( 'Which tasks to show. “default” resolves to the default view of the block for logged-in users and to all tasks for anonymous visitors.', 'action-items' ),
							'type'        => 'string',
							'enum'        => array( 'default', 'mine', 'all' ),
							'default'     => 'default',
						),
						'default'  => array(
							'description' => __( 'Default view of the block.', 'action-items' ),
							'type'        => 'string',
							'enum'        => array( 'mine', 'all' ),
							'default'     => 'mine',
						),
						'assignee' => array(
							'description' => __( 'Limit the list to tasks assigned to this user ID.', 'action-items' ),
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => 0,
						),
						'status'   => array(
							'description' => __( 'Status filter.', 'action-items' ),
							'type'        => 'string',
							'enum'        => array_keys( Renderer::get_status_filters() ),
							'default'     => 'incomplete',
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/users',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_users' ),
					'permission_callback' => array( $this, 'get_users_permissions_check' ),
					'args'                => array(
						'search'   => array(
							'description' => __( 'Search by display name or username.', 'action-items' ),
							'type'        => 'string',
							'default'     => '',
							'maxLength'   => 100,
						),
						'include'  => array(
							'description' => __( 'Limit the results to these user IDs.', 'action-items' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'maxItems'    => 100,
							'default'     => array(),
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 10,
						),
					),
				),
			)
		);
	}

	/**
	 * Query parameters of the tasks collection.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'status'   => array(
				'description' => __( 'Limit the results to these statuses. Defaults to incomplete tasks.', 'action-items' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => array_keys( Tasks::get_statuses() ),
				),
				'default'     => array( Tasks::STATUS_NOT_STARTED, Tasks::STATUS_IN_PROGRESS ),
			),
			'assignee' => array(
				'description'       => __( 'Limit the results to tasks assigned to this user ID, or “me” for the current user.', 'action-items' ),
				'type'              => array( 'integer', 'string' ),
				'validate_callback' => static function ( $value ) {
					return 'me' === $value || ( is_numeric( $value ) && (int) $value > 0 && (string) (int) $value === (string) $value );
				},
			),
			'post'     => array(
				'description' => __( 'Limit the results to the tasks of this post. Tasks of unpublished posts are only returned to users who can edit the post.', 'action-items' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'include'  => array(
				'description' => __( 'Limit the results to these task identifiers.', 'action-items' ),
				'type'        => 'array',
				'items'       => array(
					'type'    => 'string',
					'pattern' => '^' . self::UUID_PATTERN . '$',
				),
				'maxItems'    => 100,
			),
			'page'     => array(
				'description' => __( 'Current page of the collection.', 'action-items' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
			),
			'per_page' => array(
				'description' => __( 'Maximum number of tasks per page.', 'action-items' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => 20,
			),
		);
	}

	/**
	 * Checks whether the tasks collection can be read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( 'me' === $request['assignee'] && ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'You are not currently logged in.', 'action-items' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Returns a page of tasks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$post_id  = (int) $request['post'];
		$assignee = 'me' === $request['assignee'] ? get_current_user_id() : (int) $request['assignee'];

		$result = Tasks::query(
			array(
				'status'      => $request['status'],
				'assignee'    => $assignee,
				'post_id'     => $post_id,
				'include'     => $request['include'],
				// Unpublished tasks are only listed per post, for users who can edit that post.
				'public_only' => ! ( $post_id && current_user_can( 'edit_post', $post_id ) ),
				'page'        => $request['page'],
				'per_page'    => $request['per_page'],
			)
		);

		Renderer::prime_caches( $result['items'] );

		$items = array();
		foreach ( $result['items'] as $task ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $task, $request ) );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) ceil( $result['total'] / $request['per_page'] ) );

		return $response;
	}

	/**
	 * Fetches the task of a request when the current user can see it.
	 *
	 * Tasks the user cannot see are reported as missing, so that identifiers
	 * of unpublished tasks cannot be probed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return object|WP_Error
	 */
	private function get_task( $request ) {
		$task = Tasks::get( $request['id'] );

		if ( ! $task || ! Permissions::can_read( $task, get_current_user_id() ) ) {
			return new WP_Error( 'action_items_not_found', __( 'Task not found.', 'action-items' ), array( 'status' => 404 ) );
		}

		return $task;
	}

	/**
	 * Checks whether a task can be read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$task = $this->get_task( $request );

		return is_wp_error( $task ) ? $task : true;
	}

	/**
	 * Returns a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$task = $this->get_task( $request );

		return is_wp_error( $task ) ? $task : $this->prepare_item_for_response( $task, $request );
	}

	/**
	 * Checks whether the status of a task can be changed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'You are not currently logged in.', 'action-items' ), array( 'status' => 401 ) );
		}

		$task = $this->get_task( $request );
		if ( is_wp_error( $task ) ) {
			return $task;
		}

		if ( ! Permissions::can_update_status( $task, get_current_user_id() ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to update this task.', 'action-items' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Changes the status of a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$task = $this->get_task( $request );
		if ( is_wp_error( $task ) ) {
			return $task;
		}

		$task = Tasks::set_status( $task, $request['status'], get_current_user_id() );

		return $this->prepare_item_for_response( $task, $request );
	}

	/**
	 * Returns one rendered page of a task list for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_rendered_list( $request ) {
		$viewer_id = get_current_user_id();
		$view      = 'default' === $request['view'] ? $request['default'] : $request['view'];

		$results = Renderer::render_results(
			array(
				'view'     => $view,
				'assignee' => $request['assignee'],
				'status'   => $request['status'],
				'page'     => $request['page'],
				'per_page' => $request['per_page'],
			),
			$viewer_id
		);

		$results['viewer'] = $viewer_id ? array( 'id' => $viewer_id ) : null;

		return rest_ensure_response( $results );
	}

	/**
	 * Checks whether users can be searched.
	 *
	 * @return true|WP_Error
	 */
	public function get_users_permissions_check() {
		if ( ! Permissions::can_search_users() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to assign tasks.', 'action-items' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Searches the users tasks can be assigned to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_users( $request ) {
		$users = Users::search( trim( $request['search'] ), $request['include'], $request['per_page'] );

		$data = array();
		foreach ( $users as $user ) {
			$data[] = Users::to_public_array( $user, true );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Prepares a task for a response.
	 *
	 * @param object          $item    Task.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$post      = get_post( $item->post_id );
		$link      = $post ? get_permalink( $post ) : '';
		$assignees = array();

		foreach ( $item->assignees as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$assignees[] = Users::to_public_array( $user );
			}
		}

		$data = array(
			'id'         => $item->uuid,
			'status'     => $item->status,
			'title'      => array( 'rendered' => $item->title ),
			'due_date'   => $item->due_date,
			'created'    => mysql_to_rfc3339( $item->created_at ),
			'modified'   => mysql_to_rfc3339( $item->updated_at ),
			'completed'  => $item->completed_at ? mysql_to_rfc3339( $item->completed_at ) : null,
			'assignees'  => $assignees,
			'post'       => array(
				'id'    => $item->post_id,
				'title' => $post ? Renderer::get_post_title( $post ) : '',
				'link'  => $link,
			),
			'link'       => $link ? $link . '#task-' . $item->uuid : '',
			'can_update' => Permissions::can_update_status( $item, get_current_user_id() ),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Schema of a task.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'action-item',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'description' => __( 'Unique identifier of the task.', 'action-items' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'status'     => array(
					'description' => __( 'Status of the task.', 'action-items' ),
					'type'        => 'string',
					'enum'        => array_keys( Tasks::get_statuses() ),
				),
				'title'      => array(
					'description' => __( 'Title of the task.', 'action-items' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'rendered' => array( 'type' => 'string' ),
					),
				),
				'due_date'   => array(
					'description' => __( 'Due date of the task, as YYYY-MM-DD.', 'action-items' ),
					'type'        => array( 'string', 'null' ),
					'readonly'    => true,
				),
				'created'    => array(
					'description' => __( 'Date the task was created, in GMT.', 'action-items' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'modified'   => array(
					'description' => __( 'Date the task was last modified, in GMT.', 'action-items' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'completed'  => array(
					'description' => __( 'Date the task was completed, in GMT.', 'action-items' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'assignees'  => array(
					'description' => __( 'Users the task is assigned to.', 'action-items' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array( 'type' => 'integer' ),
							'name'        => array( 'type' => 'string' ),
							'avatar_urls' => array( 'type' => 'object' ),
						),
					),
				),
				'post'       => array(
					'description' => __( 'Post the task belongs to.', 'action-items' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'id'    => array( 'type' => 'integer' ),
						'title' => array( 'type' => 'string' ),
						'link'  => array(
							'type'   => 'string',
							'format' => 'uri',
						),
					),
				),
				'link'       => array(
					'description' => __( 'URL of the task within its post.', 'action-items' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
				'can_update' => array(
					'description' => __( 'Whether the current user can change the status of the task.', 'action-items' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}

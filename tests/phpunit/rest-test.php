<?php
/**
 * Tests for the REST API and the permission rules.
 *
 * @package ActionItems
 */

use ActionItems\Tasks;

/**
 * @covers \ActionItems\REST_Controller
 * @covers \ActionItems\Permissions
 * @covers \ActionItems\Users
 */
class REST_Test extends Action_Items_Test_Case {

	/**
	 * User IDs by role name.
	 *
	 * @var int[]
	 */
	protected static $users = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
			self::$users[ $role ] = $factory->user->create(
				array(
					'role'         => $role,
					'user_login'   => 'login_' . $role,
					'display_name' => 'Display ' . ucfirst( $role ),
					'user_email'   => $role . '@private.example',
				)
			);
		}
		self::$users['assignee'] = $factory->user->create(
			array(
				'role'         => 'subscriber',
				'user_login'   => 'login_assignee',
				'display_name' => 'Ada Assignee',
				'user_email'   => 'ada@private.example',
			)
		);
	}

	/**
	 * Dispatches a REST request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route below the plugin namespace.
	 * @param array  $params Parameters.
	 * @return WP_REST_Response
	 */
	protected function request( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/action-items/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Creates a published post with one task assigned to the "assignee" user.
	 *
	 * @param array $post_args Post arguments.
	 * @return int Post ID.
	 */
	protected function create_assigned_task( array $post_args = array() ) {
		return $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Assigned task',
					'assignees' => array( self::$users['assignee'] ),
				),
			),
			array_merge( array( 'post_author' => self::$users['editor'] ), $post_args )
		);
	}

	public function test_anonymous_visitors_can_list_public_incomplete_tasks() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Open <em>task</em>',
					'assignees' => array( self::$users['assignee'] ),
					'dueDate'   => '2030-11-13',
				),
				array(
					'taskId' => self::ID_B,
					'title'  => 'Done task',
				),
			),
			array( 'post_title' => 'Meeting notes' )
		);
		$this->complete( self::ID_B );
		$this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_C,
					'title'  => 'Draft task',
				),
			),
			array( 'post_status' => 'draft' )
		);

		$response = $this->request( 'GET', '/tasks' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );

		$task = $data[0];
		$this->assertSame( self::ID_A, $task['id'] );
		$this->assertSame( 'not-started', $task['status'] );
		$this->assertSame( 'Open <em>task</em>', $task['title']['rendered'] );
		$this->assertSame( '2030-11-13', $task['due_date'] );
		$this->assertNull( $task['completed'] );
		$this->assertSame( $post_id, $task['post']['id'] );
		$this->assertSame( 'Meeting notes', $task['post']['title'] );
		$this->assertSame( get_permalink( $post_id ) . '#task-' . self::ID_A, $task['link'] );
		$this->assertFalse( $task['can_update'] );

		$this->assertCount( 1, $task['assignees'] );
		$this->assertSame( self::$users['assignee'], $task['assignees'][0]['id'] );
		$this->assertSame( 'Ada Assignee', $task['assignees'][0]['name'] );
	}

	public function test_responses_never_contain_private_user_data() {
		$this->create_assigned_task();
		wp_set_current_user( self::$users['administrator'] );

		$json = wp_json_encode(
			array(
				$this->request( 'GET', '/tasks' )->get_data(),
				$this->request( 'GET', '/tasks/' . self::ID_A )->get_data(),
				$this->request( 'GET', '/task-list', array( 'view' => 'all' ) )->get_data(),
				$this->request( 'GET', '/users', array( 'search' => 'Ada' ) )->get_data(),
			)
		);

		$this->assertStringContainsString( 'Ada Assignee', $json );
		$this->assertStringNotContainsString( 'private.example', $json );
		// The nicename is only given to logged-in users searching for assignees.
		$this->assertSame( 1, substr_count( $json, 'login_assignee' ) );
		$this->assertStringContainsString( '"slug":"login_assignee"', $json );
		$this->assertStringNotContainsString( 'login_assignee', wp_json_encode( $this->request( 'GET', '/tasks' )->get_data() ) );
	}

	public function test_status_filter() {
		$this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'A',
				),
				array(
					'taskId' => self::ID_B,
					'title'  => 'B',
					'status' => 'in-progress',
				),
				array(
					'taskId' => self::ID_C,
					'title'  => 'C',
					'status' => 'completed',
				),
			)
		);

		$ids = static function ( $response ) {
			return wp_list_pluck( $response->get_data(), 'id' );
		};

		$this->assertSame( array( self::ID_A, self::ID_B ), $ids( $this->request( 'GET', '/tasks' ) ) );
		$this->assertSame( array( self::ID_C ), $ids( $this->request( 'GET', '/tasks', array( 'status' => 'completed' ) ) ) );
		$this->assertSame( array( self::ID_B ), $ids( $this->request( 'GET', '/tasks', array( 'status' => array( 'in-progress' ) ) ) ) );
		$this->assertSame(
			array( self::ID_A, self::ID_B, self::ID_C ),
			$ids( $this->request( 'GET', '/tasks', array( 'status' => 'not-started,in-progress,completed' ) ) )
		);
	}

	public function test_assignee_filter_and_my_tasks() {
		$this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Mine',
					'assignees' => array( self::$users['assignee'] ),
				),
				array(
					'taskId'    => self::ID_B,
					'title'     => 'Shared',
					'assignees' => array( self::$users['assignee'], self::$users['author'] ),
				),
				array(
					'taskId'    => self::ID_C,
					'title'     => 'Theirs',
					'assignees' => array( self::$users['author'] ),
				),
			)
		);

		$ids = static function ( $response ) {
			return wp_list_pluck( $response->get_data(), 'id' );
		};

		$this->assertSame(
			array( self::ID_B, self::ID_C ),
			$ids( $this->request( 'GET', '/tasks', array( 'assignee' => self::$users['author'] ) ) )
		);

		$this->assertSame( 401, $this->request( 'GET', '/tasks', array( 'assignee' => 'me' ) )->get_status() );

		wp_set_current_user( self::$users['assignee'] );
		$response = $this->request( 'GET', '/tasks', array( 'assignee' => 'me' ) );
		$this->assertSame( array( self::ID_A, self::ID_B ), $ids( $response ) );
		$this->assertTrue( $response->get_data()[0]['can_update'] );

		wp_set_current_user( self::$users['subscriber'] );
		$this->assertSame( array(), $ids( $this->request( 'GET', '/tasks', array( 'assignee' => 'me' ) ) ) );
	}

	public function test_incomplete_tasks_are_sorted_by_due_date_then_creation_and_completed_last() {
		global $wpdb;

		$this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'No due date, older',
				),
				array(
					'taskId'  => self::ID_B,
					'title'   => 'Due later',
					'dueDate' => '2031-01-01',
				),
				array(
					'taskId'  => self::ID_C,
					'title'   => 'Completed, due first',
					'dueDate' => '2029-01-01',
				),
			)
		);
		$newer = '44444444-4444-4444-8444-444444444444';
		$soon  = '55555555-5555-4555-8555-555555555555';
		$this->create_post_with_tasks(
			array(
				array(
					'taskId' => $newer,
					'title'  => 'No due date, newer',
				),
				array(
					'taskId'  => $soon,
					'title'   => 'Due sooner',
					'dueDate' => '2030-06-01',
				),
			)
		);
		$this->complete( self::ID_C );

		// Make the creation dates differ.
		// phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ActionItems\Schema::tasks_table() . " SET created_at = '2020-01-01 00:00:00' WHERE task_uuid IN (%s, %s, %s)", self::ID_A, self::ID_B, self::ID_C ) );
		Tasks::flush_cache();

		$response = $this->request( 'GET', '/tasks', array( 'status' => 'not-started,in-progress,completed' ) );

		$this->assertSame(
			array( $soon, self::ID_B, self::ID_A, $newer, self::ID_C ),
			wp_list_pluck( $response->get_data(), 'id' )
		);
	}

	public function test_pagination() {
		$tasks = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$tasks[] = array(
				'taskId'  => wp_generate_uuid4(),
				'title'   => 'Task ' . $i,
				'dueDate' => '2030-01-0' . $i,
			);
		}
		$this->create_post_with_tasks( $tasks );

		$response = $this->request(
			'GET',
			'/tasks',
			array(
				'per_page' => 2,
				'page'     => 3,
			)
		);

		$this->assertSame( '5', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '3', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( array( $tasks[4]['taskId'] ), wp_list_pluck( $response->get_data(), 'id' ) );

		$this->assertSame( array(), $this->request( 'GET', '/tasks', array( 'page' => 9 ) )->get_data() );
	}

	/**
	 * @dataProvider data_invalid_collection_params
	 *
	 * @param array $params Invalid parameters.
	 */
	public function test_invalid_collection_params_are_rejected( $params ) {
		$this->assertSame( 400, $this->request( 'GET', '/tasks', $params )->get_status() );
	}

	public function data_invalid_collection_params() {
		return array(
			'unknown status'      => array( array( 'status' => 'done' ) ),
			'sql in status'       => array( array( 'status' => "completed' OR '1'='1" ) ),
			'assignee string'     => array( array( 'assignee' => 'someone' ) ),
			'assignee injection'  => array( array( 'assignee' => '1 OR 1=1' ) ),
			'negative assignee'   => array( array( 'assignee' => -1 ) ),
			'include not uuid'    => array( array( 'include' => array( 'abc' ) ) ),
			'include injection'   => array( array( 'include' => "x') OR 1=1 -- " ) ),
			'per_page too large'  => array( array( 'per_page' => 101 ) ),
			'per_page zero'       => array( array( 'per_page' => 0 ) ),
			'page zero'           => array( array( 'page' => 0 ) ),
			'post not an integer' => array( array( 'post' => 'abc' ) ),
		);
	}

	public function test_tasks_of_unpublished_posts_are_only_listed_for_users_who_can_edit_the_post() {
		$post_id = $this->create_assigned_task( array( 'post_status' => 'draft' ) );
		$params  = array(
			'post'   => $post_id,
			'status' => 'not-started,in-progress,completed',
		);

		// Anonymous visitors, unrelated users and even the assignee see nothing.
		foreach ( array( 0, self::$users['subscriber'], self::$users['contributor'], self::$users['assignee'] ) as $user_id ) {
			wp_set_current_user( $user_id );
			$this->assertSame( array(), $this->request( 'GET', '/tasks', $params )->get_data(), "User $user_id" );
			$this->assertSame( array(), $this->request( 'GET', '/tasks', array( 'include' => array( self::ID_A ) ) )->get_data(), "User $user_id" );
			$this->assertSame( 404, $this->request( 'GET', '/tasks/' . self::ID_A )->get_status(), "User $user_id" );
			$this->assertSame( $user_id ? 404 : 401, $this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) )->get_status(), "User $user_id" );
		}

		wp_set_current_user( self::$users['editor'] );
		$this->assertCount( 1, $this->request( 'GET', '/tasks', $params )->get_data() );
		$this->assertSame( 200, $this->request( 'GET', '/tasks/' . self::ID_A )->get_status() );

		// Without the post parameter, lists are always limited to public tasks.
		$this->assertSame( array(), $this->request( 'GET', '/tasks' )->get_data() );
		$this->assertStringNotContainsString( 'Assigned task', $this->request( 'GET', '/task-list', array( 'view' => 'all' ) )->get_data()['html'] );
	}

	public function test_private_and_password_protected_posts_do_not_leak_tasks() {
		$this->create_assigned_task( array( 'post_status' => 'private' ) );
		$this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_B,
					'title'     => 'Protected task',
					'assignees' => array( self::$users['assignee'] ),
				),
			),
			array( 'post_password' => 'secret' )
		);

		wp_set_current_user( self::$users['assignee'] );

		$this->assertSame( array(), $this->request( 'GET', '/tasks', array( 'assignee' => 'me' ) )->get_data() );
		$this->assertSame( 404, $this->request( 'GET', '/tasks/' . self::ID_A )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/tasks/' . self::ID_B )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/tasks/' . self::ID_B, array( 'status' => 'completed' ) )->get_status() );
		$this->assertSame( 'not-started', Tasks::get( self::ID_B )->status );
	}

	public function test_get_single_task() {
		$this->create_assigned_task();

		$response = $this->request( 'GET', '/tasks/' . self::ID_A );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::ID_A, $response->get_data()['id'] );

		$this->assertSame( 404, $this->request( 'GET', '/tasks/' . self::ID_B )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/tasks/not-a-uuid' )->get_status() );
	}

	/**
	 * @dataProvider data_status_update_permissions
	 *
	 * @param string|null $role     Key of the acting user, null for anonymous.
	 * @param int         $expected Expected response status.
	 */
	public function test_status_update_permissions( $role, $expected ) {
		$this->create_assigned_task();
		wp_set_current_user( $role ? self::$users[ $role ] : 0 );

		$response = $this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) );

		$this->assertSame( $expected, $response->get_status() );
		$this->assertSame( 200 === $expected ? 'completed' : 'not-started', Tasks::get( self::ID_A )->status );
	}

	public function data_status_update_permissions() {
		return array(
			'anonymous'                    => array( null, 401 ),
			'unrelated subscriber'         => array( 'subscriber', 403 ),
			'unrelated contributor'        => array( 'contributor', 403 ),
			'author of another post'       => array( 'author', 403 ),
			'assignee who cannot edit'     => array( 'assignee', 200 ),
			'editor who can edit the post' => array( 'editor', 200 ),
			'administrator'                => array( 'administrator', 200 ),
		);
	}

	public function test_assignee_completes_a_task_without_being_able_to_edit_the_post() {
		$post_id = $this->create_assigned_task();
		$content = get_post( $post_id )->post_content;
		wp_set_current_user( self::$users['assignee'] );

		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );

		$response = $this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $data['status'] );
		$this->assertNotNull( $data['completed'] );
		$this->assertTrue( $data['can_update'] );

		$task = Tasks::get( self::ID_A );
		$this->assertSame( self::$users['assignee'], $task->status_changed_by );

		// The post itself is untouched: same content, no new revision.
		$this->assertSame( $content, get_post( $post_id )->post_content );

		// Reopening works too, and clears the completion date.
		$response = $this->request( 'PATCH', '/tasks/' . self::ID_A, array( 'status' => 'in-progress' ) );
		$this->assertSame( 'in-progress', $response->get_data()['status'] );
		$this->assertNull( $response->get_data()['completed'] );
	}

	public function test_author_can_update_tasks_of_their_own_post() {
		$this->create_assigned_task( array( 'post_author' => self::$users['author'] ) );
		wp_set_current_user( self::$users['author'] );

		$this->assertSame( 200, $this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) )->get_status() );
	}

	public function test_removed_assignee_loses_access() {
		$post_id = $this->create_assigned_task();
		$this->update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->task_block(
					array(
						'taskId'    => self::ID_A,
						'title'     => 'Assigned task',
						'assignees' => array( self::$users['author'] ),
					)
				),
			)
		);

		wp_set_current_user( self::$users['assignee'] );

		$this->assertSame( 403, $this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) )->get_status() );
	}

	/**
	 * @dataProvider data_invalid_status_updates
	 *
	 * @param array $params Invalid parameters.
	 */
	public function test_invalid_status_updates_are_rejected( $params ) {
		$this->create_assigned_task();
		wp_set_current_user( self::$users['assignee'] );

		$this->assertSame( 400, $this->request( 'POST', '/tasks/' . self::ID_A, $params )->get_status() );
		$this->assertSame( 'not-started', Tasks::get( self::ID_A )->status );
	}

	public function data_invalid_status_updates() {
		return array(
			'missing status' => array( array() ),
			'unknown status' => array( array( 'status' => 'done' ) ),
			'empty status'   => array( array( 'status' => '' ) ),
			'array status'   => array( array( 'status' => array( 'completed' ) ) ),
			'markup'         => array( array( 'status' => '<script>alert(1)</script>' ) ),
		);
	}

	public function test_only_the_status_can_be_changed_through_the_api() {
		$this->create_assigned_task();
		wp_set_current_user( self::$users['administrator'] );

		$this->request(
			'POST',
			'/tasks/' . self::ID_A,
			array(
				'status'    => 'in-progress',
				'title'     => 'Hijacked',
				'assignees' => array( self::$users['administrator'] ),
				'due_date'  => '2031-01-01',
				'post'      => 1,
			)
		);

		$task = Tasks::get( self::ID_A );
		$this->assertSame( 'in-progress', $task->status );
		$this->assertSame( 'Assigned task', $task->title );
		$this->assertSame( array( self::$users['assignee'] ), $task->assignees );
		$this->assertNull( $task->due_date );
	}

	public function test_cookie_authentication_requires_a_valid_nonce() {
		$this->create_assigned_task();
		wp_set_current_user( self::$users['assignee'] );

		// Simulate a cookie-authenticated request, as sent by a browser.
		global $wp_rest_auth_cookie;
		$wp_rest_auth_cookie = true;

		$_SERVER['HTTP_X_WP_NONCE'] = 'invalid';
		$this->assertWPError( rest_cookie_check_errors( null ) );

		// Without any nonce the request is treated as anonymous.
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		rest_cookie_check_errors( null );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) )->get_status() );

		wp_set_current_user( self::$users['assignee'] );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 200, $this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) )->get_status() );

		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$wp_rest_auth_cookie = null;
	}

	public function test_user_search_permissions() {
		$this->assertSame( 401, $this->request( 'GET', '/users', array( 'search' => 'Ada' ) )->get_status() );

		wp_set_current_user( self::$users['subscriber'] );
		$this->assertSame( 403, $this->request( 'GET', '/users', array( 'search' => 'Ada' ) )->get_status() );

		// Contributors cannot list users in core, but can search assignees.
		wp_set_current_user( self::$users['contributor'] );
		$this->assertFalse( current_user_can( 'list_users' ) );

		$response = $this->request( 'GET', '/users', array( 'search' => 'Ada' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( array( 'id', 'name', 'slug', 'avatar_urls' ), array_keys( $response->get_data()[0] ) );
		$this->assertSame( self::$users['assignee'], $response->get_data()[0]['id'] );
	}

	public function test_user_search_matches_names_and_logins_but_not_emails() {
		wp_set_current_user( self::$users['contributor'] );

		$this->assertCount( 1, $this->request( 'GET', '/users', array( 'search' => 'login_assig' ) )->get_data() );
		$this->assertCount( 0, $this->request( 'GET', '/users', array( 'search' => 'private.example' ) )->get_data() );
		$this->assertCount( 0, $this->request( 'GET', '/users', array( 'search' => 'ada@' ) )->get_data() );

		$response = $this->request( 'GET', '/users', array( 'include' => array( self::$users['editor'], self::$users['author'] ) ) );
		$this->assertEqualSets( array( self::$users['editor'], self::$users['author'] ), wp_list_pluck( $response->get_data(), 'id' ) );

		$this->assertSame( 400, $this->request( 'GET', '/users', array( 'include' => array( 'abc' ) ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/users', array( 'per_page' => 1000 ) )->get_status() );
	}

	public function test_rendered_list_defaults_to_the_viewers_incomplete_tasks() {
		$this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'My open task',
					'assignees' => array( self::$users['assignee'] ),
				),
				array(
					'taskId'    => self::ID_B,
					'title'     => 'My finished task',
					'assignees' => array( self::$users['assignee'] ),
					'status'    => 'completed',
				),
				array(
					'taskId'    => self::ID_C,
					'title'     => 'Somebody else',
					'assignees' => array( self::$users['author'] ),
				),
			),
			array( 'post_author' => self::$users['editor'] )
		);

		// Anonymous visitors get all incomplete tasks, read-only.
		$anonymous = $this->request( 'GET', '/task-list' )->get_data();
		$this->assertSame( 'all', $anonymous['view'] );
		$this->assertNull( $anonymous['viewer'] );
		$this->assertSame( 2, $anonymous['total'] );
		$this->assertStringContainsString( 'My open task', $anonymous['html'] );
		$this->assertStringContainsString( 'Somebody else', $anonymous['html'] );
		$this->assertStringNotContainsString( 'My finished task', $anonymous['html'] );
		$this->assertSame( 2, substr_count( $anonymous['html'], 'type="checkbox"' ) );
		$this->assertSame( 2, substr_count( $anonymous['html'], "disabled='disabled' />" ) );

		// "My Tasks" is not available to anonymous visitors.
		$this->assertSame( 'all', $this->request( 'GET', '/task-list', array( 'view' => 'mine' ) )->get_data()['view'] );

		// The assignee gets their own incomplete tasks, with enabled controls.
		wp_set_current_user( self::$users['assignee'] );
		$mine = $this->request( 'GET', '/task-list' )->get_data();
		$this->assertSame( 'mine', $mine['view'] );
		$this->assertSame( array( 'id' => self::$users['assignee'] ), $mine['viewer'] );
		$this->assertSame( 1, $mine['total'] );
		$this->assertStringContainsString( 'My open task', $mine['html'] );
		$this->assertStringNotContainsString( 'Somebody else', $mine['html'] );
		$this->assertStringNotContainsString( 'disabled', $mine['html'] );

		// Including completed tasks.
		$all_mine = $this->request( 'GET', '/task-list', array( 'status' => 'all' ) )->get_data();
		$this->assertSame( 2, $all_mine['total'] );
		$this->assertStringContainsString( 'My finished task', $all_mine['html'] );

		// In "All Tasks", only their own tasks are editable.
		$all = $this->request( 'GET', '/task-list', array( 'view' => 'all' ) )->get_data();
		$this->assertSame( 2, $all['total'] );
		$this->assertSame( 1, substr_count( $all['html'], "disabled='disabled' />" ) );

		// Filter by another contributor.
		$theirs = $this->request(
			'GET',
			'/task-list',
			array(
				'view'     => 'all',
				'assignee' => self::$users['author'],
			)
		)->get_data();
		$this->assertSame( 1, $theirs['total'] );
		$this->assertStringContainsString( 'Somebody else', $theirs['html'] );

		// A block configured to default to all tasks.
		$this->assertSame( 'all', $this->request( 'GET', '/task-list', array( 'default' => 'all' ) )->get_data()['view'] );

		// Empty state.
		wp_set_current_user( self::$users['subscriber'] );
		$empty = $this->request( 'GET', '/task-list' )->get_data();
		$this->assertSame( 0, $empty['total'] );
		$this->assertStringContainsString( 'No incomplete tasks assigned to you.', $empty['html'] );
	}

	public function test_rendered_list_pagination() {
		$tasks = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$tasks[] = array(
				'taskId'  => wp_generate_uuid4(),
				'title'   => 'Paged task ' . $i,
				'dueDate' => '2030-01-0' . $i,
			);
		}
		$this->create_post_with_tasks( $tasks );

		$page = $this->request(
			'GET',
			'/task-list',
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		)->get_data();

		$this->assertSame( 5, $page['total'] );
		$this->assertSame( 3, $page['total_pages'] );
		$this->assertSame( 2, $page['page'] );
		$this->assertStringContainsString( 'Paged task 3', $page['html'] );
		$this->assertStringContainsString( 'Paged task 4', $page['html'] );
		$this->assertStringNotContainsString( 'Paged task 5', $page['html'] );
		$this->assertStringContainsString( 'Page 2 of 3', $page['html'] );

		// Out-of-range pages fall back to the last page.
		$last = $this->request(
			'GET',
			'/task-list',
			array(
				'per_page' => 2,
				'page'     => 50,
			)
		)->get_data();
		$this->assertSame( 3, $last['page'] );
		$this->assertStringContainsString( 'Paged task 5', $last['html'] );

		$this->assertSame( 400, $this->request( 'GET', '/task-list', array( 'status' => 'bogus' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/task-list', array( 'view' => 'everyone' ) )->get_status() );
	}

	public function test_status_change_is_reflected_everywhere() {
		$post_id = $this->create_assigned_task();
		wp_set_current_user( self::$users['assignee'] );

		$this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) );

		// The centralized list no longer shows it among incomplete tasks.
		$this->assertSame( 0, $this->request( 'GET', '/task-list' )->get_data()['total'] );
		$this->assertSame( 1, $this->request( 'GET', '/task-list', array( 'status' => 'completed' ) )->get_data()['total'] );

		// The original post shows it as completed.
		wp_set_current_user( 0 );
		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );
		$this->assertStringContainsString( 'is-status-completed', $html );
		$this->assertStringContainsString( "checked='checked'", $html );
	}

	public function test_can_update_filter() {
		$this->create_assigned_task();
		wp_set_current_user( self::$users['subscriber'] );

		add_filter( 'action_items_can_update_status', '__return_true' );
		$status = $this->request( 'POST', '/tasks/' . self::ID_A, array( 'status' => 'completed' ) )->get_status();
		remove_filter( 'action_items_can_update_status', '__return_true' );

		$this->assertSame( 200, $status );
	}
}

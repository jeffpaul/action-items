<?php
/**
 * Tests for multisite behavior.
 *
 * @package ActionItems
 */

use ActionItems\Schema;
use ActionItems\Tasks;

/**
 * @group ms-required
 * @covers \ActionItems\Schema
 */
class Multisite_Test extends Action_Items_Test_Case {

	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite: run phpunit -c tests/phpunit/multisite.xml' );
		}
	}

	public function test_tasks_are_isolated_per_site() {
		global $wpdb;

		$alice      = self::factory()->user->create();
		$main_post  = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Main site task',
					'assignees' => array( $alice ),
				),
			)
		);
		$main_table = Schema::tasks_table();

		$other_site = self::factory()->blog->create();
		add_user_to_blog( $other_site, $alice, 'subscriber' );

		switch_to_blog( $other_site );

		$this->assertNotSame( $main_table, Schema::tasks_table() );
		$this->assertNotSame( Schema::DB_VERSION, get_option( Schema::OPTION ), 'Tables are created lazily.' );

		// Saving a post creates the tables of the site on demand. The same identifier can exist on both sites.
		$other_post = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Other site task',
					'assignees' => array( $alice ),
				),
			)
		);

		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );
		$this->assertSame( 1, Tasks::query()['total'] );
		$this->assertSame( 'Other site task', Tasks::get( self::ID_A )->title );
		$this->assertSame( $other_post, Tasks::get( self::ID_A )->post_id );
		$this->assertSame( self::ID_A, $this->get_content_tasks( $other_post )[0]['taskId'], 'No conflict across sites.' );

		$this->complete( self::ID_A );

		$request = new WP_REST_Request( 'GET', '/action-items/v1/tasks' );
		$request->set_query_params( array( 'status' => 'completed' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'Other site task', $data[0]['title']['rendered'] );

		restore_current_blog();

		$this->assertSame( 'Main site task', Tasks::get( self::ID_A )->title );
		$this->assertSame( 'not-started', Tasks::get( self::ID_A )->status );
		$this->assertSame( $main_post, Tasks::get( self::ID_A )->post_id );
		$this->assertSame( 1, Tasks::query()['total'] );

		// Deleting the other site drops its tables.
		$other_table = $wpdb->get_blog_prefix( $other_site ) . 'action_items';
		$this->assertContains( $other_table, $this->get_tables_dropped_with( $other_site ) );
	}

	public function test_only_members_of_the_site_are_assignable_by_default() {
		$member     = self::factory()->user->create();
		$non_member = self::factory()->user->create();
		remove_user_from_blog( $non_member, get_current_blog_id() );

		$this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Task',
					'assignees' => array( $member, $non_member ),
				),
			)
		);
		$this->assertSame( array( $member ), Tasks::get( self::ID_A )->assignees );

		// Networks such as WordPress.org can accept any user.
		add_filter( 'action_items_is_assignable_user', array( $this, 'accept_existing_users' ), 10, 2 );
		$this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_B,
					'title'     => 'Task',
					'assignees' => array( $member, $non_member ),
				),
			)
		);
		remove_filter( 'action_items_is_assignable_user', array( $this, 'accept_existing_users' ), 10 );

		$this->assertSame( array( $member, $non_member ), Tasks::get( self::ID_B )->assignees );
	}

	/**
	 * Filter callback accepting any existing user as assignee.
	 *
	 * @param bool $assignable Whether the user is assignable.
	 * @param int  $user_id    User ID.
	 * @return bool
	 */
	public function accept_existing_users( $assignable, $user_id ) {
		return (bool) get_userdata( $user_id );
	}

	/**
	 * Returns the tables WordPress would drop when deleting a site.
	 *
	 * @param int $site_id Site ID.
	 * @return string[]
	 */
	private function get_tables_dropped_with( $site_id ) {
		global $wpdb;

		switch_to_blog( $site_id );
		$tables = apply_filters( 'wpmu_drop_tables', $wpdb->tables( 'blog' ), $site_id );
		restore_current_blog();

		return array_values( $tables );
	}
}

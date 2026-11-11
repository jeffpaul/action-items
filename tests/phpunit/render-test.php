<?php
/**
 * Tests for block rendering.
 *
 * @package ActionItems
 */

use ActionItems\Tasks;

/**
 * @covers \ActionItems\Renderer
 */
class Render_Test extends Action_Items_Test_Case {

	public function test_blocks_are_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( 'action-items/task' ), 'Run "npm run build" before the tests.' );
		$this->assertTrue( $registry->is_registered( 'action-items/task-list' ) );
	}

	public function test_task_block_renders_title_assignees_due_date_and_anchor() {
		$alice   = self::factory()->user->create( array( 'display_name' => 'Alice Example' ) );
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Review the <strong>proposal</strong>',
					'assignees' => array( $alice ),
					'dueDate'   => '2030-11-13',
				),
			)
		);

		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringContainsString( 'id="task-' . self::ID_A . '"', $html );
		$this->assertStringContainsString( 'data-task-id="' . self::ID_A . '"', $html );
		$this->assertStringContainsString( 'wp-block-action-items-task', $html );
		$this->assertStringContainsString( 'is-status-not-started', $html );
		$this->assertStringContainsString( 'Review the <strong>proposal</strong>', $html );
		$this->assertStringContainsString( 'Alice Example', $html );
		$this->assertStringContainsString( '<time class="action-items-task__due" datetime="2030-11-13">Due Nov 13, 2030</time>', $html );
		$this->assertStringContainsString( "disabled='disabled'", $html, 'Server-rendered controls are read-only.' );
	}

	public function test_task_block_shows_the_stored_status_not_the_attribute() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Task',
					'status' => 'not-started',
				),
			)
		);
		$this->complete( self::ID_A );

		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringContainsString( 'is-status-completed', $html );
		$this->assertStringNotContainsString( 'is-status-not-started', $html );
	}

	public function test_task_block_output_does_not_depend_on_the_viewer() {
		$alice   = self::factory()->user->create();
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Task',
					'assignees' => array( $alice ),
				),
			)
		);
		$content = get_post( $post_id )->post_content;

		$anonymous = do_blocks( $content );
		wp_set_current_user( $alice );
		$assignee = do_blocks( $content );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$admin = do_blocks( $content );

		$this->assertSame( $anonymous, $assignee );
		$this->assertSame( $anonymous, $admin );
	}

	public function test_unsaved_task_block_falls_back_to_its_attributes() {
		$html = do_blocks(
			$this->task_block(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Preview',
					'status' => 'in-progress',
				)
			)
		);

		$this->assertStringContainsString( 'is-status-in-progress', $html );
		$this->assertStringNotContainsString( 'data-task-id', $html, 'Unsaved tasks cannot be updated.' );
	}

	public function test_empty_task_renders_nothing() {
		$this->assertSame( '', trim( do_blocks( $this->task_block( array( 'taskId' => self::ID_A ) ) ) ) );
	}

	public function test_task_title_is_sanitized_on_output() {
		$html = do_blocks(
			$this->task_block(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Hello <script>alert(1)</script><img src=x onerror=alert(1)> <em>world</em>',
				)
			)
		);

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '<em>world</em>', $html );
	}

	public function test_overdue_tasks_are_marked_until_completed() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'  => self::ID_A,
					'title'   => 'Late',
					'dueDate' => '2001-01-01',
				),
			)
		);
		$content = get_post( $post_id )->post_content;

		$this->assertStringContainsString( 'is-overdue', do_blocks( $content ) );

		$this->complete( self::ID_A );

		$this->assertStringNotContainsString( 'is-overdue', do_blocks( $content ) );
	}

	public function test_feeds_get_markup_without_form_controls() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Task in a feed',
				),
			)
		);
		$this->complete( self::ID_A );

		$this->go_to( get_feed_link() );
		$html = do_blocks( get_post( $post_id )->post_content );

		$this->assertTrue( is_feed() );
		$this->assertStringContainsString( 'Task in a feed', $html );
		$this->assertStringContainsString( 'Completed', $html );
		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringNotContainsString( '<select', $html );
	}

	public function test_task_list_block_renders_the_public_list_for_everyone() {
		$alice = self::factory()->user->create( array( 'display_name' => 'Alice Example' ) );
		$bob   = self::factory()->user->create( array( 'display_name' => 'Bob Example' ) );

		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId'    => self::ID_A,
					'title'     => 'Task of Alice',
					'assignees' => array( $alice ),
				),
				array(
					'taskId'    => self::ID_B,
					'title'     => 'Task of Bob',
					'assignees' => array( $bob ),
				),
				array(
					'taskId' => self::ID_C,
					'title'  => 'Finished task',
					'status' => 'completed',
				),
			),
			array( 'post_title' => 'Weekly meeting' )
		);
		$this->create_post_with_tasks(
			array(
				array(
					'taskId'    => wp_generate_uuid4(),
					'title'     => 'Secret draft task',
					'assignees' => array( $alice ),
				),
			),
			array( 'post_status' => 'draft' )
		);

		$block     = '<!-- wp:action-items/task-list {"perPage":10} /-->';
		$anonymous = do_blocks( $block );

		$this->assertStringContainsString( 'data-per-page="10"', $anonymous );
		$this->assertStringContainsString( 'data-default-view="mine"', $anonymous );
		$this->assertStringContainsString( 'Task of Alice', $anonymous );
		$this->assertStringContainsString( 'Task of Bob', $anonymous );
		$this->assertStringNotContainsString( 'Finished task', $anonymous );
		$this->assertStringNotContainsString( 'Secret draft task', $anonymous );
		$this->assertStringContainsString( 'href="' . get_permalink( $post_id ) . '#task-' . self::ID_A . '"', $anonymous );
		$this->assertStringContainsString( 'Weekly meeting', $anonymous );
		$this->assertStringContainsString( '<option value="' . $alice . '">Alice Example</option>', $anonymous );
		$this->assertStringNotContainsString( 'id="task-', $anonymous, 'List items do not duplicate the anchors of Task blocks.' );

		// Cached pages must be safe to serve to anyone: same markup for every viewer.
		wp_set_current_user( $alice );
		$this->assertSame( $anonymous, do_blocks( $block ) );
	}

	public function test_task_list_block_empty_state_and_settings() {
		$html = do_blocks( '<!-- wp:action-items/task-list {"defaultView":"all","showFilters":false,"perPage":5000} /-->' );

		$this->assertStringContainsString( 'No incomplete tasks.', $html );
		$this->assertStringContainsString( 'data-default-view="all"', $html );
		$this->assertStringContainsString( 'data-per-page="100"', $html );
		$this->assertStringNotContainsString( 'data-filter=', $html );
	}

	public function test_query_results_are_refreshed_after_changes() {
		$post_id = $this->create_post_with_tasks(
			array(
				array(
					'taskId' => self::ID_A,
					'title'  => 'Task',
				),
			)
		);

		$this->assertSame( 1, Tasks::query()['total'] );

		$this->complete( self::ID_A );
		$this->assertSame( 0, Tasks::query( array( 'status' => array( 'not-started', 'in-progress' ) ) )['total'] );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 0, Tasks::query()['total'] );
	}
}

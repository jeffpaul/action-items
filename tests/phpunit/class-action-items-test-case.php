<?php
/**
 * Base test case.
 *
 * @package ActionItems
 */

use ActionItems\Schema;
use ActionItems\Tasks;

/**
 * Shared helpers for the plugin tests.
 */
abstract class Action_Items_Test_Case extends WP_UnitTestCase {

	const ID_A = '11111111-1111-4111-8111-111111111111';
	const ID_B = '22222222-2222-4222-8222-222222222222';
	const ID_C = '33333333-3333-4333-8333-333333333333';

	/**
	 * Start of a JSON unicode escape, as found in serialized block attributes.
	 */
	const U = "\x5cu";

	/**
	 * Makes sure the tables exist.
	 */
	public function set_up() {
		parent::set_up();
		Schema::maybe_install();
	}

	/**
	 * Serializes a Task block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	protected function task_block( array $attributes ) {
		return '<!-- wp:action-items/task ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/**
	 * Creates a post containing Task blocks.
	 *
	 * @param array[] $tasks     List of Task block attributes.
	 * @param array   $post_args Post arguments.
	 * @return int Post ID.
	 */
	protected function create_post_with_tasks( array $tasks, array $post_args = array() ) {
		$content = "<!-- wp:paragraph -->\n<p>Meeting notes</p>\n<!-- /wp:paragraph -->";
		foreach ( $tasks as $task ) {
			$content .= "\n\n" . $this->task_block( $task );
		}

		return $this->create_post(
			array_merge(
				array(
					'post_status'  => 'publish',
					'post_content' => $content,
				),
				$post_args
			)
		);
	}

	/**
	 * Creates a post. Like the REST API, takes unslashed data.
	 *
	 * @param array $args Post arguments.
	 * @return int Post ID.
	 */
	protected function create_post( array $args ) {
		return self::factory()->post->create( wp_slash( $args ) );
	}

	/**
	 * Updates a post. Like the REST API, takes unslashed data.
	 *
	 * @param array $args Post arguments.
	 * @return int Post ID.
	 */
	protected function update_post( array $args ) {
		return wp_update_post( wp_slash( $args ) );
	}

	/**
	 * Switches to a user whose content is not rewritten by KSES on save.
	 *
	 * @return int User ID.
	 */
	protected function act_as_unfiltered_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Returns the Task block attributes stored in the content of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	protected function get_content_tasks( $post_id ) {
		return ActionItems\Sync::find_task_blocks( get_post( $post_id )->post_content );
	}

	/**
	 * Returns the number of rows in the tasks table.
	 *
	 * @return int
	 */
	protected function count_tasks() {
		global $wpdb;
		// phpcs:ignore WordPress.DB
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tasks_table() );
	}

	/**
	 * Returns the number of rows in the assignees table.
	 *
	 * @return int
	 */
	protected function count_assignee_rows() {
		global $wpdb;
		// phpcs:ignore WordPress.DB
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::assignees_table() );
	}

	/**
	 * Marks a task as completed, bypassing permissions.
	 *
	 * @param string $uuid Task identifier.
	 * @return object
	 */
	protected function complete( $uuid ) {
		return Tasks::set_status( Tasks::get( $uuid ), Tasks::STATUS_COMPLETED, 0 );
	}
}

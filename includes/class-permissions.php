<?php
/**
 * Permission rules.
 *
 * @package ActionItems
 */

namespace ActionItems;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for every permission decision of the plugin.
 */
class Permissions {

	/**
	 * Whether the tasks of a post are visible to everyone, including anonymous visitors.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return bool
	 */
	public static function is_post_public( $post ) {
		$post = get_post( $post );

		return $post
			&& 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, get_supported_post_types(), true );
	}

	/**
	 * Whether a user can see a task.
	 *
	 * Tasks of public posts are public. Other tasks are only visible to users
	 * who can edit the post they belong to.
	 *
	 * @param object $task    Task.
	 * @param int    $user_id User ID, 0 for anonymous visitors.
	 * @return bool
	 */
	public static function can_read( $task, $user_id ) {
		if ( self::is_post_public( $task->post_id ) ) {
			return true;
		}

		return $user_id && user_can( $user_id, 'edit_post', $task->post_id );
	}

	/**
	 * Whether a user can change the status of a task.
	 *
	 * Allowed for users who can edit the post the task belongs to, and for the
	 * assignees of the task as long as that post is public.
	 *
	 * @param object $task    Task.
	 * @param int    $user_id User ID, 0 for anonymous visitors.
	 * @return bool
	 */
	public static function can_update_status( $task, $user_id ) {
		$user_id = (int) $user_id;
		$can     = false;

		if ( $user_id ) {
			$can = user_can( $user_id, 'edit_post', $task->post_id )
				|| ( in_array( $user_id, $task->assignees, true ) && self::is_post_public( $task->post_id ) );
		}

		/**
		 * Filters whether a user can change the status of a task.
		 *
		 * @param bool   $can     Whether the user can change the status.
		 * @param object $task    Task.
		 * @param int    $user_id User ID, 0 for anonymous visitors.
		 */
		return (bool) apply_filters( 'action_items_can_update_status', $can, $task, $user_id );
	}

	/**
	 * Whether the current user can search users in order to assign tasks.
	 *
	 * @return bool
	 */
	public static function can_search_users() {
		/**
		 * Filters the capability required to search users when assigning tasks.
		 *
		 * @param string $capability Capability name. Default 'edit_posts', which Contributors have.
		 */
		return current_user_can( (string) apply_filters( 'action_items_assign_capability', 'edit_posts' ) );
	}
}

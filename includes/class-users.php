<?php
/**
 * User lookups.
 *
 * @package ActionItems
 */

namespace ActionItems;

defined( 'ABSPATH' ) || exit;

/**
 * User search and the public representation of users.
 *
 * Only public profile data leaves this class: display name, nicename and
 * avatar. Email addresses and logins are never exposed.
 */
class Users {

	/**
	 * Whether tasks can be assigned to a user.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_assignable( $user_id ) {
		$user_id    = (int) $user_id;
		$assignable = $user_id > 0 && get_userdata( $user_id )
			&& ( ! is_multisite() || is_user_member_of_blog( $user_id ) );

		/**
		 * Filters whether tasks can be assigned to a user.
		 *
		 * On multisite, only members of the current site are assignable by
		 * default. Return true to accept any existing user of the network.
		 *
		 * @param bool $assignable Whether the user is assignable.
		 * @param int  $user_id    User ID.
		 */
		return (bool) apply_filters( 'action_items_is_assignable_user', (bool) $assignable, $user_id );
	}

	/**
	 * Keeps the assignable user IDs of a list.
	 *
	 * @param mixed $user_ids Raw list of user IDs.
	 * @return int[]
	 */
	public static function filter_assignable( $user_ids ) {
		if ( ! is_array( $user_ids ) ) {
			return array();
		}

		$user_ids = array_unique( array_map( 'intval', array_filter( $user_ids, 'is_numeric' ) ) );

		return array_values( array_filter( $user_ids, array( self::class, 'is_assignable' ) ) );
	}

	/**
	 * Searches assignable users by display name, nicename or login.
	 *
	 * @param string $search   Search term. Empty to list users.
	 * @param int[]  $user_ids Limit the results to these user IDs.
	 * @param int    $number   Maximum number of results.
	 * @return \WP_User[]
	 */
	public static function search( $search, array $user_ids = array(), $number = 10 ) {
		$args = array(
			'number'      => $number,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => false,
		);

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name' );
		}

		if ( $user_ids ) {
			$args['include'] = $user_ids;
		}

		/**
		 * Filters the WP_User_Query arguments used to search assignable users.
		 *
		 * Large networks can use this to search network-wide
		 * (`'blog_id' => 0`) or to switch to prefix matching.
		 *
		 * @param array  $args   WP_User_Query arguments.
		 * @param string $search Search term.
		 */
		$args = (array) apply_filters( 'action_items_user_query_args', $args, $search );

		return array_values( array_filter( get_users( $args ), array( self::class, 'is_assignable_user' ) ) );
	}

	/**
	 * Whether tasks can be assigned to a user object.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	public static function is_assignable_user( $user ) {
		return $user instanceof \WP_User && self::is_assignable( $user->ID );
	}

	/**
	 * Returns the name shown for a user.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	public static function get_label( $user ) {
		/**
		 * Filters the name shown for an assignee.
		 *
		 * @param string   $label Display name.
		 * @param \WP_User $user  User.
		 */
		return (string) apply_filters( 'action_items_user_label', $user->display_name, $user );
	}

	/**
	 * Returns the URL an assignee name links to.
	 *
	 * @param \WP_User $user User.
	 * @return string URL, or an empty string for no link.
	 */
	public static function get_url( $user ) {
		/**
		 * Filters the URL an assignee name links to.
		 *
		 * @param string   $url  URL. Default empty: names are not linked.
		 * @param \WP_User $user User.
		 */
		return (string) apply_filters( 'action_items_user_url', '', $user );
	}

	/**
	 * Returns the public REST representation of a user.
	 *
	 * @param \WP_User $user      User.
	 * @param bool     $with_slug Whether to include the nicename. Only done for
	 *                            logged-in users picking assignees, who need it
	 *                            to tell users with the same name apart.
	 * @return array
	 */
	public static function to_public_array( $user, $with_slug = false ) {
		$data = array(
			'id'   => $user->ID,
			'name' => self::get_label( $user ),
		);

		if ( $with_slug ) {
			$data['slug'] = $user->user_nicename;
		}

		$data['avatar_urls'] = get_option( 'show_avatars' ) ? rest_get_avatar_urls( $user ) : (object) array();

		return $data;
	}
}

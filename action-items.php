<?php
/**
 * Plugin Name:       Action Items
 * Plugin URI:        https://github.com/jeffpaul/action-items
 * Description:       Task blocks for meeting notes, with a centralized task list that stays in sync with the original posts.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Action Items contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       action-items
 *
 * @package ActionItems
 */

namespace ActionItems;

defined( 'ABSPATH' ) || exit;

define( 'ACTION_ITEMS_VERSION', '0.1.0' );
define( 'ACTION_ITEMS_FILE', __FILE__ );
define( 'ACTION_ITEMS_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-tasks.php';
require_once __DIR__ . '/includes/class-permissions.php';
require_once __DIR__ . '/includes/class-users.php';
require_once __DIR__ . '/includes/class-sync.php';
require_once __DIR__ . '/includes/class-renderer.php';
require_once __DIR__ . '/includes/class-rest-controller.php';

register_activation_hook( __FILE__, array( Schema::class, 'install' ) );

add_action( 'init', array( Schema::class, 'maybe_install' ), 5 );
add_filter( 'wpmu_drop_tables', array( Schema::class, 'filter_drop_tables' ) );

Sync::init();
Renderer::init();

add_action(
	'rest_api_init',
	static function () {
		( new REST_Controller() )->register_routes();
	}
);

/**
 * Returns the post types whose content is scanned for Task blocks.
 *
 * @return string[]
 */
function get_supported_post_types() {
	$post_types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

	/**
	 * Filters the post types whose content is scanned for Task blocks.
	 *
	 * Only publicly viewable post types should be listed: tasks are shown in
	 * public task lists as soon as their post is published.
	 *
	 * @param string[] $post_types Post type names.
	 */
	return array_values( array_unique( (array) apply_filters( 'action_items_post_types', $post_types ) ) );
}

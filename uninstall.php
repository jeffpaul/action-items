<?php
/**
 * Uninstall handler: removes the plugin tables and options.
 *
 * @package ActionItems
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-schema.php';

if ( is_multisite() ) {
	// On very large networks, drop the tables with WP-CLI instead; see docs/meta-deployment.md.
	$action_items_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $action_items_site_ids as $action_items_site_id ) {
		switch_to_blog( $action_items_site_id );
		ActionItems\Schema::uninstall();
		restore_current_blog();
	}
} else {
	ActionItems\Schema::uninstall();
}

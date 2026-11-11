<?php
/**
 * Database schema.
 *
 * @package ActionItems
 */

namespace ActionItems;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes the per-site task tables.
 *
 * Tables use the site prefix, so every site of a multisite network has its
 * own, isolated set of tasks.
 */
class Schema {

	const DB_VERSION = '1';
	const OPTION     = 'action_items_db_version';

	/**
	 * Name of the tasks table for the current site.
	 *
	 * @return string
	 */
	public static function tasks_table() {
		global $wpdb;
		return $wpdb->prefix . 'action_items';
	}

	/**
	 * Name of the task assignees table for the current site.
	 *
	 * @return string
	 */
	public static function assignees_table() {
		global $wpdb;
		return $wpdb->prefix . 'action_item_assignees';
	}

	/**
	 * Installs or upgrades the tables of the current site when needed.
	 *
	 * Runs lazily so that network-activated installs and newly created sites
	 * get their tables without iterating over the whole network.
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Creates or upgrades the tables of the current site.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tasks           = self::tasks_table();
		$assignees       = self::assignees_table();

		dbDelta(
			"CREATE TABLE {$tasks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				task_uuid char(36) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				title text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'not-started',
				due_date date DEFAULT NULL,
				position int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				completed_at datetime DEFAULT NULL,
				status_changed_by bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY task_uuid (task_uuid),
				KEY post_id (post_id),
				KEY status_due (status,due_date,created_at)
			) {$charset_collate};
			CREATE TABLE {$assignees} (
				task_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (task_id,user_id),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		update_option( self::OPTION, self::DB_VERSION );
	}

	/**
	 * Drops the plugin tables together with a deleted site.
	 *
	 * @param string[] $tables Tables about to be dropped.
	 * @return string[]
	 */
	public static function filter_drop_tables( $tables ) {
		$tables[] = self::tasks_table();
		$tables[] = self::assignees_table();
		return $tables;
	}

	/**
	 * Removes the tables and options of the current site.
	 */
	public static function uninstall() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Table names are built from the trusted site prefix.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::tasks_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::assignees_table() );
		// phpcs:enable

		delete_option( self::OPTION );
	}
}

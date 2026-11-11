<?php
/**
 * Configuration of the WordPress test suite, driven by environment variables.
 *
 * WP_TESTS_CORE_DIR must point to a WordPress checkout. The database named by
 * WP_TESTS_DB_NAME is wiped on every run: never point it at a real site.
 *
 * @package ActionItems
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals

$action_items_core_dir = getenv( 'WP_TESTS_CORE_DIR' );
if ( ! $action_items_core_dir ) {
	$action_items_core_dir = dirname( __DIR__, 2 ) . '/wordpress';
}

define( 'ABSPATH', rtrim( $action_items_core_dir, '/' ) . '/' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', false !== getenv( 'WP_TESTS_DB_PASSWORD' ) ? getenv( 'WP_TESTS_DB_PASSWORD' ) : '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Action Items Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

define( 'AUTH_KEY', 'action-items-tests' );
define( 'SECURE_AUTH_KEY', 'action-items-tests' );
define( 'LOGGED_IN_KEY', 'action-items-tests' );
define( 'NONCE_KEY', 'action-items-tests' );
define( 'AUTH_SALT', 'action-items-tests' );
define( 'SECURE_AUTH_SALT', 'action-items-tests' );
define( 'LOGGED_IN_SALT', 'action-items-tests' );
define( 'NONCE_SALT', 'action-items-tests' );

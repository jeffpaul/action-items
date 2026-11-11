<?php
/**
 * PHPUnit bootstrap.
 *
 * @package ActionItems
 */

// WP_TESTS_PHPUNIT_DIR selects another copy of the WordPress test library than the Composer package, to test against other WordPress versions.
$action_items_tests_dir = getenv( 'WP_TESTS_PHPUNIT_DIR' );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Tells wp-phpunit where the configuration is.
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

if ( ! $action_items_tests_dir ) {
	$action_items_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

require_once $action_items_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/action-items.php';
	}
);

require $action_items_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-action-items-test-case.php';

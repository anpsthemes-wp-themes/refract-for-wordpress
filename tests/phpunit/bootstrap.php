<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library (wp-env sets WP_TESTS_DIR) and the plugin, pointed at a fake
 * Refract that the tests answer through `pre_http_request`.
 *
 * @package Refract
 */

$refract_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $refract_tests_dir || '' === $refract_tests_dir ) {
	$refract_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $refract_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test library in ' . $refract_tests_dir . '. Run the tests in wp-env: npx wp-env run tests-cli --env-cwd=wp-content/plugins/refract vendor/bin/phpunit' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
define( 'REFRACT_URL', 'https://refract.test' );

require_once $refract_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/refract.php';
	}
);

require $refract_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-refract-mock.php';
require_once __DIR__ . '/class-refract-test-case.php';

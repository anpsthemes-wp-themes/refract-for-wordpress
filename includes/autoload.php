<?php
/**
 * Constants and the class autoloader, shared by the plugin and uninstall.php.
 *
 * @package Refract
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'REFRACT_VERSION' ) ) {
	define( 'REFRACT_VERSION', '1.2.0' );
}

if ( ! defined( 'REFRACT_FILE' ) ) {
	define( 'REFRACT_FILE', dirname( __DIR__ ) . '/refract.php' );
}

if ( ! defined( 'REFRACT_DIR' ) ) {
	define( 'REFRACT_DIR', dirname( __DIR__ ) . '/' );
}

/*
 * Where Refract lives. Define it in wp-config.php to use another Refract (for example a local copy while developing).
 */
if ( ! defined( 'REFRACT_URL' ) ) {
	define( 'REFRACT_URL', 'https://refract.social' );
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Refract\\WP\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$file = REFRACT_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( substr( $class_name, strlen( $prefix ) ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

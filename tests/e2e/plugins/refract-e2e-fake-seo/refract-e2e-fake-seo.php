<?php
/**
 * Plugin Name: Refract Fake SEO
 * Description: Pretends to be Yoast SEO (defines WPSEO_VERSION) so the e2e tests can check that Refract leaves the social preview tags to it. Only mapped into wp-env's tests environment.
 * Version: 1.0.0
 *
 * @package Refract
 */

if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '99.0' );
}

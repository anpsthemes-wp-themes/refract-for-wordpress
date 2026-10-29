<?php
/**
 * Detects SEO plugins that print their own social preview tags.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * When one of these plugins is active, Refract doesn't print link preview tags, so they're never printed twice.
 */
final class Seo_Plugins {

	/**
	 * The name of the active SEO plugin, or null.
	 */
	public static function active(): ?string {
		$checks = array(
			'Yoast SEO'         => defined( 'WPSEO_VERSION' ),
			'Rank Math'         => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ),
			'All in One SEO'    => defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ),
			'SEOPress'          => defined( 'SEOPRESS_VERSION' ),
			'The SEO Framework' => defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ),
		);
		$name   = null;

		foreach ( $checks as $plugin => $active ) {
			if ( $active ) {
				$name = $plugin;
				break;
			}
		}

		/**
		 * Filters the name of the active SEO plugin that prints its own social preview tags.
		 *
		 * @param string|null $name The plugin's name, or null when there's none.
		 */
		$name = apply_filters( 'refract_seo_plugin', $name );

		return is_string( $name ) && '' !== $name ? $name : null;
	}
}

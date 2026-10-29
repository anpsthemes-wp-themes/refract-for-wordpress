<?php
/**
 * Removing the plugin's data.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes options, post meta, transients and scheduled events on every site, after trying to remove the site from
 * Refract (which revokes the access). Uninstall carries on when Refract can't be reached.
 */
final class Uninstaller {

	/**
	 * Options the plugin keeps.
	 */
	public const OPTIONS = array(
		Settings::OPTION,
		Settings::AFTER_VERSIONS,
		Connection::OPTION,
		Secrets::OPTION,
		Connector::CLIENT_OPTION,
		Activity::OPTION,
		Refract_Client::REFRESH_LOCK,
	);

	/**
	 * Cleans up the current site, or every site of a network.
	 */
	public static function run(): void {
		Plugin::each_site( is_multisite(), array( self::class, 'clean_site' ) );
	}

	/**
	 * Cleans up one site.
	 */
	public static function clean_site(): void {
		global $wpdb;

		try {
			Connector::disconnect();
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		wp_unschedule_hook( Plugin::CRON_SEND );
		wp_unschedule_hook( Plugin::CRON_PRUNE );

		delete_transient( Refract_Data::ACCOUNTS );
		delete_transient( Refract_Data::ME );

		foreach ( array_merge( Post_Meta::EDITABLE, array_keys( Post_Meta::INTERNAL ), array( '_refract_source_url' ) ) as $key ) {
			delete_post_meta_by_key( $key );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes every row the plugin wrote; there's no API for finding them by prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_refract_' ) . '%' ) );
		$transients = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_refract_' ) . '%'
			)
		);
		// phpcs:enable

		foreach ( $transients as $option_name ) {
			delete_transient( substr( (string) $option_name, strlen( '_transient_' ) ) );
		}
	}
}

<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Boot, activation and deactivation. Each site of a network keeps its own connection and settings.
 */
final class Plugin {

	/**
	 * Cron hook that sends one post to Refract. Args: post id, and the `start_from` override ('' = settings).
	 */
	public const CRON_SEND = 'refract_send_post';

	/**
	 * Daily cron hook that drops activity older than 30 days.
	 */
	public const CRON_PRUNE = 'refract_prune_activity';

	/**
	 * Adds every hook the plugin needs.
	 */
	public static function boot(): void {
		Settings::register_hooks();
		Post_Meta::register_hooks();
		Admin::register_hooks();
		Admin_Rest::register_hooks();
		Signed_Rest::register_hooks();
		Sender::register_hooks();
		Editor::register_hooks();
		Link_Previews::register_hooks();
		Activity::register_hooks();

		add_action( 'init', array( self::class, 'schedule_prune' ) );
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 200 );
	}

	/**
	 * Activation: on one site, or on every site when network activated.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::each_site( $network_wide, array( self::class, 'activate_site' ) );
	}

	/**
	 * Deactivation stops scheduled events and keeps the connection.
	 *
	 * @param bool $network_wide Whether the plugin is being network deactivated.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		self::each_site( $network_wide, array( self::class, 'deactivate_site' ) );
	}

	/**
	 * Sets up one site.
	 */
	public static function activate_site(): void {
		add_option( Settings::AFTER_VERSIONS, Settings::WAIT_FOR_APPROVAL );
		self::schedule_prune();
	}

	/**
	 * Stops one site's scheduled events.
	 */
	public static function deactivate_site(): void {
		wp_unschedule_hook( self::CRON_SEND );
		wp_unschedule_hook( self::CRON_PRUNE );
	}

	/**
	 * Schedules the daily activity cleanup when it isn't scheduled yet.
	 */
	public static function schedule_prune(): void {
		if ( ! wp_next_scheduled( self::CRON_PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE );
		}
	}

	/**
	 * Sets up a site added to a network where the plugin is network active.
	 *
	 * @param WP_Site $site The new site.
	 */
	public static function initialize_site( WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( REFRACT_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::activate_site();
		restore_current_blog();
	}

	/**
	 * Runs a callback on the current site, or on every site of the network.
	 *
	 * @param bool     $network_wide Whether to run it on every site.
	 * @param callable $callback     What to run on each site.
	 */
	public static function each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$callback();

			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$callback();
			restore_current_blog();
		}
	}
}

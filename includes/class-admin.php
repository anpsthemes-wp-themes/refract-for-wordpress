<?php
/**
 * The admin menu, pages and notices.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * The Refract menu (Settings and Activity), the scripts those pages load, the reconnect and failed-post notices, and
 * the suggested privacy policy text.
 */
final class Admin {

	/**
	 * Per-user transient prefix for a notice shown once on the Settings page.
	 */
	public const FLASH_PREFIX = 'refract_notice_';

	/**
	 * Hook suffixes of the plugin's pages.
	 *
	 * @var array<string, string>
	 */
	private static array $pages = array();

	/**
	 * Adds the admin hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_action( 'admin_init', array( self::class, 'privacy_policy' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( REFRACT_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Adds the Refract menu with Settings and Activity.
	 */
	public static function menu(): void {
		$settings = add_menu_page(
			__( 'Refract', 'refract' ),
			__( 'Refract', 'refract' ),
			'manage_options',
			'refract',
			array( self::class, 'render_settings' ),
			self::menu_icon()
		);

		add_submenu_page( 'refract', __( 'Refract settings', 'refract' ), __( 'Settings', 'refract' ), 'manage_options', 'refract', array( self::class, 'render_settings' ) );
		$activity = add_submenu_page( 'refract', __( 'Refract activity', 'refract' ), __( 'Activity', 'refract' ), 'manage_options', 'refract-activity', array( self::class, 'render_activity' ) );

		self::$pages = array(
			'settings' => (string) $settings,
			'activity' => (string) $activity,
		);

		add_action( 'load-' . $settings, array( Connector::class, 'handle_callback' ) );
	}

	/**
	 * Prints the Settings page mount point.
	 */
	public static function render_settings(): void {
		echo '<div class="wrap"><div id="refract-settings" class="refract-admin"></div></div>';
	}

	/**
	 * Prints the Activity page mount point.
	 */
	public static function render_activity(): void {
		echo '<div class="wrap"><div id="refract-activity" class="refract-admin"></div></div>';
	}

	/**
	 * Loads the admin app on the plugin's pages and the classic editor script on classic post screens.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue( string $hook_suffix ): void {
		$page = array_search( $hook_suffix, self::$pages, true );

		if ( false === $page ) {
			return;
		}

		if ( ! self::enqueue_build( 'refract-admin', 'admin', array( 'wp-components' ) ) ) {
			return;
		}

		$data = array(
			'page'   => $page,
			'logo'   => self::logo_url(),
			'notice' => 'settings' === $page ? self::take_flash() : null,
			'urls'   => array(
				'settings' => admin_url( 'admin.php?page=refract' ),
				'activity' => admin_url( 'admin.php?page=refract-activity' ),
			),
		);

		wp_add_inline_script( 'refract-admin', 'window.refractAdmin = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Enqueues `build/{name}.js` with its `.asset.php`, `build/{name}.css` when it exists, and the script's
	 * translations.
	 *
	 * @param string   $handle      Script and style handle.
	 * @param string   $name        File name in build/, without extension.
	 * @param string[] $style_deps  Style dependencies.
	 * @return bool Whether the build exists.
	 */
	public static function enqueue_build( string $handle, string $name, array $style_deps = array() ): bool {
		$asset_file = REFRACT_DIR . 'build/' . $name . '.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			return false;
		}

		$asset   = require $asset_file;
		$deps    = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$version = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : REFRACT_VERSION;

		wp_enqueue_script( $handle, plugins_url( 'build/' . $name . '.js', REFRACT_FILE ), $deps, $version, true );
		wp_set_script_translations( $handle, 'refract', REFRACT_DIR . 'languages' );

		if ( is_readable( REFRACT_DIR . 'build/' . $name . '.css' ) ) {
			wp_enqueue_style( $handle, plugins_url( 'build/' . $name . '.css', REFRACT_FILE ), $style_deps, $version );
		} else {
			foreach ( $style_deps as $style ) {
				wp_enqueue_style( $style );
			}
		}

		return true;
	}

	/**
	 * Shows the reconnect notice to people who manage options, and a failed post's notice on its edit screen.
	 */
	public static function notices(): void {
		$screen = get_current_screen();

		if ( current_user_can( 'manage_options' ) && 'needs_reconnect' === Connection::status() && ( null === $screen || ! in_array( $screen->id, self::$pages, true ) ) ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Refract needs you to connect again. Your site can\'t send posts until you do.', 'refract' ),
				esc_url( admin_url( 'admin.php?page=refract' ) ),
				esc_html__( 'Reconnect', 'refract' )
			);
		}

		$post = get_post();

		if ( null === $screen || 'post' !== $screen->base || null === $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$notice = (string) get_post_meta( $post->ID, '_refract_notice', true );

		if ( '' !== $notice ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $notice ) );
		}
	}

	/**
	 * Adds the suggested privacy policy text.
	 */
	public static function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . __( 'This site uses Refract (refract.social) to turn its blog posts into posts for social networks.', 'refract' ) . '</p>'
			. '<p>' . __( 'When a post is sent to Refract, the site sends the post\'s title, excerpt, text, link and images, and the social accounts chosen for it. Refract stores them to write and schedule the social posts.', 'refract' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: 1: Refract terms URL, 2: Refract privacy policy URL. */
				__( 'Refract\'s <a href="%1$s">terms</a> and <a href="%2$s">privacy policy</a> apply.', 'refract' ),
				'https://refract.social/terms',
				'https://refract.social/privacy'
			) . '</p>';

		wp_add_privacy_policy_content( __( 'Refract', 'refract' ), wp_kses_post( $text ) );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array<string|int, string> $links The plugin's action links.
	 * @return array<string|int, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=refract' ) ), esc_html__( 'Settings', 'refract' ) ) );

		return $links;
	}

	/**
	 * Keeps a notice for the next Settings page load of the current user.
	 *
	 * @param string $type    error|success.
	 * @param string $message The text.
	 */
	public static function flash( string $type, string $message ): void {
		set_transient(
			self::FLASH_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * The kept notice, removed once read.
	 *
	 * @return array{type: string, message: string}|null
	 */
	public static function take_flash(): ?array {
		$key    = self::FLASH_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $notice ) || ! is_string( $notice['type'] ?? null ) || ! is_string( $notice['message'] ?? null ) ) {
			return null;
		}

		return array(
			'type'    => $notice['type'],
			'message' => $notice['message'],
		);
	}

	/**
	 * URL of the Refract mark.
	 */
	public static function logo_url(): string {
		return plugins_url( 'assets/logo.svg', REFRACT_FILE );
	}

	/**
	 * The Refract mark in one colour as an SVG data URI; WordPress paints it in the admin colour scheme.
	 */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="4 4 56 56"><g fill="black"><rect x="6" y="9" width="40" height="9.4" rx="2.6"/><rect x="10" y="21" width="40" height="9.4" rx="2.6"/><rect x="14" y="33" width="40" height="9.4" rx="2.6"/><rect x="18" y="45" width="40" height="9.4" rx="2.6"/></g></svg>';

		return 'data:image/svg+xml;base64,' . sodium_bin2base64( $svg, SODIUM_BASE64_VARIANT_ORIGINAL );
	}
}

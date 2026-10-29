<?php
/**
 * The site's Refract settings.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitizes and saves `refract_settings`, and keeps `refract_after_versions` at its only value.
 */
final class Settings {

	/**
	 * Option holding the settings array.
	 */
	public const OPTION = 'refract_settings';

	/**
	 * Option for what happens after the versions are written. Only one value today; autopilot comes later.
	 */
	public const AFTER_VERSIONS = 'refract_after_versions';

	/**
	 * The only value of `refract_after_versions`.
	 */
	public const WAIT_FOR_APPROVAL = 'wait_for_approval';

	/**
	 * Registers both settings on every request, so REST and cron see the sanitize callbacks too.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/**
	 * Registers the settings with the Settings API. Neither is shown in REST.
	 */
	public static function register(): void {
		register_setting(
			'refract',
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'Refract settings', 'refract' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'refract',
			self::AFTER_VERSIONS,
			array(
				'type'              => 'string',
				'description'       => __( 'What happens after the versions are written', 'refract' ),
				'sanitize_callback' => array( self::class, 'sanitize_after_versions' ),
				'default'           => self::WAIT_FOR_APPROVAL,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * The settings of a site that hasn't saved any.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'send_mode'        => 'publish',
			'post_types'       => array( 'post' ),
			'categories'       => array(),
			'accounts'         => array(),
			'write_mode'       => 'note',
			'images'           => 'crop',
			'on_update'        => 'update',
			'refract_publish'  => false,
			'publish_as'       => 0,
			'default_category' => 0,
			'refract_can'      => 'publish',
			'link_previews'    => true,
			'setup_done'       => false,
		);
	}

	/**
	 * The current settings, every key present.
	 *
	 * @return array{send_mode: string, post_types: list<string>, categories: list<int>, accounts: list<string>, write_mode: string, images: string, on_update: string, refract_publish: bool, publish_as: int, default_category: int, refract_can: string, link_previews: bool, setup_done: bool}
	 */
	public static function get(): array {
		$stored   = get_option( self::OPTION, array() );
		$settings = array_merge( self::defaults(), array_intersect_key( is_array( $stored ) ? $stored : array(), self::defaults() ) );

		return array(
			'send_mode'        => (string) $settings['send_mode'],
			'post_types'       => array_values( array_map( 'strval', (array) $settings['post_types'] ) ),
			'categories'       => array_values( array_map( 'intval', (array) $settings['categories'] ) ),
			'accounts'         => array_values( array_map( 'strval', (array) $settings['accounts'] ) ),
			'write_mode'       => (string) $settings['write_mode'],
			'images'           => (string) $settings['images'],
			'on_update'        => (string) $settings['on_update'],
			'refract_publish'  => (bool) $settings['refract_publish'],
			'publish_as'       => (int) $settings['publish_as'],
			'default_category' => (int) $settings['default_category'],
			'refract_can'      => (string) $settings['refract_can'],
			'link_previews'    => (bool) $settings['link_previews'],
			'setup_done'       => (bool) $settings['setup_done'],
		);
	}

	/**
	 * Saves part of the settings and returns them all.
	 *
	 * @param array<string, mixed> $changes The keys to change.
	 * @return array<string, mixed>
	 */
	public static function update( array $changes ): array {
		update_option( self::OPTION, self::sanitize( $changes ) );

		return self::get();
	}

	/**
	 * Sanitize callback: lays the given keys over the current settings, drops unknown keys and fixes every value.
	 *
	 * @param mixed $value The submitted settings (all or some keys).
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $value ): array {
		return self::sanitize_onto( self::get(), is_array( $value ) ? $value : array() );
	}

	/**
	 * Sanitize callback for `refract_after_versions`: only one value exists.
	 *
	 * @return string
	 */
	public static function sanitize_after_versions(): string {
		return self::WAIT_FOR_APPROVAL;
	}

	/**
	 * Whether a post type is chosen for sending.
	 *
	 * @param string $post_type The post type name.
	 */
	public static function post_type_enabled( string $post_type ): bool {
		return in_array( $post_type, self::get()['post_types'], true );
	}

	/**
	 * Public post types a person can choose, attachments excluded.
	 *
	 * @return list<string>
	 */
	public static function public_post_types(): array {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );

		return array_values( $types );
	}

	/**
	 * Lays submitted values over a base, keeping only valid ones.
	 *
	 * @param array<string, mixed> $base  Settings to start from.
	 * @param array<mixed>         $input Submitted values.
	 * @return array<string, mixed>
	 */
	private static function sanitize_onto( array $base, array $input ): array {
		$enums = array(
			'send_mode'   => array( 'publish', 'manual' ),
			'write_mode'  => array( 'note', 'finished' ),
			'images'      => array( 'crop', 'crop_or_make' ),
			'on_update'   => array( 'update', 'leave' ),
			'refract_can' => array( 'publish', 'drafts' ),
		);

		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$base[ $key ] = $input[ $key ];
			}
		}

		foreach ( array( 'refract_publish', 'link_previews', 'setup_done' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$base[ $key ] = rest_sanitize_boolean( is_scalar( $input[ $key ] ) ? $input[ $key ] : false );
			}
		}

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$base['post_types'] = array_values( array_intersect( self::public_post_types(), array_map( 'strval', array_filter( $input['post_types'], 'is_scalar' ) ) ) );
		}

		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$base['categories'] = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', array_filter( $input['categories'], 'is_scalar' ) ),
						static fn ( int $id ): bool => $id > 0 && term_exists( $id, 'category' )
					)
				)
			);
		}

		if ( isset( $input['accounts'] ) && is_array( $input['accounts'] ) ) {
			$base['accounts'] = self::sanitize_account_ids( $input['accounts'] );
		}

		if ( array_key_exists( 'publish_as', $input ) ) {
			$user_id            = is_scalar( $input['publish_as'] ) ? absint( $input['publish_as'] ) : 0;
			$base['publish_as'] = $user_id > 0 && user_can( $user_id, 'publish_posts' ) ? $user_id : 0;
		}

		if ( array_key_exists( 'default_category', $input ) ) {
			$category_id              = is_scalar( $input['default_category'] ) ? absint( $input['default_category'] ) : 0;
			$base['default_category'] = $category_id > 0 && term_exists( $category_id, 'category' ) ? $category_id : 0;
		}

		return array_intersect_key( $base, self::defaults() );
	}

	/**
	 * Keeps Refract account ids (UUIDs and similar), once each.
	 *
	 * @param array<mixed> $ids Submitted ids.
	 * @return list<string>
	 */
	public static function sanitize_account_ids( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
				$clean[] = $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}

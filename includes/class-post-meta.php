<?php
/**
 * The plugin's post meta.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `_refract_*` meta for every public post type (three of them editable over REST by people who can edit
 * the post) and reports the last webhook event time to the editor through the heartbeat.
 */
final class Post_Meta {

	/**
	 * Meta the editor can change.
	 */
	public const EDITABLE = array( '_refract_send', '_refract_accounts', '_refract_social_image' );

	/**
	 * Meta only the plugin writes, with its type.
	 */
	public const INTERNAL = array(
		'_refract_post_id'  => 'string',
		'_refract_sent_at'  => 'integer',
		'_refract_status'   => 'string',
		'_refract_error'    => 'string',
		'_refract_buy_url'  => 'string',
		'_refract_needed'   => 'integer',
		'_refract_left'     => 'integer',
		'_refract_fallback' => 'string',
		'_refract_hash'     => 'string',
		'_refract_attempts' => 'integer',
		'_refract_source'   => 'string',
		'_refract_event_at' => 'integer',
		'_refract_notice'   => 'string',
	);

	/**
	 * Adds the hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ), 20 );
		add_filter( 'heartbeat_received', array( self::class, 'heartbeat' ), 10, 2 );
	}

	/**
	 * Registers the meta for every public post type.
	 */
	public static function register(): void {
		foreach ( Settings::public_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'editor' ) && ! post_type_supports( $post_type, 'custom-fields' ) ) {
				add_post_type_support( $post_type, 'custom-fields' );
			}

			register_post_meta(
				$post_type,
				'_refract_send',
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'sanitize_callback' => array( self::class, 'sanitize_send' ),
					'auth_callback'     => array( self::class, 'can_edit' ),
					'show_in_rest'      => array(
						'schema' => array(
							'type' => 'string',
							'enum' => array( '', '0', '1' ),
						),
					),
				)
			);

			register_post_meta(
				$post_type,
				'_refract_accounts',
				array(
					'type'              => 'array',
					'single'            => true,
					'default'           => array(),
					'sanitize_callback' => array( self::class, 'sanitize_accounts' ),
					'auth_callback'     => array( self::class, 'can_edit' ),
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				)
			);

			register_post_meta(
				$post_type,
				'_refract_social_image',
				array(
					'type'              => 'integer',
					'single'            => true,
					'default'           => 0,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( self::class, 'can_edit' ),
					'show_in_rest'      => true,
				)
			);

			foreach ( self::INTERNAL as $key => $type ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'          => $type,
						'single'        => true,
						'show_in_rest'  => false,
						'auth_callback' => '__return_false',
					)
				);
			}
		}
	}

	/**
	 * Auth callback for the editable meta: people who can edit the post.
	 *
	 * @param bool   $allowed  Whether allowed so far.
	 * @param string $meta_key The meta key.
	 * @param int    $post_id  The post id.
	 */
	public static function can_edit( bool $allowed, string $meta_key, int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Sanitizes `_refract_send`: '', '0' or '1'.
	 *
	 * @param mixed $value The value.
	 */
	public static function sanitize_send( mixed $value ): string {
		return in_array( $value, array( '0', '1' ), true ) ? $value : '';
	}

	/**
	 * Sanitizes `_refract_accounts`.
	 *
	 * @param mixed $value The value.
	 * @return list<string>
	 */
	public static function sanitize_accounts( mixed $value ): array {
		return is_array( $value ) ? Settings::sanitize_account_ids( $value ) : array();
	}

	/**
	 * Adds `refract_event_at` to heartbeat responses when the editor sends `refract_post_id`.
	 *
	 * @param array<string, mixed> $response The heartbeat response.
	 * @param array<string, mixed> $data     What the browser sent.
	 * @return array<string, mixed>
	 */
	public static function heartbeat( array $response, array $data ): array {
		$post_id = isset( $data['refract_post_id'] ) && is_scalar( $data['refract_post_id'] ) ? absint( $data['refract_post_id'] ) : 0;

		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			$response['refract_event_at'] = (int) get_post_meta( $post_id, '_refract_event_at', true );
		}

		return $response;
	}
}

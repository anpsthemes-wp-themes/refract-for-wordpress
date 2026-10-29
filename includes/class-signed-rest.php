<?php
/**
 * REST routes Refract calls.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `refract/v1`: only requests signed with the site's signing secret, at most 5 minutes old, each signature once.
 */
final class Signed_Rest {

	/**
	 * The namespace.
	 */
	public const NAMESPACE = 'refract/v1';

	/**
	 * Requests older (or newer) than this many seconds are refused.
	 */
	public const WINDOW = 300;

	/**
	 * Seen signatures are remembered this long.
	 */
	public const REPLAY_SECONDS = 600;

	/**
	 * Adds the route registration.
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers `GET /site`, `POST /posts`, `DELETE /posts/{refract_post_id}` (since 1.1.0) and `POST /events`.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/site',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'site' ),
				'permission_callback' => array( self::class, 'verify' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( Receiver::class, 'handle' ),
				'permission_callback' => array( self::class, 'verify' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<refract_post_id>[A-Za-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( Receiver::class, 'remove' ),
				'permission_callback' => array( self::class, 'verify' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/events',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( Events::class, 'handle' ),
				'permission_callback' => array( self::class, 'verify' ),
			)
		);
	}

	/**
	 * Checks the signature: `X-Refract-Timestamp` + `X-Refract-Signature`, or the webhook form
	 * `Refract-Signature: t=…,v1=…[,v1=…]`. HMAC-SHA256 of "{timestamp}.{raw body}" with the signing secret.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public static function verify( WP_REST_Request $request ): bool|WP_Error {
		$secret = Secrets::get()['signing_secret'];
		$bad    = new WP_Error( 'refract_bad_signature', __( 'The request isn\'t signed by Refract.', 'refract' ), array( 'status' => 401 ) );

		$timestamp  = (string) $request->get_header( 'x_refract_timestamp' );
		$signatures = array_filter( array( (string) $request->get_header( 'x_refract_signature' ) ) );

		if ( '' === $timestamp || array() === $signatures ) {
			$timestamp  = '';
			$signatures = array();

			foreach ( explode( ',', (string) $request->get_header( 'refract_signature' ) ) as $part ) {
				$pair = explode( '=', trim( $part ), 2 );

				if ( 2 !== count( $pair ) ) {
					continue;
				}

				if ( 't' === $pair[0] ) {
					$timestamp = $pair[1];
				} elseif ( 'v1' === $pair[0] ) {
					$signatures[] = $pair[1];
				}
			}
		}

		if ( '' === $secret || 1 !== preg_match( '/^\d{1,12}$/', $timestamp ) || array() === $signatures ) {
			return $bad;
		}

		if ( abs( time() - (int) $timestamp ) > self::WINDOW ) {
			return new WP_Error( 'refract_expired_signature', __( 'The request is too old.', 'refract' ), array( 'status' => 401 ) );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret );
		$matched  = false;

		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, strtolower( trim( $signature ) ) ) ) {
				$matched = true;
			}
		}

		if ( ! $matched ) {
			return $bad;
		}

		$seen = 'refract_sig_' . md5( $expected );

		if ( false !== get_transient( $seen ) ) {
			return new WP_Error( 'refract_replayed_signature', __( 'The request was already received.', 'refract' ), array( 'status' => 401 ) );
		}

		set_transient( $seen, 1, self::REPLAY_SECONDS );

		return true;
	}

	/**
	 * `GET /site`: what Refract needs to publish here and to fill its WordPress composer tab.
	 */
	public static function site(): WP_REST_Response {
		$settings = Settings::get();
		$user     = $settings['publish_as'] > 0 ? get_userdata( $settings['publish_as'] ) : false;

		if ( ! $settings['refract_publish'] ) {
			$publishing = 'off';
		} else {
			$publishing = 'drafts' === $settings['refract_can'] ? 'drafts' : 'publish';
		}

		return rest_ensure_response(
			array(
				'name'             => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'url'              => home_url(),
				'rest_url'         => get_rest_url(),
				'plugin_version'   => REFRACT_VERSION,
				'wp_version'       => get_bloginfo( 'version' ),
				'locale'           => get_locale(),
				'timezone'         => wp_timezone_string(),
				'publishing'       => $publishing,
				'categories'       => Admin_State::categories(),
				'tags'             => Admin_State::terms( 'post_tag', 200 ),
				'default_category' => $settings['default_category'] > 0 ? $settings['default_category'] : (int) get_option( 'default_category' ),
				'publish_as'       => false === $user ? null : $user->display_name,
			)
		);
	}
}

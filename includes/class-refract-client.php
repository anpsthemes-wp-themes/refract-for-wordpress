<?php
/**
 * Talks to Refract.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every call to Refract goes through here: `wp_remote_request()`, a 15 s timeout, the plugin's user agent, JSON, and
 * the access token (refreshed when it has expired or has less than 5 minutes left).
 */
final class Refract_Client {

	/**
	 * Seconds before giving up on Refract.
	 */
	public const TIMEOUT = 15;

	/**
	 * Refresh the access token when it has less than this many seconds left.
	 */
	public const REFRESH_MARGIN = 300;

	/**
	 * Option used as a lock so parallel requests don't refresh the token twice.
	 */
	public const REFRESH_LOCK = 'refract_refresh_lock';

	/**
	 * The user agent Refract sees.
	 */
	public static function user_agent(): string {
		return 'Refract-WordPress/' . REFRACT_VERSION;
	}

	/**
	 * Calls the Refract API (`{refract}/api/v1/{path}`) with the site's token.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path under /api/v1, without the leading slash.
	 * @param array<mixed>|null    $body    JSON body, or null.
	 * @param array<string,string> $headers Extra headers.
	 * @return Refract_Response|WP_Error A response (any status), or an error when Refract can't be reached or the
	 *                                   connection needs reconnecting.
	 */
	public static function api( string $method, string $path, ?array $body = null, array $headers = array() ): Refract_Response|WP_Error {
		$token = self::access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url      = Connection::refract_url() . '/api/v1/' . ltrim( $path, '/' );
		$response = self::send( $method, $url, $body, $headers + array( 'Authorization' => 'Bearer ' . $token ) );

		if ( $response instanceof Refract_Response && 401 === $response->status && 'oauth' === Connection::get()['kind'] ) {
			$token = self::refresh( $token );

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = self::send( $method, $url, $body, array_merge( $headers, array( 'Authorization' => 'Bearer ' . $token ) ) );
		}

		if ( $response instanceof Refract_Response && 401 === $response->status ) {
			Connection::mark_needs_reconnect();

			return self::reconnect_error();
		}

		return $response;
	}

	/**
	 * Sends one request to any Refract URL (OAuth endpoints use this directly).
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Full URL.
	 * @param array<mixed>|null    $body    JSON body, or null.
	 * @param array<string,string> $headers Extra headers.
	 * @param array<string,string> $form    Form fields, sent instead of JSON when given.
	 * @return Refract_Response|WP_Error
	 */
	public static function send( string $method, string $url, ?array $body = null, array $headers = array(), array $form = array() ): Refract_Response|WP_Error {
		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'user-agent'  => self::user_agent(),
			'headers'     => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);

		if ( array() !== $form ) {
			$args['body']                    = $form;
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
		} elseif ( null !== $body ) {
			$args['body']                    = (string) wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'refract_unreachable',
				__( 'Refract didn\'t answer. Check your connection and try again in a minute.', 'refract' ),
				array(
					'status' => 502,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$headers = array();

		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return new Refract_Response( (int) wp_remote_retrieve_response_code( $response ), is_array( $decoded ) ? $decoded : array(), $headers );
	}

	/**
	 * The access token to use now, refreshed first when it has expired or expires within 5 minutes.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token(): string|WP_Error {
		$secrets = Secrets::get();

		if ( '' === $secrets['access_token'] ) {
			return new WP_Error( 'refract_not_connected', __( 'Connect your site to Refract first.', 'refract' ), array( 'status' => 409 ) );
		}

		if ( 'oauth' === Connection::get()['kind'] && $secrets['expires_at'] > 0 && $secrets['expires_at'] - time() < self::REFRESH_MARGIN ) {
			return self::refresh( $secrets['access_token'] );
		}

		return $secrets['access_token'];
	}

	/**
	 * Refreshes the access token once, even when several requests need it at the same time. A rejected refresh token
	 * marks the connection as needing a reconnect.
	 *
	 * @param string $stale_token The token the caller found too old.
	 * @return string|WP_Error The new access token.
	 */
	public static function refresh( string $stale_token ): string|WP_Error {
		if ( ! self::lock() ) {
			for ( $i = 0; $i < 10; $i++ ) {
				usleep( 500000 );
				$secrets = Secrets::fresh();

				if ( '' !== $secrets['access_token'] && $secrets['access_token'] !== $stale_token ) {
					return $secrets['access_token'];
				}
			}

			return new WP_Error( 'refract_busy', __( 'Refract is busy. Try again in a minute.', 'refract' ), array( 'status' => 503 ) );
		}

		try {
			$secrets = Secrets::fresh();

			if ( '' !== $secrets['access_token'] && $secrets['access_token'] !== $stale_token && $secrets['expires_at'] - time() >= self::REFRESH_MARGIN ) {
				return $secrets['access_token'];
			}

			$client = Connector::oauth_client();

			if ( '' === $secrets['refresh_token'] || null === $client ) {
				Connection::mark_needs_reconnect();

				return self::reconnect_error();
			}

			$response = self::send(
				'POST',
				Connection::refract_url() . '/oauth/token',
				null,
				array(),
				array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $client['client_id'],
					'refresh_token' => $secrets['refresh_token'],
					'scope'         => 'api',
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( in_array( $response->status, array( 400, 401 ), true ) ) {
				Connection::mark_needs_reconnect();

				return self::reconnect_error();
			}

			return self::store_tokens( $response );
		} finally {
			delete_option( self::REFRESH_LOCK );
		}
	}

	/**
	 * Saves the tokens from an OAuth token response.
	 *
	 * @param Refract_Response $response The /oauth/token response.
	 * @return string|WP_Error The access token.
	 */
	public static function store_tokens( Refract_Response $response ): string|WP_Error {
		$access = $response->body['access_token'] ?? null;

		if ( ! $response->ok() || ! is_string( $access ) || '' === $access ) {
			return $response->ok() ? new WP_Error( 'refract_error', __( 'Refract sent an answer the plugin doesn\'t understand. Try again in a minute.', 'refract' ), array( 'status' => 502 ) ) : $response->to_error();
		}

		$refresh = $response->body['refresh_token'] ?? '';
		$expires = $response->body['expires_in'] ?? 0;

		Secrets::update(
			array(
				'access_token'  => $access,
				'refresh_token' => is_string( $refresh ) ? $refresh : '',
				'expires_at'    => is_numeric( $expires ) && (int) $expires > 0 ? time() + (int) $expires : 0,
			)
		);

		return $access;
	}

	/**
	 * The error shown when the connection needs reconnecting.
	 */
	public static function reconnect_error(): WP_Error {
		return new WP_Error( 'refract_needs_reconnect', __( 'Refract needs you to connect again. Your site can\'t send posts until you do.', 'refract' ), array( 'status' => 401 ) );
	}

	/**
	 * Takes the refresh lock. A lock older than 30 seconds is left over from a crashed request and is taken over.
	 */
	private static function lock(): bool {
		if ( add_option( self::REFRESH_LOCK, (string) time(), '', false ) ) {
			return true;
		}

		wp_cache_delete( self::REFRESH_LOCK, 'options' );
		$since = (int) get_option( self::REFRESH_LOCK, 0 );

		if ( $since > time() - 30 ) {
			return false;
		}

		delete_option( self::REFRESH_LOCK );

		return add_option( self::REFRESH_LOCK, (string) time(), '', false );
	}
}

<?php
/**
 * The connection's secrets.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Tokens and the signing secret, encrypted in one option that isn't autoloaded and never reaches REST or the browser.
 */
final class Secrets {

	/**
	 * Option holding the encrypted secrets.
	 */
	public const OPTION = 'refract_secrets';

	/**
	 * The secrets, empty strings and zero when missing.
	 *
	 * @return array{access_token: string, refresh_token: string, expires_at: int, signing_secret: string}
	 */
	public static function get(): array {
		$empty = array(
			'access_token'   => '',
			'refresh_token'  => '',
			'expires_at'     => 0,
			'signing_secret' => '',
		);
		$blob  = get_option( self::OPTION, '' );

		if ( ! is_string( $blob ) || '' === $blob ) {
			return $empty;
		}

		$plain = Crypto::decrypt( $blob );
		$data  = null === $plain ? null : json_decode( $plain, true );

		if ( ! is_array( $data ) ) {
			return $empty;
		}

		return array(
			'access_token'   => is_string( $data['access_token'] ?? null ) ? $data['access_token'] : '',
			'refresh_token'  => is_string( $data['refresh_token'] ?? null ) ? $data['refresh_token'] : '',
			'expires_at'     => is_int( $data['expires_at'] ?? null ) ? $data['expires_at'] : 0,
			'signing_secret' => is_string( $data['signing_secret'] ?? null ) ? $data['signing_secret'] : '',
		);
	}

	/**
	 * Changes some secrets and keeps the others.
	 *
	 * @param array{access_token?: string, refresh_token?: string, expires_at?: int, signing_secret?: string} $changes The secrets to change.
	 */
	public static function update( array $changes ): void {
		$secrets = array_merge( self::get(), $changes );

		update_option( self::OPTION, Crypto::encrypt( (string) wp_json_encode( $secrets ) ), false );
	}

	/**
	 * Forgets every secret.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Re-reads the secrets from the database, skipping the object cache (another request may have refreshed them).
	 *
	 * @return array{access_token: string, refresh_token: string, expires_at: int, signing_secret: string}
	 */
	public static function fresh(): array {
		wp_cache_delete( self::OPTION, 'options' );

		return self::get();
	}

	/**
	 * A new signing secret: 32 random bytes, base64.
	 */
	public static function new_signing_secret(): string {
		return sodium_bin2base64( random_bytes( 32 ), SODIUM_BASE64_VARIANT_ORIGINAL );
	}
}

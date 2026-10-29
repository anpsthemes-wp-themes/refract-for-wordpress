<?php
/**
 * Encryption for the secrets the plugin keeps.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Libsodium secretbox with a key derived from the site's auth salt. Changing the salts makes the secrets unreadable,
 * which means connecting again.
 */
final class Crypto {

	/**
	 * Encrypts a string. The result is base64: nonce, then ciphertext.
	 *
	 * @param string $plain The text to encrypt.
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return sodium_bin2base64( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ), SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Decrypts what encrypt() returned, or null when it can't (other salts, or damaged data).
	 *
	 * @param string $blob The encrypted text.
	 */
	public static function decrypt( string $blob ): ?string {
		try {
			$raw = sodium_base642bin( $blob, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			return null;
		}

		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return false === $plain ? null : $plain;
	}

	/**
	 * The 32-byte key: a hash of the auth salt.
	 */
	private static function key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}

<?php
/**
 * Secrets tests.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Crypto;
use Refract\WP\Secrets;

/**
 * Encryption and storage of tokens and the signing secret.
 */
final class Test_Secrets extends Refract_Test_Case {

	/**
	 * Encrypting then decrypting gives the original; damaged data gives null.
	 */
	public function test_encryption_round_trip(): void {
		$blob = Crypto::encrypt( 'rf_live_secret' );

		$this->assertNotSame( 'rf_live_secret', $blob );
		$this->assertSame( 'rf_live_secret', Crypto::decrypt( $blob ) );
		$this->assertNotSame( $blob, Crypto::encrypt( 'rf_live_secret' ), 'Each encryption uses a new nonce.' );
		$this->assertNull( Crypto::decrypt( substr( $blob, 0, -4 ) . 'AAAA' ) );
		$this->assertNull( Crypto::decrypt( 'not base64 at all' ) );
	}

	/**
	 * Secrets are stored encrypted and not autoloaded.
	 */
	public function test_secrets_are_encrypted_and_not_autoloaded(): void {
		Secrets::update(
			array(
				'access_token'   => 'access-plain',
				'signing_secret' => 'signing-plain',
			)
		);

		$raw = (string) get_option( Secrets::OPTION );

		$this->assertStringNotContainsString( 'access-plain', $raw );
		$this->assertStringNotContainsString( 'signing-plain', $raw );
		$this->assertSame( 'access-plain', Secrets::get()['access_token'] );
		$this->assertSame( 'signing-plain', Secrets::get()['signing_secret'] );

		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( Secrets::OPTION, wp_load_alloptions() );
	}
}

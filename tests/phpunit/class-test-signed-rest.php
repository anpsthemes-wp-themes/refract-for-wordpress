<?php
/**
 * Signed route tests.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Secrets;
use Refract\WP\Settings;

/**
 * Signature checks on every `refract/v1` route, and `GET /site`.
 */
final class Test_Signed_Rest extends Refract_Test_Case {

	/**
	 * A valid signature opens `GET /site`.
	 */
	public function test_valid_signature(): void {
		$this->connect(
			array(
				'refract_publish' => true,
				'publish_as'      => self::factory()->user->create(
					array(
						'role'         => 'editor',
						'display_name' => 'Ana Kovač',
					)
				),
				'refract_can'     => 'drafts',
			)
		);
		self::factory()->tag->create( array( 'name' => 'Huila' ) );

		$response = $this->signed( 'GET', '/refract/v1/site' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'drafts', $data['publishing'] );
		$this->assertSame( 'Ana Kovač', $data['publish_as'] );
		$this->assertSame( REFRACT_VERSION, $data['plugin_version'] );
		$this->assertSame( get_rest_url(), $data['rest_url'] );
		$this->assertContains( 'Huila', array_column( $data['tags'], 'name' ) );
		$this->assertSame( (int) get_option( 'default_category' ), $data['default_category'] );

		Settings::update( array( 'refract_publish' => false ) );
		$this->assertSame( 'off', $this->signed( 'GET', '/refract/v1/site' )->get_data()['publishing'] );
	}

	/**
	 * Old, replayed and wrong signatures are refused, with distinct codes.
	 */
	public function test_expired_replayed_and_bad(): void {
		$this->connect();

		$expired = $this->signed( 'GET', '/refract/v1/site', null, time() - 301 );
		$this->assertSame( 401, $expired->get_status() );
		$this->assertSame( 'refract_expired_signature', $expired->get_data()['code'] );

		$future = $this->signed( 'GET', '/refract/v1/site', null, time() + 301 );
		$this->assertSame( 'refract_expired_signature', $future->get_data()['code'] );

		$timestamp = (string) time();
		$headers   = array(
			'X-Refract-Timestamp' => $timestamp,
			'X-Refract-Signature' => hash_hmac( 'sha256', $timestamp . '.', Secrets::get()['signing_secret'] ),
		);
		$this->assertSame( 200, $this->rest( 'GET', '/refract/v1/site', array(), $headers )->get_status() );

		$replayed = $this->rest( 'GET', '/refract/v1/site', array(), $headers );
		$this->assertSame( 401, $replayed->get_status() );
		$this->assertSame( 'refract_replayed_signature', $replayed->get_data()['code'] );

		$bad = $this->rest(
			'GET',
			'/refract/v1/site',
			array(),
			array(
				'X-Refract-Timestamp' => $timestamp,
				'X-Refract-Signature' => hash_hmac( 'sha256', $timestamp . '.', 'wrong secret' ),
			)
		);
		$this->assertSame( 401, $bad->get_status() );
		$this->assertSame( 'refract_bad_signature', $bad->get_data()['code'] );

		$this->assertSame( 'refract_bad_signature', $this->rest( 'GET', '/refract/v1/site' )->get_data()['code'], 'No signature at all.' );

		$tampered = $this->rest(
			'POST',
			'/refract/v1/events',
			array(),
			array(
				'X-Refract-Timestamp' => $timestamp,
				'X-Refract-Signature' => hash_hmac( 'sha256', $timestamp . '.{"type":"post.failed"}', Secrets::get()['signing_secret'] ),
			),
			'{"type":"post.queued"}'
		);
		$this->assertSame( 'refract_bad_signature', $tampered->get_data()['code'], 'The body is part of the signature.' );
	}

	/**
	 * The webhook form `Refract-Signature: t=…,v1=…` works too, with several v1 values (secret rotation).
	 */
	public function test_webhook_header_form(): void {
		$this->connect();
		$body      = '{"id":"evt_1","type":"post.unknown","data":{}}';
		$timestamp = time();
		$valid     = hash_hmac( 'sha256', $timestamp . '.' . $body, Secrets::get()['signing_secret'] );

		$response = $this->rest( 'POST', '/refract/v1/events', array(), array( 'Refract-Signature' => 't=' . $timestamp . ',v1=' . str_repeat( 'a', 64 ) . ',v1=' . $valid ), $body );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'ok' => true ), $response->get_data() );

		$replayed = $this->rest( 'POST', '/refract/v1/events', array(), array( 'Refract-Signature' => 't=' . $timestamp . ',v1=' . $valid ), $body );
		$this->assertSame( 'refract_replayed_signature', $replayed->get_data()['code'] );

		$old     = time() - 400;
		$expired = $this->rest( 'POST', '/refract/v1/events', array(), array( 'Refract-Signature' => 't=' . $old . ',v1=' . hash_hmac( 'sha256', $old . '.' . $body, Secrets::get()['signing_secret'] ) ), $body );
		$this->assertSame( 'refract_expired_signature', $expired->get_data()['code'] );

		$bad = $this->rest( 'POST', '/refract/v1/events', array(), array( 'Refract-Signature' => 't=' . $timestamp . ',v1=' . str_repeat( 'b', 64 ) ), $body );
		$this->assertSame( 'refract_bad_signature', $bad->get_data()['code'] );
	}

	/**
	 * A site without a signing secret refuses everything.
	 */
	public function test_not_connected(): void {
		$timestamp = (string) time();
		$response  = $this->rest(
			'GET',
			'/refract/v1/site',
			array(),
			array(
				'X-Refract-Timestamp' => $timestamp,
				'X-Refract-Signature' => hash_hmac( 'sha256', $timestamp . '.', '' ),
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}
}

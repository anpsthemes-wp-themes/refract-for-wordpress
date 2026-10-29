<?php
/**
 * Base class for the plugin's tests.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Connection;
use Refract\WP\Connector;
use Refract\WP\Post_Meta;
use Refract\WP\Refract_Data;
use Refract\WP\Secrets;
use Refract\WP\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * A fake Refract per test, and helpers to connect the site and call REST routes.
 */
abstract class Refract_Test_Case extends WP_UnitTestCase {

	/**
	 * The fake Refract.
	 *
	 * @var Refract_Mock
	 */
	protected Refract_Mock $refract;

	/**
	 * How many signed calls were made, so each gets its own timestamp.
	 *
	 * @var int
	 */
	private static int $signed_calls = 0;

	/**
	 * Starts a fresh fake Refract and a fresh REST server.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->refract = new Refract_Mock();

		// The test case unregisters meta between tests.
		Post_Meta::register();

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Stops the fake Refract.
	 */
	public function tear_down(): void {
		$this->refract->stop();

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Puts the site in the connected state, with two accounts chosen.
	 *
	 * @param array<string, mixed> $settings Extra settings.
	 */
	protected function connect( array $settings = array() ): void {
		Secrets::update(
			array(
				'access_token'   => 'access-1',
				'refresh_token'  => 'refresh-1',
				'expires_at'     => time() + HOUR_IN_SECONDS,
				'signing_secret' => 'c2VjcmV0LXNpZ25pbmcta2V5LWZvci10ZXN0cy0xMjM0NTY=',
			)
		);
		Connection::update(
			array(
				'status'       => 'connected',
				'kind'         => 'oauth',
				'refract_url'  => 'https://refract.test',
				'email'        => 'ana@kolo.coffee',
				'name'         => 'Ana',
				'workspace'    => array(
					'id'   => 'ws-1',
					'name' => 'Kolo',
					'slug' => 'kolo',
				),
				'workspaces'   => array(
					array(
						'id'   => 'ws-1',
						'name' => 'Kolo',
						'slug' => 'kolo',
					),
				),
				'account_id'   => 'site-account',
				'connected_at' => time(),
			)
		);
		update_option(
			Connector::CLIENT_OPTION,
			array(
				'client_id'    => 'client-1',
				'redirect_uri' => Connector::redirect_uri(),
				'refract_url'  => 'https://refract.test',
			)
		);
		Settings::update(
			array_merge(
				array(
					'accounts'   => array( 'acc-instagram', 'acc-linkedin' ),
					'setup_done' => true,
				),
				$settings
			)
		);
		Refract_Data::forget();
	}

	/**
	 * Calls a REST route.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $route   Route, with namespace.
	 * @param array<string, mixed>  $params  Body parameters.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Raw body (overrides params).
	 */
	protected function rest( string $method, string $route, array $params = array(), array $headers = array(), ?string $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $body );
		} elseif ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Calls a `refract/v1` route signed like Refract does.
	 *
	 * @param string            $method    HTTP method.
	 * @param string            $route     Route, with namespace.
	 * @param array<mixed>|null $payload   JSON body.
	 * @param int|null          $timestamp Unix time to sign with.
	 */
	protected function signed( string $method, string $route, ?array $payload = null, ?int $timestamp = null ): WP_REST_Response {
		// Each call signs with another second, like separate requests from Refract (a repeated signature is a replay).
		++self::$signed_calls;
		$timestamp = $timestamp ?? time() - ( self::$signed_calls % 250 );
		$body      = null === $payload ? '' : (string) wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, Secrets::get()['signing_secret'] );

		return $this->rest(
			$method,
			$route,
			array(),
			array(
				'X-Refract-Timestamp' => (string) $timestamp,
				'X-Refract-Signature' => $signature,
			),
			$body
		);
	}

	/**
	 * Creates an admin and logs in as them.
	 */
	protected function login_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * A 1×1 PNG.
	 */
	protected static function png(): string {
		return (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.
	}
}

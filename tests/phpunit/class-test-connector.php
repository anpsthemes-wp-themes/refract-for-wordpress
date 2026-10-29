<?php
/**
 * Connecting, tokens and disconnecting.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Admin;
use Refract\WP\Connection;
use Refract\WP\Connector;
use Refract\WP\Refract_Client;
use Refract\WP\Refract_Data;
use Refract\WP\Secrets;
use Refract\WP\Settings;

/**
 * The connect flows, token refresh and disconnect.
 */
final class Test_Connector extends Refract_Test_Case {

	/**
	 * Answers /me and /workspaces.
	 *
	 * @param int $workspaces How many workspaces the person has.
	 */
	private function account( int $workspaces = 1 ): void {
		$this->refract->on(
			'GET',
			'/api/v1/me',
			200,
			array(
				'data' => array(
					'id'         => 'user-1',
					'attributes' => array(
						'name'  => 'Ana',
						'email' => 'ana@kolo.coffee',
					),
				),
				'meta' => array(
					'access'  => array(
						'organization' => array(
							'name' => 'Kolo Coffee',
							'plan' => 'Business',
						),
					),
					'credits' => array(
						'left'      => 40,
						'refill_on' => '2026-11-01',
						'own_key'   => false,
						'buy_url'   => 'https://refract.test/billing',
					),
				),
			)
		);

		$list = array();

		for ( $i = 1; $i <= $workspaces; $i++ ) {
			$list[] = array(
				'id'         => 'ws-' . $i,
				'type'       => 'workspaces',
				'attributes' => array(
					'name' => 'Workspace ' . $i,
					'slug' => 'workspace-' . $i,
				),
			);
		}

		$this->refract->on( 'GET', '/api/v1/workspaces', 200, array( 'data' => $list ) );
	}

	/**
	 * Registers the site like Refract does.
	 *
	 * @param string $workspace Workspace id.
	 */
	private function accept_site( string $workspace ): void {
		$this->refract->on(
			'POST',
			'/api/v1/workspaces/' . $workspace . '/accounts/wordpress',
			201,
			array(
				'account_id' => 'site-account',
				'handle'     => 'example.org',
				'workspace'  => array(
					'id'   => $workspace,
					'slug' => 'kolo',
					'name' => 'Kolo',
				),
			)
		);
	}

	/**
	 * OAuth: authorize URL with PKCE, then the callback exchanges the code and registers the only workspace.
	 */
	public function test_oauth_connect(): void {
		$this->login_admin();
		$this->refract->on( 'POST', '/oauth/register', 201, array( 'client_id' => 'client-9' ) );

		$this->assertSame( array(), $this->refract->requests, 'Nothing is sent before Connect.' );

		$url = $this->rest( 'POST', '/refract-admin/v1/connect' )->get_data()['authorize_url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$register = $this->refract->sent( '/oauth/register' )[0]['body'];
		$this->assertSame( array( Connector::redirect_uri() ), $register['redirect_uris'] );
		$this->assertStringStartsWith( 'https://refract.test/oauth/authorize?', $url );
		$this->assertSame( 'client-9', $query['client_id'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( 'api', $query['scope'] );
		$this->assertSame( Connector::redirect_uri(), $query['redirect_uri'] );

		$this->refract->answer(
			'POST',
			'/oauth/token',
			function ( array $args ) use ( &$query ): array {
				$this->assertSame( 'authorization_code', $args['body']['grant_type'] );
				$this->assertSame( 'the-code', $args['body']['code'] );
				$this->assertSame( $query['code_challenge'], rtrim( strtr( base64_encode( hash( 'sha256', $args['body']['code_verifier'], true ) ), '+/', '-_' ), '=' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE check.

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'token_type'    => 'Bearer',
							'expires_in'    => 86400,
							'access_token'  => 'access-9',
							'refresh_token' => 'refresh-9',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
		$this->account();
		$this->accept_site( 'ws-1' );

		$this->assertWPError( Connector::complete( 'the-code', 'wrong-state', '' ), 'The state must match.' );

		$url = (string) $this->rest( 'POST', '/refract-admin/v1/connect' )->get_data()['authorize_url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'Your site is connected to Refract.', Connector::complete( 'the-code', $query['state'], '' ) );

		$connection = Connection::get();
		$secrets    = Secrets::get();
		$site       = $this->refract->sent( '/api/v1/workspaces/ws-1/accounts/wordpress', 'POST' )[0];

		$this->assertSame( 'connected', $connection['status'] );
		$this->assertSame( 'oauth', $connection['kind'] );
		$this->assertSame( 'ana@kolo.coffee', $connection['email'] );
		$this->assertSame( 'Business', $connection['plan'] );
		$this->assertSame( 'site-account', $connection['account_id'] );
		$this->assertSame( 'access-9', $secrets['access_token'] );
		$this->assertSame( 32, strlen( (string) base64_decode( $secrets['signing_secret'], true ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Checks the secret's length.
		$this->assertSame( $secrets['signing_secret'], $site['body']['signing_secret'] );
		$this->assertSame( rest_url( 'refract/v1/events' ), $site['body']['events_url'] );
		$this->assertSame( get_rest_url(), $site['body']['rest_url'] );
		$this->assertSame( 'Bearer access-9', $site['headers']['Authorization'] );
		$this->assertSame( 1, count( $this->refract->sent( '/oauth/register' ) ), 'The OAuth client is registered once.' );

		$this->assertWPError( Connector::complete( 'the-code', $query['state'], '' ), 'A state is used once.' );
	}

	/**
	 * Denying in Refract changes nothing.
	 */
	public function test_oauth_denied(): void {
		$this->login_admin();
		$this->refract->on( 'POST', '/oauth/register', 201, array( 'client_id' => 'client-9' ) );
		$url = (string) $this->rest( 'POST', '/refract-admin/v1/connect' )->get_data()['authorize_url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$result = Connector::complete( '', $query['state'], 'access_denied' );

		$this->assertWPError( $result );
		$this->assertSame( 'You didn\'t allow the connection, so nothing changed.', $result->get_error_message() );
		$this->assertSame( 'disconnected', Connection::status() );
	}

	/**
	 * A token: several workspaces wait for a choice; Refract's 422 detail reaches the person; then it registers.
	 */
	public function test_token_connect_with_workspace_choice(): void {
		$this->login_admin();
		$this->account( 2 );

		$state = $this->rest( 'POST', '/refract-admin/v1/connect-token', array( 'token' => 'rf_live_abcdefghijkl' ) )->get_data();

		$this->assertSame( 'unregistered', $state['connection']['status'] );
		$this->assertSame( 'token', $state['connection']['kind'] );
		$this->assertCount( 2, $state['connection']['workspaces'] );
		$this->assertSame( 40, $state['connection']['credits']['left'] );
		$this->assertSame( array(), $this->refract->sent( '/api/v1/workspaces/.*/accounts/wordpress', 'POST' ) );

		$this->refract->on(
			'POST',
			'/api/v1/workspaces/ws-2/accounts/wordpress',
			422,
			array(
				'errors' => array(
					array(
						'status' => '422',
						'code'   => 'plan_full',
						'title'  => 'Plan full',
						'detail' => 'Your plan has room for 5 accounts. Remove one or upgrade to connect this site.',
					),
				),
			),
			1
		);
		$full = $this->rest( 'POST', '/refract-admin/v1/workspace', array( 'workspace_id' => 'ws-2' ) );
		$this->assertSame( 422, $full->get_status() );
		$this->assertSame( 'Your plan has room for 5 accounts. Remove one or upgrade to connect this site.', $full->get_data()['message'] );
		$this->assertSame( 'Your plan has room for 5 accounts. Remove one or upgrade to connect this site.', Connection::get()['error'] );

		$this->accept_site( 'ws-2' );
		$state = $this->rest( 'POST', '/refract-admin/v1/workspace', array( 'workspace_id' => 'ws-2' ) )->get_data();
		$this->assertSame( 'connected', $state['connection']['status'] );
		$this->assertNull( $state['connection']['error'] );

		$this->assertSame( 422, $this->rest( 'POST', '/refract-admin/v1/workspace', array( 'workspace_id' => 'ws-unknown' ) )->get_status() );
	}

	/**
	 * A rejected token leaves the site disconnected.
	 */
	public function test_bad_token(): void {
		$this->login_admin();
		$this->refract->on(
			'GET',
			'/api/v1/me',
			401,
			array(
				'errors' => array(
					array(
						'status' => '401',
						'title'  => 'Unauthenticated.',
					),
				),
			)
		);

		$response = $this->rest( 'POST', '/refract-admin/v1/connect-token', array( 'token' => 'rf_live_wrongtoken' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'disconnected', Connection::status() );
		$this->assertSame( '', Secrets::get()['access_token'] );
	}

	/**
	 * The accounts list leaves out WordPress sites and puts the main channel first.
	 */
	public function test_accounts_exclude_wordpress(): void {
		$this->connect();
		$this->refract->on(
			'GET',
			'/api/v1/workspaces/ws-1/accounts',
			200,
			array(
				'data' => array(
					array(
						'id'         => 'acc-site',
						'attributes' => array(
							'platform' => 'wordpress',
							'name'     => 'Kolo',
							'handle'   => 'kolo.coffee',
						),
					),
					array(
						'id'         => 'acc-li',
						'attributes' => array(
							'platform'     => 'linkedin',
							'label'        => 'LinkedIn',
							'name'         => 'Kolo',
							'handle'       => 'kolo',
							'main'         => false,
							'availability' => 'available',
						),
					),
					array(
						'id'         => 'acc-ig',
						'attributes' => array(
							'platform'     => 'instagram',
							'label'        => 'Instagram',
							'name'         => 'Kolo',
							'handle'       => '@kolocoffee',
							'main'         => true,
							'availability' => 'beta',
						),
					),
				),
			)
		);

		$accounts = Refract_Data::accounts();

		$this->assertSame( array( 'acc-ig', 'acc-li' ), array_column( $accounts, 'id' ) );
		$this->assertSame( 'beta', $accounts[0]['availability'] );
		$this->assertSame( 'Instagram', $accounts[0]['network_label'] );
	}

	/**
	 * Disconnect removes the site in Refract, then forgets everything here (keeping settings for Change workspace).
	 */
	public function test_disconnect(): void {
		$this->connect( array( 'send_mode' => 'manual' ) );
		$this->login_admin();
		$this->refract->on( 'DELETE', '/api/v1/workspaces/ws-1/accounts/wordpress/site-account', 204 );

		$state = $this->rest( 'POST', '/refract-admin/v1/disconnect', array( 'keep_settings' => true ) )->get_data();

		$this->assertCount( 1, $this->refract->sent( '/api/v1/workspaces/ws-1/accounts/wordpress/site-account', 'DELETE' ) );
		$this->assertSame( 'disconnected', $state['connection']['status'] );
		$this->assertSame( '', Secrets::get()['signing_secret'] );
		$this->assertSame( 'manual', Settings::get()['send_mode'] );
		$this->assertSame( array(), Settings::get()['accounts'] );

		$this->connect( array( 'send_mode' => 'manual' ) );
		$this->refract->unreachable( 'DELETE', '/api/v1/workspaces/ws-1/accounts/wordpress/site-account' );
		$this->rest( 'POST', '/refract-admin/v1/disconnect' );

		$this->assertSame( 'disconnected', Connection::status(), 'Disconnecting works even when Refract can\'t be reached.' );
		$this->assertSame( 'publish', Settings::get()['send_mode'] );
	}

	/**
	 * An access token close to expiry is refreshed first, once.
	 */
	public function test_token_refresh(): void {
		$this->connect();
		Secrets::update( array( 'expires_at' => time() + 120 ) );
		$this->refract->on(
			'POST',
			'/oauth/token',
			200,
			array(
				'access_token'  => 'access-2',
				'refresh_token' => 'refresh-2',
				'expires_in'    => 86400,
			)
		);
		$this->refract->on( 'GET', '/api/v1/me', 200, array( 'data' => array() ) );

		$response = Refract_Client::api( 'GET', 'me' );

		$this->assertFalse( is_wp_error( $response ) );
		$refresh = $this->refract->sent( '/oauth/token' );
		$this->assertCount( 1, $refresh );
		$this->assertSame( 'refresh_token', $refresh[0]['body']['grant_type'] );
		$this->assertSame( 'refresh-1', $refresh[0]['body']['refresh_token'] );
		$this->assertSame( 'api', $refresh[0]['body']['scope'] );
		$this->assertSame( 'Bearer access-2', $this->refract->sent( '/api/v1/me' )[0]['headers']['Authorization'] );
		$this->assertSame( 'refresh-2', Secrets::get()['refresh_token'] );
		$this->assertFalse( get_option( Refract_Client::REFRESH_LOCK ), 'The lock is released.' );

		Refract_Client::api( 'GET', 'me' );
		$this->assertCount( 1, $this->refract->sent( '/oauth/token' ), 'A fresh token is not refreshed again.' );
	}

	/**
	 * A rejected refresh token marks the connection and shows the reconnect notice to admins.
	 */
	public function test_refresh_rejected_needs_reconnect(): void {
		$this->connect();
		Secrets::update( array( 'expires_at' => time() - 10 ) );
		$this->refract->on( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant' ) );

		$response = Refract_Client::api( 'GET', 'me' );

		$this->assertWPError( $response );
		$this->assertSame( 'refract_needs_reconnect', $response->get_error_code() );
		$this->assertSame( 'needs_reconnect', Connection::status() );

		set_current_screen( 'dashboard' );
		$this->login_admin();
		ob_start();
		Admin::notices();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'Refract needs you to connect again. Your site can&#039;t send posts until you do.', $notice );
		$this->assertStringContainsString( 'Reconnect', $notice );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		Admin::notices();
		$this->assertSame( '', (string) ob_get_clean(), 'Only people who manage options see it.' );
	}

	/**
	 * A 401 from Refract after a refresh (or with an API token) also needs a reconnect.
	 */
	public function test_unauthorized_needs_reconnect(): void {
		$this->connect();
		Connection::update( array( 'kind' => 'token' ) );
		$this->refract->on( 'GET', '/api/v1/me', 401, array() );

		$this->assertWPError( Refract_Client::api( 'GET', 'me' ) );
		$this->assertSame( 'needs_reconnect', Connection::status() );
	}

	/**
	 * Refresh waits for a parallel refresh instead of starting another.
	 */
	public function test_refresh_lock(): void {
		$this->connect();
		add_option( Refract_Client::REFRESH_LOCK, (string) time(), '', false );
		Secrets::update( array( 'access_token' => 'access-from-other-request' ) );

		$this->assertSame( 'access-from-other-request', Refract_Client::refresh( 'access-1' ) );
		$this->assertSame( array(), $this->refract->sent( '/oauth/token' ) );
	}
}

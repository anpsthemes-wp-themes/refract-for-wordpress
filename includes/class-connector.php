<?php
/**
 * Connecting and disconnecting the site.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth (dynamic client registration and PKCE), the token method, registering the site in a workspace, and
 * disconnecting. Nothing is sent to Refract before the person clicks Connect.
 */
final class Connector {

	/**
	 * Option holding the OAuth client the site registered.
	 */
	public const CLIENT_OPTION = 'refract_oauth_client';

	/**
	 * Per-user transient prefix for the OAuth state and PKCE verifier.
	 */
	public const PENDING_PREFIX = 'refract_oauth_';

	/**
	 * Where Refract sends the person back after Allow.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin.php?page=refract&refract_connect=1' );
	}

	/**
	 * Starts connecting: registers the OAuth client when needed and returns Refract's authorize URL.
	 *
	 * @return string|WP_Error
	 */
	public static function start(): string|WP_Error {
		$client = self::ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$verifier = self::base64url( random_bytes( 48 ) );
		$state    = self::base64url( random_bytes( 24 ) );

		set_transient(
			self::PENDING_PREFIX . get_current_user_id(),
			array(
				'state'    => $state,
				'verifier' => $verifier,
			),
			30 * MINUTE_IN_SECONDS
		);

		return $client['refract_url'] . '/oauth/authorize?' . http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client['client_id'],
				'redirect_uri'          => $client['redirect_uri'],
				'scope'                 => 'api',
				'state'                 => $state,
				'code_challenge'        => self::base64url( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Handles the person coming back from Refract (`refract_connect=1` on the Settings page), then redirects to the
	 * Settings page with a notice. Visiting the URL without a code (Refract's "Site address" field) just opens Settings.
	 */
	public static function handle_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The OAuth state is checked instead of a nonce.
		if ( ! isset( $_GET['refract_connect'] ) ) {
			return;
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		if ( '' !== $code || '' !== $error ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to connect this site to Refract.', 'refract' ), '', array( 'response' => 403 ) );
			}

			$result = self::complete( $code, $state, $error );
			Admin::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : $result );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=refract' ) );
		exit;
	}

	/**
	 * Finishes the OAuth flow: checks the state, exchanges the code and connects.
	 *
	 * @param string $code  The authorization code.
	 * @param string $state The state Refract sent back.
	 * @param string $error The OAuth error, if any.
	 * @return string|WP_Error The success message.
	 */
	public static function complete( string $code, string $state, string $error ): string|WP_Error {
		$key     = self::PENDING_PREFIX . get_current_user_id();
		$pending = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $pending ) || ! is_string( $pending['state'] ?? null ) || ! is_string( $pending['verifier'] ?? null ) || '' === $state || ! hash_equals( $pending['state'], $state ) ) {
			return new WP_Error( 'refract_state', __( 'That connection link has expired. Click Connect to Refract to try again.', 'refract' ), array( 'status' => 400 ) );
		}

		if ( '' !== $error ) {
			return new WP_Error( 'refract_denied', __( 'You didn\'t allow the connection, so nothing changed.', 'refract' ), array( 'status' => 400 ) );
		}

		$client = self::oauth_client();

		if ( null === $client ) {
			return new WP_Error( 'refract_state', __( 'That connection link has expired. Click Connect to Refract to try again.', 'refract' ), array( 'status' => 400 ) );
		}

		$response = Refract_Client::send(
			'POST',
			$client['refract_url'] . '/oauth/token',
			null,
			array(),
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $client['client_id'],
				'redirect_uri'  => $client['redirect_uri'],
				'code'          => $code,
				'code_verifier' => $pending['verifier'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$snapshot = self::snapshot();
		Connection::update(
			array(
				'kind'        => 'oauth',
				'refract_url' => $client['refract_url'],
			)
		);
		$token = Refract_Client::store_tokens( $response );

		if ( is_wp_error( $token ) ) {
			self::restore( $snapshot );

			return $token;
		}

		return self::finish( 'oauth', $client['refract_url'], $snapshot );
	}

	/**
	 * Connects with a pasted API token, for sites where the redirect doesn't work.
	 *
	 * @param string $token The `rf_live_…` token.
	 * @return string|WP_Error The success message.
	 */
	public static function connect_token( string $token ): string|WP_Error {
		$token = trim( $token );

		if ( '' === $token || 1 !== preg_match( '/^[\x21-\x7E]{8,512}$/', $token ) ) {
			return new WP_Error( 'refract_invalid', __( 'Paste the whole token from Refract.', 'refract' ), array( 'status' => 422 ) );
		}

		$snapshot = self::snapshot();
		Secrets::update(
			array(
				'access_token'  => $token,
				'refresh_token' => '',
				'expires_at'    => 0,
			)
		);
		Connection::update(
			array(
				'kind'        => 'token',
				'refract_url' => Connection::default_url(),
			)
		);

		return self::finish( 'token', Connection::default_url(), $snapshot );
	}

	/**
	 * After the token is stored: reads the person and the workspaces, makes a new signing secret, and registers the
	 * site right away when there's one workspace (or the one it was in before).
	 *
	 * @param string                                   $kind        oauth|token.
	 * @param string                                   $refract_url The Refract the token belongs to.
	 * @param array{connection: mixed, secrets: mixed} $snapshot    The stored connection before this attempt.
	 * @return string|WP_Error The success message.
	 */
	private static function finish( string $kind, string $refract_url, array $snapshot ): string|WP_Error {
		$previous = Connection::get();
		Refract_Data::forget();

		$me = Refract_Client::api( 'GET', 'me' );

		$rejected = is_wp_error( $me ) ? 'refract_needs_reconnect' === $me->get_error_code() : 403 === $me->status;

		if ( $rejected ) {
			$me = new WP_Error( 'refract_invalid_token', __( 'Refract didn\'t accept that token. Check that you copied all of it and that it hasn\'t expired.', 'refract' ), array( 'status' => 422 ) );
		} elseif ( ! is_wp_error( $me ) && ! $me->ok() ) {
			$me = $me->to_error();
		}

		$workspaces = is_wp_error( $me ) ? $me : Refract_Client::api( 'GET', 'workspaces' );

		if ( ! is_wp_error( $workspaces ) && ! $workspaces->ok() ) {
			$workspaces = $workspaces->to_error();
		}

		if ( is_wp_error( $me ) || is_wp_error( $workspaces ) ) {
			self::restore( $snapshot );

			return is_wp_error( $me ) ? $me : $workspaces;
		}

		$list = array();

		foreach ( (array) ( $workspaces->body['data'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$list[] = Connection::workspace(
					array(
						'id'   => $item['id'],
						'name' => $item['attributes']['name'] ?? '',
						'slug' => $item['attributes']['slug'] ?? '',
					)
				);
			}
		}

		$list         = array_values( array_filter( $list ) );
		$attributes   = is_array( $me->body['data']['attributes'] ?? null ) ? $me->body['data']['attributes'] : array();
		$organization = is_array( $me->body['meta']['access']['organization'] ?? null ) ? $me->body['meta']['access']['organization'] : array();

		set_transient( Refract_Data::ME, $me->body, Refract_Data::TTL );
		Secrets::update( array( 'signing_secret' => Secrets::new_signing_secret() ) );
		Connection::update(
			array(
				'status'       => 'unregistered',
				'kind'         => $kind,
				'refract_url'  => $refract_url,
				'email'        => is_string( $attributes['email'] ?? null ) ? $attributes['email'] : '',
				'name'         => is_string( $attributes['name'] ?? null ) ? $attributes['name'] : '',
				'organization' => is_string( $organization['name'] ?? null ) ? $organization['name'] : '',
				'plan'         => is_string( $organization['plan'] ?? null ) ? $organization['plan'] : '',
				'workspace'    => null,
				'workspaces'   => $list,
				'account_id'   => null,
				'connected_at' => time(),
				'error'        => null,
			)
		);

		$choice = null;

		if ( 1 === count( $list ) ) {
			$choice = $list[0]['id'];
		} elseif ( null !== $previous['workspace'] && in_array( $previous['workspace']['id'], array_column( $list, 'id' ), true ) ) {
			$choice = $previous['workspace']['id'];
		}

		if ( array() === $list ) {
			Connection::update( array( 'error' => __( 'Your Refract account has no workspace this site can join. Create one in Refract, then connect again.', 'refract' ) ) );
		}

		$registered = null === $choice ? null : self::register( $choice );

		if ( is_wp_error( $registered ) ) {
			return $registered;
		}

		return null === $choice ? __( 'You\'re signed in to Refract. Choose a workspace to finish.', 'refract' ) : __( 'Your site is connected to Refract.', 'refract' );
	}

	/**
	 * Registers the site in a workspace (also "Try again" after an error). Moving to another workspace removes the
	 * site from the old one first.
	 *
	 * @param string $workspace_id The workspace id.
	 * @return true|WP_Error
	 */
	public static function register( string $workspace_id ): bool|WP_Error {
		$connection = Connection::get();
		$workspace  = null;

		foreach ( $connection['workspaces'] as $candidate ) {
			if ( $candidate['id'] === $workspace_id ) {
				$workspace = $candidate;
			}
		}

		if ( null === $workspace || ! in_array( $connection['status'], array( 'connected', 'unregistered' ), true ) ) {
			return new WP_Error( 'refract_invalid', __( 'Choose one of your workspaces.', 'refract' ), array( 'status' => 422 ) );
		}

		if ( 'connected' === $connection['status'] && null !== $connection['workspace'] && null !== $connection['account_id'] && $connection['workspace']['id'] !== $workspace_id ) {
			Refract_Client::api( 'DELETE', 'workspaces/' . rawurlencode( $connection['workspace']['id'] ) . '/accounts/wordpress/' . rawurlencode( $connection['account_id'] ) );
		}

		$secret = Secrets::get()['signing_secret'];

		if ( '' === $secret ) {
			$secret = Secrets::new_signing_secret();
			Secrets::update( array( 'signing_secret' => $secret ) );
		}

		$response = Refract_Client::api( 'POST', 'workspaces/' . rawurlencode( $workspace_id ) . '/accounts/wordpress', self::site_details( $secret ) );

		if ( ! is_wp_error( $response ) && ! $response->ok() ) {
			$response = $response->to_error();
		}

		if ( is_wp_error( $response ) ) {
			if ( 'refract_needs_reconnect' !== $response->get_error_code() ) {
				Connection::update(
					array(
						'status'    => 'unregistered',
						'workspace' => $workspace,
						'error'     => $response->get_error_message(),
					)
				);
			}

			return $response;
		}

		$returned = Connection::workspace( $response->body['workspace'] ?? null );
		Connection::update(
			array(
				'status'     => 'connected',
				'workspace'  => null === $returned ? $workspace : $returned,
				'account_id' => is_scalar( $response->body['account_id'] ?? null ) ? (string) $response->body['account_id'] : null,
				'error'      => null,
			)
		);
		Refract_Data::forget();
		Activity::log(
			'connected',
			array(
				'message' => sprintf(
					/* translators: %s: workspace name. */
					__( 'Connected to %s.', 'refract' ),
					null === $returned ? $workspace['name'] : $returned['name']
				),
			)
		);

		return true;
	}

	/**
	 * Disconnects: removes the site from Refract (which revokes the access), then forgets the connection here even
	 * when Refract can't be reached.
	 *
	 * @param bool $keep_settings Keep the settings except the accounts (Change workspace).
	 */
	public static function disconnect( bool $keep_settings = false ): void {
		$connection = Connection::get();

		if ( null !== $connection['workspace'] && null !== $connection['account_id'] && 'connected' === $connection['status'] ) {
			Refract_Client::api( 'DELETE', 'workspaces/' . rawurlencode( $connection['workspace']['id'] ) . '/accounts/wordpress/' . rawurlencode( $connection['account_id'] ) );
		} elseif ( 'oauth' === $connection['kind'] && 'unregistered' === $connection['status'] ) {
			Refract_Client::api( 'DELETE', 'me/token' );
		}

		self::forget( $keep_settings );
		Activity::log( 'disconnected', array( 'message' => __( 'Disconnected from Refract.', 'refract' ) ) );
	}

	/**
	 * Forgets the connection here, without calling Refract.
	 *
	 * @param bool $keep_settings Keep the settings except the accounts.
	 */
	public static function forget( bool $keep_settings ): void {
		Secrets::clear();
		Connection::clear();
		Refract_Data::forget();

		if ( $keep_settings ) {
			Settings::update(
				array(
					'accounts'   => array(),
					'setup_done' => false,
				)
			);
		} else {
			delete_option( Settings::OPTION );
		}
	}

	/**
	 * The stored connection and secrets, raw, so a failed connect attempt can put them back.
	 *
	 * @return array{connection: mixed, secrets: mixed}
	 */
	private static function snapshot(): array {
		return array(
			'connection' => get_option( Connection::OPTION, null ),
			'secrets'    => get_option( Secrets::OPTION, null ),
		);
	}

	/**
	 * Puts back what snapshot() saved.
	 *
	 * @param array{connection: mixed, secrets: mixed} $snapshot The saved options.
	 */
	private static function restore( array $snapshot ): void {
		$options = array(
			Connection::OPTION => $snapshot['connection'],
			Secrets::OPTION    => $snapshot['secrets'],
		);

		foreach ( $options as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
	}

	/**
	 * What Refract needs to register the site.
	 *
	 * @param string $secret The signing secret.
	 * @return array<string, string>
	 */
	public static function site_details( string $secret ): array {
		return array(
			'site_url'       => home_url(),
			'site_name'      => self::site_name(),
			'rest_url'       => get_rest_url(),
			'plugin_version' => REFRACT_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'locale'         => get_locale(),
			'timezone'       => wp_timezone_string(),
			'signing_secret' => $secret,
			'events_url'     => rest_url( Signed_Rest::NAMESPACE . '/events' ),
		);
	}

	/**
	 * The stored OAuth client, or null.
	 *
	 * @return array{client_id: string, redirect_uri: string, refract_url: string}|null
	 */
	public static function oauth_client(): ?array {
		$client = get_option( self::CLIENT_OPTION );

		if ( ! is_array( $client ) || ! is_string( $client['client_id'] ?? null ) || ! is_string( $client['redirect_uri'] ?? null ) || ! is_string( $client['refract_url'] ?? null ) ) {
			return null;
		}

		return array(
			'client_id'    => $client['client_id'],
			'redirect_uri' => $client['redirect_uri'],
			'refract_url'  => $client['refract_url'],
		);
	}

	/**
	 * The OAuth client for this site and this Refract, registered (dynamic client registration) when missing or
	 * outdated.
	 *
	 * @return array{client_id: string, redirect_uri: string, refract_url: string}|WP_Error
	 */
	private static function ensure_client(): array|WP_Error {
		$refract_url = Connection::default_url();
		$redirect    = self::redirect_uri();
		$client      = self::oauth_client();

		if ( null !== $client && $client['redirect_uri'] === $redirect && $client['refract_url'] === $refract_url ) {
			return $client;
		}

		$response = Refract_Client::send(
			'POST',
			$refract_url . '/oauth/register',
			array(
				'client_name'   => self::site_name(),
				'redirect_uris' => array( $redirect ),
				'client_uri'    => home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$client_id = $response->body['client_id'] ?? null;

		if ( ! $response->ok() || ! is_string( $client_id ) || '' === $client_id ) {
			return $response->ok() ? new WP_Error( 'refract_error', __( 'Refract sent an answer the plugin doesn\'t understand. Try again in a minute.', 'refract' ), array( 'status' => 502 ) ) : $response->to_error();
		}

		$client = array(
			'client_id'    => $client_id,
			'redirect_uri' => $redirect,
			'refract_url'  => $refract_url,
		);
		update_option( self::CLIENT_OPTION, $client, false );

		return $client;
	}

	/**
	 * The site's name, or its host when it has none.
	 */
	private static function site_name(): string {
		$name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );

		return '' === trim( $name ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : $name;
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	private static function base64url( string $bytes ): string {
		return sodium_bin2base64( $bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
	}
}

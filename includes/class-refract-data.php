<?php
/**
 * Data the plugin reads from Refract and caches for a few minutes.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * The workspace's accounts and the person's credits, cached in 5-minute transients.
 */
final class Refract_Data {

	/**
	 * Transient with the workspace's accounts.
	 */
	public const ACCOUNTS = 'refract_accounts';

	/**
	 * Transient with the `/me` document.
	 */
	public const ME = 'refract_me';

	/**
	 * How long both stay cached.
	 */
	public const TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Network names, for events that only carry the network's key. Brand names, so they aren't translated.
	 */
	private const NETWORK_LABELS = array(
		'instagram'       => 'Instagram',
		'facebook'        => 'Facebook',
		'threads'         => 'Threads',
		'tiktok'          => 'TikTok',
		'youtube'         => 'YouTube',
		'linkedin'        => 'LinkedIn',
		'x'               => 'X',
		'pinterest'       => 'Pinterest',
		'bluesky'         => 'Bluesky',
		'mastodon'        => 'Mastodon',
		'telegram'        => 'Telegram',
		'google_business' => 'Google Business Profile',
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Refract's platform key is lowercase.
		'wordpress'       => 'WordPress',
	);

	/**
	 * The workspace's accounts a version can be written for (never WordPress sites), main channel first.
	 *
	 * @return list<array{id: string, network: string, network_label: string, name: string, handle: string, main: bool, availability: string}>
	 */
	public static function accounts(): array {
		$workspace_id = Connection::workspace_id();

		if ( ! Connection::is_connected() || null === $workspace_id ) {
			return array();
		}

		$cached = get_transient( self::ACCOUNTS );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = Refract_Client::api( 'GET', 'workspaces/' . rawurlencode( $workspace_id ) . '/accounts' );

		if ( is_wp_error( $response ) || ! $response->ok() ) {
			return array();
		}

		$accounts = array();

		foreach ( (array) ( $response->body['data'] ?? array() ) as $item ) {
			$attributes = is_array( $item ) && is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array();
			$platform   = is_string( $attributes['platform'] ?? null ) ? $attributes['platform'] : '';

			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Refract's platform key is lowercase.
			if ( ! is_array( $item ) || ! isset( $item['id'] ) || '' === $platform || 'wordpress' === strtolower( $platform ) ) {
				continue;
			}

			$accounts[] = array(
				'id'            => (string) $item['id'],
				'network'       => $platform,
				'network_label' => is_string( $attributes['label'] ?? null ) ? $attributes['label'] : self::network_label( $platform ),
				'name'          => is_string( $attributes['name'] ?? null ) ? $attributes['name'] : '',
				'handle'        => is_string( $attributes['handle'] ?? null ) ? $attributes['handle'] : '',
				'main'          => true === ( $attributes['main'] ?? false ),
				'availability'  => in_array( $attributes['availability'] ?? null, array( 'available', 'beta', 'soon' ), true ) ? $attributes['availability'] : 'available',
			);
		}

		usort( $accounts, static fn ( array $a, array $b ): int => (int) $b['main'] <=> (int) $a['main'] );

		set_transient( self::ACCOUNTS, $accounts, self::TTL );

		return $accounts;
	}

	/**
	 * One account by id, or null.
	 *
	 * @param string $id Refract account id.
	 * @return array{id: string, network: string, network_label: string, name: string, handle: string, main: bool, availability: string}|null
	 */
	public static function account( string $id ): ?array {
		foreach ( self::accounts() as $account ) {
			if ( $account['id'] === $id ) {
				return $account;
			}
		}

		return null;
	}

	/**
	 * The person's credits from `/me`, or null when not connected or Refract can't be reached.
	 *
	 * @return array{left: int, refill_on: string|null, own_key: bool, buy_url: string}|null
	 */
	public static function credits(): ?array {
		$me      = self::me();
		$credits = is_array( $me ) && is_array( $me['meta']['credits'] ?? null ) ? $me['meta']['credits'] : null;

		if ( null === $credits ) {
			return null;
		}

		return array(
			'left'      => (int) ( $credits['left'] ?? 0 ),
			'refill_on' => is_string( $credits['refill_on'] ?? null ) ? $credits['refill_on'] : null,
			'own_key'   => true === ( $credits['own_key'] ?? false ),
			'buy_url'   => is_string( $credits['buy_url'] ?? null ) ? $credits['buy_url'] : '',
		);
	}

	/**
	 * The `/me` document, cached.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array<mixed>|null
	 */
	public static function me( bool $fresh = false ): ?array {
		if ( ! in_array( Connection::status(), array( 'connected', 'unregistered' ), true ) ) {
			return null;
		}

		$cached = $fresh ? false : get_transient( self::ME );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = Refract_Client::api( 'GET', 'me' );

		if ( is_wp_error( $response ) || ! $response->ok() ) {
			return null;
		}

		set_transient( self::ME, $response->body, self::TTL );

		return $response->body;
	}

	/**
	 * Drops both caches.
	 */
	public static function forget(): void {
		delete_transient( self::ACCOUNTS );
		delete_transient( self::ME );
	}

	/**
	 * "Instagram · @kolocoffee" for the Activity log.
	 *
	 * @param array<mixed> $account An account from a webhook (`{id, platform, handle, name}`).
	 */
	public static function account_label( array $account ): ?string {
		$id       = is_string( $account['id'] ?? null ) ? $account['id'] : '';
		$known    = '' === $id ? null : self::account( $id );
		$platform = is_string( $account['platform'] ?? null ) ? $account['platform'] : ( is_string( $account['network'] ?? null ) ? $account['network'] : '' );
		$label    = null === $known ? self::network_label( $platform ) : $known['network_label'];
		$handle   = is_string( $account['handle'] ?? null ) && '' !== $account['handle'] ? $account['handle'] : ( null === $known ? '' : $known['handle'] );

		if ( '' === $label ) {
			return null;
		}

		return '' === $handle ? $label : $label . ' · ' . $handle;
	}

	/**
	 * A network's name from its key ("google_business" → "Google Business Profile").
	 *
	 * @param string $platform Refract's network key.
	 */
	public static function network_label( string $platform ): string {
		if ( '' === $platform ) {
			return '';
		}

		return self::NETWORK_LABELS[ $platform ] ?? ucwords( str_replace( '_', ' ', $platform ) );
	}
}

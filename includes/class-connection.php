<?php
/**
 * What the site knows about its Refract connection.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * The plain (not secret) connection details in `refract_connection`.
 */
final class Connection {

	/**
	 * Option holding the connection.
	 */
	public const OPTION = 'refract_connection';

	/**
	 * The connection, or the empty shape when the site isn't connected.
	 *
	 * @return array{status: string, kind: string|null, refract_url: string, email: string, name: string, organization: string, plan: string, workspace: array{id: string, name: string, slug: string}|null, workspaces: list<array{id: string, name: string, slug: string}>, account_id: string|null, connected_at: int|null, error: string|null}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'status'       => is_string( $stored['status'] ?? null ) ? $stored['status'] : 'disconnected',
			'kind'         => in_array( $stored['kind'] ?? null, array( 'oauth', 'token' ), true ) ? $stored['kind'] : null,
			'refract_url'  => is_string( $stored['refract_url'] ?? null ) ? $stored['refract_url'] : self::default_url(),
			'email'        => (string) ( $stored['email'] ?? '' ),
			'name'         => (string) ( $stored['name'] ?? '' ),
			'organization' => (string) ( $stored['organization'] ?? '' ),
			'plan'         => (string) ( $stored['plan'] ?? '' ),
			'workspace'    => self::workspace( $stored['workspace'] ?? null ),
			'workspaces'   => array_values( array_filter( array_map( array( self::class, 'workspace' ), (array) ( $stored['workspaces'] ?? array() ) ) ) ),
			'account_id'   => is_string( $stored['account_id'] ?? null ) ? $stored['account_id'] : null,
			'connected_at' => isset( $stored['connected_at'] ) ? (int) $stored['connected_at'] : null,
			'error'        => is_string( $stored['error'] ?? null ) ? $stored['error'] : null,
		);
	}

	/**
	 * Changes some connection details and keeps the others.
	 *
	 * @param array<string, mixed> $changes The details to change.
	 */
	public static function update( array $changes ): void {
		update_option( self::OPTION, array_merge( self::get(), $changes ), false );
	}

	/**
	 * Forgets the connection.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * `disconnected`, `connected`, `needs_reconnect` or `unregistered`.
	 */
	public static function status(): string {
		return self::get()['status'];
	}

	/**
	 * Whether the site is registered in a workspace and its access works.
	 */
	public static function is_connected(): bool {
		return 'connected' === self::status();
	}

	/**
	 * Marks the connection as needing a reconnect (the refresh token or the API token was rejected).
	 */
	public static function mark_needs_reconnect(): void {
		if ( 'disconnected' !== self::status() ) {
			self::update( array( 'status' => 'needs_reconnect' ) );
		}
	}

	/**
	 * The Refract this site talks to, without a trailing slash.
	 */
	public static function refract_url(): string {
		return untrailingslashit( self::get()['refract_url'] );
	}

	/**
	 * The Refract URL from the REFRACT_URL constant.
	 */
	public static function default_url(): string {
		return untrailingslashit( (string) REFRACT_URL );
	}

	/**
	 * The workspace id the site is in, or null.
	 */
	public static function workspace_id(): ?string {
		$workspace = self::get()['workspace'];

		return null === $workspace ? null : $workspace['id'];
	}

	/**
	 * Normalizes a stored workspace to `{id, name, slug}`.
	 *
	 * @param mixed $workspace The stored value.
	 * @return array{id: string, name: string, slug: string}|null
	 */
	public static function workspace( mixed $workspace ): ?array {
		if ( ! is_array( $workspace ) || ! isset( $workspace['id'] ) || ! is_scalar( $workspace['id'] ) ) {
			return null;
		}

		return array(
			'id'   => (string) $workspace['id'],
			'name' => is_scalar( $workspace['name'] ?? null ) ? (string) $workspace['name'] : '',
			'slug' => is_scalar( $workspace['slug'] ?? null ) ? (string) $workspace['slug'] : '',
		);
	}
}

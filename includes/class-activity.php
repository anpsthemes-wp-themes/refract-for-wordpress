<?php
/**
 * The Activity log.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * What happened between the site and Refract, newest first, kept 30 days (at most 500 entries) in one option.
 */
final class Activity {

	/**
	 * Option holding the entries.
	 */
	public const OPTION = 'refract_activity';

	/**
	 * Entries older than this are dropped.
	 */
	public const KEEP_SECONDS = 30 * DAY_IN_SECONDS;

	/**
	 * At most this many entries are kept.
	 */
	public const MAX_ENTRIES = 500;

	/**
	 * Adds the daily prune.
	 */
	public static function register_hooks(): void {
		add_action( Plugin::CRON_PRUNE, array( self::class, 'prune' ) );
	}

	/**
	 * Adds an entry.
	 *
	 * @param string               $event  sent|updated|versions_ready|approved|queued|posted|failed|send_failed|out_of_credits|received|removed|connected|disconnected.
	 * @param array<string, mixed> $fields post_id, account, ok, message, link.
	 */
	public static function log( string $event, array $fields = array() ): void {
		$post_id = isset( $fields['post_id'] ) ? (int) $fields['post_id'] : null;
		$entry   = array(
			'id'      => wp_generate_uuid4(),
			'time'    => time(),
			'post_id' => $post_id,
			'event'   => $event,
			'account' => isset( $fields['account'] ) && is_string( $fields['account'] ) ? $fields['account'] : null,
			'ok'      => (bool) ( $fields['ok'] ?? true ),
			'message' => isset( $fields['message'] ) && is_string( $fields['message'] ) ? $fields['message'] : '',
			'link'    => isset( $fields['link'] ) && is_string( $fields['link'] ) && '' !== $fields['link'] ? $fields['link'] : null,
		);

		$entries = self::stored();
		array_unshift( $entries, $entry );
		self::save( $entries );
	}

	/**
	 * The newest entries, with the post's current title and edit link.
	 *
	 * @param bool $problems_only Only entries that weren't OK.
	 * @param int  $limit         How many.
	 * @return list<array<string, mixed>>
	 */
	public static function entries( bool $problems_only = false, int $limit = 100 ): array {
		$entries = array();

		foreach ( self::stored() as $entry ) {
			if ( $problems_only && $entry['ok'] ) {
				continue;
			}

			$post = null === $entry['post_id'] ? null : get_post( (int) $entry['post_id'] );

			$entries[] = array(
				'id'         => $entry['id'],
				'time'       => $entry['time'],
				'post_id'    => $entry['post_id'],
				'post_title' => null === $post ? '' : html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'post_url'   => null === $post ? null : get_edit_post_link( $post, 'raw' ),
				'event'      => $entry['event'],
				'account'    => $entry['account'],
				'ok'         => $entry['ok'],
				'message'    => $entry['message'],
				'link'       => $entry['link'],
			);

			if ( count( $entries ) >= $limit ) {
				break;
			}
		}

		return $entries;
	}

	/**
	 * Drops entries older than 30 days.
	 */
	public static function prune(): void {
		self::save( self::stored() );
	}

	/**
	 * The stored entries.
	 *
	 * @return list<array{id: string, time: int, post_id: int|null, event: string, account: string|null, ok: bool, message: string, link: string|null}>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Saves entries, keeping the last 30 days and at most 500.
	 *
	 * @param list<array{id: string, time: int, post_id: int|null, event: string, account: string|null, ok: bool, message: string, link: string|null}> $entries Newest first.
	 */
	private static function save( array $entries ): void {
		$since   = time() - self::KEEP_SECONDS;
		$entries = array_values( array_filter( $entries, static fn ( array $entry ): bool => $entry['time'] >= $since ) );

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}
}

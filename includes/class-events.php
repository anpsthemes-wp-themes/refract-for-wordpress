<?php
/**
 * Webhook events from Refract.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * `POST refract/v1/events`: updates the post's meta (so the editor refreshes) and the Activity log. Unknown types are
 * ignored.
 */
final class Events {

	/**
	 * Handles one event.
	 *
	 * @param WP_REST_Request $request The signed request.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$event = (array) $request->get_json_params();
		$type  = is_string( $event['type'] ?? null ) ? $event['type'] : '';
		$data  = is_array( $event['data'] ?? null ) ? $event['data'] : array();

		if ( 'account.disconnected' === $type ) {
			self::account_disconnected( is_array( $data['account'] ?? null ) ? $data['account'] : array() );
		} elseif ( str_starts_with( $type, 'post.' ) ) {
			self::post_event( $type, $data );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * A `post.*` event about a post this site sent.
	 *
	 * @param string       $type The event type.
	 * @param array<mixed> $data The event data.
	 */
	private static function post_event( string $type, array $data ): void {
		$remote  = is_array( $data['post'] ?? null ) ? $data['post'] : array();
		$post_id = self::local_post( $remote );

		if ( 0 === $post_id ) {
			return;
		}

		update_post_meta( $post_id, '_refract_event_at', time() );

		switch ( $type ) {
			case 'post.versions_ready':
				Activity::log(
					'versions_ready',
					array(
						'post_id' => $post_id,
						'message' => __( 'The versions are ready for your OK.', 'refract' ),
					)
				);
				break;

			case 'post.version_approved':
				$version  = is_array( $data['version'] ?? null ) ? $data['version'] : array();
				$approver = is_array( $data['approved_by'] ?? null ) && is_string( $data['approved_by']['name'] ?? null ) ? $data['approved_by']['name'] : '';
				Activity::log(
					'approved',
					array(
						'post_id' => $post_id,
						'account' => Refract_Data::account_label( array( 'network' => $version['network'] ?? '' ) ),
						/* translators: %s: name of the person who approved. */
						'message' => '' === $approver ? __( 'A version was approved.', 'refract' ) : sprintf( __( '%s approved a version.', 'refract' ), $approver ),
					)
				);
				break;

			case 'post.queued':
				foreach ( (array) ( $data['accounts'] ?? array() ) as $item ) {
					$account = is_array( $item ) && is_array( $item['account'] ?? null ) ? $item['account'] : array();
					$when    = is_array( $item ) && is_string( $item['scheduled_for'] ?? null ) ? strtotime( $item['scheduled_for'] ) : false;
					Activity::log(
						'queued',
						array(
							'post_id' => $post_id,
							'account' => Refract_Data::account_label( $account ),
							'message' => false === $when
								? __( 'Added to the queue.', 'refract' )
								/* translators: %s: date and time. */
								: sprintf( __( 'Scheduled for %s.', 'refract' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) ),
						)
					);
				}
				break;

			case 'post.published':
				$clicks_link = is_string( $data['short_link'] ?? null ) ? $data['short_link'] : null;
				Activity::log(
					'posted',
					array(
						'post_id' => $post_id,
						'account' => Refract_Data::account_label( is_array( $data['account'] ?? null ) ? $data['account'] : array() ),
						'message' => __( 'Posted.', 'refract' ),
						'link'    => is_string( $data['permalink'] ?? null ) ? $data['permalink'] : $clicks_link,
					)
				);
				break;

			case 'post.failed':
				$error   = is_array( $data['error'] ?? null ) ? $data['error'] : array();
				$account = Refract_Data::account_label( is_array( $data['account'] ?? null ) ? $data['account'] : array() );
				$message = is_string( $error['message'] ?? null ) ? $error['message'] : __( 'The post couldn\'t go out.', 'refract' );
				$fix     = is_string( $error['fix'] ?? null ) ? $error['fix'] : '';
				$text    = trim( $message . ' ' . $fix );

				update_post_meta(
					$post_id,
					'_refract_notice',
					null === $account
						/* translators: %s: what went wrong and how to fix it. */
						? sprintf( __( 'Refract couldn\'t post this. %s', 'refract' ), $text )
						/* translators: 1: account, for example "Instagram · @kolocoffee", 2: what went wrong and how to fix it. */
						: sprintf( __( 'Refract couldn\'t post this to %1$s. %2$s', 'refract' ), $account, $text )
				);
				Activity::log(
					'failed',
					array(
						'post_id' => $post_id,
						'account' => $account,
						'ok'      => false,
						'message' => $text,
					)
				);
				break;
		}
	}

	/**
	 * `account.disconnected`: forgets an account removed in Refract. When it's this site's own account, the site is
	 * disconnected here too (Refract already revoked the access).
	 *
	 * @param array<mixed> $account The account (`{id, platform, handle, name}`).
	 */
	private static function account_disconnected( array $account ): void {
		$id = is_scalar( $account['id'] ?? null ) ? (string) $account['id'] : '';

		if ( '' === $id ) {
			return;
		}

		$label = Refract_Data::account_label( $account );

		if ( Connection::get()['account_id'] === $id ) {
			Connector::forget( true );
			Activity::log(
				'disconnected',
				array(
					'ok'      => false,
					'message' => __( 'This site was removed from Refract. Connect again to keep sending posts.', 'refract' ),
				)
			);

			return;
		}

		$settings = Settings::get();

		if ( in_array( $id, $settings['accounts'], true ) ) {
			Settings::update( array( 'accounts' => array_values( array_diff( $settings['accounts'], array( $id ) ) ) ) );
		}

		delete_transient( Refract_Data::ACCOUNTS );
		Activity::log(
			'disconnected',
			array(
				'account' => $label,
				'message' => __( 'The account was disconnected in Refract, so it no longer gets versions.', 'refract' ),
			)
		);
	}

	/**
	 * The local post an event is about: the source's external id when the source is this site, else the post that
	 * stores Refract's post id.
	 *
	 * @param array<mixed> $remote The event's `post`.
	 */
	private static function local_post( array $remote ): int {
		$remote_id = is_scalar( $remote['id'] ?? null ) ? (string) $remote['id'] : '';
		$source    = is_array( $remote['source'] ?? null ) ? $remote['source'] : array();
		$own       = Connection::get()['account_id'];

		if ( null !== $own && ( $source['account_id'] ?? null ) === $own && is_scalar( $source['external_id'] ?? null ) ) {
			$post = get_post( (int) $source['external_id'] );

			if ( null !== $post ) {
				$stored = (string) get_post_meta( $post->ID, '_refract_post_id', true );

				if ( '' === $stored && '' !== $remote_id ) {
					update_post_meta( $post->ID, '_refract_post_id', $remote_id );
				}

				if ( '' === $stored || $stored === $remote_id ) {
					return $post->ID;
				}
			}
		}

		if ( '' === $remote_id ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_refract_post_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs only for Refract's events.
				'meta_value'     => $remote_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Runs only for Refract's events.
			)
		);

		return array() === $ids ? 0 : (int) $ids[0];
	}
}

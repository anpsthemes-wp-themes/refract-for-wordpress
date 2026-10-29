<?php
/**
 * What the editor panel shows for one post.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the PostPanel document: the post's send state and, once sent, its versions read live from Refract.
 */
final class Post_Panel {

	/**
	 * The panel for a post.
	 *
	 * @param WP_Post                   $post     The post.
	 * @param array<string, mixed>|null $versions A versions document Refract just returned, to skip fetching it again.
	 * @return array<string, mixed>
	 */
	public static function build( WP_Post $post, ?array $versions = null ): array {
		$settings     = Settings::get();
		$connected    = Connection::is_connected();
		$from_refract = Sender::from_refract( $post->ID );
		$status       = (string) get_post_meta( $post->ID, '_refract_status', true );
		$chosen       = get_post_meta( $post->ID, '_refract_accounts', true );
		$credits      = $connected ? Refract_Data::credits() : null;
		$remote_id    = (string) get_post_meta( $post->ID, '_refract_post_id', true );
		$sent_at      = (int) get_post_meta( $post->ID, '_refract_sent_at', true );
		$event_at     = (int) get_post_meta( $post->ID, '_refract_event_at', true );
		$error        = (string) get_post_meta( $post->ID, '_refract_error', true );
		$buy_url      = (string) get_post_meta( $post->ID, '_refract_buy_url', true );
		$notice       = (string) get_post_meta( $post->ID, '_refract_notice', true );
		$refract      = null;
		$fetch_error  = null;

		if ( $from_refract ) {
			$state = 'from_refract';
		} elseif ( in_array( $status, array( 'queued', 'sent', 'retrying', 'failed', 'out_of_credits' ), true ) ) {
			$state = $status;
		} else {
			$state = '' === $remote_id ? 'not_sent' : 'sent';
		}

		if ( $connected && '' !== $remote_id && ! $from_refract ) {
			if ( null === $versions ) {
				$response = Refract_Client::api( 'GET', 'workspaces/' . rawurlencode( (string) Connection::workspace_id() ) . '/posts/' . rawurlencode( $remote_id ) . '/versions' );

				if ( is_wp_error( $response ) ) {
					$fetch_error = $response->get_error_message();
				} elseif ( ! $response->ok() ) {
					$fetch_error = $response->error_message();
				} else {
					$versions = $response->body;
				}
			}

			$refract = $versions;
		}

		return array(
			'connected'     => $connected,
			'enabled'       => in_array( $post->post_type, $settings['post_types'], true ),
			'state'         => $state,
			'send_mode'     => $settings['send_mode'],
			'default_send'  => 'publish' === $settings['send_mode'],
			'can_send_now'  => $connected && ! $from_refract && in_array( $post->post_status, array( 'publish', 'future' ), true ) && '0' !== (string) get_post_meta( $post->ID, '_refract_send', true ),
			'accounts'      => $connected ? Refract_Data::accounts() : array(),
			'selected'      => is_array( $chosen ) && array() !== $chosen ? Settings::sanitize_account_ids( $chosen ) : $settings['accounts'],
			'write_mode'    => $settings['write_mode'],
			'own_key'       => null !== $credits && $credits['own_key'],
			'fallback'      => '1' === get_post_meta( $post->ID, '_refract_fallback', true ),
			'error'         => '' === $error || in_array( $state, array( 'sent', 'not_sent', 'queued' ), true ) ? null : $error,
			'buy_url'       => '' === $buy_url ? null : $buy_url,
			'needed'        => 'out_of_credits' === $state ? (int) get_post_meta( $post->ID, '_refract_needed', true ) : null,
			'left'          => 'out_of_credits' === $state ? (int) get_post_meta( $post->ID, '_refract_left', true ) : null,
			'sent_at'       => $sent_at > 0 ? $sent_at : null,
			'event_at'      => $event_at > 0 ? $event_at : null,
			'refract'       => $refract,
			'refract_error' => $fetch_error,
			'notice'        => '' === $notice ? null : $notice,
		);
	}
}

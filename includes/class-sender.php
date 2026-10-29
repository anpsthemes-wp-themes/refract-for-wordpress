<?php
/**
 * Sending posts to Refract.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Error;
use WP_HTML_Tag_Processor;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a published post into a note in Refract. Publishing never waits for Refract: the hook only schedules a cron
 * event a few seconds later, once per post, and the event does the sending, with retries after 1, 5 and 15 minutes.
 */
final class Sender {

	/**
	 * Seconds after publishing before the post is sent.
	 */
	public const DELAY = 5;

	/**
	 * Seconds the per-post lock lasts.
	 */
	public const LOCK_SECONDS = 60;

	/**
	 * Waits before each retry.
	 */
	public const RETRY_DELAYS = array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS );

	/**
	 * At most this many content images, besides the featured image.
	 */
	public const MAX_CONTENT_IMAGES = 9;

	/**
	 * The longest side of the images sent.
	 */
	public const MAX_IMAGE_SIZE = 2048;

	/**
	 * Adds the publish hook and the cron handler.
	 */
	public static function register_hooks(): void {
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( Plugin::CRON_SEND, array( self::class, 'run' ), 10, 2 );
	}

	/**
	 * Schedules sending when a post is published, or when a sent post is updated and updates are on.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       The post.
	 */
	public static function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( self::from_refract( $post->ID ) || ! Connection::is_connected() || ! Settings::post_type_enabled( $post->post_type ) ) {
			return;
		}

		if ( self::was_sent( $post->ID ) ) {
			if ( 'publish' === $old_status && 'update' === Settings::get()['on_update'] ) {
				self::schedule( $post->ID );
			}

			return;
		}

		if ( 'publish' !== $old_status ) {
			self::schedule( $post->ID );
		}
	}

	/**
	 * Schedules one send, unless one is already scheduled or running for the post.
	 *
	 * @param int    $post_id    The post id.
	 * @param string $start_from '' (settings), `note` or `finished`.
	 * @return bool Whether it was scheduled.
	 */
	public static function schedule( int $post_id, string $start_from = '' ): bool {
		$lock = self::lock_key( $post_id );

		if ( false !== get_transient( $lock ) || false !== wp_next_scheduled( Plugin::CRON_SEND, array( $post_id, $start_from ) ) ) {
			return false;
		}

		set_transient( $lock, 'scheduled', self::LOCK_SECONDS );
		wp_schedule_single_event( time() + self::DELAY, Plugin::CRON_SEND, array( $post_id, $start_from ) );

		if ( ! self::was_sent( $post_id ) ) {
			update_post_meta( $post_id, '_refract_status', 'queued' );
		}

		return true;
	}

	/**
	 * Cron handler: sends the post, then releases its lock.
	 *
	 * @param int|string $post_id    The post id.
	 * @param string     $start_from '' (settings), `note` or `finished`.
	 */
	public static function run( int|string $post_id, string $start_from = '' ): void {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		set_transient( self::lock_key( $post->ID ), 'running', self::LOCK_SECONDS );

		try {
			self::send( $post, $start_from, false );
		} finally {
			delete_transient( self::lock_key( $post->ID ) );
		}
	}

	/**
	 * Sends a post now. Automatic sends re-check everything; "Send to Refract" in the editor (`$manual`) skips the
	 * post's switch and the category filter. The outcome is stored in the post's meta.
	 *
	 * @param WP_Post $post       The post.
	 * @param string  $start_from '' (settings), `note` or `finished`.
	 * @param bool    $manual     Whether the person clicked "Send to Refract".
	 * @return true|WP_Error An error only when the post can't be sent at all (manual sends).
	 */
	public static function send( WP_Post $post, string $start_from, bool $manual ): bool|WP_Error {
		$error = self::check( $post, $manual );

		if ( is_wp_error( $error ) ) {
			if ( ! $manual && 'queued' === get_post_meta( $post->ID, '_refract_status', true ) ) {
				delete_post_meta( $post->ID, '_refract_status' );
			}

			return $error;
		}

		$settings = Settings::get();

		if ( self::was_sent( $post->ID ) ) {
			if ( ! $manual && 'update' !== $settings['on_update'] ) {
				return true;
			}

			self::update_note( $post );

			return true;
		}

		$status = (string) get_post_meta( $post->ID, '_refract_status', true );

		if ( ! $manual && 'retrying' !== $status && ! self::wanted( $post ) ) {
			delete_post_meta( $post->ID, '_refract_status' );

			return true;
		}

		self::create_note( $post, in_array( $start_from, array( 'note', 'finished' ), true ) ? $start_from : $settings['write_mode'], $start_from, $manual );

		return true;
	}

	/**
	 * Whether a post can be sent at all.
	 *
	 * @param WP_Post $post   The post.
	 * @param bool    $manual Whether the person clicked "Send to Refract".
	 * @return true|WP_Error
	 */
	private static function check( WP_Post $post, bool $manual ): bool|WP_Error {
		if ( self::from_refract( $post->ID ) ) {
			return new WP_Error( 'refract_from_refract', __( 'This post came from Refract, so it isn\'t sent back.', 'refract' ), array( 'status' => 409 ) );
		}

		if ( ! Connection::is_connected() ) {
			if ( 'needs_reconnect' === Connection::status() ) {
				return Refract_Client::reconnect_error();
			}

			return new WP_Error( 'refract_not_connected', __( 'Connect your site to Refract first.', 'refract' ), array( 'status' => 409 ) );
		}

		if ( ! Settings::post_type_enabled( $post->post_type ) ) {
			return new WP_Error( 'refract_disabled', __( 'Refract isn\'t on for this type of post. Turn it on in Refract › Settings.', 'refract' ), array( 'status' => 409 ) );
		}

		$allowed = $manual ? array( 'publish', 'future' ) : array( 'publish' );

		if ( ! in_array( $post->post_status, $allowed, true ) ) {
			return new WP_Error( 'refract_not_published', __( 'Publish the post first. Each version links to it.', 'refract' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Whether an automatic send wants this post: its switch (or the site default) and the category filter.
	 *
	 * @param WP_Post $post The post.
	 */
	public static function wanted( WP_Post $post ): bool {
		$settings = Settings::get();
		$switch   = (string) get_post_meta( $post->ID, '_refract_send', true );

		if ( '0' === $switch || ( '' === $switch && 'publish' !== $settings['send_mode'] ) ) {
			return false;
		}

		if ( array() !== $settings['categories'] && is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			$categories = wp_get_post_categories( $post->ID );

			if ( ! is_array( $categories ) || array() === array_intersect( $settings['categories'], array_map( 'intval', $categories ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Creates the note in Refract.
	 *
	 * @param WP_Post $post       The post.
	 * @param string  $start_from `note` or `finished`.
	 * @param string  $override   The cron argument, kept for retries.
	 * @param bool    $manual     Whether the person clicked "Send to Refract".
	 */
	private static function create_note( WP_Post $post, string $start_from, string $override, bool $manual ): void {
		$accounts = self::accounts( $post );

		if ( array() === $accounts ) {
			self::fail( $post, __( 'Choose which accounts get a version in Refract › Settings.', 'refract' ) );

			return;
		}

		$source   = self::source( $post );
		$response = Refract_Client::api(
			'POST',
			'workspaces/' . rawurlencode( (string) Connection::workspace_id() ) . '/notes',
			array(
				'source'     => $source,
				'start_from' => $start_from,
				'note'       => self::note(),
				'accounts'   => $accounts,
				'media'      => self::media( $post ),
				'images'     => Settings::get()['images'],
				'write'      => true,
			),
			array( 'Idempotency-Key' => self::idempotency_key( $post ) )
		);

		if ( is_wp_error( $response ) ) {
			self::retry_or_fail( $post, $response, $override );

			return;
		}

		if ( $response->ok() ) {
			self::sent( $post, $response, $source, 'sent', 'finished' === $start_from ? __( 'Sent to Refract as the same post for every account.', 'refract' ) : __( 'Sent to Refract. The versions are being written.', 'refract' ) );

			return;
		}

		if ( 402 === $response->status ) {
			if ( ! $manual && 'note' === $start_from ) {
				update_post_meta( $post->ID, '_refract_fallback', '1' );
				Activity::log(
					'out_of_credits',
					array(
						'post_id' => $post->ID,
						'ok'      => true,
						'message' => __( 'You were out of AI credits, so Refract got the same post for every account.', 'refract' ),
						'link'    => is_string( $response->body['buy_url'] ?? null ) ? $response->body['buy_url'] : null,
					)
				);
				self::create_note( $post, 'finished', 'finished', false );

				return;
			}

			self::out_of_credits( $post, $response );

			return;
		}

		self::handle_failure( $post, $response, $override );
	}

	/**
	 * Sends the changed post to Refract (the "note changed" flow), only when the title, excerpt or text changed.
	 *
	 * @param WP_Post $post The post.
	 */
	private static function update_note( WP_Post $post ): void {
		$source = self::source( $post );

		if ( self::hash( $source ) === get_post_meta( $post->ID, '_refract_hash', true ) ) {
			if ( 'retrying' === get_post_meta( $post->ID, '_refract_status', true ) ) {
				update_post_meta( $post->ID, '_refract_status', 'sent' );
			}

			return;
		}

		$response = Refract_Client::api(
			'PATCH',
			'workspaces/' . rawurlencode( (string) Connection::workspace_id() ) . '/notes/' . rawurlencode( (string) get_post_meta( $post->ID, '_refract_post_id', true ) ),
			array(
				'source' => $source,
				'note'   => self::note(),
			),
			array( 'Idempotency-Key' => self::idempotency_key( $post ) )
		);

		if ( is_wp_error( $response ) ) {
			self::retry_or_fail( $post, $response, '' );

			return;
		}

		if ( $response->ok() ) {
			self::sent( $post, $response, $source, 'updated', __( 'Sent the changes to Refract.', 'refract' ) );

			return;
		}

		if ( 402 === $response->status || 409 === $response->status ) {
			// The post already went out (409), or rewriting needs credits (402): keep what Refract has, don't retry.
			update_post_meta( $post->ID, '_refract_hash', self::hash( $source ) );
			update_post_meta( $post->ID, '_refract_status', 'sent' );
			delete_post_meta( $post->ID, '_refract_attempts' );
			Activity::log(
				402 === $response->status ? 'out_of_credits' : 'updated',
				array(
					'post_id' => $post->ID,
					'ok'      => false,
					'message' => $response->error_message(),
				)
			);

			return;
		}

		self::handle_failure( $post, $response, '' );
	}

	/**
	 * Stores a successful send.
	 *
	 * @param WP_Post               $post     The post.
	 * @param Refract_Response      $response Refract's 202.
	 * @param array<string, string> $source   What was sent.
	 * @param string                $event    sent|updated.
	 * @param string                $message  Activity text.
	 */
	private static function sent( WP_Post $post, Refract_Response $response, array $source, string $event, string $message ): void {
		$remote_id = $response->body['post_id'] ?? null;

		if ( is_scalar( $remote_id ) && '' !== (string) $remote_id ) {
			update_post_meta( $post->ID, '_refract_post_id', (string) $remote_id );
		}

		update_post_meta( $post->ID, '_refract_sent_at', time() );
		update_post_meta( $post->ID, '_refract_status', 'sent' );
		update_post_meta( $post->ID, '_refract_hash', self::hash( $source ) );

		foreach ( array( '_refract_error', '_refract_buy_url', '_refract_needed', '_refract_left', '_refract_attempts', '_refract_notice' ) as $key ) {
			delete_post_meta( $post->ID, $key );
		}

		Activity::log(
			$event,
			array(
				'post_id' => $post->ID,
				'message' => $message,
				'link'    => is_string( $response->body['edit_url'] ?? null ) ? $response->body['edit_url'] : null,
			)
		);
	}

	/**
	 * Stores a 402: out of credits, with Refract's buy link.
	 *
	 * @param WP_Post          $post     The post.
	 * @param Refract_Response $response Refract's 402.
	 */
	private static function out_of_credits( WP_Post $post, Refract_Response $response ): void {
		$needed  = (int) ( $response->body['needed'] ?? 0 );
		$left    = (int) ( $response->body['left'] ?? 0 );
		$buy_url = is_string( $response->body['buy_url'] ?? null ) ? $response->body['buy_url'] : '';
		/* translators: 1: credits the post needs, 2: credits left. */
		$message = sprintf( __( 'You\'re out of AI credits for this post. It needs %1$d and you have %2$d.', 'refract' ), $needed, $left );

		update_post_meta( $post->ID, '_refract_status', 'out_of_credits' );
		update_post_meta( $post->ID, '_refract_error', $message );
		update_post_meta( $post->ID, '_refract_buy_url', $buy_url );
		update_post_meta( $post->ID, '_refract_needed', $needed );
		update_post_meta( $post->ID, '_refract_left', $left );
		delete_post_meta( $post->ID, '_refract_attempts' );

		Activity::log(
			'out_of_credits',
			array(
				'post_id' => $post->ID,
				'ok'      => false,
				'message' => $message,
				'link'    => '' === $buy_url ? null : $buy_url,
			)
		);
	}

	/**
	 * Retries a temporary failure (timeouts, 408, 429, 5xx); fails at once otherwise.
	 *
	 * @param WP_Post          $post     The post.
	 * @param Refract_Response $response Refract's answer.
	 * @param string           $override The cron argument, kept for retries.
	 */
	private static function handle_failure( WP_Post $post, Refract_Response $response, string $override ): void {
		if ( in_array( $response->status, array( 408, 429 ), true ) || $response->status >= 500 ) {
			self::retry_or_fail( $post, $response->to_error(), $override );

			return;
		}

		self::fail( $post, $response->error_message() );
	}

	/**
	 * Schedules the next retry (after 1, 5 and 15 minutes), then gives up.
	 *
	 * @param WP_Post  $post     The post.
	 * @param WP_Error $error    What went wrong.
	 * @param string   $override The cron argument, kept for retries.
	 */
	private static function retry_or_fail( WP_Post $post, WP_Error $error, string $override ): void {
		if ( in_array( $error->get_error_code(), array( 'refract_needs_reconnect', 'refract_not_connected' ), true ) ) {
			self::fail( $post, $error->get_error_message() );

			return;
		}

		$attempts = (int) get_post_meta( $post->ID, '_refract_attempts', true ) + 1;

		if ( $attempts > count( self::RETRY_DELAYS ) ) {
			self::fail( $post, $error->get_error_message() );

			return;
		}

		update_post_meta( $post->ID, '_refract_attempts', $attempts );
		update_post_meta( $post->ID, '_refract_status', 'retrying' );
		update_post_meta( $post->ID, '_refract_error', $error->get_error_message() );
		wp_schedule_single_event( time() + self::RETRY_DELAYS[ $attempts - 1 ], Plugin::CRON_SEND, array( $post->ID, $override ) );
	}

	/**
	 * Stores a failure and logs it.
	 *
	 * @param WP_Post $post    The post.
	 * @param string  $message What went wrong.
	 */
	private static function fail( WP_Post $post, string $message ): void {
		update_post_meta( $post->ID, '_refract_status', 'failed' );
		update_post_meta( $post->ID, '_refract_error', $message );
		delete_post_meta( $post->ID, '_refract_attempts' );

		Activity::log(
			'send_failed',
			array(
				'post_id' => $post->ID,
				'ok'      => false,
				'message' => $message,
			)
		);
	}

	/**
	 * The accounts this post goes to: the post's own choice, or the site's.
	 *
	 * @param WP_Post $post The post.
	 * @return list<string>
	 */
	public static function accounts( WP_Post $post ): array {
		$chosen   = get_post_meta( $post->ID, '_refract_accounts', true );
		$accounts = is_array( $chosen ) && array() !== $chosen ? Settings::sanitize_account_ids( $chosen ) : Settings::get()['accounts'];
		$own      = Connection::get()['account_id'];

		return array_values( array_filter( $accounts, static fn ( string $id ): bool => $id !== $own ) );
	}

	/**
	 * The `source` object: the site account, the post's id, link, title, excerpt and plain text.
	 *
	 * @param WP_Post $post The post.
	 * @return array<string, string>
	 */
	public static function source( WP_Post $post ): array {
		$text = self::text( $post );

		return array(
			'type'        => 'wordpress',
			'account_id'  => (string) Connection::get()['account_id'],
			'external_id' => (string) $post->ID,
			'url'         => (string) get_permalink( $post ),
			'title'       => self::plain( $post->post_title ),
			'excerpt'     => self::excerpt( $post, $text ),
			'text'        => $text,
		);
	}

	/**
	 * The note: empty fields, so Refract fills them from the source (and handles the link).
	 *
	 * @return array<string, string>
	 */
	private static function note(): array {
		return array(
			'point'  => '',
			'detail' => '',
			'todo'   => '',
		);
	}

	/**
	 * The post's text: blocks rendered, tags stripped, paragraphs kept.
	 *
	 * @param WP_Post $post The post.
	 */
	public static function text( WP_Post $post ): string {
		$html = do_blocks( $post->post_content );
		$html = (string) preg_replace( '#</(p|h[1-6]|li|blockquote|div|figure|figcaption|pre|ul|ol|table|tr)>|<br\s*/?>#i', "$0\n", $html );
		$text = self::plain( wp_strip_all_tags( strip_shortcodes( $html ) ) );
		$text = (string) preg_replace( "/[ \t]+/u", ' ', $text );
		$text = (string) preg_replace( "/ *\n */u", "\n", $text );

		return trim( (string) preg_replace( "/\n{3,}/u", "\n\n", $text ) );
	}

	/**
	 * The excerpt, or the first two sentences of the text.
	 *
	 * @param WP_Post $post The post.
	 * @param string  $text The post's plain text.
	 */
	public static function excerpt( WP_Post $post, string $text ): string {
		$excerpt = trim( self::plain( wp_strip_all_tags( $post->post_excerpt ) ) );

		if ( '' !== $excerpt ) {
			return $excerpt;
		}

		$sentences = preg_split( '/(?<=[.!?…])\s+/u', trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 3 );

		return is_array( $sentences ) ? trim( implode( ' ', array_slice( $sentences, 0, 2 ) ) ) : '';
	}

	/**
	 * The featured image and up to 9 content images, at the largest size up to 2048 px, with alt text.
	 *
	 * @param WP_Post $post The post.
	 * @return list<array{url: string, alt: string, role: string}>
	 */
	public static function media( WP_Post $post ): array {
		$media = array();
		$seen  = array();
		$thumb = (int) get_post_thumbnail_id( $post );

		if ( $thumb > 0 ) {
			$image = self::attachment_image( $thumb );

			if ( null !== $image ) {
				$media[]               = $image + array( 'role' => 'featured' );
				$seen[ $image['url'] ] = true;
			}
		}

		$tags    = new WP_HTML_Tag_Processor( do_blocks( $post->post_content ) );
		$content = 0;

		while ( $content < self::MAX_CONTENT_IMAGES && $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
			$class = $tags->get_attribute( 'class' );
			$image = null;

			if ( is_string( $class ) && 1 === preg_match( '/\bwp-image-(\d+)\b/', $class, $match ) ) {
				$image = self::attachment_image( (int) $match[1] );
				$alt   = $tags->get_attribute( 'alt' );

				if ( null !== $image && is_string( $alt ) && '' !== trim( $alt ) ) {
					$image['alt'] = trim( $alt );
				}
			}

			if ( null === $image ) {
				$src   = $tags->get_attribute( 'src' );
				$alt   = $tags->get_attribute( 'alt' );
				$image = is_string( $src ) && 1 === preg_match( '#^https?://#i', $src ) ? array(
					'url' => $src,
					'alt' => is_string( $alt ) ? $alt : '',
				) : null;
			}

			if ( null === $image || isset( $seen[ $image['url'] ] ) ) {
				continue;
			}

			$seen[ $image['url'] ] = true;
			$media[]               = $image + array( 'role' => 'content' );
			++$content;
		}

		return $media;
	}

	/**
	 * An image attachment at the largest size whose longest side is at most 2048 px, with its alt text.
	 *
	 * @param int $attachment_id The attachment id.
	 * @return array{url: string, alt: string}|null
	 */
	public static function attachment_image( int $attachment_id ): ?array {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$url  = null;

		$full_width  = (int) self::field( $meta, 'width' );
		$full_height = (int) self::field( $meta, 'height' );

		if ( $full_width > 0 && $full_height > 0 && max( $full_width, $full_height ) <= self::MAX_IMAGE_SIZE ) {
			$url = wp_get_attachment_url( $attachment_id );
		} else {
			$best  = 0;
			$sizes = self::field( $meta, 'sizes' );

			foreach ( is_array( $sizes ) ? $sizes : array() as $size => $data ) {
				$width  = (int) self::field( $data, 'width' );
				$height = (int) self::field( $data, 'height' );

				if ( max( $width, $height ) <= self::MAX_IMAGE_SIZE && $width * $height > $best ) {
					$source = wp_get_attachment_image_src( $attachment_id, (string) $size );

					if ( is_array( $source ) ) {
						$best = $width * $height;
						$url  = $source[0];
					}
				}
			}

			if ( null === $url ) {
				$source = wp_get_attachment_image_src( $attachment_id, 'large' );
				$url    = is_array( $source ) ? $source[0] : wp_get_attachment_url( $attachment_id );
			}
		}

		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		return array(
			'url' => $url,
			'alt' => trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
		);
	}

	/**
	 * `wp:{blog_id}:{post_id}:{revision}`, where revision is the latest revision's id, or the modified time when the
	 * site keeps no revisions.
	 *
	 * @param WP_Post $post The post.
	 */
	public static function idempotency_key( WP_Post $post ): string {
		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$latest    = array() === $revisions ? null : reset( $revisions );
		$revision  = is_int( $latest ) ? $latest : ( $latest instanceof WP_Post ? $latest->ID : (int) get_post_modified_time( 'U', true, $post ) );

		return 'wp:' . get_current_blog_id() . ':' . $post->ID . ':' . $revision;
	}

	/**
	 * Whether the post came from Refract (it's never sent back).
	 *
	 * @param int $post_id The post id.
	 */
	public static function from_refract( int $post_id ): bool {
		return 'refract' === get_post_meta( $post_id, '_refract_source', true );
	}

	/**
	 * Whether the post is already a note in Refract.
	 *
	 * @param int $post_id The post id.
	 */
	public static function was_sent( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, '_refract_post_id', true );
	}

	/**
	 * The lock transient's name.
	 *
	 * @param int $post_id The post id.
	 */
	public static function lock_key( int $post_id ): string {
		return 'refract_lock_' . $post_id;
	}

	/**
	 * The md5 of the title, excerpt and text last sent.
	 *
	 * @param array<string, string> $source The source object.
	 */
	private static function hash( array $source ): string {
		return md5( $source['title'] . "\n" . $source['excerpt'] . "\n" . $source['text'] );
	}

	/**
	 * Decodes HTML entities.
	 *
	 * @param string $text The text.
	 */
	private static function plain( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * One key of attachment metadata, which can be missing or partial (SVG, HEIC, failed thumbnails).
	 *
	 * @param mixed  $data The metadata (or a size inside it).
	 * @param string $key  The key.
	 * @return mixed
	 */
	private static function field( mixed $data, string $key ): mixed {
		return is_array( $data ) ? ( $data[ $key ] ?? null ) : null;
	}
}

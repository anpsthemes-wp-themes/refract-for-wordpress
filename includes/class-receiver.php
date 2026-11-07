<?php
/**
 * Posts Refract publishes to the site.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * `POST refract/v1/posts`: creates a post, or updates one Refract made before, with its images downloaded into the
 * media library. Posts from Refract are marked so they're never sent back. `DELETE refract/v1/posts/{id}` moves one
 * Refract made to the trash. Refract never acts as a user: the "Publish as" person is only the posts' author, and what
 * that person may do is checked with user_can() before anything changes.
 */
final class Receiver {

	/**
	 * Handles one post from Refract.
	 *
	 * @param WP_REST_Request $request The signed request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$settings = Settings::get();

		if ( ! $settings['refract_publish'] ) {
			return new WP_Error( 'refract_publishing_off', __( 'Publishing from Refract is turned off on this site.', 'refract' ), array( 'status' => 403 ) );
		}

		$input = self::validate( $request->get_json_params() );

		if ( is_wp_error( $input ) ) {
			return $input;
		}

		if ( 'drafts' === $settings['refract_can'] ) {
			$input['status'] = 'draft';
		}

		$user       = $settings['publish_as'] > 0 ? get_userdata( $settings['publish_as'] ) : false;
		$capability = 'draft' === $input['status'] ? 'edit_posts' : 'publish_posts';
		$cannot     = new WP_Error( 'refract_cannot_publish', __( 'The person Refract publishes as can\'t publish posts on this site.', 'refract' ), array( 'status' => 403 ) );

		if ( false === $user || ! user_can( $user, $capability ) ) {
			return $cannot;
		}

		$existing = self::find( $input['refract_post_id'] );

		if ( $existing > 0 && ! user_can( $user, 'edit_post', $existing ) ) {
			return $cannot;
		}

		$result = self::save( $input, $existing, $user->ID, $settings['default_category'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = (string) get_post_status( $result );
		// get_edit_post_link() needs a signed-in user, and none is.
		$link = in_array( $status, array( 'draft', 'pending' ), true ) ? add_query_arg(
			array(
				'post'   => $result,
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		) : get_permalink( $result );

		Activity::log(
			'received',
			array(
				'post_id' => $result,
				'message' => 'draft' === $status ? __( 'Got a draft from Refract.', 'refract' ) : __( 'Got a post from Refract.', 'refract' ),
				'link'    => is_string( $link ) ? $link : null,
			)
		);

		return new WP_REST_Response(
			array(
				'id'     => $result,
				'link'   => is_string( $link ) ? $link : '',
				'status' => $status,
			),
			$existing > 0 ? 200 : 201
		);
	}

	/**
	 * `DELETE refract/v1/posts/{refract_post_id}`: moves the post made from that Refract post to the trash, when the
	 * "Publish as" person could. A post that's no longer here (never made, already trashed or deleted) counts as removed.
	 *
	 * @param WP_REST_Request $request The signed request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$settings = Settings::get();

		if ( ! $settings['refract_publish'] ) {
			return new WP_Error( 'refract_publishing_off', __( 'Publishing from Refract is turned off on this site.', 'refract' ), array( 'status' => 403 ) );
		}

		$existing = self::find( sanitize_text_field( (string) $request->get_param( 'refract_post_id' ) ) );

		if ( 0 === $existing ) {
			return new WP_REST_Response(
				array(
					'id'     => null,
					'status' => 'gone',
				),
				200
			);
		}

		$user   = $settings['publish_as'] > 0 ? get_userdata( $settings['publish_as'] ) : false;
		$cannot = new WP_Error( 'refract_cannot_remove', __( 'The person Refract publishes as can\'t delete this post on this site.', 'refract' ), array( 'status' => 403 ) );

		if ( false === $user || ! user_can( $user, 'delete_post', $existing ) ) {
			return $cannot;
		}

		$title   = get_the_title( $existing );
		$trashed = wp_trash_post( $existing );

		if ( ! $trashed ) {
			return new WP_Error( 'refract_remove_failed', __( 'WordPress couldn\'t move the post to the trash.', 'refract' ), array( 'status' => 500 ) );
		}

		Activity::log(
			'removed',
			array(
				'post_id' => $existing,
				/* translators: %s: the post's title. */
				'message' => sprintf( __( 'Refract moved "%s" to the trash.', 'refract' ), html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ),
			)
		);

		return new WP_REST_Response(
			array(
				'id'     => $existing,
				'status' => 'trash',
			),
			200
		);
	}

	/**
	 * Checks and normalizes the body.
	 *
	 * @param mixed $params The JSON body.
	 * @return array{refract_post_id: string, title: string, content: string, excerpt: string, status: string, date_gmt: string|null, categories: list<int>, tags: list<int|string>, featured_image: array{url: string, alt: string}|null, images: list<array{url: string, alt: string}>}|WP_Error
	 */
	private static function validate( mixed $params ): array|WP_Error {
		$params = is_array( $params ) ? $params : array();
		$fail   = static fn ( string $message ): WP_Error => new WP_Error( 'refract_invalid', $message, array( 'status' => 422 ) );

		$refract_id = is_scalar( $params['refract_post_id'] ?? null ) ? sanitize_text_field( (string) $params['refract_post_id'] ) : '';
		$title      = is_string( $params['title'] ?? null ) ? sanitize_text_field( $params['title'] ) : '';
		$status     = $params['status'] ?? 'publish';

		if ( '' === $refract_id ) {
			return $fail( __( 'The post needs refract_post_id.', 'refract' ) );
		}

		if ( '' === $title ) {
			return $fail( __( 'The post needs a title.', 'refract' ) );
		}

		if ( ! in_array( $status, array( 'publish', 'draft', 'future' ), true ) ) {
			return $fail( __( 'The status must be publish, draft or future.', 'refract' ) );
		}

		$date_gmt = null;

		if ( isset( $params['date_gmt'] ) && is_string( $params['date_gmt'] ) && '' !== $params['date_gmt'] ) {
			$time = strtotime( $params['date_gmt'] . ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $params['date_gmt'] ) ? '' : 'Z' ) );

			if ( false === $time ) {
				return $fail( __( 'The date isn\'t valid.', 'refract' ) );
			}

			$date_gmt = gmdate( 'Y-m-d H:i:s', $time );
		}

		if ( 'future' === $status && null === $date_gmt ) {
			return $fail( __( 'A scheduled post needs a date.', 'refract' ) );
		}

		$tags = array();

		foreach ( (array) ( $params['tags'] ?? array() ) as $tag ) {
			if ( is_int( $tag ) || ( is_string( $tag ) && ctype_digit( $tag ) ) ) {
				$tags[] = (int) $tag;
			} elseif ( is_string( $tag ) && '' !== trim( $tag ) ) {
				$tags[] = sanitize_text_field( $tag );
			}
		}

		$images = array();

		foreach ( (array) ( $params['images'] ?? array() ) as $image ) {
			$normalized = self::image( $image );

			if ( null !== $normalized ) {
				$images[] = $normalized;
			}
		}

		return array(
			'refract_post_id' => $refract_id,
			'title'           => $title,
			'content'         => is_string( $params['content'] ?? null ) ? $params['content'] : '',
			'excerpt'         => is_string( $params['excerpt'] ?? null ) ? sanitize_textarea_field( $params['excerpt'] ) : '',
			'status'          => $status,
			'date_gmt'        => $date_gmt,
			'categories'      => array_values( array_filter( array_map( 'absint', array_filter( (array) ( $params['categories'] ?? array() ), 'is_scalar' ) ), static fn ( int $id ): bool => $id > 0 && term_exists( $id, 'category' ) ) ),
			'tags'            => $tags,
			'featured_image'  => self::image( $params['featured_image'] ?? null ),
			'images'          => $images,
		);
	}

	/**
	 * Normalizes `{url, alt}`; only http(s) URLs.
	 *
	 * @param mixed $image The submitted image.
	 * @return array{url: string, alt: string}|null
	 */
	private static function image( mixed $image ): ?array {
		if ( ! is_array( $image ) || ! is_string( $image['url'] ?? null ) ) {
			return null;
		}

		$url = esc_url_raw( $image['url'], array( 'http', 'https' ) );

		if ( '' === $url ) {
			return null;
		}

		return array(
			'url' => $url,
			'alt' => is_string( $image['alt'] ?? null ) ? sanitize_text_field( $image['alt'] ) : '',
		);
	}

	/**
	 * Creates or updates the post. Images are downloaded first, so the post is saved once with its final content.
	 *
	 * @param array{refract_post_id: string, title: string, content: string, excerpt: string, status: string, date_gmt: string|null, categories: list<int>, tags: list<int|string>, featured_image: array{url: string, alt: string}|null, images: list<array{url: string, alt: string}>} $input            The validated body.
	 * @param int                                                                                                                                                                                                                                                                        $existing         The post to update, or 0.
	 * @param int                                                                                                                                                                                                                                                                        $author           The "Publish as" person.
	 * @param int                                                                                                                                                                                                                                                                        $default_category The default category setting.
	 * @return int|WP_Error The post id.
	 */
	private static function save( array $input, int $existing, int $author, int $default_category ): int|WP_Error {
		$created = array();
		$content = $input['content'];

		foreach ( $input['images'] as $image ) {
			if ( ! str_contains( $content, $image['url'] ) ) {
				continue;
			}

			$attachment = self::sideload( $image, $author, $created );

			if ( is_wp_error( $attachment ) ) {
				self::discard( $created );

				return $attachment;
			}

			$local   = (string) wp_get_attachment_url( $attachment );
			$content = str_replace( array( $image['url'], esc_url( $image['url'] ) ), $local, $content );
		}

		$featured = 0;

		if ( null !== $input['featured_image'] ) {
			$featured = self::sideload( $input['featured_image'], $author, $created );

			if ( is_wp_error( $featured ) ) {
				self::discard( $created );

				return $featured;
			}
		}

		$tag_ids = array();

		foreach ( $input['tags'] as $tag ) {
			if ( is_int( $tag ) ) {
				if ( term_exists( $tag, 'post_tag' ) ) {
					$tag_ids[] = $tag;
				}
				continue;
			}

			$term = term_exists( $tag, 'post_tag' ) ?? wp_insert_term( $tag, 'post_tag' );

			if ( is_array( $term ) ) {
				$tag_ids[] = (int) $term['term_id'];
			}
		}

		$categories = $input['categories'];

		if ( array() === $categories ) {
			$categories = array( $default_category > 0 ? $default_category : (int) get_option( 'default_category' ) );
		}

		$postarr = array(
			'post_type'     => 'post',
			'post_title'    => $input['title'],
			'post_content'  => wp_kses_post( $content ),
			'post_excerpt'  => $input['excerpt'],
			'post_status'   => $input['status'],
			'post_author'   => $author,
			'post_category' => $categories,
			'tags_input'    => $tag_ids,
			'meta_input'    => array(
				'_refract_source'  => 'refract',
				'_refract_post_id' => $input['refract_post_id'],
			),
		);

		if ( null !== $input['date_gmt'] ) {
			$postarr['post_date_gmt'] = $input['date_gmt'];
			$postarr['post_date']     = get_date_from_gmt( $input['date_gmt'] );
			$postarr['edit_date']     = true;
		}

		if ( $existing > 0 ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			self::discard( $created );

			return new WP_Error( 'refract_invalid', $post_id->get_error_message(), array( 'status' => 422 ) );
		}

		foreach ( $created as $attachment_id ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $post_id,
				)
			);
		}

		if ( $featured > 0 ) {
			set_post_thumbnail( $post_id, $featured );
		}

		return (int) $post_id;
	}

	/**
	 * Downloads an image into the media library, or reuses the attachment made from the same URL before.
	 *
	 * @param array{url: string, alt: string} $image   The image.
	 * @param int                             $author  The "Publish as" person, the attachment's author.
	 * @param int[]                           $created Attachments made during this request (appended to).
	 * @return int|WP_Error The attachment id.
	 */
	private static function sideload( array $image, int $author, array &$created ): int|WP_Error {
		$known = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_refract_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs only when Refract sends a post.
				'meta_value'     => $image['url'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Runs only when Refract sends a post.
			)
		);

		if ( array() !== $known ) {
			return (int) $known[0];
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment = self::download( $image['url'], $author );

		if ( is_wp_error( $attachment ) ) {
			return new WP_Error(
				'refract_invalid',
				/* translators: %s: image URL. */
				sprintf( __( 'The image %s couldn\'t be downloaded.', 'refract' ), $image['url'] ),
				array( 'status' => 422 )
			);
		}

		$created[] = $attachment;
		update_post_meta( $attachment, '_refract_source_url', $image['url'] );

		if ( '' !== $image['alt'] ) {
			update_post_meta( $attachment, '_wp_attachment_image_alt', $image['alt'] );
		}

		return $attachment;
	}

	/**
	 * Downloads an image and adds it to the media library, named after its real type (a URL may have no extension, or
	 * the wrong one).
	 *
	 * @param string $url    The image URL.
	 * @param int    $author The attachment's author.
	 * @return int|WP_Error The attachment id.
	 */
	private static function download( string $url, int $author ): int|WP_Error {
		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$mime       = wp_get_image_mime( $tmp );
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/heic' => 'heic',
		);

		if ( false === $mime || ! isset( $extensions[ $mime ] ) ) {
			wp_delete_file( $tmp );

			return new WP_Error( 'refract_not_image', __( 'That file isn\'t an image.', 'refract' ) );
		}

		$name = sanitize_file_name( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		$name = ( '' === $name ? 'refract-image' : $name ) . '.' . $extensions[ $mime ];
		$id   = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			null,
			array( 'post_author' => $author )
		);

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
		}

		return $id;
	}

	/**
	 * Deletes attachments made during a request that failed.
	 *
	 * @param int[] $created Attachment ids.
	 */
	private static function discard( array $created ): void {
		foreach ( $created as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * The post made from this Refract post before, or 0.
	 *
	 * @param string $refract_post_id Refract's post id.
	 */
	private static function find( string $refract_post_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs only when Refract sends a post.
				'meta_query'     => array(
					array(
						'key'   => '_refract_post_id',
						'value' => $refract_post_id,
					),
					array(
						'key'   => '_refract_source',
						'value' => 'refract',
					),
				),
			)
		);

		return array() === $ids ? 0 : (int) $ids[0];
	}
}

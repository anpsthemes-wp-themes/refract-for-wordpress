<?php
/**
 * Social preview tags.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Open Graph and X card tags for single posts, so shared links show a title and an image. Off when a listed SEO
 * plugin prints its own.
 */
final class Link_Previews {

	/**
	 * Image size for previews: 1200 × 630, cropped.
	 */
	public const IMAGE_SIZE = 'refract-social';

	/**
	 * Adds the hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'after_setup_theme', array( self::class, 'add_image_size' ) );
		add_action( 'wp_head', array( self::class, 'print_tags' ), 5 );
	}

	/**
	 * Registers the 1200 × 630 crop.
	 */
	public static function add_image_size(): void {
		add_image_size( self::IMAGE_SIZE, 1200, 630, true );
	}

	/**
	 * Prints the tags on single posts when they're on.
	 */
	public static function print_tags(): void {
		if ( ! is_singular() || ! Settings::get()['link_previews'] || null !== Seo_Plugins::active() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Settings::public_post_types(), true ) ) {
			return;
		}

		foreach ( self::tags( $post ) as $name => $content ) {
			$attribute = str_starts_with( $name, 'og:' ) ? 'property' : 'name';
			printf( '<meta %1$s="%2$s" content="%3$s" />' . "\n", esc_attr( $attribute ), esc_attr( $name ), esc_attr( $content ) );
		}
	}

	/**
	 * The tags for a post.
	 *
	 * @param \WP_Post $post The post.
	 * @return array<string, string>
	 */
	public static function tags( \WP_Post $post ): array {
		// A space after each block-level end tag, so "…€14.50.</p><p>We roast…" doesn't run together.
		$content     = (string) preg_replace( '#</(p|h[1-6]|li|blockquote|div|figure|figcaption|pre|td|th)>|<br\s*/?>#i', '$0 ', $post->post_content );
		$description = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $content ) ), 40, '…' );
		$tags        = array(
			'og:type'        => 'article',
			'og:title'       => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'og:description' => html_entity_decode( wp_strip_all_tags( $description ), ENT_QUOTES, 'UTF-8' ),
			'og:url'         => (string) get_permalink( $post ),
			'og:site_name'   => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'twitter:card'   => 'summary_large_image',
		);

		$image_id = (int) get_post_meta( $post->ID, '_refract_social_image', true );
		$image_id = $image_id > 0 ? $image_id : (int) get_post_thumbnail_id( $post );
		$image    = $image_id > 0 ? wp_get_attachment_image_src( $image_id, self::IMAGE_SIZE ) : false;

		if ( is_array( $image ) ) {
			$tags['og:image'] = $image[0];

			if ( 1200 === (int) $image[1] && 630 === (int) $image[2] ) {
				$tags['og:image:width']  = '1200';
				$tags['og:image:height'] = '630';
			}

			$alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );

			if ( '' !== $alt ) {
				$tags['og:image:alt'] = $alt;
			}
		}

		return array_filter( $tags, static fn ( string $value ): bool => '' !== $value );
	}
}

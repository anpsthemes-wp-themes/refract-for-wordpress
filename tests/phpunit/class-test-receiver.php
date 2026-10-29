<?php
/**
 * Receiving posts from Refract.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Activity;
use Refract\WP\Plugin;
use Refract\WP\Settings;

/**
 * `POST refract/v1/posts` and `DELETE refract/v1/posts/{refract_post_id}`.
 */
final class Test_Receiver extends Refract_Test_Case {

	/**
	 * The "Publish as" editor.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Image URLs downloaded.
	 *
	 * @var list<string>
	 */
	private array $downloads = array();

	/**
	 * Connects with publishing on, and answers image downloads with a PNG.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Ana Kovač',
			)
		);
		$this->connect(
			array(
				'refract_publish' => true,
				'publish_as'      => $this->editor,
			)
		);
		_set_cron_array( array() );
		add_filter( 'pre_http_request', array( $this, 'download' ), 10, 3 );
	}

	/**
	 * Removes the download mock.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'download' ), 10 );
		parent::tear_down();
	}

	/**
	 * Answers downloads from https://images.test with a PNG, written where download_url() asks.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  The URL.
	 * @return mixed
	 */
	public function download( mixed $pre, array $args, string $url ): mixed {
		if ( false !== $pre || ! str_starts_with( $url, 'https://images.test/' ) ) {
			return $pre;
		}

		$this->downloads[] = $url;

		if ( ! empty( $args['stream'] ) && is_string( $args['filename'] ?? null ) ) {
			file_put_contents( $args['filename'], self::png() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		}

		return array(
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	}

	/**
	 * A post body.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed>
	 */
	private function payload( array $changes = array() ): array {
		return array_merge(
			array(
				'refract_post_id' => 'refract-post-9',
				'title'           => 'Five things about Huila',
				'content'         => '<!-- wp:paragraph --><p>Hello <script>alert(1)</script>world.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'A short excerpt.',
				'status'          => 'publish',
				'categories'      => array(),
				'tags'            => array( 'New tag' ),
			),
			$changes
		);
	}

	/**
	 * Publishing creates the post as the "Publish as" person, marked as from Refract, and never sends it back.
	 */
	public function test_publish(): void {
		$response = $this->signed( 'POST', '/refract/v1/posts', $this->payload() );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'publish', $data['status'] );
		$this->assertSame( get_permalink( $data['id'] ), $data['link'] );

		$post = get_post( $data['id'] );
		$this->assertSame( 'Five things about Huila', $post->post_title );
		$this->assertSame( $this->editor, (int) $post->post_author );
		$this->assertStringNotContainsString( '<script>', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $post->post_content );
		$this->assertSame( 'refract', get_post_meta( $post->ID, '_refract_source', true ) );
		$this->assertSame( array( 'New tag' ), wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ) );
		$this->assertSame( array( (int) get_option( 'default_category' ) ), wp_get_post_categories( $post->ID ) );
		$this->assertFalse( wp_next_scheduled( Plugin::CRON_SEND, array( $post->ID, '' ) ), 'Posts from Refract never go back.' );
		$this->assertSame( 0, get_current_user_id(), 'The "Publish as" user is only for the request.' );

		$again = $this->signed( 'POST', '/refract/v1/posts', $this->payload( array( 'title' => 'Updated title' ) ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $data['id'], $again->get_data()['id'], 'Same Refract post: updated, not duplicated.' );
		$this->assertSame( 'Updated title', get_post( $data['id'] )->post_title );
	}

	/**
	 * Drafts link to the editor; scheduled posts get their date.
	 */
	public function test_draft_and_future(): void {
		$draft = $this->signed( 'POST', '/refract/v1/posts', $this->payload( array( 'status' => 'draft' ) ) )->get_data();
		$this->assertSame( 'draft', $draft['status'] );
		$this->assertStringContainsString( 'post.php?post=' . $draft['id'], $draft['link'] );

		$when   = gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS );
		$future = $this->signed(
			'POST',
			'/refract/v1/posts',
			$this->payload(
				array(
					'refract_post_id' => 'refract-post-10',
					'status'          => 'future',
					'date_gmt'        => $when,
				)
			)
		)->get_data();

		$this->assertSame( 'future', $future['status'] );
		$this->assertSame( str_replace( 'T', ' ', $when ), get_post( $future['id'] )->post_date_gmt );

		$missing = $this->signed( 'POST', '/refract/v1/posts', $this->payload( array( 'status' => 'future' ) ) );
		$this->assertSame( 422, $missing->get_status() );
		$this->assertSame( 'refract_invalid', $missing->get_data()['code'] );
	}

	/**
	 * "Send drafts only" turns publish and future into drafts.
	 */
	public function test_drafts_only(): void {
		Settings::update( array( 'refract_can' => 'drafts' ) );

		$published = $this->signed( 'POST', '/refract/v1/posts', $this->payload() )->get_data();
		$scheduled = $this->signed(
			'POST',
			'/refract/v1/posts',
			$this->payload(
				array(
					'refract_post_id' => 'refract-post-11',
					'status'          => 'future',
					'date_gmt'        => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ),
				)
			)
		)->get_data();

		$this->assertSame( 'draft', $published['status'] );
		$this->assertSame( 'draft', get_post_status( $published['id'] ) );
		$this->assertSame( 'draft', $scheduled['status'] );
	}

	/**
	 * Publishing off, or a "Publish as" person who can't publish: 403.
	 */
	public function test_refusals(): void {
		Settings::update( array( 'refract_publish' => false ) );
		$off = $this->signed( 'POST', '/refract/v1/posts', $this->payload() );
		$this->assertSame( 403, $off->get_status() );
		$this->assertSame( 'refract_publishing_off', $off->get_data()['code'] );
		$this->assertSame( 'Publishing from Refract is turned off on this site.', $off->get_data()['message'] );

		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$settings    = get_option( Settings::OPTION );
		update_option(
			Settings::OPTION,
			array_merge(
				$settings,
				array(
					'refract_publish' => true,
					'publish_as'      => $contributor,
				)
			)
		);
		// The sanitize callback refuses a contributor, so store it the way an old value would be.
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		update_option(
			Settings::OPTION,
			array_merge(
				(array) $settings,
				array(
					'refract_publish' => true,
					'publish_as'      => $contributor,
				)
			)
		);

		$cannot = $this->signed( 'POST', '/refract/v1/posts', $this->payload() );
		$this->assertSame( 403, $cannot->get_status() );
		$this->assertSame( 'refract_cannot_publish', $cannot->get_data()['code'] );

		$this->assertSame( 201, $this->signed( 'POST', '/refract/v1/posts', $this->payload( array( 'status' => 'draft' ) ) )->get_status(), 'Contributors can still create drafts.' );

		$untitled = $this->signed( 'POST', '/refract/v1/posts', $this->payload( array( 'title' => '' ) ) );
		$this->assertSame( 422, $untitled->get_status() );
	}

	/**
	 * `DELETE /posts/{refract_post_id}` trashes the post made from that Refract post; a post that's gone counts as removed.
	 */
	public function test_remove(): void {
		$id = $this->signed( 'POST', '/refract/v1/posts', $this->payload() )->get_data()['id'];

		$this->assertSame( 401, $this->rest( 'DELETE', '/refract/v1/posts/refract-post-9' )->get_status(), 'Only Refract can remove.' );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$removed = $this->signed( 'DELETE', '/refract/v1/posts/refract-post-9' );
		$this->assertSame( 200, $removed->get_status() );
		$this->assertSame( 'trash', $removed->get_data()['status'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( 0, get_current_user_id(), 'The "Publish as" user is only for the request.' );

		$entry = Activity::entries()[0];
		$this->assertSame( 'removed', $entry['event'] );
		$this->assertSame( $id, $entry['post_id'] );

		$again = $this->signed( 'DELETE', '/refract/v1/posts/refract-post-9' );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 'gone', $again->get_data()['status'] );
		$this->assertSame( 'gone', $this->signed( 'DELETE', '/refract/v1/posts/never-sent' )->get_data()['status'] );
	}

	/**
	 * Publishing off, or a "Publish as" person who can't delete the post: 403, and the post stays.
	 */
	public function test_remove_refusals(): void {
		$id = $this->signed( 'POST', '/refract/v1/posts', $this->payload() )->get_data()['id'];

		Settings::update( array( 'refract_publish' => false ) );
		$off = $this->signed( 'DELETE', '/refract/v1/posts/refract-post-9' );
		$this->assertSame( 403, $off->get_status() );
		$this->assertSame( 'refract_publishing_off', $off->get_data()['code'] );

		// The sanitize callback refuses a contributor, so store it the way an old value would be.
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		update_option(
			Settings::OPTION,
			array_merge(
				(array) get_option( Settings::OPTION ),
				array(
					'refract_publish' => true,
					'publish_as'      => self::factory()->user->create( array( 'role' => 'contributor' ) ),
				)
			)
		);

		$cannot = $this->signed( 'DELETE', '/refract/v1/posts/refract-post-9' );
		$this->assertSame( 403, $cannot->get_status() );
		$this->assertSame( 'refract_cannot_remove', $cannot->get_data()['code'] );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	/**
	 * Images are downloaded into the library, the content points at them, and the featured image is set.
	 */
	public function test_image_sideloading(): void {
		$response = $this->signed(
			'POST',
			'/refract/v1/posts',
			$this->payload(
				array(
					'content'        => '<!-- wp:image --><figure class="wp-block-image"><img src="https://images.test/cups.png" alt="Cups"/></figure><!-- /wp:image --><p>Unused <a href="https://images.test/elsewhere.png">link</a></p>',
					'images'         => array(
						array(
							'url' => 'https://images.test/cups.png',
							'alt' => 'Cups',
						),
						array(
							'url' => 'https://images.test/not-in-content.png',
							'alt' => '',
						),
					),
					'featured_image' => array(
						'url' => 'https://images.test/media/7f3a',
						'alt' => 'A bag of Huila',
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );

		$post      = get_post( $data['id'] );
		$thumbnail = (int) get_post_thumbnail_id( $post );

		$this->assertGreaterThan( 0, $thumbnail );
		$this->assertSame( 'A bag of Huila', get_post_meta( $thumbnail, '_wp_attachment_image_alt', true ) );
		$this->assertStringEndsWith( '.png', (string) get_attached_file( $thumbnail ), 'URLs without an extension get one from the file type.' );
		$this->assertStringNotContainsString( 'https://images.test/cups.png', $post->post_content );
		$this->assertStringContainsString( wp_get_upload_dir()['baseurl'], $post->post_content );
		$this->assertNotContains( 'https://images.test/not-in-content.png', $this->downloads );
		$this->assertSame( $post->ID, wp_get_post_parent_id( $thumbnail ) );

		$count = count( $this->downloads );
		$this->signed( 'POST', '/refract/v1/posts', $this->payload( array( 'featured_image' => array( 'url' => 'https://images.test/media/7f3a' ) ) ) );
		$this->assertCount( $count, $this->downloads, 'An image already downloaded is reused.' );
	}

	/**
	 * A failed download refuses the post and leaves nothing behind.
	 */
	public function test_failed_download(): void {
		add_filter(
			'pre_http_request',
			static fn ( $pre, $args, $url ) => str_starts_with( $url, 'https://images.test/broken' ) ? new \WP_Error( 'http_request_failed', 'Not found' ) : $pre,
			5,
			3
		);
		$before = count(
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			)
		);

		$response = $this->signed(
			'POST',
			'/refract/v1/posts',
			$this->payload(
				array(
					'content'        => '<p><img src="https://images.test/ok.png"></p>',
					'images'         => array( array( 'url' => 'https://images.test/ok.png' ) ),
					'featured_image' => array( 'url' => 'https://images.test/broken.png' ),
				)
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame(
			$before,
			count(
				get_posts(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'fields'         => 'ids',
						'posts_per_page' => -1,
					)
				)
			)
		);
		$this->assertSame(
			array(),
			get_posts(
				array(
					'title'       => 'Five things about Huila',
					'post_status' => 'any',
				)
			)
		);
	}
}

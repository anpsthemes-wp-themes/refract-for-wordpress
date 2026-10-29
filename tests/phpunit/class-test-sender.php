<?php
/**
 * Sending tests.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Activity;
use Refract\WP\Plugin;
use Refract\WP\Sender;
use Refract\WP\Settings;
use WP_Post;

/**
 * The publish hook, the payload, retries and out of credits.
 */
final class Test_Sender extends Refract_Test_Case {

	/**
	 * Clears scheduled events between tests.
	 */
	public function set_up(): void {
		parent::set_up();
		_set_cron_array( array() );
	}

	/**
	 * Number of scheduled sends for a post.
	 *
	 * @param int $post_id The post id.
	 */
	private function scheduled_sends( int $post_id ): int {
		$count = 0;

		foreach ( _get_cron_array() as $hooks ) {
			foreach ( $hooks[ Plugin::CRON_SEND ] ?? array() as $event ) {
				if ( (int) $event['args'][0] === $post_id ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * A draft post.
	 *
	 * @param array<string, mixed> $args Post fields.
	 */
	private function draft( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_excerpt' => '',
					'post_status'  => 'draft',
					'post_title'   => 'Our autumn Huila is here',
					'post_content' => "<!-- wp:paragraph -->\n<p>The Huila is back. It tastes of red apple &amp; caramel. Order today.</p>\n<!-- /wp:paragraph -->",
				),
				$args
			)
		);
	}

	/**
	 * Accepts notes like Refract does.
	 */
	private function accept_notes(): void {
		$this->refract->on(
			'POST',
			'/api/v1/workspaces/ws-1/notes',
			202,
			array(
				'post_id'  => 'refract-post-1',
				'status'   => 'draft',
				'versions' => array(),
				'edit_url' => 'https://refract.test/w/kolo/posts/refract-post-1',
			)
		);
	}

	/**
	 * Publishing schedules one send, however often the post is saved.
	 */
	public function test_publish_schedules_exactly_once(): void {
		$this->connect();
		$post_id = $this->draft();

		wp_publish_post( $post_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		Sender::on_transition( 'publish', 'draft', get_post( $post_id ) );

		$this->assertSame( 1, $this->scheduled_sends( $post_id ) );
		$this->assertNotFalse( get_transient( Sender::lock_key( $post_id ) ) );
		$this->assertSame( 'queued', get_post_meta( $post_id, '_refract_status', true ) );
		$this->assertSame( array(), $this->refract->requests, 'Publishing never waits for Refract.' );
	}

	/**
	 * Posts from Refract, other post types and a disconnected site schedule nothing.
	 */
	public function test_publish_skips(): void {
		$from_refract = $this->draft( array( 'meta_input' => array( '_refract_source' => 'refract' ) ) );
		$page         = $this->draft( array( 'post_type' => 'page' ) );
		$disconnected = $this->draft();

		wp_publish_post( $disconnected );
		$this->assertSame( 0, $this->scheduled_sends( $disconnected ) );

		$this->connect();
		wp_publish_post( $from_refract );
		wp_publish_post( $page );

		$this->assertSame( 0, $this->scheduled_sends( $from_refract ) );
		$this->assertSame( 0, $this->scheduled_sends( $page ) );

		$created = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'meta_input'  => array( '_refract_source' => 'refract' ),
			)
		);
		$this->assertSame( 0, $this->scheduled_sends( $created ), 'meta_input is set before transition_post_status.' );
	}

	/**
	 * The idempotency key changes with each revision.
	 */
	public function test_idempotency_key_changes_with_each_revision(): void {
		$post_id = $this->draft();
		$first   = Sender::idempotency_key( get_post( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Changed text.',
			)
		);
		$second = Sender::idempotency_key( get_post( $post_id ) );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Changed title',
			)
		);
		$third = Sender::idempotency_key( get_post( $post_id ) );

		$this->assertMatchesRegularExpression( '/^wp:' . get_current_blog_id() . ':' . $post_id . ':\d+$/', $first );
		$this->assertNotSame( $first, $second );
		$this->assertNotSame( $second, $third );
		$this->assertSame( $third, Sender::idempotency_key( get_post( $post_id ) ), 'The key is stable while the post is unchanged.' );
	}

	/**
	 * The note carries the post's text, link, images, accounts and settings.
	 */
	public function test_payload(): void {
		$this->connect( array( 'images' => 'crop_or_make' ) );
		$this->accept_notes();

		$file = get_temp_dir() . 'refract-test-' . wp_generate_password( 6, false ) . '.png';
		file_put_contents( $file, self::png() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$featured = self::factory()->attachment->create_upload_object( $file );
		update_post_meta( $featured, '_wp_attachment_image_alt', 'A bag of Huila' );
		$inline = self::factory()->attachment->create_upload_object( $file );
		update_post_meta( $inline, '_wp_attachment_image_alt', 'Stale library alt' );

		$post_id = $this->draft(
			array(
				'post_content' => '<!-- wp:paragraph --><p>First sentence here. Second one follows! Third is cut.</p><!-- /wp:paragraph -->'
					. '<!-- wp:image {"id":' . $inline . '} --><figure class="wp-block-image"><img src="' . wp_get_attachment_url( $inline ) . '" alt="Cups" class="wp-image-' . $inline . '"/></figure><!-- /wp:image -->'
					. '<p><img src="https://cdn.example.com/beans.jpg" alt="Beans"></p>',
			)
		);
		set_post_thumbnail( $post_id, $featured );
		update_post_meta( $post_id, '_refract_accounts', array( 'acc-instagram' ) );
		wp_publish_post( $post_id );

		Sender::run( $post_id, '' );

		$sent = $this->refract->sent( '/api/v1/workspaces/ws-1/notes', 'POST' );
		$this->assertCount( 1, $sent );

		$body = $sent[0]['body'];
		$this->assertSame( 'wordpress', $body['source']['type'] ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Refract's lowercase type.
		$this->assertSame( 'site-account', $body['source']['account_id'] );
		$this->assertSame( (string) $post_id, $body['source']['external_id'] );
		$this->assertSame( get_permalink( $post_id ), $body['source']['url'] );
		$this->assertSame( 'Our autumn Huila is here', $body['source']['title'] );
		$this->assertSame( 'First sentence here. Second one follows!', $body['source']['excerpt'] );
		$this->assertStringContainsString( 'Third is cut.', $body['source']['text'] );
		$this->assertStringNotContainsString( '<', $body['source']['text'] );
		$this->assertSame( 'note', $body['start_from'] );
		$this->assertSame( 'crop_or_make', $body['images'] );
		$this->assertSame( array( 'acc-instagram' ), $body['accounts'] );
		$this->assertTrue( $body['write'] );
		$this->assertCount( 3, $body['media'] );
		$this->assertSame( 'featured', $body['media'][0]['role'] );
		$this->assertSame( 'A bag of Huila', $body['media'][0]['alt'] );
		$this->assertSame( wp_get_attachment_url( $inline ), $body['media'][1]['url'] );
		$this->assertSame( 'Cups', $body['media'][1]['alt'], 'The tag\'s own alt wins over the attachment\'s.' );
		$this->assertSame(
			array(
				'url'  => 'https://cdn.example.com/beans.jpg',
				'alt'  => 'Beans',
				'role' => 'content',
			),
			$body['media'][2]
		);
		$this->assertSame( Sender::idempotency_key( get_post( $post_id ) ), $sent[0]['headers']['Idempotency-Key'] );
		$this->assertSame( 'Bearer access-1', $sent[0]['headers']['Authorization'] );
		$this->assertSame( 'Refract-WordPress/' . REFRACT_VERSION, $sent[0]['args']['user-agent'] );
		$this->assertSame( 15, $sent[0]['args']['timeout'] );

		$this->assertSame( 'refract-post-1', get_post_meta( $post_id, '_refract_post_id', true ) );
		$this->assertSame( 'sent', get_post_meta( $post_id, '_refract_status', true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, '_refract_sent_at', true ) );
		$this->assertSame( 'sent', Activity::entries()[0]['event'] );
		$this->assertFalse( get_transient( Sender::lock_key( $post_id ) ) );
	}

	/**
	 * Later updates send a PATCH only when the text changed and updates are on.
	 */
	public function test_updates(): void {
		$this->connect();
		$this->accept_notes();
		$this->refract->on( 'PATCH', '/api/v1/workspaces/ws-1/notes/refract-post-1', 202, array( 'post_id' => 'refract-post-1' ) );

		$post_id = $this->draft();
		wp_publish_post( $post_id );
		Sender::run( $post_id, '' );

		wp_update_post(
			array(
				'ID'                => $post_id,
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		Sender::run( $post_id, '' );
		$this->assertCount( 0, $this->refract->sent( '/api/v1/workspaces/ws-1/notes/refract-post-1', 'PATCH' ), 'Nothing changed.' );

		delete_transient( Sender::lock_key( $post_id ) );
		_set_cron_array( array() );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'A new title',
			)
		);
		$this->assertSame( 1, $this->scheduled_sends( $post_id ) );
		Sender::run( $post_id, '' );

		$patches = $this->refract->sent( '/api/v1/workspaces/ws-1/notes/refract-post-1', 'PATCH' );
		$this->assertCount( 1, $patches );
		$this->assertSame( 'A new title', $patches[0]['body']['source']['title'] );
		$this->assertSame( 'updated', Activity::entries()[0]['event'] );

		Settings::update( array( 'on_update' => 'leave' ) );
		delete_transient( Sender::lock_key( $post_id ) );
		_set_cron_array( array() );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Another title',
			)
		);
		$this->assertSame( 0, $this->scheduled_sends( $post_id ) );
	}

	/**
	 * Manual mode doesn't send on publish, unless the post's switch is on; the editor's button sends at once.
	 */
	public function test_manual_mode(): void {
		$this->connect( array( 'send_mode' => 'manual' ) );
		$this->accept_notes();

		$post_id = $this->draft();
		wp_publish_post( $post_id );
		Sender::run( $post_id, '' );
		$this->assertCount( 0, $this->refract->requests );
		$this->assertSame( '', get_post_meta( $post_id, '_refract_status', true ) );

		$switched = $this->draft( array( 'meta_input' => array( '_refract_send' => '1' ) ) );
		wp_publish_post( $switched );
		Sender::run( $switched, '' );
		$this->assertCount( 1, $this->refract->requests );

		$this->assertTrue( Sender::send( get_post( $post_id ), '', true ) );
		$this->assertSame( 'sent', get_post_meta( $post_id, '_refract_status', true ) );

		$unpublished = $this->draft();
		$this->assertWPError( Sender::send( get_post( $unpublished ), '', true ) );
	}

	/**
	 * The category filter applies to automatic sends.
	 */
	public function test_category_filter(): void {
		$coffee = self::factory()->category->create();
		$news   = self::factory()->category->create();
		$this->connect( array( 'categories' => array( $coffee ) ) );
		$this->accept_notes();

		$skipped = $this->draft( array( 'post_category' => array( $news ) ) );
		$this->assertFalse( Sender::wanted( get_post( $skipped ) ) );

		$wanted = $this->draft( array( 'post_category' => array( $coffee ) ) );
		$this->assertTrue( Sender::wanted( get_post( $wanted ) ) );
	}

	/**
	 * Temporary failures retry after 1, 5 and 15 minutes, then fail.
	 */
	public function test_retries_then_fails(): void {
		$this->connect();
		$this->refract->on(
			'POST',
			'/api/v1/workspaces/ws-1/notes',
			503,
			array(
				'errors' => array(
					array(
						'status' => '503',
						'title'  => 'Refract is down for maintenance.',
					),
				),
			)
		);

		$post_id = $this->draft();
		wp_publish_post( $post_id );
		_set_cron_array( array() );

		foreach ( array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS ) as $attempt => $delay ) {
			$before = time();
			Sender::run( $post_id, '' );

			$this->assertSame( 'retrying', get_post_meta( $post_id, '_refract_status', true ) );
			$this->assertSame( $attempt + 1, (int) get_post_meta( $post_id, '_refract_attempts', true ) );

			$next = wp_next_scheduled( Plugin::CRON_SEND, array( $post_id, '' ) );
			$this->assertIsInt( $next );
			$this->assertGreaterThanOrEqual( $before + $delay, $next );
			$this->assertLessThanOrEqual( time() + $delay, $next );
			wp_unschedule_event( $next, Plugin::CRON_SEND, array( $post_id, '' ) );
		}

		Sender::run( $post_id, '' );

		$this->assertCount( 4, $this->refract->requests );
		$this->assertSame( 'failed', get_post_meta( $post_id, '_refract_status', true ) );
		$this->assertSame( 'Refract is down for maintenance.', get_post_meta( $post_id, '_refract_error', true ) );
		$this->assertFalse( wp_next_scheduled( Plugin::CRON_SEND, array( $post_id, '' ) ) );
		$this->assertSame( 'send_failed', Activity::entries( true )[0]['event'] );
	}

	/**
	 * Refract can't be reached: retried too.
	 */
	public function test_unreachable_is_retried(): void {
		$this->connect();
		$this->refract->unreachable( 'POST', '/api/v1/workspaces/ws-1/notes' );

		$post_id = $this->draft();
		wp_publish_post( $post_id );
		Sender::run( $post_id, '' );

		$this->assertSame( 'retrying', get_post_meta( $post_id, '_refract_status', true ) );
	}

	/**
	 * A 422 isn't retried.
	 */
	public function test_validation_error_fails_at_once(): void {
		$this->connect();
		$this->refract->on(
			'POST',
			'/api/v1/workspaces/ws-1/notes',
			422,
			array(
				'errors' => array(
					array(
						'status' => '422',
						'code'   => 'invalid',
						'title'  => 'Invalid',
						'detail' => 'That account isn\'t in this workspace.',
					),
				),
			)
		);

		$post_id = $this->draft();
		wp_publish_post( $post_id );
		Sender::run( $post_id, '' );

		$this->assertSame( 'failed', get_post_meta( $post_id, '_refract_status', true ) );
		$this->assertSame( 'That account isn\'t in this workspace.', get_post_meta( $post_id, '_refract_error', true ) );
	}

	/**
	 * Out of credits: automatic sends fall back to the free post; the editor's button stores the 402 so the person
	 * chooses.
	 */
	public function test_out_of_credits(): void {
		$this->connect();
		$this->refract->answer(
			'POST',
			'/api/v1/workspaces/ws-1/notes',
			static function ( array $args ): array {
				$body   = json_decode( (string) $args['body'], true );
				$status = 'note' === $body['start_from'] ? 402 : 202;
				$json   = 402 === $status
					? array(
						'errors'    => array(
							array(
								'status' => '402',
								'code'   => 'out_of_credits',
								'title'  => 'Out of credits',
								'detail' => 'You need 2 credits.',
							),
						),
						'needed'    => 2,
						'left'      => 1,
						'refill_on' => '2026-11-01',
						'buy_url'   => 'https://refract.test/billing',
					)
					: array( 'post_id' => 'refract-free' );

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $json ),
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$automatic = $this->draft();
		wp_publish_post( $automatic );
		Sender::run( $automatic, '' );

		$this->assertCount( 2, $this->refract->requests );
		$this->assertSame( 'finished', $this->refract->requests[1]['body']['start_from'] );
		$this->assertSame( '1', get_post_meta( $automatic, '_refract_fallback', true ) );
		$this->assertSame( 'refract-free', get_post_meta( $automatic, '_refract_post_id', true ) );

		$manual = $this->draft();
		wp_update_post(
			array(
				'ID'          => $manual,
				'post_status' => 'publish',
				'meta_input'  => array( '_refract_send' => '0' ),
			)
		);
		// The switch blocked the automatic send on publish; turn it back on for the manual sends.
		update_post_meta( $manual, '_refract_send', '1' );
		Sender::send( get_post( $manual ), '', true );

		$this->assertSame( 'out_of_credits', get_post_meta( $manual, '_refract_status', true ) );
		$this->assertSame( 'https://refract.test/billing', get_post_meta( $manual, '_refract_buy_url', true ) );
		$this->assertSame( 'You\'re out of AI credits for this post. It needs 2 and you have 1.', get_post_meta( $manual, '_refract_error', true ) );

		$user = $this->login_admin();
		$this->assertGreaterThan( 0, $user );
		$panel = $this->rest( 'GET', '/refract-admin/v1/posts/' . $manual )->get_data();
		$this->assertSame( 'out_of_credits', $panel['state'] );
		$this->assertSame( 2, $panel['needed'] );
		$this->assertSame( 1, $panel['left'] );

		$this->refract->on(
			'GET',
			'/api/v1/workspaces/ws-1/posts/refract-free/versions',
			200,
			array(
				'post_id'  => 'refract-free',
				'versions' => array(),
			)
		);
		$panel = $this->rest( 'POST', '/refract-admin/v1/posts/' . $manual . '/send', array( 'start_from' => 'finished' ) )->get_data();
		$this->assertSame( 'sent', $panel['state'] );
		$this->assertTrue( $panel['fallback'] );
		$this->assertSame( 'refract-free', $panel['refract']['post_id'] );
	}

	/**
	 * Only people who can edit the post can use the post routes.
	 */
	public function test_post_routes_need_edit_post(): void {
		$this->connect();
		$post_id = self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', '/refract-admin/v1/posts/' . $post_id )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/refract-admin/v1/posts/' . $post_id . '/send' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/refract-admin/v1/posts/' . $post_id . '/approve-all' )->get_status() );
	}

	/**
	 * A manual send is refused while the post's switch is off, and the panel says so.
	 */
	public function test_manual_send_respects_the_post_switch(): void {
		$this->connect();
		$post_id = self::factory()->post->create( array( 'meta_input' => array( '_refract_send' => '0' ) ) );

		$this->login_admin();
		$response = $this->rest( 'POST', '/refract-admin/v1/posts/' . $post_id . '/send' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'refract_switched_off', $response->get_data()['code'] );
		$this->assertCount( 0, $this->refract->sent( '/api/v1/workspaces/ws-1/notes', 'POST' ) );
		$this->assertFalse( $this->rest( 'GET', '/refract-admin/v1/posts/' . $post_id )->get_data()['can_send_now'] );
	}

	/**
	 * Approving goes to Refract and returns the panel with the new versions.
	 */
	public function test_approve_all(): void {
		$this->connect();
		$post_id = self::factory()->post->create( array( 'meta_input' => array( '_refract_post_id' => 'refract-post-1' ) ) );
		$this->refract->on(
			'POST',
			'/api/v1/workspaces/ws-1/posts/refract-post-1/approve-all',
			200,
			array(
				'post_id'  => 'refract-post-1',
				'queued'   => true,
				'versions' => array(
					array(
						'id'     => 'v1',
						'status' => 'scheduled',
					),
				),
			)
		);
		$this->login_admin();

		$panel = $this->rest( 'POST', '/refract-admin/v1/posts/' . $post_id . '/approve-all' )->get_data();

		$this->assertTrue( $panel['refract']['queued'] );
		$this->assertSame( 'sent', $panel['state'] );
		$this->assertSame( 'approved', Activity::entries()[0]['event'] );
	}

	/**
	 * Images without size metadata (HEIC, SVG, failed thumbnails) are sent without warnings.
	 */
	public function test_image_without_metadata(): void {
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'photo.heic',
				'post_mime_type' => 'image/heic',
			)
		);
		wp_update_attachment_metadata( $attachment, array() );

		$image = Sender::attachment_image( $attachment );

		$this->assertIsArray( $image );
		$this->assertStringEndsWith( 'photo.heic', $image['url'] );
	}

	/**
	 * The editor sees the latest event time through the heartbeat.
	 */
	public function test_heartbeat(): void {
		$post_id = self::factory()->post->create( array( 'meta_input' => array( '_refract_event_at' => 1234 ) ) );
		$this->login_admin();

		$response = apply_filters( 'heartbeat_received', array(), array( 'refract_post_id' => $post_id ), 'post' );

		$this->assertSame( 1234, $response['refract_event_at'] );
	}

	/**
	 * The post's sent state survives as WP_Post for the type checker.
	 */
	public function test_from_refract_helper(): void {
		$post = get_post( $this->draft( array( 'meta_input' => array( '_refract_source' => 'refract' ) ) ) );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertTrue( Sender::from_refract( $post->ID ) );
		$this->assertWPError( Sender::send( $post, '', true ) );
	}
}

<?php
/**
 * Webhook events, the Activity log, link previews and the classic meta box.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Activity;
use Refract\WP\Admin;
use Refract\WP\Connection;
use Refract\WP\Editor;
use Refract\WP\Link_Previews;
use Refract\WP\Settings;

/**
 * What happens after a post was sent.
 */
final class Test_Events extends Refract_Test_Case {

	/**
	 * A sent post.
	 */
	private function sent_post(): int {
		return self::factory()->post->create(
			array(
				'post_title' => 'Huila',
				'meta_input' => array(
					'_refract_post_id' => 'refract-post-1',
					'_refract_status'  => 'sent',
				),
			)
		);
	}

	/**
	 * An event's `post`.
	 *
	 * @param int $post_id The local post id.
	 * @return array<string, mixed>
	 */
	private function remote_post( int $post_id ): array {
		return array(
			'id'     => 'refract-post-1',
			'status' => 'scheduled',
			'source' => array(
				'type'        => 'wordpress',
				'account_id'  => 'site-account',
				'external_id' => (string) $post_id,
			),
		);
	}

	/**
	 * A failed post: notice on its edit screen, Activity entry with the fix.
	 */
	public function test_post_failed(): void {
		$this->connect();
		$post_id = $this->sent_post();

		$response = $this->signed(
			'POST',
			'/refract/v1/events',
			array(
				'id'   => 'evt_1',
				'type' => 'post.failed',
				'data' => array(
					'post'    => $this->remote_post( $post_id ),
					'account' => array(
						'id'       => 'acc-instagram',
						'platform' => 'instagram',
						'handle'   => '@kolocoffee',
					),
					'error'   => array(
						'code'    => 'token_expired',
						'message' => 'Instagram signed you out.',
						'fix'     => 'Connect Instagram again in Refract.',
					),
				),
			)
		);

		$this->assertSame( array( 'ok' => true ), $response->get_data() );
		$this->assertSame( 'Refract couldn\'t post this to Instagram · @kolocoffee. Instagram signed you out. Connect Instagram again in Refract.', get_post_meta( $post_id, '_refract_notice', true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, '_refract_event_at', true ) );

		$entry = Activity::entries( true )[0];
		$this->assertSame( 'failed', $entry['event'] );
		$this->assertSame( $post_id, $entry['post_id'] );
		$this->assertSame( 'Instagram · @kolocoffee', $entry['account'] );
		$this->assertFalse( $entry['ok'] );

		$this->login_admin();
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The edit screen's post.
		set_current_screen( 'post' );
		ob_start();
		Admin::notices();
		$this->assertStringContainsString( 'Instagram signed you out.', (string) ob_get_clean() );
	}

	/**
	 * Other events update the event time and the log; published ones link to the network post.
	 */
	public function test_post_events(): void {
		$this->connect();
		$post_id = $this->sent_post();

		$this->signed(
			'POST',
			'/refract/v1/events',
			array(
				'type' => 'post.versions_ready',
				'data' => array(
					'post'     => $this->remote_post( $post_id ),
					'versions' => array(),
				),
			)
		);
		$this->signed(
			'POST',
			'/refract/v1/events',
			array(
				'type' => 'post.published',
				'data' => array(
					'post'       => $this->remote_post( $post_id ),
					'account'    => array(
						'id'       => 'acc-linkedin',
						'platform' => 'linkedin',
						'handle'   => 'kolo',
					),
					'permalink'  => 'https://linkedin.example/post/1',
					'short_link' => 'https://rfr.ac/x',
				),
			)
		);
		$this->signed(
			'POST',
			'/refract/v1/events',
			array(
				'type' => 'post.nobody_knows',
				'data' => array( 'post' => $this->remote_post( $post_id ) ),
			)
		);

		$entries = Activity::entries();
		$this->assertSame( array( 'posted', 'versions_ready' ), array_column( $entries, 'event' ) );
		$this->assertSame( 'https://linkedin.example/post/1', $entries[0]['link'] );
		$this->assertSame( 'Huila', $entries[0]['post_title'] );
	}

	/**
	 * A disconnected account leaves the settings; this site's own account disconnects the site.
	 */
	public function test_account_disconnected(): void {
		$this->connect();

		$this->signed(
			'POST',
			'/refract/v1/events',
			array(
				'type' => 'account.disconnected',
				'data' => array(
					'account' => array(
						'id'       => 'acc-linkedin',
						'platform' => 'linkedin',
						'handle'   => 'kolo',
					),
				),
			)
		);
		$this->assertSame( array( 'acc-instagram' ), Settings::get()['accounts'] );
		$this->assertSame( 'connected', Connection::status() );

		$this->signed(
			'POST',
			'/refract/v1/events',
			array(
				'type' => 'account.disconnected',
				'data' => array(
					'account' => array(
						'id'       => 'site-account',
						'platform' => 'wordpress',
					),
				),
			)
		);
		$this->assertSame( 'disconnected', Connection::status() );
	}

	/**
	 * The Activity log keeps 30 days and at most 500 entries, newest first.
	 */
	public function test_activity_pruning(): void {
		update_option(
			Activity::OPTION,
			array(
				array(
					'id'      => 'old',
					'time'    => time() - 31 * DAY_IN_SECONDS,
					'post_id' => null,
					'event'   => 'sent',
					'account' => null,
					'ok'      => true,
					'message' => '',
					'link'    => null,
				),
			)
		);

		for ( $i = 0; $i < 505; $i++ ) {
			Activity::log( 'sent', array( 'message' => (string) $i ) );
		}

		$stored = get_option( Activity::OPTION );
		$this->assertCount( 500, $stored );
		$this->assertSame( '504', $stored[0]['message'] );
		$this->assertNotContains( 'old', array_column( $stored, 'id' ) );
		$this->assertCount( 100, Activity::entries() );
		$this->assertSame( array(), Activity::entries( true ) );

		$this->login_admin();
		$this->assertCount( 100, $this->rest( 'GET', '/refract-admin/v1/activity' )->get_data()['events'] );
	}

	/**
	 * Link previews: Open Graph and X card tags, with the 1200 × 630 crop, off with an SEO plugin.
	 */
	public function test_link_previews(): void {
		$file = get_temp_dir() . 'refract-og-' . wp_generate_password( 6, false ) . '.png';
		file_put_contents( $file, self::png() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$image   = self::factory()->attachment->create_upload_object( $file );
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Tom & Jerry "quoted"',
				'post_excerpt' => 'A short <b>summary</b>.',
			)
		);
		set_post_thumbnail( $post_id, $image );

		$this->go_to( get_permalink( $post_id ) );
		ob_start();
		Link_Previews::print_tags();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta property="og:type" content="article" />', $html );
		$this->assertStringContainsString( '<meta property="og:description" content="A short summary." />', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $html );
		$this->assertStringContainsString( 'property="og:image" content="' . esc_attr( wp_get_attachment_url( $image ) ), $html );
		$this->assertStringContainsString( 'property="og:title" content="Tom &amp; Jerry', $html );
		$this->assertTrue( has_image_size( Link_Previews::IMAGE_SIZE ) );

		// A title stored entity-encoded (no `unfiltered_html`) is decoded once, then escaped.
		$encoded = self::factory()->post->create( array( 'post_title' => 'Kolo &#038; Co.' ) );
		$this->go_to( get_permalink( $encoded ) );
		ob_start();
		Link_Previews::print_tags();
		$this->assertStringContainsString( '<meta property="og:title" content="Kolo &amp; Co." />', (string) ob_get_clean() );

		add_filter( 'refract_seo_plugin', static fn (): string => 'Rank Math' );
		ob_start();
		Link_Previews::print_tags();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * The classic meta box saves the switch and accounts only with a nonce and permission.
	 */
	public function test_classic_meta_box(): void {
		$this->connect();
		$this->refract->on(
			'GET',
			'/api/v1/workspaces/ws-1/accounts',
			200,
			array(
				'data' => array(
					array(
						'id'         => 'acc-instagram',
						'attributes' => array(
							'platform' => 'instagram',
							'label'    => 'Instagram',
							'handle'   => '@kolocoffee',
						),
					),
				),
			)
		);
		$this->login_admin();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		ob_start();
		Editor::render_meta_box( get_post( $post_id ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="refract_meta_box"', $html );
		$this->assertStringContainsString( 'name="refract_fields"', $html );
		$this->assertStringContainsString( 'name="refract_accounts_listed"', $html );
		$this->assertStringContainsString( '<div id="refract-classic" data-post-id="' . $post_id . '"></div>', $html );

		$_POST = array(
			'refract_fields'          => '1',
			'refract_accounts_listed' => '1',
			'refract_send'            => '1',
			'refract_accounts'        => array( 'acc-instagram' ),
		);
		Editor::save_meta_box( $post_id, get_post( $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, '_refract_send', true ), 'No nonce, nothing saved.' );
		$this->assertSame( array(), get_post_meta( $post_id, '_refract_accounts', true ) );

		$_POST['refract_meta_box'] = wp_create_nonce( 'refract_meta_box' );
		unset( $_POST['refract_send'] );
		Editor::save_meta_box( $post_id, get_post( $post_id ) );
		$this->assertSame( '0', get_post_meta( $post_id, '_refract_send', true ) );
		$this->assertSame( array( 'acc-instagram' ), get_post_meta( $post_id, '_refract_accounts', true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['refract_meta_box'] = wp_create_nonce( 'refract_meta_box' );
		$_POST['refract_send']     = '1';
		Editor::save_meta_box( $post_id, get_post( $post_id ) );
		$this->assertSame( '0', get_post_meta( $post_id, '_refract_send', true ), 'People who can\'t edit the post change nothing.' );

		$_POST = array();
	}

	/**
	 * Unchecking every account in the classic meta box means "don't send this post".
	 */
	public function test_classic_meta_box_no_accounts(): void {
		$this->connect();
		$this->login_admin();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$_POST = array(
			'refract_meta_box'        => wp_create_nonce( 'refract_meta_box' ),
			'refract_fields'          => '1',
			'refract_accounts_listed' => '1',
			'refract_send'            => '1',
		);
		Editor::save_meta_box( $post_id, get_post( $post_id ) );
		$_POST = array();

		$this->assertSame( '0', get_post_meta( $post_id, '_refract_send', true ) );
		$this->assertSame( array(), get_post_meta( $post_id, '_refract_accounts', true ) );
	}

	/**
	 * A queued post saved through the classic form (which shows no fields then) keeps its switch and still sends.
	 */
	public function test_classic_save_without_fields_keeps_switch(): void {
		$this->connect( array( 'send_mode' => 'manual' ) );
		$this->login_admin();
		$this->refract->on( 'POST', '/api/v1/workspaces/ws-1/notes', 202, array( 'post_id' => 'refract-post-7' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'meta_input'  => array( '_refract_send' => '1' ),
			)
		);
		wp_publish_post( $post_id );
		$this->assertSame( 'queued', get_post_meta( $post_id, '_refract_status', true ) );

		$_POST = array( 'refract_meta_box' => wp_create_nonce( 'refract_meta_box' ) );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Saved again while queued',
			)
		);
		$_POST = array();

		$this->assertSame( '1', get_post_meta( $post_id, '_refract_send', true ) );

		\Refract\WP\Sender::run( $post_id, '' );
		$this->assertSame( 'refract-post-7', get_post_meta( $post_id, '_refract_post_id', true ) );
	}

	/**
	 * The editable meta is in REST for editors; the internal meta isn't.
	 */
	public function test_meta_in_rest(): void {
		$this->login_admin();
		$post_id = self::factory()->post->create( array( 'meta_input' => array( '_refract_post_id' => 'secret-ish' ) ) );

		$response = $this->rest(
			'POST',
			'/wp/v2/posts/' . $post_id,
			array(
				'meta' => array(
					'_refract_send'         => '1',
					'_refract_accounts'     => array( 'acc-1' ),
					'_refract_social_image' => 5,
				),
			)
		);
		$meta     = $response->get_data()['meta'];

		$this->assertSame( '1', $meta['_refract_send'] );
		$this->assertSame( array( 'acc-1' ), $meta['_refract_accounts'] );
		$this->assertSame( 5, $meta['_refract_social_image'] );
		$this->assertArrayNotHasKey( '_refract_post_id', $meta );
	}
}

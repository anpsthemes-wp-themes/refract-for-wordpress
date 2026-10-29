<?php
/**
 * Uninstall and multisite tests.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Activity;
use Refract\WP\Connection;
use Refract\WP\Plugin;
use Refract\WP\Secrets;
use Refract\WP\Settings;
use Refract\WP\Uninstaller;

/**
 * Cleanup, and each site of a network keeping its own connection.
 */
final class Test_Uninstall extends Refract_Test_Case {

	/**
	 * Uninstall deletes options, post meta, transients and events, even when Refract can't be reached.
	 */
	public function test_uninstall_cleanup(): void {
		$this->connect();
		$this->refract->unreachable( 'DELETE', '/api/v1/workspaces/ws-1/accounts/wordpress/site-account' );
		Activity::log( 'sent' );
		set_transient( 'refract_lock_5', 1, 60 );
		set_transient( 'refract_sig_abc', 1, 600 );
		$post_id = self::factory()->post->create(
			array(
				'meta_input' => array(
					'_refract_post_id' => 'refract-post-1',
					'_refract_send'    => '1',
					'_refract_hash'    => 'abc',
					'unrelated_meta'   => 'keep',
				),
			)
		);
		Plugin::activate_site();
		wp_schedule_single_event( time() + 60, Plugin::CRON_SEND, array( $post_id, '' ) );

		Uninstaller::run();

		$this->assertCount( 1, $this->refract->sent( '/api/v1/workspaces/ws-1/accounts/wordpress/site-account', 'DELETE' ), 'Uninstall tries to remove the site from Refract.' );

		foreach ( array( Settings::OPTION, Settings::AFTER_VERSIONS, Connection::OPTION, Secrets::OPTION, 'refract_oauth_client', Activity::OPTION ) as $option ) {
			$this->assertSame( 'gone', get_option( $option, 'gone' ), $option );
		}

		$this->assertFalse( get_transient( 'refract_lock_5' ) );
		$this->assertFalse( get_transient( 'refract_sig_abc' ) );
		$keys = array_keys( get_post_meta( $post_id ) );
		$this->assertSame( array(), array_values( array_filter( $keys, static fn ( string $key ): bool => str_starts_with( $key, '_refract_' ) ) ) );
		$this->assertContains( 'unrelated_meta', $keys );
		$this->assertFalse( wp_next_scheduled( Plugin::CRON_SEND, array( $post_id, '' ) ) );
		$this->assertFalse( wp_next_scheduled( Plugin::CRON_PRUNE ) );
	}

	/**
	 * Deactivating stops scheduled events and keeps the connection.
	 */
	public function test_deactivate_keeps_connection(): void {
		$this->connect();
		Plugin::activate();
		wp_schedule_single_event( time() + 60, Plugin::CRON_SEND, array( 1, '' ) );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Plugin::CRON_PRUNE ) );
		$this->assertFalse( wp_next_scheduled( Plugin::CRON_SEND, array( 1, '' ) ) );
		$this->assertSame( 'connected', Connection::status() );
	}

	/**
	 * Each site connects separately; network activation and uninstall cover every site.
	 */
	public function test_multisite_sites_are_separate(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs in multisite (phpunit-multisite.xml.dist or WP_MULTISITE=1).' );
		}

		$main  = get_current_blog_id();
		$other = self::factory()->blog->create();

		$this->connect();
		$main_secret = Secrets::get()['signing_secret'];

		switch_to_blog( $other );
		$this->assertSame( 'disconnected', Connection::status(), 'A new site starts disconnected.' );
		$this->assertSame( '', Secrets::get()['signing_secret'] );
		$this->assertSame( array(), Settings::get()['accounts'] );

		Secrets::update(
			array(
				'access_token'   => 'access-other',
				'signing_secret' => Secrets::new_signing_secret(),
			)
		);
		Connection::update(
			array(
				'status'     => 'connected',
				'workspace'  => array(
					'id'   => 'ws-2',
					'name' => 'Other',
					'slug' => 'other',
				),
				'account_id' => 'site-account-2',
			)
		);
		$other_secret = Secrets::get()['signing_secret'];
		restore_current_blog();

		$this->assertNotSame( $main_secret, $other_secret );
		$this->assertSame( 'site-account', Connection::get()['account_id'] );
		$this->assertSame( $main_secret, Secrets::get()['signing_secret'] );

		$timestamp = (string) time();
		switch_to_blog( $other );
		$response = $this->rest(
			'GET',
			'/refract/v1/site',
			array(),
			array(
				'X-Refract-Timestamp' => $timestamp,
				'X-Refract-Signature' => hash_hmac( 'sha256', $timestamp . '.', $main_secret ),
			)
		);
		restore_current_blog();
		$this->assertSame( 401, $response->get_status(), 'One site\'s secret doesn\'t open another site.' );

		_set_cron_array( array() );
		Plugin::activate( true );
		switch_to_blog( $other );
		$this->assertIsInt( wp_next_scheduled( Plugin::CRON_PRUNE ), 'Network activation sets up every site.' );
		restore_current_blog();
		$this->assertIsInt( wp_next_scheduled( Plugin::CRON_PRUNE ) );

		$this->refract->on( 'DELETE', '/api/v1/workspaces/ws-1/accounts/wordpress/site-account', 204 );
		$this->refract->on( 'DELETE', '/api/v1/workspaces/ws-2/accounts/wordpress/site-account-2', 204 );
		Uninstaller::run();

		$this->assertCount( 2, $this->refract->sent( '/api/v1/workspaces/.*/accounts/wordpress/.*', 'DELETE' ) );
		$this->assertSame( $main, get_current_blog_id() );
		$this->assertSame( 'disconnected', Connection::status() );
		switch_to_blog( $other );
		$this->assertSame( 'disconnected', Connection::status() );
		$this->assertFalse( get_option( Secrets::OPTION ) );
		restore_current_blog();
	}
}

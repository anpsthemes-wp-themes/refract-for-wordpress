<?php
/**
 * Settings tests.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use Refract\WP\Settings;

/**
 * Saving and sanitizing the settings.
 */
final class Test_Settings extends Refract_Test_Case {

	/**
	 * A site that saved nothing gets the defaults.
	 */
	public function test_defaults(): void {
		$settings = Settings::get();

		$this->assertSame( 'publish', $settings['send_mode'] );
		$this->assertSame( array( 'post' ), $settings['post_types'] );
		$this->assertSame( 'note', $settings['write_mode'] );
		$this->assertSame( 'crop', $settings['images'] );
		$this->assertSame( 'update', $settings['on_update'] );
		$this->assertFalse( $settings['refract_publish'] );
		$this->assertSame( 'publish', $settings['refract_can'] );
		$this->assertTrue( $settings['link_previews'] );
		$this->assertFalse( $settings['setup_done'] );
		$this->assertSame( 'wait_for_approval', get_option( Settings::AFTER_VERSIONS ) );
	}

	/**
	 * Invalid values are dropped and valid ones kept; unknown keys never reach the option.
	 */
	public function test_update_sanitizes(): void {
		$category   = self::factory()->category->create();
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		Settings::update(
			array(
				'send_mode'        => 'manual',
				'write_mode'       => 'nonsense',
				'images'           => 'crop_or_make',
				'post_types'       => array( 'page', 'attachment', 'nope', array( 'x' ) ),
				'categories'       => array( $category, 999999, '0', 'abc' ),
				'accounts'         => array( 'acc-1', 'acc-1', '<script>', 42, 'acc_2' ),
				'refract_publish'  => '1',
				'publish_as'       => $editor,
				'default_category' => 999999,
				'refract_can'      => 'drafts',
				'link_previews'    => 'false',
				'evil'             => 'yes',
			)
		);

		$settings = Settings::get();
		$stored   = get_option( Settings::OPTION );

		$this->assertSame( 'manual', $settings['send_mode'] );
		$this->assertSame( 'note', $settings['write_mode'] );
		$this->assertSame( 'crop_or_make', $settings['images'] );
		$this->assertSame( array( 'page' ), $settings['post_types'] );
		$this->assertSame( array( $category ), $settings['categories'] );
		$this->assertSame( array( 'acc-1', 'acc_2' ), $settings['accounts'] );
		$this->assertTrue( $settings['refract_publish'] );
		$this->assertSame( $editor, $settings['publish_as'] );
		$this->assertSame( 0, $settings['default_category'] );
		$this->assertSame( 'drafts', $settings['refract_can'] );
		$this->assertFalse( $settings['link_previews'] );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'evil', $stored );

		Settings::update( array( 'publish_as' => $subscriber ) );
		$this->assertSame( 0, Settings::get()['publish_as'], 'Only people who can publish posts can be "Publish as".' );

		Settings::update( array( 'images' => 'crop' ) );
		$this->assertSame( 'manual', Settings::get()['send_mode'], 'Saving some keys keeps the others.' );
	}

	/**
	 * `refract_after_versions` only has one value.
	 */
	public function test_after_versions_is_fixed(): void {
		update_option( Settings::AFTER_VERSIONS, 'autopilot' );

		$this->assertSame( 'wait_for_approval', get_option( Settings::AFTER_VERSIONS ) );
	}

	/**
	 * The settings route saves for admins, refuses others, and needs "Publish as" when publishing is on.
	 */
	public function test_settings_route(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$this->assertSame( 403, $this->rest( 'POST', '/refract-admin/v1/settings', array( 'settings' => array( 'send_mode' => 'manual' ) ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/refract-admin/v1/state' )->get_status() );

		$this->login_admin();

		$response = $this->rest( 'POST', '/refract-admin/v1/settings', array( 'settings' => array( 'send_mode' => 'manual' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'manual', $response->get_data()['settings']['send_mode'] );
		$this->assertSame( 'wait_for_approval', $response->get_data()['after_versions'] );
		$this->assertSame( 'disconnected', $response->get_data()['connection']['status'] );

		$response = $this->rest( 'POST', '/refract-admin/v1/settings', array( 'settings' => array( 'refract_publish' => true ) ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertFalse( Settings::get()['refract_publish'] );
	}

	/**
	 * The state names an active SEO plugin, and the settings are never exposed in core REST settings.
	 */
	public function test_seo_plugin_and_no_core_rest_exposure(): void {
		$this->login_admin();
		add_filter( 'refract_seo_plugin', static fn (): string => 'Yoast SEO' );

		$this->assertSame( 'Yoast SEO', $this->rest( 'GET', '/refract-admin/v1/state' )->get_data()['seo_plugin'] );

		$core = $this->rest( 'GET', '/wp/v2/settings' )->get_data();
		$this->assertArrayNotHasKey( Settings::OPTION, $core );
		$this->assertArrayNotHasKey( 'refract_secrets', $core );
	}
}

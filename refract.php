<?php
/**
 * Plugin Name:       Refract – AI Social Media Scheduler for Blog Posts
 * Plugin URI:        https://refract.social/wordpress-plugin
 * Description:       Share each blog post to Bluesky, Mastodon, Pinterest and Telegram. AI writes a version for each network in your voice; you approve before it posts.
 * Version:           1.2.2
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Refract
 * Author URI:        https://anpsthemes.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       refract
 * Domain Path:       /languages
 *
 * @package Refract
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, array( Refract\WP\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Refract\WP\Plugin::class, 'deactivate' ) );

Refract\WP\Plugin::boot();

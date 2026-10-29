<?php
/**
 * Plugin Name:       Refract
 * Plugin URI:        https://refract.social/platforms/wordpress
 * Description:       Turn each blog post into a post for every social network, in your voice. Approve every one, then let Refract schedule them.
 * Version:           1.2.0
 * Requires at least: 6.8
 * Tested up to:      7.1
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

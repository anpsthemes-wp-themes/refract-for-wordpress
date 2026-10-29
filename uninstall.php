<?php
/**
 * Deletes everything the plugin stored, on every site of a network, and tries to remove the site from Refract.
 *
 * @package Refract
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/autoload.php';

Refract\WP\Uninstaller::run();

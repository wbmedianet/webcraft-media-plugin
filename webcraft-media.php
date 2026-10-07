<?php
/**
 * Plugin Name:       Webcraft Media
 * Plugin URI:        https://github.com/wbmedianet/webcraft-media-plugin
 * Description:       Updates for the themes and plugins built for your site by Webcraft Media.
 * Version:           1.1.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Webcraft Media
 * Author URI:        https://webcraftmedia.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/wbmedianet/webcraft-media-plugin
 * Text Domain:       webcraft-media
 * Domain Path:       /languages
 *
 * @package WebcraftMedia
 */

namespace WebcraftMedia;

defined( 'ABSPATH' ) || exit;

const PLUGIN_FILE = __FILE__;

/*
 * Website shown to clients when they need to get in touch.
 */
const CONTACT_URL = 'https://webcraftmedia.net';

require __DIR__ . '/includes/github.php';
require __DIR__ . '/includes/updates.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/exports.php';

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'webcraft-media', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}
);

// First check right away, so the settings page has a status to show.
register_activation_hook( PLUGIN_FILE, __NAMESPACE__ . '\check_now' );

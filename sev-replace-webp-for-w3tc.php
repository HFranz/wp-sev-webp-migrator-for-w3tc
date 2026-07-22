<?php
/**
 * Plugin Name: SEV Replace WebP for W3TC
 * Description: Permanently rewrites image URLs in all posts from their original extension to the WebP version once W3 Total Cache ImageService has converted them, with an option to delete the original source images.
 * Author: Heinrich Franz
 * Author URI: https://sevmatic.com
 * Plugin URI: https://github.com/HFranz/wp-sev-replace-webp-for-w3tc
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Version: 1.0.0
 * Text Domain: sev-replace-webp-for-w3tc
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: w3-total-cache
 *
 * php version 8.0
 *
 * @package SevReplaceWebPForW3TC
 */

use SevReplaceWebPForW3TC\Admin_Settings;
use SevReplaceWebPForW3TC\Conversion_Listener;
use SevReplaceWebPForW3TC\Processor;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-attachment-urls.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-content-replacer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-attachment-migrator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-source-cleaner.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-processor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-conversion-listener.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-admin-settings.php';

/**
 * Register hooks only when W3TC is active.
 * For mu-plugins "Requires Plugins" has no effect – this guard handles the check at runtime.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! defined( 'W3TC' ) ) {
			return;
		}

		$processor = new Processor();

		$conversion_listener = new Conversion_Listener( $processor );
		add_action( 'added_post_meta', array( $conversion_listener, 'on_meta_write' ), 10, 4 );
		add_action( 'updated_post_meta', array( $conversion_listener, 'on_meta_write' ), 10, 4 );

		if ( is_admin() ) {
			( new Admin_Settings( $processor ) )->register();
		}
	}
);

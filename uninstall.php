<?php
/**
 * Uninstall routine for SEV Replace WebP for W3TC.
 *
 * Called automatically by WordPress when the plugin is deleted via the admin UI.
 * Removes the plugin's own setting. Post content and attachment records that
 * were already rewritten to WebP are left as-is, since the rewrite is a
 * one-time, permanent change independent of the plugin remaining active.
 *
 * @package SevReplaceWebPForW3TC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die();
}

delete_option( 'sevrwfw3tc_delete_originals' );

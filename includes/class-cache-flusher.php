<?php
/**
 * Purges W3 Total Cache's caches for content this plugin has rewritten.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Content_Replacer and Options_Replacer write directly to the database,
 * which bypasses the save hooks W3 Total Cache normally purges its page
 * cache on. Without an explicit purge, cached pages would keep serving the
 * old jpg/png/gif URLs - and once Source_Cleaner has deleted those files,
 * show broken images until the cache happens to expire.
 *
 * Purges per post where possible. Falls back to purging every cached post
 * page when the change affects every page at once: a widget, a theme mod,
 * or a post type rendered site-wide (Additional CSS, block theme templates,
 * global styles, navigation menus).
 */
class Cache_Flusher {

	/** Post types whose content is rendered on every page rather than on its own URL. */
	private const SITEWIDE_POST_TYPES = array(
		'custom_css',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
	);

	/**
	 * Purges the cache for the given rewritten posts and/or options.
	 *
	 * A no-op if W3 Total Cache's flush API is not available.
	 *
	 * @param int[] $post_ids        IDs of posts whose content was rewritten.
	 * @param bool  $options_changed Whether a widget or theme mod option was rewritten.
	 * @return void
	 */
	public function flush( array $post_ids, bool $options_changed ): void {
		if ( empty( $post_ids ) && ! $options_changed ) {
			return;
		}

		if ( ! function_exists( 'w3tc_flush_post' ) || ! function_exists( 'w3tc_flush_posts' ) ) {
			return;
		}

		if ( $options_changed || $this->contains_sitewide_post( $post_ids ) ) {
			w3tc_flush_posts();
			return;
		}

		foreach ( $post_ids as $post_id ) {
			w3tc_flush_post( $post_id );
		}
	}

	/**
	 * Whether any of the posts is of a post type rendered on every page.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return bool
	 */
	private function contains_sitewide_post( array $post_ids ): bool {
		foreach ( $post_ids as $post_id ) {
			if ( in_array( get_post_type( $post_id ), self::SITEWIDE_POST_TYPES, true ) ) {
				return true;
			}
		}

		return false;
	}
}

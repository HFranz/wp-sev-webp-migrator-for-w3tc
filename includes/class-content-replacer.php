<?php
/**
 * Rewrites converted image URLs directly in post content.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Permanently replaces old-extension image URLs with their WebP counterparts
 * inside `wp_posts.post_content`, across all posts that reference them.
 *
 * Unlike a runtime content filter, this writes the replacement back to the
 * database once, so every future request (including feeds, REST API, search
 * indexing, etc.) already sees the WebP URL.
 */
class Content_Replacer {

	/**
	 * Replaces every old→new URL pair in the content of all posts that reference them.
	 *
	 * @param array<int, array{old: string, new: string}> $url_pairs Old/new URL pairs.
	 * @return int Number of distinct posts updated.
	 */
	public function replace( array $url_pairs ): int {
		global $wpdb;

		if ( empty( $url_pairs ) ) {
			return 0;
		}

		$updated_post_ids = array();

		foreach ( $url_pairs as $pair ) {
			$old = $pair['old'];
			$new = $pair['new'];

			if ( '' === $old || $old === $new ) {
				continue;
			}

			// Narrow down to posts that actually contain this URL before rewriting.
			$like = '%' . $wpdb->esc_like( $old ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$matches = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM $wpdb->posts WHERE post_content LIKE %s AND post_type != 'revision'",
					$like
				)
			);

			if ( empty( $matches ) ) {
				continue;
			}

			foreach ( $matches as $row ) {
				$new_content = str_replace( $old, $new, $row->post_content );

				if ( $new_content === $row->post_content ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->posts,
					array( 'post_content' => $new_content ),
					array( 'ID' => $row->ID )
				);

				clean_post_cache( $row->ID );
				$updated_post_ids[ $row->ID ] = true;
			}
		}

		return count( $updated_post_ids );
	}
}

<?php
/**
 * Migrates an attachment's own record to its WebP files.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Once post content has been rewritten to point at the .webp files, the
 * attachment post itself (`_wp_attached_file`, `_wp_attachment_metadata`,
 * `post_mime_type`) is updated to match. Without this step the Media Library,
 * REST API, and any future call to wp_get_attachment_image() would keep
 * pointing at the old-extension file.
 */
class Attachment_Migrator {

	/**
	 * Repoints the attachment's own metadata at its WebP files.
	 *
	 * Takes the already-resolved old/new path pairs from
	 * {@see Processor::resolve_webp_case()} instead of re-predicting them here.
	 * That resolution can involve more than a plain extension swap - a
	 * different filesystem case, W3TC's own "child attachment" record (see
	 * {@see Attachment_Urls::w3tc_child_webp()}), or a regenerated intermediate
	 * size - and re-deriving it independently here previously meant this
	 * method could disagree with what content_replacer->replace() had just
	 * used, silently leaving the attachment unmigrated even though its post
	 * content already pointed at valid, working WebP URLs.
	 *
	 * @param int                                            $attachment_id Attachment post ID.
	 * @param array<int, array{old: string, new: string}>    $path_pairs    Resolved old/new path pairs, full size first
	 *                                                                      (see {@see Attachment_Urls::path_pairs()}).
	 * @return bool True if the attachment record was updated.
	 */
	public function migrate( int $attachment_id, array $path_pairs ): bool {
		if ( empty( $path_pairs ) ) {
			return false;
		}

		$attached_file = $path_pairs[0]['old'];
		$webp_file     = $path_pairs[0]['new'];

		if ( ! file_exists( $webp_file ) ) {
			return false;
		}

		update_attached_file( $attachment_id, $webp_file );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) ) {
			$full_filename = basename( $attached_file );
			$base_dir      = trailingslashit( dirname( $attached_file ) );

			if ( ! empty( $metadata['file'] ) && is_string( $metadata['file'] ) && str_ends_with( $metadata['file'], $full_filename ) ) {
				$metadata['file'] = substr( $metadata['file'], 0, -strlen( $full_filename ) ) . basename( $webp_file );
			}

			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$resolved_sizes = array();
				foreach ( array_slice( $path_pairs, 1 ) as $pair ) {
					$resolved_sizes[ $pair['old'] ] = $pair['new'];
				}

				foreach ( $metadata['sizes'] as $size_name => $size ) {
					if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
						$size_webp_file = $resolved_sizes[ $base_dir . $size['file'] ] ?? null;

						if ( null !== $size_webp_file ) {
							$metadata['sizes'][ $size_name ]['file']      = basename( $size_webp_file );
							$metadata['sizes'][ $size_name ]['mime-type'] = 'image/webp';
						}
					}
				}
			}

			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'image/webp',
			)
		);

		return true;
	}
}

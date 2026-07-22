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
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True if the attachment record was updated.
	 */
	public function migrate( int $attachment_id ): bool {
		$attached_file = get_attached_file( $attachment_id, true );
		if ( ! is_string( $attached_file ) || '' === $attached_file ) {
			return false;
		}

		$webp_file = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $attached_file );
		if ( null === $webp_file || $webp_file === $attached_file || ! file_exists( $webp_file ) ) {
			return false;
		}

		update_attached_file( $attachment_id, $webp_file );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['file'] ) && is_string( $metadata['file'] ) ) {
				$metadata['file'] = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $metadata['file'] );
			}

			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_name => $size ) {
					if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
						$metadata['sizes'][ $size_name ]['file']      = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $size['file'] );
						$metadata['sizes'][ $size_name ]['mime-type'] = 'image/webp';
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

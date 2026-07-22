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
		if ( null === $webp_file || $webp_file === $attached_file ) {
			return false;
		}

		// W3TC may have written the file with an uppercase .WEBP extension
		// (e.g. following an uploaded "photo.JPG"); resolve to whichever case
		// actually exists so the DB never points at a nonexistent file.
		$webp_file = Attachment_Urls::resolve_case( $webp_file );
		if ( null === $webp_file ) {
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
				foreach ( $metadata['sizes'] as $size_name => $size ) {
					if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
						$predicted_webp_file = Attachment_Urls::webp_size_filename( $full_filename, $size['file'] );
						$size_webp_file       = null !== $predicted_webp_file
							? Attachment_Urls::resolve_case( $base_dir . $predicted_webp_file )
							: null;

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

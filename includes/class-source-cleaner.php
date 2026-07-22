<?php
/**
 * Deletes original source images once they have been replaced.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Removes the old-extension files (full size and every intermediate size)
 * from disk. Only ever called after content has been rewritten and the
 * attachment record migrated, and only ever deletes a source file once its
 * .webp counterpart has been confirmed to exist.
 */
class Source_Cleaner {

	/**
	 * Deletes the old-extension files for which a WebP counterpart exists.
	 *
	 * @param array<int, array{old: string, new: string}> $path_pairs Filesystem old/new path pairs.
	 * @return int Number of files deleted.
	 */
	public function delete_originals( array $path_pairs ): int {
		$upload_dir = wp_upload_dir();
		$uploads_basedir = isset( $upload_dir['basedir'] ) ? realpath( $upload_dir['basedir'] ) : false;

		$deleted = 0;

		foreach ( $path_pairs as $pair ) {
			$old = $pair['old'];
			$new = $pair['new'];

			if ( '' === $old || $old === $new || ! file_exists( $new ) || ! file_exists( $old ) ) {
				continue;
			}

			// Only ever delete files that resolve inside the uploads directory.
			$real_old = realpath( $old );
			if ( false === $real_old || false === $uploads_basedir || ! str_starts_with( $real_old, $uploads_basedir . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			wp_delete_file( $old );

			if ( ! file_exists( $old ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}
}

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

			// Only ever delete files that resolve inside uploads directory, and
			// only in favour of a replacement that lives there as well.
			if ( ! self::is_inside( $old, $uploads_basedir ) || ! self::is_inside( $new, $uploads_basedir ) ) {
				continue;
			}

			// The replacement must be a readable, non-empty image: a 0-byte or
			// truncated .webp left behind by a failed conversion would otherwise
			// still count as "exists" and the original would be lost for good.
			if ( ! self::is_valid_image( $new ) ) {
				continue;
			}

			wp_delete_file( $old );

			if ( ! file_exists( $old ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Whether a path resolves (symlinks followed) to a location inside the given directory.
	 *
	 * @param string       $path    Filesystem path.
	 * @param string|false $basedir Already realpath()-resolved directory, or false if unknown.
	 * @return bool
	 */
	private static function is_inside( string $path, string|false $basedir ): bool {
		if ( false === $basedir ) {
			return false;
		}

		$real = realpath( $path );

		return false !== $real && str_starts_with( $real, $basedir . DIRECTORY_SEPARATOR );
	}

	/**
	 * Whether a file is a non-empty image with readable dimensions.
	 *
	 * @param string $path Filesystem path.
	 * @return bool
	 */
	private static function is_valid_image( string $path ): bool {
		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			return false;
		}

		$info = wp_getimagesize( $path );

		return is_array( $info ) && $info[0] > 0 && $info[1] > 0;
	}
}

<?php
/**
 * Builds old→new URL/path pairs for a converted attachment.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Resolves every file W3TC ImageService generates for an attachment
 * (the full-size file plus every registered intermediate size) and
 * pairs each one with its WebP counterpart, as URL and as filesystem path.
 */
class Attachment_Urls {

	/** Extensions W3TC ImageService can convert to WebP. */
	private const CONVERTIBLE_EXTENSION = '/\.(jpe?g|png|gif)$/i';

	/** Matches a "-scaled" full-size filename, capturing the part before it. */
	private const SCALED_FULL_FILE = '/^(.*)-scaled\.(?:jpe?g|png|gif)$/i';

	/** Matches the "-{width}x{height}" dimension suffix WordPress appends to intermediate size filenames. */
	private const SIZE_DIMENSION_SUFFIX = '/(-\d+x\d+)\.(?:jpe?g|png|gif)$/i';

	/**
	 * Builds the list of old/new URL pairs for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<int, array{old: string, new: string}> URL pairs, largest/original first.
	 */
	public static function url_pairs( int $attachment_id ): array {
		$full_url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $full_url ) || '' === $full_url ) {
			return array();
		}

		return self::build_pairs( $full_url, wp_get_attachment_metadata( $attachment_id ) );
	}

	/**
	 * Builds the list of old/new filesystem paths for an attachment,
	 * mirroring {@see self::url_pairs()} but resolved against the upload basedir.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<int, array{old: string, new: string}> Filesystem path pairs.
	 */
	public static function path_pairs( int $attachment_id ): array {
		$full_path = get_attached_file( $attachment_id );
		if ( ! is_string( $full_path ) || '' === $full_path ) {
			return array();
		}

		return self::build_pairs( $full_path, wp_get_attachment_metadata( $attachment_id ) );
	}

	/**
	 * Shared pair-building logic for both URLs and filesystem paths.
	 *
	 * @param string       $full_path_or_url Full-size path or URL.
	 * @param mixed        $metadata         Return value of wp_get_attachment_metadata().
	 * @return array<int, array{old: string, new: string}> Old/new pairs.
	 */
	private static function build_pairs( string $full_path_or_url, mixed $metadata ): array {
		$pairs = array();

		$webp_full = self::to_webp( $full_path_or_url );
		if ( null !== $webp_full ) {
			$pairs[] = array(
				'old' => $full_path_or_url,
				'new' => $webp_full,
			);
		}

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $pairs;
		}

		$base_dir    = trailingslashit( dirname( $full_path_or_url ) );
		$scaled_base = self::scaled_base( $full_path_or_url );

		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}

			$size_path_or_url = $base_dir . $size['file'];
			$webp             = self::to_webp_for_size( $size['file'], $base_dir, $scaled_base );
			if ( null !== $webp ) {
				$pairs[] = array(
					'old' => $size_path_or_url,
					'new' => $webp,
				);
			}
		}

		return $pairs;
	}

	/**
	 * Resolves the WebP counterpart of an intermediate size file.
	 *
	 * W3TC ImageService names intermediate-size WebP files after the "-scaled"
	 * full file (e.g. "photo-scaled-300x200.webp"), not after each size's own
	 * filename as recorded in the attachment metadata (e.g. "photo-300x200.jpg",
	 * without "-scaled" - that's WordPress' own naming for sizes generated from
	 * the pre-scale original). Falling back to a plain extension swap would
	 * build a path that W3TC never actually writes.
	 *
	 * @param string      $size_file   Size filename from the attachment metadata.
	 * @param string      $base_dir    Trailing-slashed directory the file lives in.
	 * @param string|null $scaled_base Basename (without extension) of the "-scaled" full file, or null if none.
	 * @return string|null The .webp path/URL, or null if the extension is not convertible.
	 */
	private static function to_webp_for_size( string $size_file, string $base_dir, ?string $scaled_base ): ?string {
		if ( null !== $scaled_base && preg_match( self::SIZE_DIMENSION_SUFFIX, $size_file, $matches ) ) {
			return $base_dir . $scaled_base . $matches[1] . '.webp';
		}

		return self::to_webp( $base_dir . $size_file );
	}

	/**
	 * Extracts the "-scaled" basename (without extension) from a full-size path/URL.
	 *
	 * @param string $full_path_or_url Full-size path or URL.
	 * @return string|null Basename ending in "-scaled", or null if this attachment has no scaled full size.
	 */
	private static function scaled_base( string $full_path_or_url ): ?string {
		if ( ! preg_match( self::SCALED_FULL_FILE, basename( $full_path_or_url ), $matches ) ) {
			return null;
		}

		return $matches[1] . '-scaled';
	}

	/**
	 * Swaps a convertible image extension (jpg/jpeg/png/gif) for .webp.
	 *
	 * @param string $path_or_url Filesystem path or URL.
	 * @return string|null The .webp variant, or null if the extension is not convertible.
	 */
	private static function to_webp( string $path_or_url ): ?string {
		if ( ! preg_match( self::CONVERTIBLE_EXTENSION, $path_or_url ) ) {
			return null;
		}

		return preg_replace( self::CONVERTIBLE_EXTENSION, '.webp', $path_or_url );
	}
}

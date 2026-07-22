<?php
/**
 * Builds old→new URL/path pairs for a converted attachment.
 *
 * @package SevReplaceWebPForW3TC
 */

namespace SevReplaceWebPForW3TC;

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

	/**
	 * Builds the list of old/new URL pairs for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<int, array{old: string, new: string}> URL pairs, largest/original first.
	 */
	public static function url_pairs( int $attachment_id ): array {
		$pairs = array();

		$full_url = wp_get_attachment_url( $attachment_id );
		if ( is_string( $full_url ) && '' !== $full_url ) {
			$webp_url = self::to_webp( $full_url );
			if ( null !== $webp_url ) {
				$pairs[] = array(
					'old' => $full_url,
					'new' => $webp_url,
				);
			}
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $pairs;
		}

		$base_dir = trailingslashit( dirname( (string) $full_url ) );

		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}

			$size_url = $base_dir . $size['file'];
			$webp_url = self::to_webp( $size_url );
			if ( null !== $webp_url ) {
				$pairs[] = array(
					'old' => $size_url,
					'new' => $webp_url,
				);
			}
		}

		return $pairs;
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

		$pairs    = array();
		$webp_path = self::to_webp( $full_path );
		if ( null !== $webp_path ) {
			$pairs[] = array(
				'old' => $full_path,
				'new' => $webp_path,
			);
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $pairs;
		}

		$base_dir = trailingslashit( dirname( $full_path ) );

		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}

			$size_path = $base_dir . $size['file'];
			$webp_path = self::to_webp( $size_path );
			if ( null !== $webp_path ) {
				$pairs[] = array(
					'old' => $size_path,
					'new' => $webp_path,
				);
			}
		}

		return $pairs;
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

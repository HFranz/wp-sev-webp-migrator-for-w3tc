<?php
/**
 * Corrects image references at save time for a specific race condition.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Closes a timing gap in the event-driven migration Processor performs:
 * if an image is inserted into a post being edited (still unsaved) while
 * W3TC ImageService happens to finish converting it in the background, the
 * `w3tc_imageservice` meta write fires and Processor::process() runs
 * immediately - but `wp_posts.post_content` in the database still holds the
 * pre-edit content, so Content_Replacer finds nothing to rewrite yet. The
 * attachment is nonetheless correctly marked `image/webp`. When the post is
 * saved afterwards, its content now references the attachment by its old
 * (jpg/png/gif) URL, but Processor::already_processed() short-circuits any
 * future run for that attachment, and the "process now" batch tool no
 * longer finds it either (its post_mime_type is already `image/webp`) - so
 * that one reference would otherwise never be corrected, silently breaking
 * the "no rewrites needed" premise this plugin exists for.
 *
 * Rather than re-scanning the whole site, this hooks `content_save_pre` -
 * fired once, synchronously, right before WordPress writes `post_content` to
 * the database - and only touches `<img>` tags carrying the `wp-image-{ID}`
 * class WordPress itself adds when an image is inserted via the block or
 * classic editor, checking only that one attachment's already-known
 * migration status instead of scanning the database.
 */
class Save_Listener {

	/** Matches a single <img ...> tag. */
	private const IMG_TAG = '/<img\b[^>]*>/i';

	/** Extracts the attachment ID from WordPress' own "wp-image-{ID}" class. */
	private const WP_IMAGE_CLASS = '/\bwp-image-(\d+)\b/i';

	/**
	 * Registers the `content_save_pre` filter. Call once during `plugins_loaded`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'content_save_pre', array( $this, 'rewrite' ) );
	}

	/**
	 * Rewrites `<img class="wp-image-{ID}">` tags for already-migrated attachments.
	 *
	 * @param string $content Post content about to be saved.
	 * @return string Content with any such tags corrected.
	 */
	public function rewrite( string $content ): string {
		// Cheap early exit: the vast majority of saves don't touch an <img>
		// tag carrying this class at all, and this avoids a regex pass over
		// the full content (which can be large) for every post save.
		if ( ! str_contains( $content, 'wp-image-' ) ) {
			$this->log( 'content_save_pre fired, but no "wp-image-" substring found - nothing to check' );
			return $content;
		}

		return preg_replace_callback( self::IMG_TAG, array( $this, 'rewrite_img_tag' ), $content );
	}

	/**
	 * @param array<int, string> $matches Regex match; [0] is the full <img> tag.
	 * @return string The tag, rewritten if its attachment is already WebP.
	 */
	private function rewrite_img_tag( array $matches ): string {
		$tag = $matches[0];

		if ( ! preg_match( self::WP_IMAGE_CLASS, $tag, $id_matches ) ) {
			$this->log( "img tag found but no wp-image-{ID} class in it: {$tag}" );
			return $tag;
		}

		$attachment_id = (int) $id_matches[1];
		$mime_type     = get_post_mime_type( $attachment_id );

		if ( 'image/webp' !== $mime_type ) {
			$this->log( "attachment #{$attachment_id} not rewritten, current post_mime_type is: " . ( false === $mime_type ? '(post not found)' : $mime_type ) );
			return $tag;
		}

		$full_url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $full_url ) || '' === $full_url ) {
			$this->log( "attachment #{$attachment_id} is image/webp but wp_get_attachment_url() returned nothing, not rewritten" );
			return $tag;
		}

		$rewritten = $this->rewrite_urls_for_attachment( $tag, $full_url, $this->sizes_by_dimensions( $attachment_id, $full_url ) );
		$this->log( "attachment #{$attachment_id} is image/webp, rewrote tag: {$tag} -> {$rewritten}" );

		return $rewritten;
	}

	/**
	 * Replaces every URL inside the tag that lives in this attachment's own
	 * upload directory and ends in a convertible extension, resolving each
	 * one to its *actual* current WebP counterpart rather than guessing via a
	 * blind extension swap.
	 *
	 * A blind swap ("photo-1024x549.png" -> "photo-1024x549.webp") is wrong
	 * for an intermediate size of an attachment WordPress itself auto-scaled
	 * on upload: W3TC names those after the "-scaled" full file (e.g.
	 * "photo-scaled-1024x549.webp"), not after the size's own filename (see
	 * {@see Attachment_Urls::to_webp_for_size()}, which this mirrors using
	 * the attachment's own already-migrated metadata as the source of truth
	 * instead of re-deriving the naming convention here).
	 *
	 * @param string                $tag           The <img> tag being rewritten.
	 * @param string                $full_url      This attachment's current (already-migrated) full-size URL.
	 * @param array<string, string> $sizes         "{width}x{height}" => current WebP URL, from {@see self::sizes_by_dimensions()}.
	 * @return string
	 */
	private function rewrite_urls_for_attachment( string $tag, string $full_url, array $sizes ): string {
		$base_dir = trailingslashit( dirname( $full_url ) );
		$pattern  = '/' . preg_quote( $base_dir, '/' ) . '[^\s"\'<>]*?(?:-(?P<dimensions>\d+x\d+))?\.(?:jpe?g|png|gif|webp)(?=\\\\*["\'\s>])/i';

		return preg_replace_callback(
			$pattern,
			static function ( array $url_matches ) use ( $full_url, $sizes ) {
				$dimensions = $url_matches['dimensions'] ?? '';

				if ( '' === $dimensions ) {
					return $full_url;
				}

				// Unrecognised size: leave it untouched rather than guess wrong.
				return $sizes[ $dimensions ] ?? $url_matches[0];
			},
			$tag
		);
	}

	/**
	 * Maps each of this attachment's registered intermediate sizes to its
	 * current WebP URL, keyed by "{width}x{height}" so a URL found in the
	 * tag can be resolved by the dimensions in its filename regardless of
	 * what base filename W3TC actually gave it.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $full_url      This attachment's current full-size URL.
	 * @return array<string, string>
	 */
	private function sizes_by_dimensions( int $attachment_id, string $full_url ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$result   = array();

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $result;
		}

		$base_dir = trailingslashit( dirname( $full_url ) );

		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) || ! is_string( $size['file'] ) || ! isset( $size['width'], $size['height'] ) ) {
				continue;
			}

			$result[ $size['width'] . 'x' . $size['height'] ] = $base_dir . $size['file'];
		}

		return $result;
	}

	/**
	 * Writes a diagnostic line to the PHP error log, gated behind WP_DEBUG_LOG.
	 *
	 * @param string $message Diagnostic message.
	 * @return void
	 */
	private function log( string $message ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging, gated behind WP_DEBUG_LOG.
		error_log( '[SEV WebP Migrator for W3TC] Save_Listener: ' . $message );
	}
}

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
	 * Matches a convertible extension only where it ends a URL (before a quote,
	 * whitespace, or the tag's closing bracket). `content_save_pre` receives
	 * content already escaped for the database (WordPress slashes it before
	 * running save_pre filters), so a quote in the original markup arrives
	 * here as `\"`/`\'` - the optional backslashes account for that.
	 */
	private const CONVERTIBLE_EXTENSION = '/\.(jpe?g|png|gif)(?=\\\\*["\'\s>])/i';

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

		$rewritten = preg_replace( self::CONVERTIBLE_EXTENSION, '.webp', $tag );
		$this->log( "attachment #{$attachment_id} is image/webp, rewrote tag: {$tag} -> {$rewritten}" );

		return $rewritten;
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

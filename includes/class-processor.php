<?php
/**
 * Orchestrates the replace-and-optionally-delete workflow for one attachment.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Ties Attachment_Urls, Content_Replacer, Attachment_Migrator, and
 * Source_Cleaner together into the single per-attachment workflow:
 * rewrite post content → migrate the attachment record → optionally
 * delete the original files.
 */
class Processor {

	private Content_Replacer $content_replacer;
	private Options_Replacer $options_replacer;
	private Attachment_Migrator $attachment_migrator;
	private Source_Cleaner $source_cleaner;

	public function __construct() {
		$this->content_replacer    = new Content_Replacer();
		$this->options_replacer    = new Options_Replacer();
		$this->attachment_migrator = new Attachment_Migrator();
		$this->source_cleaner      = new Source_Cleaner();
	}

	/**
	 * Whether this attachment has already been migrated to WebP.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True if already processed.
	 */
	public function already_processed( int $attachment_id ): bool {
		return 'image/webp' === get_post_mime_type( $attachment_id );
	}

	/**
	 * Runs the full workflow for a single converted attachment.
	 *
	 * @param int  $attachment_id    Attachment post ID.
	 * @param bool $delete_originals Whether to delete the old-extension files afterwards.
	 * @return array{posts_updated: int, options_updated: int, migrated: bool, files_deleted: int} Result summary.
	 */
	public function process( int $attachment_id, bool $delete_originals ): array {
		$result = array(
			'posts_updated'   => 0,
			'options_updated' => 0,
			'migrated'        => false,
			'files_deleted'   => 0,
		);

		if ( $this->already_processed( $attachment_id ) ) {
			return $result;
		}

		$url_pairs = Attachment_Urls::url_pairs( $attachment_id );
		if ( empty( $url_pairs ) ) {
			// Distinguish the two ways this can happen: wp_get_attachment_url()
			// returning nothing at all (unusual - even a broken _wp_attached_file
			// falls back to the post's guid) versus returning a URL whose
			// extension Attachment_Urls doesn't recognise as convertible, which
			// points at very different underlying problems.
			$attachment_url = wp_get_attachment_url( $attachment_id );
			$this->log_skip(
				$attachment_id,
				$attachment_url
					? "attachment URL has no convertible (jpg/jpeg/png/gif) extension: {$attachment_url}"
					: 'wp_get_attachment_url() returned nothing for this attachment'
			);
			return $result;
		}

		// Captured before migrate() repoints the attachment at its .webp files,
		// otherwise get_attached_file() would already return the new path.
		$path_pairs = Attachment_Urls::path_pairs( $attachment_id );

		$missing_webp = $this->resolve_webp_case( $path_pairs, $url_pairs, $attachment_id );
		if ( null !== $missing_webp ) {
			// Neither the predicted path nor W3TC's own child-attachment record
			// (see Attachment_Urls::w3tc_child_webp()) point at an existing
			// full-size WebP file, so there's genuinely nothing converted yet.
			// Wait for a later w3tc_imageservice meta write.
			$this->log_skip( $attachment_id, "expected WebP file not found on disk, W3TC may not have converted this size yet: {$missing_webp}" );
			return $result;
		}

		$result['posts_updated']   = $this->content_replacer->replace( $url_pairs );
		$result['options_updated'] = $this->options_replacer->replace( $url_pairs );
		$result['migrated']        = $this->attachment_migrator->migrate( $attachment_id, $path_pairs );

		if ( $delete_originals && $result['migrated'] ) {
			$result['files_deleted'] = $this->source_cleaner->delete_originals( $path_pairs );
		}

		return $result;
	}

	/**
	 * Confirms every path pair's .webp counterpart exists on disk, resolving
	 * each pair's predicted extension case (see {@see Attachment_Urls::resolve_case()})
	 * against whatever W3TC actually wrote and mirroring it onto the
	 * corresponding URL pair, so content is rewritten to a URL that actually
	 * resolves.
	 *
	 * Two fallbacks handle W3TC ImageService's own gaps, both confirmed against
	 * its actual source (Extension_ImageService_Cron.php) rather than guessed:
	 *
	 * - Full size missing at the predicted path: W3TC creates a genuinely
	 *   separate "child attachment" post for every converted file, tracked in
	 *   the original's `w3tc_imageservice` meta. That's more authoritative than
	 *   the predicted extension-swap path, which a later re-conversion job can
	 *   invalidate (it deletes the previous child attachment and its file via
	 *   wp_delete_attachment() before writing a new one). See
	 *   {@see Attachment_Urls::w3tc_child_webp()}.
	 * - An intermediate size missing while the full size exists: W3TC only
	 *   sends the full-size image to its remote conversion API; once that's
	 *   back it generates every intermediate size locally via
	 *   wp_generate_attachment_metadata(). Which sizes are registered at that
	 *   moment depends on execution context (e.g. Core's Site Icon sizes are
	 *   only registered in specific admin/customizer screens), so a size can be
	 *   silently and permanently skipped - there is no later event to wait for.
	 *   This method runs in a normal request, where every registered size is
	 *   available, so regenerating it here succeeds where W3TC's own attempt
	 *   didn't. See https://wordpress.org/support/topic/w3-total-cache-image-service-silently-fails-to-convert-one-intermediate-image-si/
	 *
	 * @param array<int, array{old: string, new: string}> $path_pairs    Filesystem old/new path pairs, modified in place.
	 * @param array<int, array{old: string, new: string}> $url_pairs     URL old/new pairs, modified in place.
	 * @param int                                          $attachment_id Attachment post ID, passed through to wp_create_image_subsizes()
	 *                                                                    and used to look up the W3TC child attachment record.
	 * @return string|null Null if a .webp file exists (or was resolved/regenerated) for the
	 *                      full size and every intermediate size, otherwise the predicted path
	 *                      of the first one that is still missing.
	 */
	private function resolve_webp_case( array &$path_pairs, array &$url_pairs, int $attachment_id ): ?string {
		$full_webp        = null;
		$attempted_repair = false;

		foreach ( $path_pairs as $i => $pair ) {
			$resolved = Attachment_Urls::resolve_case( $pair['new'] );

			if ( 0 === $i ) {
				if ( null === $resolved ) {
					$child = Attachment_Urls::w3tc_child_webp( $attachment_id );
					if ( null !== $child ) {
						$path_pairs[ $i ]['new'] = $child['path'];
						if ( isset( $url_pairs[ $i ] ) ) {
							$url_pairs[ $i ]['new'] = $child['url'];
						}
						$this->log_skip( $attachment_id, "resolved full-size WebP via W3TC's child attachment record instead of the predicted path: {$child['path']}" );

						$full_webp = $child['path'];
						continue;
					}

					return $pair['new'];
				}
				$full_webp = $resolved;
			} elseif ( null === $resolved && null !== $full_webp && ! $attempted_repair ) {
				$attempted_repair = true;
				$this->regenerate_missing_sizes( $attachment_id, $full_webp );
				$resolved = Attachment_Urls::resolve_case( $pair['new'] );

				if ( null !== $resolved ) {
					$this->log_skip( $attachment_id, "regenerated missing WebP intermediate size from already-converted full-size WebP (W3TC silently skipped it): {$pair['new']}" );
				}
			}

			if ( null === $resolved ) {
				return $pair['new'];
			}

			if ( $resolved === $pair['new'] ) {
				continue;
			}

			$path_pairs[ $i ]['new'] = $resolved;

			if ( isset( $url_pairs[ $i ] ) ) {
				$extension              = substr( $resolved, (int) strrpos( $resolved, '.' ) );
				$url_pairs[ $i ]['new'] = preg_replace( '/\.webp$/i', $extension, $url_pairs[ $i ]['new'] );
			}
		}

		return null;
	}

	/**
	 * Regenerates every currently-registered intermediate size from an
	 * already-converted full-size WebP file, filling in whichever one(s) W3TC's
	 * own subsize generation silently skipped. Writes files to disk only; the
	 * attachment's `_wp_attachment_metadata` is updated separately by
	 * {@see Attachment_Migrator::migrate()} once a size is confirmed to exist,
	 * same as for sizes W3TC already converted itself.
	 *
	 * @param int    $attachment_id  Attachment post ID (passed through for the
	 *                                `intermediate_image_sizes_advanced` filter context).
	 * @param string $full_webp_path Filesystem path of the already-converted full-size WebP.
	 * @return void
	 */
	private function regenerate_missing_sizes( int $attachment_id, string $full_webp_path ): void {
		if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		wp_create_image_subsizes( $full_webp_path, $attachment_id );
	}

	/**
	 * Writes a diagnostic line to the PHP error log, so a stuck batch
	 * ("N images remaining" that never decreases) can be diagnosed from the
	 * server logs instead of failing silently. Gated behind WP_DEBUG_LOG like
	 * other WordPress debug output.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $message       Human-readable diagnostic message.
	 * @return void
	 */
	private function log_skip( int $attachment_id, string $message ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging, gated behind WP_DEBUG_LOG.
		error_log( sprintf( '[SEV WebP Migrator for W3TC] Attachment #%d: %s', $attachment_id, $message ) );
	}
}

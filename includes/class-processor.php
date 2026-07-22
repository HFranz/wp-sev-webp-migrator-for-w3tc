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
	private Attachment_Migrator $attachment_migrator;
	private Source_Cleaner $source_cleaner;

	public function __construct() {
		$this->content_replacer    = new Content_Replacer();
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
	 * @return array{posts_updated: int, migrated: bool, files_deleted: int} Result summary.
	 */
	public function process( int $attachment_id, bool $delete_originals ): array {
		$result = array(
			'posts_updated' => 0,
			'migrated'      => false,
			'files_deleted' => 0,
		);

		if ( $this->already_processed( $attachment_id ) ) {
			return $result;
		}

		$url_pairs = Attachment_Urls::url_pairs( $attachment_id );
		if ( empty( $url_pairs ) ) {
			return $result;
		}

		// Captured before migrate() repoints the attachment at its .webp files,
		// otherwise get_attached_file() would already return the new path.
		$path_pairs = Attachment_Urls::path_pairs( $attachment_id );

		if ( ! $this->resolve_webp_case( $path_pairs, $url_pairs ) ) {
			// W3TC ImageService converts the full size before its intermediate
			// sizes, writing this meta once the full size is done rather than
			// once per attachment. Migrating now would mark the attachment as
			// fully processed while some sizes are still the original files,
			// and since already_processed() short-circuits future runs, those
			// sizes would never be replaced/deleted once W3TC finishes them.
			// Wait for a later w3tc_imageservice meta write instead.
			return $result;
		}

		$result['posts_updated'] = $this->content_replacer->replace( $url_pairs );
		$result['migrated']      = $this->attachment_migrator->migrate( $attachment_id );

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
	 * @param array<int, array{old: string, new: string}> $path_pairs Filesystem old/new path pairs, modified in place.
	 * @param array<int, array{old: string, new: string}> $url_pairs  URL old/new pairs, modified in place.
	 * @return bool True only if a .webp file exists for the full size and every intermediate size.
	 */
	private function resolve_webp_case( array &$path_pairs, array &$url_pairs ): bool {
		foreach ( $path_pairs as $i => $pair ) {
			$resolved = Attachment_Urls::resolve_case( $pair['new'] );
			if ( null === $resolved ) {
				return false;
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

		return true;
	}
}

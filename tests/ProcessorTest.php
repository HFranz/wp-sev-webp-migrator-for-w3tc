<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SevWebPMigratorForW3TC\Processor;

final class ProcessorTest extends TestCase {

	private const NOOP_RESULT = array(
		'posts_updated' => 0,
		'migrated'      => false,
		'files_deleted' => 0,
	);

	private string $tmp_dir;

	protected function setUp(): void {
		WPTestStub::reset();
		$GLOBALS['wpdb'] = new Fake_Wpdb();

		$this->tmp_dir = sys_get_temp_dir() . '/sevwmfw3tc-test-' . uniqid( '', true );
		mkdir( $this->tmp_dir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->tmp_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->tmp_dir );
	}

	public function test_process_does_nothing_when_attachment_is_already_webp(): void {
		WPTestStub::$mime_types[5] = 'image/webp';

		$result = ( new Processor() )->process( 5, false );

		$this->assertSame( self::NOOP_RESULT, $result );
	}

	public function test_process_does_nothing_when_attachment_has_no_url(): void {
		$result = ( new Processor() )->process( 999, false );

		$this->assertSame( self::NOOP_RESULT, $result );
	}

	public function test_process_does_nothing_while_full_size_webp_does_not_exist_yet(): void {
		touch( "{$this->tmp_dir}/photo.jpg" );

		WPTestStub::$attachment_urls[7] = 'http://example.com/uploads/photo.jpg';
		WPTestStub::$attached_files[7]  = "{$this->tmp_dir}/photo.jpg";
		WPTestStub::$mime_types[7]      = 'image/jpeg';

		$result = ( new Processor() )->process( 7, false );

		$this->assertSame( self::NOOP_RESULT, $result );
	}

	/**
	 * Reproduces the reported bug: W3TC ImageService only sends the full-size
	 * image to its remote conversion API; once that's back it generates every
	 * intermediate size locally, and can silently skip one permanently
	 * depending on which sizes are registered in that execution context (see
	 * https://wordpress.org/support/topic/w3-total-cache-image-service-silently-fails-to-convert-one-intermediate-image-si/).
	 * Processor attempts to regenerate the missing size itself once the
	 * full-size WebP exists; this fixture simulates that regeneration also
	 * failing (the default wp_create_image_subsizes() stub is a no-op, same
	 * as a real environment where no image editor is available), so
	 * everything must still be left untouched - migrating now would mark the
	 * attachment as fully processed (already_processed() short-circuits every
	 * later run), so the intermediate size would never be replaced/deleted.
	 */
	public function test_process_does_nothing_while_an_intermediate_size_is_not_yet_converted(): void {
		// Intermediate sizes keep the pre-scale original's filename in their own
		// metadata (no "-scaled"), even though the full size is "-scaled".
		touch( "{$this->tmp_dir}/photo-scaled.jpg" );
		touch( "{$this->tmp_dir}/photo-scaled.webp" ); // Full size already converted by W3TC.
		touch( "{$this->tmp_dir}/photo-300x200.jpg" ); // Intermediate size: not converted yet, no .webp.

		WPTestStub::$attachment_urls[42]     = 'http://example.com/uploads/photo-scaled.jpg';
		WPTestStub::$attached_files[42]      = "{$this->tmp_dir}/photo-scaled.jpg";
		WPTestStub::$attachment_metadata[42] = array(
			'file'  => 'photo-scaled.jpg',
			'sizes' => array(
				'medium' => array( 'file' => 'photo-300x200.jpg' ),
			),
		);
		WPTestStub::$mime_types[42] = 'image/jpeg';

		global $wpdb;
		$wpdb->post_content = array(
			1 => '<img src="http://example.com/uploads/photo-scaled.jpg" />',
		);

		$result = ( new Processor() )->process( 42, true );

		$this->assertSame( self::NOOP_RESULT, $result );
		$this->assertSame( array(), $wpdb->updates, 'Post content must not be rewritten before every size is converted.' );
		$this->assertSame(
			'<img src="http://example.com/uploads/photo-scaled.jpg" />',
			$wpdb->post_content[1]
		);
		$this->assertFileExists( "{$this->tmp_dir}/photo-scaled.jpg", 'The full-size original must not be deleted before every size is converted.' );

		$this->assertCount( 1, WPTestStub::$image_subsizes_calls, 'Processor must attempt to regenerate the missing size from the already-converted full-size WebP.' );
		$this->assertSame( "{$this->tmp_dir}/photo-scaled.webp", WPTestStub::$image_subsizes_calls[0]['file'] );
		$this->assertSame( 42, WPTestStub::$image_subsizes_calls[0]['attachment_id'] );
	}

	public function test_process_does_nothing_when_only_an_intermediate_size_is_converted_but_full_size_is_not(): void {
		touch( "{$this->tmp_dir}/photo-scaled.jpg" ); // Full size: not converted yet, no .webp.
		touch( "{$this->tmp_dir}/photo-300x200.jpg" );
		// W3TC names the intermediate size's WebP after the "-scaled" full file, not after "photo-300x200.jpg".
		touch( "{$this->tmp_dir}/photo-scaled-300x200.webp" ); // Intermediate size already converted.

		WPTestStub::$attachment_urls[43]     = 'http://example.com/uploads/photo-scaled.jpg';
		WPTestStub::$attached_files[43]      = "{$this->tmp_dir}/photo-scaled.jpg";
		WPTestStub::$attachment_metadata[43] = array(
			'file'  => 'photo-scaled.jpg',
			'sizes' => array(
				'medium' => array( 'file' => 'photo-300x200.jpg' ),
			),
		);
		WPTestStub::$mime_types[43] = 'image/jpeg';

		$result = ( new Processor() )->process( 43, false );

		$this->assertSame( self::NOOP_RESULT, $result );
		$this->assertSame( array(), WPTestStub::$image_subsizes_calls, 'Nothing to regenerate from while the full size itself is not converted yet.' );
	}

	/**
	 * Reproduces a variant of the reported bug where the full-size WebP itself
	 * is missing from its predicted (extension-swapped) path, even though W3TC
	 * marked the attachment "converted". Per W3TC's own Extension_ImageService_Cron.php,
	 * every converted file gets a genuinely separate "child attachment" post,
	 * referenced from the original's w3tc_imageservice meta; that reference is
	 * authoritative even when the predicted path doesn't (or no longer) exist
	 * (e.g. a later re-conversion job deletes the previous child attachment and
	 * its file before writing a new one). The intermediate size is deliberately
	 * left unresolvable here so the assertions can observe, via the recorded
	 * wp_create_image_subsizes() call, that the child attachment's real path
	 * was used - without the test needing to reach Attachment_Migrator::migrate().
	 */
	public function test_process_resolves_full_size_via_w3tc_child_attachment_when_predicted_path_is_missing(): void {
		touch( "{$this->tmp_dir}/photo.jpg" );
		touch( "{$this->tmp_dir}/photo-300x200.jpg" );
		// Deliberately not touching "{$this->tmp_dir}/photo.webp" (the predicted path) or
		// "{$this->tmp_dir}/photo-300x200.webp": W3TC actually wrote the full size under a
		// different filename this time, tracked only via the child attachment reference.
		touch( "{$this->tmp_dir}/photo-w3tc-converted.webp" );

		WPTestStub::$attachment_urls[44]     = 'http://example.com/uploads/photo.jpg';
		WPTestStub::$attached_files[44]      = "{$this->tmp_dir}/photo.jpg";
		WPTestStub::$attachment_metadata[44] = array(
			'file'  => 'photo.jpg',
			'sizes' => array(
				'medium' => array( 'file' => 'photo-300x200.jpg' ),
			),
		);
		WPTestStub::$mime_types[44]  = 'image/jpeg';
		WPTestStub::$post_meta[44]   = array(
			'w3tc_imageservice' => array(
				'status'         => 'converted',
				'post_children'  => array( 'webp' => 900 ),
			),
		);

		WPTestStub::$attachment_urls[900] = 'http://example.com/uploads/photo-w3tc-converted.webp';
		WPTestStub::$attached_files[900]  = "{$this->tmp_dir}/photo-w3tc-converted.webp";
		WPTestStub::$mime_types[900]      = 'image/webp';

		$result = ( new Processor() )->process( 44, false );

		$this->assertSame( self::NOOP_RESULT, $result, 'The intermediate size is still unresolvable, so the attachment as a whole is not migrated yet.' );
		$this->assertCount( 1, WPTestStub::$image_subsizes_calls, 'Processor must attempt to regenerate the missing intermediate size once the full size resolved via the child attachment.' );
		$this->assertSame(
			"{$this->tmp_dir}/photo-w3tc-converted.webp",
			WPTestStub::$image_subsizes_calls[0]['file'],
			'Regeneration must use the child attachment\'s real path, not the (missing) predicted one.'
		);
	}

	public function test_process_ignores_w3tc_child_attachment_reference_with_a_non_webp_mime_type(): void {
		touch( "{$this->tmp_dir}/photo2.jpg" );
		// Predicted "{$this->tmp_dir}/photo2.webp" is deliberately missing.

		WPTestStub::$attachment_urls[45]     = 'http://example.com/uploads/photo2.jpg';
		WPTestStub::$attached_files[45]      = "{$this->tmp_dir}/photo2.jpg";
		WPTestStub::$attachment_metadata[45] = array( 'file' => 'photo2.jpg' );
		WPTestStub::$mime_types[45]          = 'image/jpeg';
		WPTestStub::$post_meta[45]           = array(
			'w3tc_imageservice' => array(
				'status'        => 'converted',
				'post_children' => array( 'webp' => 901 ),
			),
		);

		// Referenced child is not actually a WebP attachment (e.g. legacy post_child
		// pointing at a different format); must not be trusted as the full-size WebP.
		WPTestStub::$mime_types[901] = 'image/jpeg';

		$result = ( new Processor() )->process( 45, false );

		$this->assertSame( self::NOOP_RESULT, $result );
		$this->assertSame( array(), WPTestStub::$image_subsizes_calls );
	}

	public function test_process_ignores_w3tc_child_attachment_reference_whose_file_no_longer_exists(): void {
		touch( "{$this->tmp_dir}/photo3.jpg" );
		// Predicted "{$this->tmp_dir}/photo3.webp" is deliberately missing.

		WPTestStub::$attachment_urls[46]     = 'http://example.com/uploads/photo3.jpg';
		WPTestStub::$attached_files[46]      = "{$this->tmp_dir}/photo3.jpg";
		WPTestStub::$attachment_metadata[46] = array( 'file' => 'photo3.jpg' );
		WPTestStub::$mime_types[46]          = 'image/jpeg';
		WPTestStub::$post_meta[46]           = array(
			'w3tc_imageservice' => array(
				'status'        => 'converted',
				'post_children' => array( 'webp' => 902 ),
			),
		);

		WPTestStub::$mime_types[902]     = 'image/webp';
		WPTestStub::$attached_files[902] = "{$this->tmp_dir}/deleted-by-a-later-reconversion.webp"; // Never touch()ed.

		$result = ( new Processor() )->process( 46, false );

		$this->assertSame( self::NOOP_RESULT, $result );
		$this->assertSame( array(), WPTestStub::$image_subsizes_calls );
	}
}

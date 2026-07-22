<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SevWebPMigratorForW3TC\Attachment_Urls;

final class AttachmentUrlsTest extends TestCase {

	protected function setUp(): void {
		WPTestStub::reset();
	}

	public function test_url_pairs_includes_full_size_and_all_registered_sizes(): void {
		WPTestStub::$attachment_urls[ 42 ]     = 'http://example.com/wp-content/uploads/2026/07/photo.jpg';
		WPTestStub::$attachment_metadata[ 42 ] = array(
			'file'  => '2026/07/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
				'medium'    => array( 'file' => 'photo-300x300.jpg' ),
			),
		);

		$pairs = Attachment_Urls::url_pairs( 42 );

		$this->assertSame(
			array(
				array(
					'old' => 'http://example.com/wp-content/uploads/2026/07/photo.jpg',
					'new' => 'http://example.com/wp-content/uploads/2026/07/photo.webp',
				),
				array(
					'old' => 'http://example.com/wp-content/uploads/2026/07/photo-150x150.jpg',
					'new' => 'http://example.com/wp-content/uploads/2026/07/photo-150x150.webp',
				),
				array(
					'old' => 'http://example.com/wp-content/uploads/2026/07/photo-300x300.jpg',
					'new' => 'http://example.com/wp-content/uploads/2026/07/photo-300x300.webp',
				),
			),
			$pairs
		);
	}

	public function test_url_pairs_handles_jpeg_png_and_gif(): void {
		foreach ( array( 'photo.jpeg', 'photo.png', 'photo.gif' ) as $filename ) {
			WPTestStub::reset();
			WPTestStub::$attachment_urls[ 1 ] = "http://example.com/wp-content/uploads/{$filename}";

			$pairs = Attachment_Urls::url_pairs( 1 );

			$this->assertCount( 1, $pairs );
			$this->assertStringEndsWith( '.webp', $pairs[0]['new'] );
		}
	}

	public function test_url_pairs_ignores_already_webp_or_unsupported_extensions(): void {
		WPTestStub::$attachment_urls[ 7 ] = 'http://example.com/wp-content/uploads/document.pdf';

		$this->assertSame( array(), Attachment_Urls::url_pairs( 7 ) );
	}

	public function test_url_pairs_returns_empty_when_attachment_has_no_url(): void {
		$this->assertSame( array(), Attachment_Urls::url_pairs( 999 ) );
	}

	/**
	 * Reproduces the reported bug: for attachments WordPress scaled down on
	 * upload, intermediate sizes keep the pre-scale original's filename in
	 * their own metadata ("photo-1024x683.jpg"), but W3TC ImageService writes
	 * their WebP counterparts named after the "-scaled" full file instead
	 * ("photo-scaled-1024x683.webp"). A plain extension swap on the size's own
	 * filename builds a WebP path that never exists on disk, so the
	 * attachment's intermediate sizes are never recognised as converted.
	 */
	public function test_url_pairs_matches_w3tc_naming_for_scaled_intermediate_sizes(): void {
		WPTestStub::$attachment_urls[ 42 ]     = 'http://example.com/wp-content/uploads/2026/07/photo-scaled.jpg';
		WPTestStub::$attachment_metadata[ 42 ] = array(
			'file'  => '2026/07/photo-scaled.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
				'medium'    => array( 'file' => 'photo-1024x683.jpg' ),
			),
		);

		$pairs = Attachment_Urls::url_pairs( 42 );

		$this->assertSame(
			array(
				array(
					'old' => 'http://example.com/wp-content/uploads/2026/07/photo-scaled.jpg',
					'new' => 'http://example.com/wp-content/uploads/2026/07/photo-scaled.webp',
				),
				array(
					'old' => 'http://example.com/wp-content/uploads/2026/07/photo-150x150.jpg',
					'new' => 'http://example.com/wp-content/uploads/2026/07/photo-scaled-150x150.webp',
				),
				array(
					'old' => 'http://example.com/wp-content/uploads/2026/07/photo-1024x683.jpg',
					'new' => 'http://example.com/wp-content/uploads/2026/07/photo-scaled-1024x683.webp',
				),
			),
			$pairs
		);
	}

	public function test_path_pairs_mirrors_url_pairs_against_filesystem_paths(): void {
		WPTestStub::$attached_files[ 42 ]      = '/srv/uploads/2026/07/photo.jpg';
		WPTestStub::$attachment_metadata[ 42 ] = array(
			'file'  => '2026/07/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			),
		);

		$pairs = Attachment_Urls::path_pairs( 42 );

		$this->assertSame(
			array(
				array(
					'old' => '/srv/uploads/2026/07/photo.jpg',
					'new' => '/srv/uploads/2026/07/photo.webp',
				),
				array(
					'old' => '/srv/uploads/2026/07/photo-150x150.jpg',
					'new' => '/srv/uploads/2026/07/photo-150x150.webp',
				),
			),
			$pairs
		);
	}

	public function test_path_pairs_matches_w3tc_naming_for_scaled_intermediate_sizes(): void {
		WPTestStub::$attached_files[ 42 ]      = '/srv/uploads/2026/07/photo-scaled.jpg';
		WPTestStub::$attachment_metadata[ 42 ] = array(
			'file'  => '2026/07/photo-scaled.jpg',
			'sizes' => array(
				'medium' => array( 'file' => 'photo-1024x683.jpg' ),
			),
		);

		$pairs = Attachment_Urls::path_pairs( 42 );

		$this->assertSame(
			array(
				array(
					'old' => '/srv/uploads/2026/07/photo-scaled.jpg',
					'new' => '/srv/uploads/2026/07/photo-scaled.webp',
				),
				array(
					'old' => '/srv/uploads/2026/07/photo-1024x683.jpg',
					'new' => '/srv/uploads/2026/07/photo-scaled-1024x683.webp',
				),
			),
			$pairs
		);
	}

	/**
	 * Attachment_Migrator uses this to rewrite `_wp_attachment_metadata['sizes']`
	 * after migration; it must resolve to the same filename build_pairs() used
	 * to confirm the WebP exists, or the rewritten metadata points at a file
	 * that was never created.
	 */
	public function test_webp_size_filename_matches_w3tc_naming_for_scaled_attachments(): void {
		$this->assertSame(
			'photo-scaled-300x200.webp',
			Attachment_Urls::webp_size_filename( 'photo-scaled.jpg', 'photo-300x200.jpg' )
		);
	}

	public function test_webp_size_filename_falls_back_to_extension_swap_when_full_size_is_not_scaled(): void {
		$this->assertSame(
			'photo-300x200.webp',
			Attachment_Urls::webp_size_filename( 'photo.jpg', 'photo-300x200.jpg' )
		);
	}
}

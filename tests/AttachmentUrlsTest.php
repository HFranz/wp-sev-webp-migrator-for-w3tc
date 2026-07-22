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
}

<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SevWebPMigratorForW3TC\Save_Listener;

final class SaveListenerTest extends TestCase {

	protected function setUp(): void {
		WPTestStub::reset();
	}

	/**
	 * Reproduces the reported race condition: an image is inserted into a post
	 * (still unsaved) while W3TC ImageService finishes converting it in the
	 * background; the attachment ends up marked image/webp before the post
	 * that references it (still by its old URL) is ever saved. This is the
	 * only later opportunity to correct that reference.
	 */
	public function test_rewrites_img_tag_for_an_already_migrated_attachment(): void {
		WPTestStub::$mime_types[42]      = 'image/webp';
		WPTestStub::$attachment_urls[42] = 'http://example.com/uploads/photo.webp';

		$content = '<p>Hello</p><img src="http://example.com/uploads/photo.jpg" class="wp-image-42" alt="A photo" />';

		$result = ( new Save_Listener() )->rewrite( $content );

		$this->assertSame(
			'<p>Hello</p><img src="http://example.com/uploads/photo.webp" class="wp-image-42" alt="A photo" />',
			$result
		);
	}

	/**
	 * Reproduces a real-world failure found via live testing: for an
	 * attachment WordPress itself auto-scaled on upload, W3TC names
	 * intermediate-size WebP files after the "-scaled" full file
	 * ("photo-scaled-1024x549.webp"), not after the size's own pre-scale
	 * filename ("photo-1024x549.jpg" -> naively "photo-1024x549.webp", which
	 * W3TC never actually writes). A blind extension swap therefore produced
	 * a URL for a file that doesn't exist. The fix resolves each size via the
	 * attachment's own (already-migrated) metadata instead of guessing.
	 */
	public function test_rewrites_intermediate_size_using_the_scaled_naming_convention(): void {
		WPTestStub::$mime_types[434]      = 'image/webp';
		WPTestStub::$attachment_urls[434] = 'http://localhost/wp-content/uploads/2026/08/photo-scaled.webp';
		WPTestStub::$attachment_metadata[434] = array(
			'file'  => '2026/08/photo-scaled.webp',
			'sizes' => array(
				'large' => array(
					'file'   => 'photo-scaled-1024x549.webp',
					'width'  => 1024,
					'height' => 549,
				),
			),
		);

		$content = '<img decoding="async" src="http://localhost/wp-content/uploads/2026/08/photo-1024x549.png" alt="" class="wp-image-434" />';

		$result = ( new Save_Listener() )->rewrite( $content );

		$this->assertSame(
			'<img decoding="async" src="http://localhost/wp-content/uploads/2026/08/photo-scaled-1024x549.webp" alt="" class="wp-image-434" />',
			$result
		);
	}

	/**
	 * Reproduces a real-world failure found via WP_DEBUG_LOG testing:
	 * content_save_pre receives content already slashed for the database, so
	 * quotes in the original markup arrive here as `\"`, not `"`.
	 */
	public function test_rewrites_img_tag_with_backslash_escaped_quotes(): void {
		WPTestStub::$mime_types[427]      = 'image/webp';
		WPTestStub::$attachment_urls[427] = 'http://localhost/wp-content/uploads/2026/08/photo.webp';

		$content = '<img src=\"http://localhost/wp-content/uploads/2026/08/photo.jpeg\" alt=\"\" class=\"wp-image-427\"/>';

		$result = ( new Save_Listener() )->rewrite( $content );

		$this->assertSame(
			'<img src=\"http://localhost/wp-content/uploads/2026/08/photo.webp\" alt=\"\" class=\"wp-image-427\"/>',
			$result
		);
	}

	public function test_rewrites_srcset_with_multiple_sizes(): void {
		WPTestStub::$mime_types[7]      = 'image/webp';
		WPTestStub::$attachment_urls[7] = 'http://example.com/uploads/photo.webp';
		WPTestStub::$attachment_metadata[7] = array(
			'file'  => 'photo.webp',
			'sizes' => array(
				'medium' => array(
					'file'   => 'photo-300x200.webp',
					'width'  => 300,
					'height' => 200,
				),
			),
		);

		$content = '<img src="http://example.com/uploads/photo.jpg" srcset="http://example.com/uploads/photo.jpg 1024w, http://example.com/uploads/photo-300x200.jpg 300w" class="wp-image-7" />';

		$result = ( new Save_Listener() )->rewrite( $content );

		$this->assertStringNotContainsString( '.jpg', $result );
		$this->assertStringContainsString( 'photo.webp', $result );
		$this->assertStringContainsString( 'photo-300x200.webp 300w', $result );
	}

	public function test_leaves_tag_unchanged_when_attachment_is_not_yet_migrated(): void {
		WPTestStub::$mime_types[42] = 'image/jpeg';

		$content = '<img src="http://example.com/uploads/photo.jpg" class="wp-image-42" />';

		$this->assertSame( $content, ( new Save_Listener() )->rewrite( $content ) );
	}

	public function test_leaves_img_tag_without_wp_image_class_unchanged(): void {
		WPTestStub::$mime_types[42] = 'image/webp';

		// No wp-image-{id} class at all, so there's no attachment to check against.
		$content = '<img src="http://example.com/uploads/photo.jpg" alt="wp-image-ish text but no class" />';

		$this->assertSame( $content, ( new Save_Listener() )->rewrite( $content ) );
	}

	public function test_leaves_content_without_any_img_tag_unchanged(): void {
		$content = '<p>No images here, just text.</p>';

		$this->assertSame( $content, ( new Save_Listener() )->rewrite( $content ) );
	}

	public function test_leaves_tag_unchanged_when_attachment_url_is_unavailable(): void {
		WPTestStub::$mime_types[42] = 'image/webp';
		// wp_get_attachment_url(42) intentionally left unset -> returns false.

		$content = '<img src="http://example.com/uploads/photo.jpg" class="wp-image-42" />';

		$this->assertSame( $content, ( new Save_Listener() )->rewrite( $content ) );
	}

	public function test_leaves_an_unrecognised_size_untouched_rather_than_guessing(): void {
		WPTestStub::$mime_types[434]      = 'image/webp';
		WPTestStub::$attachment_urls[434] = 'http://localhost/wp-content/uploads/2026/08/photo-scaled.webp';
		WPTestStub::$attachment_metadata[434] = array(
			'file'  => '2026/08/photo-scaled.webp',
			'sizes' => array(),
		);

		// "-999x999" isn't a registered size in the metadata above.
		$content = '<img src="http://localhost/wp-content/uploads/2026/08/photo-999x999.png" class="wp-image-434" />';

		$this->assertSame( $content, ( new Save_Listener() )->rewrite( $content ) );
	}

	public function test_only_rewrites_the_matching_tag_among_several(): void {
		WPTestStub::$mime_types[1]      = 'image/webp';
		WPTestStub::$attachment_urls[1] = 'http://example.com/uploads/one.webp';
		WPTestStub::$mime_types[2]      = 'image/jpeg';

		$content = '<img src="http://example.com/uploads/one.jpg" class="wp-image-1" />'
			. '<img src="http://example.com/uploads/two.jpg" class="wp-image-2" />';

		$result = ( new Save_Listener() )->rewrite( $content );

		$this->assertStringContainsString( 'one.webp', $result );
		$this->assertStringContainsString( 'two.jpg', $result );
	}
}

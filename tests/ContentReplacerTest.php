<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SevReplaceWebPForW3TC\Content_Replacer;

final class ContentReplacerTest extends TestCase {

	protected function setUp(): void {
		WPTestStub::reset();
		$GLOBALS['wpdb'] = new Fake_Wpdb();
	}

	public function test_replace_rewrites_matching_posts_and_returns_count(): void {
		global $wpdb;

		$wpdb->post_content = array(
			1 => '<img src="http://example.com/uploads/photo.jpg" />',
			2 => '<img src="http://example.com/uploads/other.jpg" />',
			3 => '<p>no images here</p>',
		);

		$replacer = new Content_Replacer();
		$updated  = $replacer->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/photo.jpg',
					'new' => 'http://example.com/uploads/photo.webp',
				),
			)
		);

		$this->assertSame( 1, $updated );
		$this->assertSame( '<img src="http://example.com/uploads/photo.webp" />', $wpdb->post_content[1] );
		$this->assertSame( '<img src="http://example.com/uploads/other.jpg" />', $wpdb->post_content[2] );
		$this->assertSame( array( 1 ), WPTestStub::$cleaned_post_caches );
	}

	public function test_replace_updates_every_post_referencing_the_image(): void {
		global $wpdb;

		$wpdb->post_content = array(
			1 => '<img src="http://example.com/uploads/photo.jpg" />',
			2 => '<img srcset="http://example.com/uploads/photo.jpg 1x" />',
		);

		$replacer = new Content_Replacer();
		$updated  = $replacer->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/photo.jpg',
					'new' => 'http://example.com/uploads/photo.webp',
				),
			)
		);

		$this->assertSame( 2, $updated );
	}

	public function test_replace_counts_each_post_once_across_multiple_url_pairs(): void {
		global $wpdb;

		$wpdb->post_content = array(
			1 => '<img src="http://example.com/uploads/photo.jpg" srcset="http://example.com/uploads/photo-150x150.jpg 1x" />',
		);

		$replacer = new Content_Replacer();
		$updated  = $replacer->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/photo.jpg',
					'new' => 'http://example.com/uploads/photo.webp',
				),
				array(
					'old' => 'http://example.com/uploads/photo-150x150.jpg',
					'new' => 'http://example.com/uploads/photo-150x150.webp',
				),
			)
		);

		$this->assertSame( 1, $updated );
		$this->assertStringContainsString( 'photo.webp', $wpdb->post_content[1] );
		$this->assertStringContainsString( 'photo-150x150.webp', $wpdb->post_content[1] );
	}

	public function test_replace_returns_zero_for_empty_pairs(): void {
		$replacer = new Content_Replacer();

		$this->assertSame( 0, $replacer->replace( array() ) );
	}

	public function test_replace_skips_pairs_where_old_and_new_are_identical(): void {
		global $wpdb;

		$wpdb->post_content = array(
			1 => '<img src="http://example.com/uploads/photo.jpg" />',
		);

		$replacer = new Content_Replacer();
		$updated  = $replacer->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/photo.jpg',
					'new' => 'http://example.com/uploads/photo.jpg',
				),
			)
		);

		$this->assertSame( 0, $updated );
		$this->assertSame( array(), $wpdb->updates );
	}
}

<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SevWebPMigratorForW3TC\Options_Replacer;

final class OptionsReplacerTest extends TestCase {

	protected function setUp(): void {
		WPTestStub::reset();
		$GLOBALS['wpdb'] = new Fake_Wpdb();
	}

	/**
	 * Reproduces the reported bug: a Custom HTML / Text widget embedding an
	 * <img> (or a background-image) pointing at the original file. Widget
	 * instances are stored serialized under a "widget_*" option; a raw
	 * string replace on the serialized column would corrupt it once ".jpg"
	 * becomes ".webp" (different byte length), so this must go through
	 * get_option()/update_option() instead.
	 */
	public function test_replace_rewrites_image_url_inside_a_widget_instance(): void {
		WPTestStub::$options['widget_text'] = array(
			2 => array(
				'title' => 'Sponsor',
				'text'  => '<img src="http://example.com/uploads/photo.jpg" alt="Sponsor" />',
			),
			'_multiwidget' => 1,
		);

		$updated = ( new Options_Replacer() )->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/photo.jpg',
					'new' => 'http://example.com/uploads/photo.webp',
				),
			)
		);

		$this->assertSame( 1, $updated );
		$this->assertSame(
			'<img src="http://example.com/uploads/photo.webp" alt="Sponsor" />',
			WPTestStub::$options['widget_text'][2]['text']
		);
		// Untouched sibling data must survive the round-trip unchanged.
		$this->assertSame( 'Sponsor', WPTestStub::$options['widget_text'][2]['title'] );
		$this->assertSame( 1, WPTestStub::$options['widget_text']['_multiwidget'] );
	}

	/**
	 * Reproduces the other half of the reported bug: a theme mod (Customizer
	 * setting) storing a background image URL, e.g. via a "custom-background"
	 * or theme-defined image control.
	 */
	public function test_replace_rewrites_image_url_inside_a_theme_mod(): void {
		WPTestStub::$options['theme_mods_twentytwentyfour'] = array(
			'background_image' => 'http://example.com/uploads/hero.jpg',
			'custom_logo'       => 42,
		);

		$updated = ( new Options_Replacer() )->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/hero.jpg',
					'new' => 'http://example.com/uploads/hero.webp',
				),
			)
		);

		$this->assertSame( 1, $updated );
		$this->assertSame(
			'http://example.com/uploads/hero.webp',
			WPTestStub::$options['theme_mods_twentytwentyfour']['background_image']
		);
		$this->assertSame( 42, WPTestStub::$options['theme_mods_twentytwentyfour']['custom_logo'] );
	}

	public function test_replace_also_matches_root_relative_urls_in_widgets(): void {
		WPTestStub::$options['widget_custom_html'] = array(
			3 => array( 'content' => '<div style="background-image:url(/uploads/hero.jpg)"></div>' ),
		);

		$updated = ( new Options_Replacer() )->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/hero.jpg',
					'new' => 'http://example.com/uploads/hero.webp',
				),
			)
		);

		$this->assertSame( 1, $updated );
		$this->assertStringContainsString(
			'url(/uploads/hero.webp)',
			WPTestStub::$options['widget_custom_html'][3]['content']
		);
	}

	public function test_replace_ignores_unrelated_options(): void {
		WPTestStub::$options['sevwmfw3tc_delete_originals'] = true;
		WPTestStub::$options['some_other_plugin_settings']  = array(
			'image' => 'http://example.com/uploads/photo.jpg',
		);

		$updated = ( new Options_Replacer() )->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/photo.jpg',
					'new' => 'http://example.com/uploads/photo.webp',
				),
			)
		);

		$this->assertSame( 0, $updated );
		$this->assertSame(
			'http://example.com/uploads/photo.jpg',
			WPTestStub::$options['some_other_plugin_settings']['image']
		);
	}

	public function test_replace_returns_zero_when_nothing_references_the_url(): void {
		WPTestStub::$options['widget_text'] = array(
			2 => array( 'text' => 'no images here' ),
		);

		$updated = ( new Options_Replacer() )->replace(
			array(
				array(
					'old' => 'http://example.com/uploads/photo.jpg',
					'new' => 'http://example.com/uploads/photo.webp',
				),
			)
		);

		$this->assertSame( 0, $updated );
	}

	public function test_replace_returns_zero_for_empty_pairs(): void {
		WPTestStub::$options['widget_text'] = array(
			2 => array( 'text' => '<img src="http://example.com/uploads/photo.jpg" />' ),
		);

		$this->assertSame( 0, ( new Options_Replacer() )->replace( array() ) );
	}
}

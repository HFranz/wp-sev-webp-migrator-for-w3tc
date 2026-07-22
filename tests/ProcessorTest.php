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
	 * Reproduces the reported bug: W3TC ImageService converts the full size
	 * before its intermediate sizes and already writes the w3tc_imageservice
	 * meta once the full size is done. Processing at that point must leave
	 * everything untouched - migrating now would mark the attachment as
	 * fully processed (already_processed() short-circuits every later run),
	 * so the intermediate sizes would never be replaced/migrated/deleted
	 * once W3TC actually finishes converting them.
	 */
	public function test_process_does_nothing_while_an_intermediate_size_is_not_yet_converted(): void {
		touch( "{$this->tmp_dir}/photo-scaled.jpg" );
		touch( "{$this->tmp_dir}/photo-scaled.webp" ); // Full size already converted by W3TC.
		touch( "{$this->tmp_dir}/photo-scaled-300x200.jpg" ); // Intermediate size: not converted yet, no .webp.

		WPTestStub::$attachment_urls[42]     = 'http://example.com/uploads/photo-scaled.jpg';
		WPTestStub::$attached_files[42]      = "{$this->tmp_dir}/photo-scaled.jpg";
		WPTestStub::$attachment_metadata[42] = array(
			'file'  => 'photo-scaled.jpg',
			'sizes' => array(
				'medium' => array( 'file' => 'photo-scaled-300x200.jpg' ),
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
	}

	public function test_process_does_nothing_when_only_an_intermediate_size_is_converted_but_full_size_is_not(): void {
		touch( "{$this->tmp_dir}/photo-scaled.jpg" ); // Full size: not converted yet, no .webp.
		touch( "{$this->tmp_dir}/photo-scaled-300x200.jpg" );
		touch( "{$this->tmp_dir}/photo-scaled-300x200.webp" ); // Intermediate size already converted.

		WPTestStub::$attachment_urls[43]     = 'http://example.com/uploads/photo-scaled.jpg';
		WPTestStub::$attached_files[43]      = "{$this->tmp_dir}/photo-scaled.jpg";
		WPTestStub::$attachment_metadata[43] = array(
			'file'  => 'photo-scaled.jpg',
			'sizes' => array(
				'medium' => array( 'file' => 'photo-scaled-300x200.jpg' ),
			),
		);
		WPTestStub::$mime_types[43] = 'image/jpeg';

		$result = ( new Processor() )->process( 43, false );

		$this->assertSame( self::NOOP_RESULT, $result );
	}
}

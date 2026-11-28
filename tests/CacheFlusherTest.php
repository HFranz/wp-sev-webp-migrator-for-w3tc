<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SevWebPMigratorForW3TC\Cache_Flusher;

final class CacheFlusherTest extends TestCase {

	protected function setUp(): void {
		WPTestStub::reset();
	}

	public function test_does_nothing_when_nothing_changed(): void {
		( new Cache_Flusher() )->flush( array(), false );

		$this->assertSame( array(), WPTestStub::$w3tc_flushes );
	}

	public function test_flushes_each_rewritten_post_individually(): void {
		( new Cache_Flusher() )->flush( array( 3, 7 ), false );

		$this->assertSame( array( array( 'post', 3 ), array( 'post', 7 ) ), WPTestStub::$w3tc_flushes );
	}

	public function test_flushes_all_posts_once_when_an_option_changed(): void {
		( new Cache_Flusher() )->flush( array( 3, 7 ), true );

		$this->assertSame( array( array( 'posts' ) ), WPTestStub::$w3tc_flushes );
	}

	public function test_flushes_all_posts_once_when_a_sitewide_post_type_changed(): void {
		WPTestStub::$post_types[7] = 'custom_css';

		( new Cache_Flusher() )->flush( array( 3, 7 ), false );

		$this->assertSame( array( array( 'posts' ) ), WPTestStub::$w3tc_flushes );
	}
}

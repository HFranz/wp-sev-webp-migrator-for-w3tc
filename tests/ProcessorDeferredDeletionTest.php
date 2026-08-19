<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SevWebPMigratorForW3TC\Processor;

/**
 * Tests the grace-period logic that defers deleting an attachment's
 * original files when it was uploaded too recently, reproducing the
 * reported bug: an image inserted into a post being edited, converted (and
 * its original deleted) almost immediately, showed as a broken image in
 * the block editor because the browser was still rendering it from the
 * now-deleted URL.
 *
 * uploaded_too_recently_to_delete() and schedule_deferred_deletion() are
 * private - Processor::process() can't reach them without first getting
 * through Attachment_Migrator::migrate(), which (see AGENTS.md) isn't
 * unit-testable here. Reflection is used to test this logic in isolation
 * instead.
 */
final class ProcessorDeferredDeletionTest extends TestCase {

	protected function setUp(): void {
		WPTestStub::reset();
	}

	private function is_too_recent( Processor $processor, int $attachment_id ): bool {
		// No setAccessible() call: reflection has bypassed method visibility
		// unconditionally since PHP 8.1 (the call itself is deprecated as of 8.5).
		$method = new ReflectionMethod( Processor::class, 'uploaded_too_recently_to_delete' );

		return $method->invoke( $processor, $attachment_id );
	}

	/** @param array<int, array{old: string, new: string}> $path_pairs */
	private function schedule( Processor $processor, int $attachment_id, array $path_pairs ): void {
		$method = new ReflectionMethod( Processor::class, 'schedule_deferred_deletion' );

		$method->invoke( $processor, $attachment_id, $path_pairs );
	}

	public function test_true_for_an_attachment_uploaded_seconds_ago(): void {
		WPTestStub::$posts[5] = (object) array(
			'ID'            => 5,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5 ),
		);

		$this->assertTrue( $this->is_too_recent( new Processor(), 5 ) );
	}

	public function test_false_for_an_attachment_uploaded_well_past_the_default_grace_period(): void {
		WPTestStub::$posts[5] = (object) array(
			'ID'            => 5,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
		);

		$this->assertFalse( $this->is_too_recent( new Processor(), 5 ) );
	}

	public function test_false_when_the_attachment_post_cannot_be_found(): void {
		$this->assertFalse( $this->is_too_recent( new Processor(), 999 ) );
	}

	public function test_schedules_a_single_event_with_the_attachment_id_and_path_pairs_as_args(): void {
		$path_pairs = array(
			array(
				'old' => '/uploads/photo.jpg',
				'new' => '/uploads/photo.webp',
			),
		);

		$this->schedule( new Processor(), 5, $path_pairs );

		$this->assertCount( 1, WPTestStub::$scheduled_events );
		$this->assertSame( Processor::DEFERRED_DELETION_HOOK, WPTestStub::$scheduled_events[0]['hook'] );
		$this->assertSame( array( 5, $path_pairs ), WPTestStub::$scheduled_events[0]['args'] );
	}

	public function test_does_not_schedule_a_duplicate_event_for_the_same_attachment_and_pairs(): void {
		$path_pairs = array(
			array(
				'old' => '/uploads/photo.jpg',
				'new' => '/uploads/photo.webp',
			),
		);
		$processor = new Processor();

		$this->schedule( $processor, 5, $path_pairs );
		$this->schedule( $processor, 5, $path_pairs );

		$this->assertCount( 1, WPTestStub::$scheduled_events );
	}

	/**
	 * Confirms the grace period respects the sevwmfw3tc_deletion_grace_period
	 * filter, so a site can shorten or lengthen it without editing the plugin.
	 */
	public function test_grace_period_is_filterable(): void {
		$shorten_to_two_seconds = static fn () => 2;
		add_filter( 'sevwmfw3tc_deletion_grace_period', $shorten_to_two_seconds );

		WPTestStub::$posts[5] = (object) array(
			'ID'            => 5,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5 ),
		);

		$this->assertFalse( $this->is_too_recent( new Processor(), 5 ) );

		remove_filter( 'sevwmfw3tc_deletion_grace_period', $shorten_to_two_seconds );
	}
}

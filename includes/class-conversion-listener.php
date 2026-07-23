<?php
/**
 * Listens for W3TC ImageService conversions and triggers processing.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Hooks into the `w3tc_imageservice` post meta writes W3 Total Cache performs
 * once it has converted an attachment, and triggers the Processor for that
 * attachment as soon as its status becomes "converted".
 */
class Conversion_Listener {

	public function __construct( private Processor $processor ) {
	}

	/**
	 * Runs on `added_post_meta` / `updated_post_meta`.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $object_id  Attachment post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 *
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function on_meta_write( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		if ( 'w3tc_imageservice' !== $meta_key ) {
			return;
		}

		if ( ! is_array( $meta_value ) || 'converted' !== ( $meta_value['status'] ?? null ) ) {
			return;
		}

		$this->processor->process( $object_id, (bool) get_option( 'sevwmfw3tc_delete_originals' ) );
	}
}

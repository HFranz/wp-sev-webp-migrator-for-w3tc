<?php
/**
 * Rewrites converted image URLs stored inside widget and theme-mod options.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Permanently replaces old-extension image URLs with their WebP counterparts
 * inside `wp_options` rows whose name starts with `widget_` (classic and
 * block widget instances) or `theme_mods_` (Customizer settings such as a
 * custom background/header image), across every such option that references
 * them.
 *
 * These two are WordPress core's own storage for content that can embed an
 * image URL but isn't a post: {@see Content_Replacer} only ever looks at
 * `wp_posts.post_content`, so a URL referenced solely from a widget or a
 * theme mod would be missed there, and {@see Source_Cleaner} would then
 * delete the original file the widget/theme mod still pointed at.
 *
 * Widget instances and theme mods are stored as serialized PHP values, so a
 * raw string replace on the `option_value` database column would corrupt the
 * serialization whenever the old and new string differ in length (e.g.
 * ".jpg" → ".webp"), since PHP's serialized format embeds each string's exact
 * byte length. Each option is therefore read via get_option() (which
 * unserializes it), walked recursively, and written back via update_option()
 * (which reserializes it correctly) - only when something actually changed.
 */
class Options_Replacer {

	/**
	 * Replaces every old→new URL pair inside matching options' values.
	 *
	 * @param array<int, array{old: string, new: string}> $url_pairs Old/new URL pairs.
	 * @return int Number of distinct options updated.
	 */
	public function replace( array $url_pairs ): int {
		global $wpdb;

		if ( empty( $url_pairs ) ) {
			return 0;
		}

		$variants = array();
		foreach ( $url_pairs as $pair ) {
			if ( '' === $pair['old'] || $pair['old'] === $pair['new'] ) {
				continue;
			}

			array_push( $variants, ...Attachment_Urls::url_variants( $pair['old'], $pair['new'] ) );
		}

		if ( empty( $variants ) ) {
			return 0;
		}

		$updated = 0;

		foreach ( self::matching_option_names( $wpdb ) as $option_name ) {
			$value = get_option( $option_name );

			$result = self::replace_recursively( $value, $variants );

			if ( ! $result['changed'] ) {
				continue;
			}

			update_option( $option_name, $result['value'] );
			++$updated;
		}

		return $updated;
	}

	/**
	 * Finds every option name starting with "widget_" or "theme_mods_".
	 *
	 * @param \wpdb $wpdb WordPress database access object.
	 * @return string[] Option names.
	 */
	private static function matching_option_names( $wpdb ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'widget_' ) . '%',
				$wpdb->esc_like( 'theme_mods_' ) . '%'
			)
		);
	}

	/**
	 * Recursively replaces every variant's old string with its new string
	 * throughout an arbitrary (possibly nested) option value.
	 *
	 * @param mixed                                          $value    Value to search, typically an array of widget instances or theme mods.
	 * @param array<int, array{old: string, new: string}>    $variants Old/new string variants to replace.
	 * @return array{value: mixed, changed: bool}
	 */
	private static function replace_recursively( mixed $value, array $variants ): array {
		if ( is_string( $value ) ) {
			$new_value = $value;
			foreach ( $variants as $variant ) {
				$new_value = str_replace( $variant['old'], $variant['new'], $new_value );
			}

			return array(
				'value'   => $new_value,
				'changed' => $new_value !== $value,
			);
		}

		if ( is_array( $value ) ) {
			$changed = false;
			$result  = array();

			foreach ( $value as $key => $item ) {
				$item_result    = self::replace_recursively( $item, $variants );
				$result[ $key ] = $item_result['value'];
				$changed        = $changed || $item_result['changed'];
			}

			return array(
				'value'   => $result,
				'changed' => $changed,
			);
		}

		if ( is_object( $value ) ) {
			$changed = false;
			$clone   = clone $value;

			foreach ( get_object_vars( $value ) as $key => $item ) {
				$item_result = self::replace_recursively( $item, $variants );
				$clone->$key = $item_result['value'];
				$changed     = $changed || $item_result['changed'];
			}

			return array(
				'value'   => $clone,
				'changed' => $changed,
			);
		}

		return array(
			'value'   => $value,
			'changed' => false,
		);
	}
}

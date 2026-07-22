<?php
/**
 * Settings page and manual bulk-processing action.
 *
 * @package SevWebPMigratorForW3TC
 */

namespace SevWebPMigratorForW3TC;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Registers the "Settings → SEV WebP Migrator for W3TC" admin page, where the
 * "delete original images" option lives, and a manual "process now" batch
 * action for images that were already converted by W3TC before this plugin
 * was active (the live listener only fires for conversions happening from now on).
 */
class Admin_Settings {

	private const OPTION_DELETE_ORIGINALS = 'sevwmfw3tc_delete_originals';
	private const PAGE_SLUG               = 'sev-webp-migrator-for-w3tc';
	private const BATCH_SIZE              = 20;

	private Processor $processor;

	public function __construct( Processor $processor ) {
		$this->processor = $processor;
	}

	/**
	 * Registers WordPress hooks. Call once during plugins_loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_sevwmfw3tc_process_batch', array( $this, 'handle_process_batch' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Adds the settings page under the "Settings" menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'WebP Migrator for W3TC', 'sev-webp-migrator-for-w3tc' ),
			__( 'WebP Migrator for W3TC', 'sev-webp-migrator-for-w3tc' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers the "delete originals" checkbox setting.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_DELETE_ORIGINALS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		add_settings_section( 'sevwmfw3tc_main', '', '__return_false', self::PAGE_SLUG );

		add_settings_field(
			self::OPTION_DELETE_ORIGINALS,
			__( 'Delete source images', 'sev-webp-migrator-for-w3tc' ),
			array( $this, 'render_delete_originals_field' ),
			self::PAGE_SLUG,
			'sevwmfw3tc_main'
		);
	}

	/**
	 * Renders the "delete originals" checkbox field.
	 *
	 * @return void
	 */
	public function render_delete_originals_field(): void {
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_DELETE_ORIGINALS ); ?>" value="1"
				<?php checked( (bool) get_option( self::OPTION_DELETE_ORIGINALS, false ) ); ?> />
			<?php esc_html_e( 'After successful replacement, permanently delete the original image files (jpg/jpeg/png/gif).', 'sev-webp-migrator-for-w3tc' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'A file is only deleted if its WebP counterpart already exists on the server. Without a backup, this step cannot be undone.', 'sev-webp-migrator-for-w3tc' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the settings page, including the manual batch-processing form.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$remaining = $this->count_unprocessed();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WebP Migrator for W3TC', 'sev-webp-migrator-for-w3tc' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save settings', 'sev-webp-migrator-for-w3tc' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Process already converted images', 'sev-webp-migrator-for-w3tc' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %d: number of not-yet-processed attachments. */
					esc_html__( 'Images that W3TC had already converted before this plugin was activated are not picked up automatically. Converted images not yet processed: %d.', 'sev-webp-migrator-for-w3tc' ),
					(int) $remaining
				);
				?>
			</p>
			<?php if ( $remaining > 0 ) : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="sevwmfw3tc_process_batch" />
					<?php wp_nonce_field( 'sevwmfw3tc_process_batch' ); ?>
					<?php
					submit_button(
						sprintf(
							/* translators: %d: batch size. */
							__( 'Process next %d images now', 'sev-webp-migrator-for-w3tc' ),
							self::BATCH_SIZE
						),
						'primary'
					);
					?>
				</form>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'All converted images have already been processed.', 'sev-webp-migrator-for-w3tc' ); ?></strong></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handles the manual "process next batch" form submission.
	 *
	 * @return void
	 */
	public function handle_process_batch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sev-webp-migrator-for-w3tc' ) );
		}

		check_admin_referer( 'sevwmfw3tc_process_batch' );

		$delete_originals = (bool) get_option( self::OPTION_DELETE_ORIGINALS, false );
		$attachment_ids    = $this->find_unprocessed( self::BATCH_SIZE );

		$posts_updated = 0;
		$files_deleted = 0;
		$processed     = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			$result = $this->processor->process( $attachment_id, $delete_originals );

			if ( $result['migrated'] ) {
				++$processed;
			}

			$posts_updated += $result['posts_updated'];
			$files_deleted += $result['files_deleted'];
		}

		$redirect = add_query_arg(
			array(
				'page'                => self::PAGE_SLUG,
				'sevwmfw3tc_processed' => $processed,
				'sevwmfw3tc_posts'     => $posts_updated,
				'sevwmfw3tc_files'     => $files_deleted,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Renders the result notice after a manual batch run.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! isset( $_GET['sevwmfw3tc_processed'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$processed = (int) $_GET['sevwmfw3tc_processed'];
		$posts     = isset( $_GET['sevwmfw3tc_posts'] ) ? (int) $_GET['sevwmfw3tc_posts'] : 0;
		$files     = isset( $_GET['sevwmfw3tc_files'] ) ? (int) $_GET['sevwmfw3tc_files'] : 0;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: attachments processed, 2: posts updated, 3: files deleted. */
					__( '%1$d image(s) processed, %2$d post(s) updated, %3$d source file(s) deleted.', 'sev-webp-migrator-for-w3tc' ),
					$processed,
					$posts,
					$files
				)
			)
		);
	}

	/**
	 * Finds attachment IDs that W3TC has converted but this plugin has not yet processed.
	 *
	 * @param int $limit Maximum number of IDs to return.
	 * @return int[] Attachment post IDs.
	 */
	private function find_unprocessed( int $limit ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => 'w3tc_imageservice',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$ids = array();
		foreach ( $query->posts as $attachment_id ) {
			$meta = get_post_meta( $attachment_id, 'w3tc_imageservice', true );
			if ( is_array( $meta ) && 'converted' === ( $meta['status'] ?? null ) ) {
				$ids[] = (int) $attachment_id;
			}
		}

		return $ids;
	}

	/**
	 * Counts attachments that W3TC has converted but this plugin has not yet processed.
	 *
	 * Capped for display purposes; large libraries are worked through batch by batch.
	 *
	 * @return int Count, capped at 500.
	 */
	private function count_unprocessed(): int {
		return count( $this->find_unprocessed( 500 ) );
	}
}

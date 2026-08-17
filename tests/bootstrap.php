<?php /** @noinspection PhpUnused */

declare( strict_types=1 );

/*
 * Minimal WordPress stubs for PHPUnit tests without a full WP bootstrap.
 * Only the functions used by the plugin (and exercised by the tests) are provided.
 *
 * Attachment_Migrator and Source_Cleaner touch the real filesystem/$wpdb schema in
 * ways that aren't meaningfully stubbable here; they are intentionally not covered
 * by these tests. See AGENTS.md.
 */

define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );

// ---------------------------------------------------------------------------
// Simple filter/action system
// ---------------------------------------------------------------------------

$GLOBALS['wp_filter'] = array();

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['wp_filter'][ $tag ][ $priority ][] = $callback;
	return true;
}

function add_action( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['wp_filter'][ $tag ][ $priority ][] = $callback;
	return true;
}

function apply_filters( string $tag, mixed $value, mixed ...$extra ): mixed {
	if ( empty( $GLOBALS['wp_filter'][ $tag ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['wp_filter'][ $tag ] );
	foreach ( $GLOBALS['wp_filter'][ $tag ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$extra );
		}
	}
	return $value;
}

function do_action( string $tag, mixed ...$args ): void {
	if ( empty( $GLOBALS['wp_filter'][ $tag ] ) ) {
		return;
	}
	ksort( $GLOBALS['wp_filter'][ $tag ] );
	foreach ( $GLOBALS['wp_filter'][ $tag ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callback( ...$args );
		}
	}
}

function is_admin(): bool {
	return false;
}

// ---------------------------------------------------------------------------
// WordPress function stubs (delegate to WPTestStub)
// ---------------------------------------------------------------------------

function plugin_dir_path( string $file ): string {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

function trailingslashit( string $string ): string {
	return rtrim( $string, '/\\' ) . '/';
}

function wp_get_attachment_url( int $attachment_id ): string|false {
	return WPTestStub::$attachment_urls[ $attachment_id ] ?? false;
}

function wp_get_attachment_metadata( int $attachment_id ): array|false {
	return WPTestStub::$attachment_metadata[ $attachment_id ] ?? false;
}

function get_attached_file( int $attachment_id, bool $unfiltered = false ): string|false {
	return WPTestStub::$attached_files[ $attachment_id ] ?? false;
}

function get_post_mime_type( int $attachment_id ): string|false {
	return WPTestStub::$mime_types[ $attachment_id ] ?? false;
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
	if ( '' === $key ) {
		return WPTestStub::$post_meta[ $post_id ] ?? array();
	}

	if ( ! array_key_exists( $post_id, WPTestStub::$post_meta ) || ! array_key_exists( $key, WPTestStub::$post_meta[ $post_id ] ) ) {
		return $single ? '' : array();
	}

	$value = WPTestStub::$post_meta[ $post_id ][ $key ];

	return $single ? $value : array( $value );
}

function get_option( string $option, mixed $default = false ): mixed {
	return WPTestStub::$options[ $option ] ?? $default;
}

function clean_post_cache( int $post_id ): void {
	WPTestStub::$cleaned_post_caches[] = $post_id;
}

/**
 * Stub for WordPress core's subsize generator. Records the call and, if a
 * test configured one, runs a side effect (e.g. actually creating the
 * "regenerated" file on disk) to simulate a successful regeneration.
 */
function wp_create_image_subsizes( string $file, int $attachment_id ): array {
	WPTestStub::$image_subsizes_calls[] = array(
		'file'          => $file,
		'attachment_id' => $attachment_id,
	);

	if ( null !== WPTestStub::$create_image_subsizes_side_effect ) {
		( WPTestStub::$create_image_subsizes_side_effect )( $file, $attachment_id );
	}

	return array();
}

// ---------------------------------------------------------------------------
// Minimal in-memory $wpdb stub, only supporting the query shape Content_Replacer uses.
// ---------------------------------------------------------------------------

class Fake_Wpdb {

	public string $posts = 'wp_posts';

	/** @var array<int, string> post_id => post_content */
	public array $post_content = array();

	/** @var array<int, array{table: string, data: array, where: array}> */
	public array $updates = array();

	private string $pending_like = '';

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->pending_like = (string) ( $args[0] ?? '' );
		return $query;
	}

	/** @return array<int, object{ID:int, post_content:string}> */
	public function get_results( string $query ): array {
		$needle  = trim( $this->pending_like, '%' );
		$matches = array();

		foreach ( $this->post_content as $id => $content ) {
			if ( '' !== $needle && str_contains( $content, $needle ) ) {
				$matches[] = (object) array(
					'ID'           => $id,
					'post_content' => $content,
				);
			}
		}

		return $matches;
	}

	public function update( string $table, array $data, array $where ): int {
		$id                          = (int) $where['ID'];
		$this->post_content[ $id ]   = $data['post_content'];
		$this->updates[]             = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);
		return 1;
	}
}

// ---------------------------------------------------------------------------
// Configurable stub data store
// ---------------------------------------------------------------------------

class WPTestStub {

	/** attachment_id => full-size URL */
	public static array $attachment_urls = array();

	/** attachment_id => wp_get_attachment_metadata() shaped array */
	public static array $attachment_metadata = array();

	/** attachment_id => filesystem path of the attached file */
	public static array $attached_files = array();

	/** attachment_id => post_mime_type */
	public static array $mime_types = array();

	/** post_id => array<meta_key, single value> (single-value get_post_meta() only, matching plugin usage) */
	public static array $post_meta = array();

	/** option_name => value */
	public static array $options = array();

	/** post_ids passed to clean_post_cache() */
	public static array $cleaned_post_caches = array();

	/** @var array<int, array{file: string, attachment_id: int}> Calls made to wp_create_image_subsizes(). */
	public static array $image_subsizes_calls = array();

	/** @var (callable(string, int): void)|null Optional side effect run by the wp_create_image_subsizes() stub. */
	public static $create_image_subsizes_side_effect = null;

	/** Resets all mock data (without clearing registered filters/actions). */
	public static function reset(): void {
		self::$attachment_urls                    = array();
		self::$attachment_metadata                = array();
		self::$attached_files                     = array();
		self::$mime_types                         = array();
		self::$post_meta                          = array();
		self::$options                            = array();
		self::$cleaned_post_caches                = array();
		self::$image_subsizes_calls               = array();
		self::$create_image_subsizes_side_effect  = null;
	}
}

// ---------------------------------------------------------------------------
// Load plugin (registers add_filter / add_action)
// ---------------------------------------------------------------------------

// Simulate W3TC as active and fire plugins_loaded so the guard takes effect.
const W3TC = true;

require_once dirname( __DIR__ ) . '/includes/class-attachment-urls.php';
require_once dirname( __DIR__ ) . '/includes/class-content-replacer.php';

// Attachment_Migrator and Source_Cleaner touch the real filesystem/$wpdb schema in
// ways that aren't meaningfully stubbable here, so their own methods are not
// exercised by tests. Their class definitions are still loaded because Processor
// instantiates them directly; every Processor test scenario is expected to stop at
// Processor's own gate before migrate()/delete_originals() would actually run.
require_once dirname( __DIR__ ) . '/includes/class-attachment-migrator.php';
require_once dirname( __DIR__ ) . '/includes/class-source-cleaner.php';
require_once dirname( __DIR__ ) . '/includes/class-processor.php';

do_action( 'plugins_loaded' );

<?php
/**
 * Orphaned-media detection regression (v6.48.2).
 *
 * The v4.x check searched PUBLISHED post bodies for the image's ORIGINAL basename
 * (photo.jpg). Gutenberg references images by their ID class (wp-image-<id>) and by
 * their SIZED URL (photo-1024x576.jpg), and the logo/site-icon live in options — so
 * real, in-use images were false-flagged as orphans. This pins the broadened signals
 * in sn_health_attachment_is_referenced(): block-ID class, generated-size filenames,
 * post meta, featured image, and site logo/icon. A genuine orphan still reads false.
 *
 * @since plugin v6.48.2
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A', 'ARRAY_A' ); }

// ── Configurable corpus (what the substring search "finds") ──
$GLOBALS['__post_bodies'] = array(); // post_content strings
$GLOBALS['__meta_values'] = array(); // meta_value strings
$GLOBALS['__att_meta']    = array(); // id => wp_get_attachment_metadata() return
$GLOBALS['__attachments'] = array(); // get_results() rows
$GLOBALS['__featured']    = array(); // _thumbnail_id meta_values
$GLOBALS['__theme_mods']  = array(); // theme_mod name => value
$GLOBALS['__options']     = array(); // option name => value

// Substring-corpus $wpdb: a LIKE %needle% query returns 1 iff `needle` is a
// substring of any string in the relevant corpus (post bodies vs meta values).
class SnOrphanWpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	private $last_sql  = '';
	private $last_args = array();
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$this->last_sql  = $sql;
		$this->last_args = $args;
		return $sql;
	}
	public function esc_like( $s ) { return $s; }
	public function get_var( $sql ) {
		$needle = isset( $this->last_args[0] ) ? trim( (string) $this->last_args[0], '%' ) : '';
		if ( '' === $needle ) { return 0; }
		$corpus = ( false !== strpos( $this->last_sql, 'postmeta' ) )
			? $GLOBALS['__meta_values']
			: $GLOBALS['__post_bodies'];
		foreach ( $corpus as $hay ) {
			if ( false !== strpos( (string) $hay, $needle ) ) { return 1; }
		}
		return 0;
	}
	public function get_results( $sql ) { return $GLOBALS['__attachments']; }
	public function get_col( $sql )     { return $GLOBALS['__featured']; }
}
$GLOBALS['wpdb'] = new SnOrphanWpdb();

// ── WP stubs ──
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( (string) $p ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $id ) { return $GLOBALS['__att_meta'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'get_theme_mod' ) ) {
	function get_theme_mod( $name, $default = false ) { return $GLOBALS['__theme_mods'][ $name ] ?? $default; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { return $GLOBALS['__options'][ $name ] ?? $default; }
}

require_once __DIR__ . '/../inc/health-checks.php';

$pass = 0; $fail = 0;
function od_true( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Orphaned-media detection — broadened reference signals (v6.48.2)\n\n";

// Metadata: image 123 has a 'large' size variant filename.
$GLOBALS['__att_meta'] = array(
	123 => array( 'file' => '2024/01/photo.jpg',  'sizes' => array( 'large' => array( 'file' => 'photo-1024x576.jpg' ) ) ),
	124 => array( 'file' => '2024/01/shot.jpg',   'sizes' => array( 'large' => array( 'file' => 'shot-1024x576.jpg' ) ) ),
	200 => array( 'file' => '2024/01/orphan.jpg', 'sizes' => array() ),
);
$guid = function ( $f ) { return 'https://example.test/wp-content/uploads/2024/01/' . $f; };

// 1. Block image referenced by ID class (wp-image-123) — the dominant case.
$GLOBALS['__post_bodies'] = array( '<figure class="wp-block-image size-large"><img src="' . $guid( 'photo-1024x576.jpg' ) . '" class="wp-image-123"/></figure>' );
$GLOBALS['__meta_values'] = array();
od_true( true === sn_health_attachment_is_referenced( 123, $guid( 'photo.jpg' ), array(), array() ),
	'block image (class="wp-image-123") is detected as referenced [was a false orphan pre-v6.48.2]' );

// 2. Sized-URL only, NO wp-image class (classic editor / direct URL): the ORIGINAL
//    basename (shot.jpg) is NOT in the body, only the sized variant (shot-1024x576.jpg).
$GLOBALS['__post_bodies'] = array( '<img src="' . $guid( 'shot-1024x576.jpg' ) . '">' );
od_true( true === sn_health_attachment_is_referenced( 124, $guid( 'shot.jpg' ), array(), array() ),
	'sized-variant URL (shot-1024x576.jpg) is detected even though the original basename is absent' );

// 3. Old behavior sanity: the ORIGINAL basename in a body still counts.
$GLOBALS['__post_bodies'] = array( 'see ' . $guid( 'photo.jpg' ) . ' here' );
od_true( true === sn_health_attachment_is_referenced( 123, $guid( 'photo.jpg' ), array(), array() ),
	'original full-size filename in a body still counts as referenced' );

// 4. Featured image → referenced.
$GLOBALS['__post_bodies'] = array();
od_true( true === sn_health_attachment_is_referenced( 200, $guid( 'orphan.jpg' ), array( 200 => true ), array() ),
	'featured-image id is referenced' );

// 5. Site logo / icon → referenced (chrome set).
od_true( true === sn_health_attachment_is_referenced( 200, $guid( 'orphan.jpg' ), array(), array( 200 => true ) ),
	'site logo / site icon id is referenced (lives in options, not a post body)' );

// 6. Post-meta reference (OG image / custom field) → referenced.
$GLOBALS['__post_bodies'] = array();
$GLOBALS['__meta_values'] = array( 'og_image=' . $guid( 'orphan.jpg' ) );
od_true( true === sn_health_attachment_is_referenced( 200, $guid( 'orphan.jpg' ), array(), array() ),
	'image referenced in post meta is detected' );

// 7. Genuine orphan (no body, no meta, no chrome, no sizes match) → NOT referenced.
$GLOBALS['__post_bodies'] = array( 'unrelated content with no images' );
$GLOBALS['__meta_values'] = array( 'some_setting=value' );
od_true( false === sn_health_attachment_is_referenced( 200, $guid( 'orphan.jpg' ), array(), array() ),
	'a genuinely unreferenced image is still flagged (false === not-referenced)' );

// 8. Integration: the full check excludes the logo + a block-referenced image,
//    flags only the true orphan.
$GLOBALS['__attachments'] = array(
	array( 'ID' => 123, 'post_title' => 'Photo',  'guid' => $guid( 'photo.jpg' ),  'post_date_gmt' => '2020-01-01 00:00:00' ),
	array( 'ID' => 200, 'post_title' => 'Orphan', 'guid' => $guid( 'orphan.jpg' ), 'post_date_gmt' => '2020-01-01 00:00:00' ),
	array( 'ID' => 300, 'post_title' => 'Logo',   'guid' => $guid( 'logo.png' ),   'post_date_gmt' => '2020-01-01 00:00:00' ),
);
$GLOBALS['__att_meta'][300] = array( 'file' => '2024/01/logo.png', 'sizes' => array() );
$GLOBALS['__featured']   = array();
$GLOBALS['__theme_mods'] = array( 'custom_logo' => 300 ); // 300 is the site logo
$GLOBALS['__options']    = array();
$GLOBALS['__post_bodies'] = array( '<img class="wp-image-123" src="' . $guid( 'photo-1024x576.jpg' ) . '">' ); // 123 used
$GLOBALS['__meta_values'] = array();
$check = sn_health_check_orphaned_media();
$ids   = array_map( function ( $f ) { return (int) $f['subject_id']; }, $check['findings'] );
od_true( ! in_array( 123, $ids, true ), 'full check: a block-referenced image (123) is NOT flagged' );
od_true( ! in_array( 300, $ids, true ), 'full check: the site logo (300) is NOT flagged' );
od_true( in_array( 200, $ids, true ) && 1 === (int) $check['count'], 'full check: only the true orphan (200) is flagged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

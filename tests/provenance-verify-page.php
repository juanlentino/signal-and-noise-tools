<?php
/**
 * Standalone harness for the /provenance/verify page seed + retrofit
 * migration. Mirrors the idiom in tests/provenance-render.php: function_exists-
 * guarded WP stubs backed by $GLOBALS, run via `php tests/provenance-verify-page.php`.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SNT_VERSION' ) ) {
	define( 'SNT_VERSION', 'test' );
}

$GLOBALS['__pv_options']     = array();
$GLOBALS['__pv_pages']       = array(); // path => page object ( ->ID, ->post_content )
$GLOBALS['__pv_inserts']     = 0;       // wp_insert_post call count
$GLOBALS['__pv_last_insert'] = null;    // last wp_insert_post args

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__pv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__pv_options'][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
		return $GLOBALS['__pv_pages'][ $path ] ?? null; }
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	// Record the insert; return a fixed synthetic ID. Tests seed existing
	// pages manually into __pv_pages to exercise the idempotent path.
	function wp_insert_post( $args = array(), $wp_error = false ) {
		++$GLOBALS['__pv_inserts'];
		$GLOBALS['__pv_last_insert'] = $args;
		return 500; }
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

require_once SNT_PATH . 'inc/content-surfaces.php';
require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0;
$fail = 0;
function vp_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function vp_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

echo "Provenance verify-page suite\n\nTask A1: fresh insert\n";
$GLOBALS['__pv_pages']       = array(
	SN_PROVENANCE_SLUG => (object) array( 'ID' => 100, 'post_content' => 'pillar' ),
);
$GLOBALS['__pv_inserts']     = 0;
$GLOBALS['__pv_last_insert'] = null;

$new_id = sn_ensure_verify_page();
vp_eq( 1, $GLOBALS['__pv_inserts'], 'one page inserted when the verify child is absent' );
vp_eq( 500, $new_id, 'returns the new page ID' );
$ins = $GLOBALS['__pv_last_insert'];
vp_eq( SN_VERIFY_SLUG, $ins['post_name'] ?? null, 'inserted page slug is "verify"' );
vp_eq( 100, $ins['post_parent'] ?? null, 'inserted page parent = the provenance page ID' );
vp_eq( 'page-provenance', $ins['page_template'] ?? null, 'inherits the page-provenance sibling template' );
vp_eq( 'publish', $ins['post_status'] ?? null, 'published' );
vp_eq( 'page', $ins['post_type'] ?? null, 'is a page' );
vp_true( false !== strpos( (string) ( $ins['post_content'] ?? '' ), '[sn_provenance_verify]' ), 'body carries the [sn_provenance_verify] shortcode' );
vp_true( '' !== trim( (string) ( $ins['post_excerpt'] ?? '' ) ), 'has a non-empty excerpt' );

echo "\nTask A2: idempotent — existing verify child left untouched\n";
$GLOBALS['__pv_pages'][ SN_PROVENANCE_SLUG . '/' . SN_VERIFY_SLUG ] = (object) array( 'ID' => 200, 'post_content' => 'existing' );
$GLOBALS['__pv_inserts'] = 0;
$existing_id = sn_ensure_verify_page();
vp_eq( 0, $GLOBALS['__pv_inserts'], 'no second insert when the page already exists' );
vp_eq( 200, $existing_id, 'returns the existing page ID' );

echo "\nTask A3: retrofit migration runs once\n";
// Run 1: flag unset, parent present, child absent -> ensures + flags.
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_pages']   = array(
	SN_PROVENANCE_SLUG => (object) array( 'ID' => 100, 'post_content' => 'pillar' ),
);
$GLOBALS['__pv_inserts'] = 0;
sn_migrate_verify_page_seed();
vp_eq( 1, $GLOBALS['__pv_inserts'], 'first run inserts the verify page' );
vp_true( (bool) get_option( SN_PROV_VERIFY_PAGE_MIGR_OPT ), 'migration flag is set after the first run' );

// Run 2: flag now set -> no-op even though the child looks absent again.
$GLOBALS['__pv_pages']   = array(
	SN_PROVENANCE_SLUG => (object) array( 'ID' => 100, 'post_content' => 'pillar' ),
);
$GLOBALS['__pv_inserts'] = 0;
sn_migrate_verify_page_seed();
vp_eq( 0, $GLOBALS['__pv_inserts'], 'second run is a no-op (flag gates it out)' );

echo "\nTask A4: migration bails cleanly when the provenance parent is missing\n";
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_pages']   = array(); // no parent yet
$GLOBALS['__pv_inserts'] = 0;
sn_migrate_verify_page_seed();
vp_eq( 0, $GLOBALS['__pv_inserts'], 'no insert while the parent page is absent' );
vp_true( (bool) get_option( SN_PROV_VERIFY_PAGE_MIGR_OPT ), 'flag still set so we stop scanning every admin_init' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

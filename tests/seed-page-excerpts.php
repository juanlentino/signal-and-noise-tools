<?php
/**
 * Standalone harness for the theme-authored Page excerpt map + back-fill
 * migration. Mirrors the idiom in tests/provenance-verify-page.php: function_exists-
 * guarded WP stubs backed by $GLOBALS, run via `php tests/seed-page-excerpts.php`.
 *
 * Covers:
 *   - sn_seed_page_excerpts() returns the canonical path=>excerpt map for the
 *     five theme-authored Pages that ship without their own back-fill migration
 *     (notes + the four provenance-family pages).
 *   - Every create-path (sn_ensure_* + the split migration) emits an excerpt that
 *     is byte-identical to the map, so a fresh install and a back-filled install
 *     describe each page the same way (single source of truth).
 *   - sn_migrate_seed_page_excerpts() back-fills post_excerpt only when empty,
 *     never clobbers an existing excerpt, is flag-gated to run once, and skips
 *     pages that don't exist yet.
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

$GLOBALS['__spe_options']     = array();
$GLOBALS['__spe_pages']       = array(); // path => page object ( ->ID, ->post_excerpt )
$GLOBALS['__spe_inserts']     = 0;       // wp_insert_post call count
$GLOBALS['__spe_last_insert'] = null;    // last wp_insert_post args
$GLOBALS['__spe_updates']     = array(); // all wp_update_post args, in order

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
		return $GLOBALS['__spe_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__spe_options'][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
		return $GLOBALS['__spe_pages'][ $path ] ?? null; }
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $args = array(), $wp_error = false ) {
		++$GLOBALS['__spe_inserts'];
		$GLOBALS['__spe_last_insert'] = $args;
		return 500; }
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args = array(), $wp_error = false ) {
		$GLOBALS['__spe_updates'][] = $args;
		return (int) ( $args['ID'] ?? 0 ); }
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

require_once SNT_PATH . 'inc/content-surfaces.php';
require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0;
$fail = 0;
function spe_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function spe_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

// Canonical paths for the five theme-authored Pages with no dedicated back-fill.
$P   = SN_PROVENANCE_SLUG;
$MAP = sn_seed_page_excerpts();
$paths = array(
	'notes'          => SN_NOTES_PAGE_SLUG,
	'provenance'     => $P,
	'over-detection' => $P . '/' . SN_OVER_DETECTION_SLUG,
	'as-substrate'   => $P . '/' . SN_AS_SUBSTRATE_SLUG,
	'verify'         => $P . '/' . SN_VERIFY_SLUG,
);

echo "Seed-page excerpt map\n";
spe_eq( 5, count( $MAP ), 'map holds exactly the five back-fill-less pages' );
foreach ( $paths as $name => $path ) {
	spe_true( isset( $MAP[ $path ] ), "map has an entry for $name ($path)" );
	spe_true( '' !== trim( (string) ( $MAP[ $path ] ?? '' ) ), "map excerpt for $name is non-empty" );
}
spe_eq(
	'Two papers proposing cryptographic provenance as the foundation of music rights infrastructure.',
	$MAP[ $P ] ?? null,
	'provenance excerpt is the canonical string'
);
spe_eq(
	"How to check any Note's cryptographic provenance record yourself, without trusting this site.",
	$MAP[ $P . '/' . SN_VERIFY_SLUG ] ?? null,
	'verify excerpt is the canonical string'
);

echo "\nCreate paths agree with the map (single source of truth)\n";
// Each sn_ensure_* insert must carry the exact map excerpt for its page.
$ensure = array(
	'notes'          => array( 'fn' => 'sn_ensure_notes_page',          'path' => SN_NOTES_PAGE_SLUG,                  'parent' => false ),
	'provenance'     => array( 'fn' => 'sn_ensure_provenance_page',      'path' => $P,                                  'parent' => false ),
	'over-detection' => array( 'fn' => 'sn_ensure_over_detection_page',  'path' => $P . '/' . SN_OVER_DETECTION_SLUG,   'parent' => true ),
	'as-substrate'   => array( 'fn' => 'sn_ensure_as_substrate_page',    'path' => $P . '/' . SN_AS_SUBSTRATE_SLUG,     'parent' => true ),
	'verify'         => array( 'fn' => 'sn_ensure_verify_page',          'path' => $P . '/' . SN_VERIFY_SLUG,           'parent' => true ),
);
foreach ( $ensure as $name => $e ) {
	// Parent present (for children), target child absent -> the ensure inserts.
	$GLOBALS['__spe_pages'] = $e['parent'] ? array( $P => (object) array( 'ID' => 100, 'post_content' => 'pillar' ) ) : array();
	$GLOBALS['__spe_inserts']     = 0;
	$GLOBALS['__spe_last_insert'] = null;
	call_user_func( $e['fn'] );
	$ins = $GLOBALS['__spe_last_insert'];
	spe_eq( 1, $GLOBALS['__spe_inserts'], "$name ensure inserts when absent" );
	spe_eq( $MAP[ $e['path'] ], $ins['post_excerpt'] ?? null, "$name create-path excerpt === map (no drift)" );
}

echo "\nBack-fill migration: seeds only empty excerpts, once\n";
// All five pages exist with EMPTY excerpts -> each gets back-filled, flag set.
$GLOBALS['__spe_options'] = array();
$GLOBALS['__spe_updates'] = array();
$id = 200;
$GLOBALS['__spe_pages'] = array();
foreach ( $paths as $path ) {
	$GLOBALS['__spe_pages'][ $path ] = (object) array( 'ID' => $id++, 'post_excerpt' => '' );
}
sn_migrate_seed_page_excerpts();
spe_eq( 5, count( $GLOBALS['__spe_updates'] ), 'all five empty-excerpt pages back-filled' );
$by_id = array();
foreach ( $GLOBALS['__spe_updates'] as $u ) {
	$by_id[ (int) $u['ID'] ] = $u['post_excerpt'] ?? null;
}
spe_eq( $MAP[ $P ], $by_id[201] ?? null, 'provenance page (ID 201) back-filled with the map excerpt' );
spe_true( (bool) get_option( SN_SEED_EXCERPTS_BACKFILL_OPT ), 'migration flag set after the run' );

echo "\nBack-fill migration: never clobbers an existing excerpt\n";
$GLOBALS['__spe_options'] = array();
$GLOBALS['__spe_updates'] = array();
$GLOBALS['__spe_pages']   = array(
	SN_NOTES_PAGE_SLUG                     => (object) array( 'ID' => 300, 'post_excerpt' => 'owner-written' ),
	$P                                     => (object) array( 'ID' => 301, 'post_excerpt' => '' ),
	$P . '/' . SN_OVER_DETECTION_SLUG      => (object) array( 'ID' => 302, 'post_excerpt' => 'already has one' ),
	$P . '/' . SN_AS_SUBSTRATE_SLUG        => (object) array( 'ID' => 303, 'post_excerpt' => '' ),
	$P . '/' . SN_VERIFY_SLUG              => (object) array( 'ID' => 304, 'post_excerpt' => '' ),
);
sn_migrate_seed_page_excerpts();
$updated_ids = array_map( function ( $u ) { return (int) $u['ID']; }, $GLOBALS['__spe_updates'] );
spe_eq( 3, count( $GLOBALS['__spe_updates'] ), 'only the three empty pages are written' );
spe_true( ! in_array( 300, $updated_ids, true ), 'owner-written excerpt (300) left untouched' );
spe_true( ! in_array( 302, $updated_ids, true ), 'existing excerpt (302) left untouched' );
spe_true( in_array( 301, $updated_ids, true ) && in_array( 303, $updated_ids, true ) && in_array( 304, $updated_ids, true ), 'the three empty pages (301,303,304) back-filled' );

echo "\nBack-fill migration: flag-gated and missing-page safe\n";
// Flag already set -> no-op.
$GLOBALS['__spe_options'] = array( SN_SEED_EXCERPTS_BACKFILL_OPT => time() );
$GLOBALS['__spe_updates'] = array();
$GLOBALS['__spe_pages']   = array( $P => (object) array( 'ID' => 400, 'post_excerpt' => '' ) );
sn_migrate_seed_page_excerpts();
spe_eq( 0, count( $GLOBALS['__spe_updates'] ), 'flag set -> never writes again' );

// Some pages not seeded yet -> skip them, back-fill the present ones, still flags.
$GLOBALS['__spe_options'] = array();
$GLOBALS['__spe_updates'] = array();
$GLOBALS['__spe_pages']   = array(
	$P                              => (object) array( 'ID' => 500, 'post_excerpt' => '' ),
	$P . '/' . SN_VERIFY_SLUG       => (object) array( 'ID' => 501, 'post_excerpt' => '' ),
);
sn_migrate_seed_page_excerpts();
spe_eq( 2, count( $GLOBALS['__spe_updates'] ), 'only the two existing pages are written; absent pages skipped' );
spe_true( (bool) get_option( SN_SEED_EXCERPTS_BACKFILL_OPT ), 'flag still set so we stop scanning every admin_init' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

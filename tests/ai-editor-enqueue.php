<?php
/**
 * Tests for the shared gen-1 AI editor-asset enqueue helper (v9.81.0).
 *
 * snt_ai_enqueue_editor_script() replaces the three near-identical (and
 * drifted) admin_enqueue_scripts closures in ai-excerpt.php,
 * ai-meta-description.php, and ai-og-card-title.php. Asserts the shared
 * gates (edit screens only, AI available, edit_posts cap), the base dep
 * set + per-caller extra deps, localization, and translation wiring —
 * plus that all three call sites actually route through the helper.
 *
 * Standalone CLI harness (no PHPUnit): inline WP stubs, a pass/fail
 * counter, and the summary line the CI sweep gates on.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }

$GLOBALS['__can']          = true;
$GLOBALS['__reg']          = array();
$GLOBALS['__loc']          = array();
$GLOBALS['__enq']          = array();
$GLOBALS['__trans']        = array();

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return $GLOBALS['__can']; } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $p, $f = '' ) { return 'https://x.test/wp-content/plugins/snt/' . $p; } }
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script( $h, $src, $deps, $v, $f ) { $GLOBALS['__reg'][ $h ] = array( 'src' => $src, 'deps' => $deps ); return true; } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( $h, $o, $d ) { $GLOBALS['__loc'][ $h ] = array( $o, $d ); return true; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( $h ) { $GLOBALS['__enq'][] = $h; return true; } }
if ( ! function_exists( 'wp_set_script_translations' ) ) { function wp_set_script_translations( $h, $d ) { $GLOBALS['__trans'][] = $h; return true; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

require_once SNT_PATH . 'inc/ai-bootstrap.php';

// Force the request-static availability cache (the real check needs the
// wp-ai-client classes, absent in this harness).
function sn_test_set_ai( $on ) { $c = &snt_ai_availability_cache(); $c = (bool) $on; }
sn_test_set_ai( true );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "shared AI editor-asset enqueue helper\n";

ok( function_exists( 'snt_ai_enqueue_editor_script' ), 'snt_ai_enqueue_editor_script() exists in the ai-bootstrap layer' );

// --- happy path: registers, localizes, enqueues, translates ---
snt_ai_enqueue_editor_script( 'post.php', 'snt-ai-excerpt', 'ai-excerpt.js', 'sntAiExcerpt', array( 'metaBoxClass' => 'sn-post-settings' ), array( 'wp-data' ) );
ok( isset( $GLOBALS['__reg']['snt-ai-excerpt'] ), 'registers the handle on post.php' );
ok( false !== strpos( $GLOBALS['__reg']['snt-ai-excerpt']['src'] ?? '', 'assets/ai-excerpt.js' ), 'src resolves under assets/' );
$deps = $GLOBALS['__reg']['snt-ai-excerpt']['deps'] ?? array();
ok( in_array( 'wp-api-fetch', $deps, true ) && in_array( 'snt-status', $deps, true ) && in_array( 'snt-ability-run', $deps, true ), 'base deps present (api-fetch, snt-status, snt-ability-run)' );
ok( in_array( 'wp-data', $deps, true ), 'per-caller extra dep merged (wp-data)' );
ok( array( 'sntAiExcerpt', array( 'metaBoxClass' => 'sn-post-settings' ) ) === ( $GLOBALS['__loc']['snt-ai-excerpt'] ?? null ), 'localizes the given object/data' );
ok( in_array( 'snt-ai-excerpt', $GLOBALS['__enq'], true ), 'enqueues the handle' );
ok( in_array( 'snt-ai-excerpt', $GLOBALS['__trans'], true ), 'wires script translations' );

// --- gates ---
$GLOBALS['__reg'] = array();
snt_ai_enqueue_editor_script( 'index.php', 'h2', 'x.js', 'o', array() );
ok( array() === $GLOBALS['__reg'], 'non-editor screens bail' );
snt_ai_enqueue_editor_script( 'post-new.php', 'h3', 'x.js', 'o', array() );
ok( isset( $GLOBALS['__reg']['h3'] ), 'post-new.php is an editor screen' );
$GLOBALS['__reg'] = array(); sn_test_set_ai( false );
snt_ai_enqueue_editor_script( 'post.php', 'h4', 'x.js', 'o', array() );
ok( array() === $GLOBALS['__reg'], 'AI unavailable bails' );
sn_test_set_ai( true ); $GLOBALS['__can'] = false;
snt_ai_enqueue_editor_script( 'post.php', 'h5', 'x.js', 'o', array() );
ok( array() === $GLOBALS['__reg'], 'missing edit_posts cap bails' );
$GLOBALS['__can'] = true;

// --- the three gen-1 call sites route through the helper ---
foreach ( array( 'ai-excerpt', 'ai-meta-description', 'ai-og-card-title' ) as $f ) {
	$src = (string) file_get_contents( SNT_PATH . 'inc/' . $f . '.php' );
	ok( false !== strpos( $src, 'snt_ai_enqueue_editor_script(' ), "$f.php enqueues via the shared helper" );
	ok( false === strpos( $src, 'wp_register_script' ), "$f.php carries no inline register block anymore" );
	ok( false === strpos( $src, 'REST endpoint:  POST signal-noise/v1' ), "$f.php docblock no longer bills the removed REST route" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

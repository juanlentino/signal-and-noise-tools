<?php
/**
 * Behavioral tests for the weekly-digest (narration) abilities (plugin v7.0.0).
 *
 *   - signal-noise/run-narration  (force a fresh digest; wraps snt_narration_run)
 *   - signal-noise/get-narration   (return the cached digest; wraps snt_narration_last)
 *
 * The narration logic itself is covered by tests/insights-narration.php; these
 * assert the ability CONTRACT (registration shape) + that the thin wrappers
 * delegate correctly (force passed through, return value propagated). The impl
 * helpers are stubbed at the delegation boundary.
 *
 * @since plugin v7.0.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }

$GLOBALS['__acts'] = array();
if ( ! function_exists( 'add_action' ) ) { function add_action( $t, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $t ][] = $cb; return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
$GLOBALS['__ab'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) { function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; return true; } }

// Delegation boundary: stub the narration impl helpers (real ones live in
// inc/insights-narration.php and are AI-driven — covered by their own suite).
$GLOBALS['__narr_last']  = null;
$GLOBALS['__narr_force'] = null;
function snt_narration_last() { return $GLOBALS['__narr_last']; }
function snt_narration_run( $force = false ) { $GLOBALS['__narr_force'] = $force; return array( 'generated_at' => 111, 'headline' => 'wk', 'paragraphs' => array( 'p' ), 'highlights' => array() ); }

require __DIR__ . '/../inc/abilities-narration.php';
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "narration abilities — v7.0.0\n";

// ── run-narration registration ──
$run = $GLOBALS['__ab']['signal-noise/run-narration'] ?? null;
ok( is_array( $run ), 'run-narration registered' );
ok( 'snt_ability_run_narration' === ( $run['execute_callback'] ?? '' ), 'run-narration execute_callback wired' );
ok( 'snt_ability_perm_manage_options' === ( $run['permission_callback'] ?? '' ), 'run-narration gated on manage_options' );
ok( isset( $run['input_schema']['properties']['force'] ), 'run-narration accepts force' );
ok( empty( $run['meta']['annotations']['readonly'] ) && false === ( $run['meta']['annotations']['idempotent'] ?? null ), 'run-narration is NOT readonly/idempotent (regenerates via AI)' );

// ── get-narration registration ──
$get = $GLOBALS['__ab']['signal-noise/get-narration'] ?? null;
ok( is_array( $get ), 'get-narration registered' );
ok( 'snt_ability_get_narration' === ( $get['execute_callback'] ?? '' ), 'get-narration execute_callback wired' );
ok( 'snt_ability_perm_manage_options' === ( $get['permission_callback'] ?? '' ), 'get-narration gated on manage_options' );
ok( true === ( $get['meta']['annotations']['readonly'] ?? null ) && true === ( $get['meta']['annotations']['idempotent'] ?? null ), 'get-narration is readonly + idempotent' );

// ── delegation behavior ──
ok( null === snt_ability_get_narration( null ), 'get-narration returns null when no digest cached' );
$GLOBALS['__narr_last'] = array( 'generated_at' => 999, 'headline' => 'Traffic up 12%', 'paragraphs' => array( 'a' ), 'highlights' => array( 'x' ) );
$g = snt_ability_get_narration( null );
ok( is_array( $g ) && 'Traffic up 12%' === $g['headline'], 'get-narration returns the cached digest verbatim' );

$GLOBALS['__narr_force'] = null;
$r = snt_ability_run_narration( array( 'force' => true ) );
ok( true === $GLOBALS['__narr_force'], 'run-narration passes force=true through to snt_narration_run' );
ok( is_array( $r ) && 'wk' === $r['headline'], 'run-narration returns the fresh digest' );
$GLOBALS['__narr_force'] = null;
snt_ability_run_narration( null );
ok( false === $GLOBALS['__narr_force'], 'run-narration defaults force=false on empty input' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );

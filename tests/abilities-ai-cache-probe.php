<?php
/**
 * Behavioral tests for the prompt-cache probe ability.
 *
 *   - signal-noise/ai-cache-probe-status (wraps snt_ai_cache_probe_verdict)
 *
 * The probe's own derive logic is covered by tests/ai-cache-probe-panel.php.
 * These assert the ability CONTRACT: registration shape, the readonly
 * annotation that makes the run controller accept GET, per-request permission,
 * and that the thin wrapper propagates the verdict rather than reshaping it.
 * The impl helper is stubbed at the delegation boundary.
 *
 * @since plugin v10.69.0
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

// Delegation boundary. The real verdict lives in inc/ai-cache-probe.php and
// reads an option; here it is a settable double so the wrapper's propagation
// is observable without a DB.
$GLOBALS['__probe_verdict'] = array(
	'state'   => 'candidate',
	'summary' => array( 'calls' => 12, 'cache_read' => 0 ),
	'models'  => array( array( 'model' => 'claude-sonnet-5', 'may_clear_floor' => true, 'repeatable' => 3 ) ),
	'best'    => array( 'model' => 'claude-sonnet-5', 'repeatable' => 3 ),
);
// Shape mirrors inc/ai-cache-probe.php's real return (state / summary / models
// / best) — taken from the emitter, not invented, so a change there surfaces
// here instead of being papered over by a cleaner-looking fixture.
function snt_ai_cache_probe_verdict( $log = null ) { return $GLOBALS['__probe_verdict']; }

require __DIR__ . '/../inc/abilities-ai-cache-probe.php';
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "ok   — $label\n"; } else { $fail++; echo "FAIL — $label\n"; }
}

// ── Registration contract ────────────────────────────────────────────────
$slug = 'signal-noise/ai-cache-probe-status';
$a    = $GLOBALS['__ab'][ $slug ] ?? null;

ok( is_array( $a ), 'ability is registered under signal-noise/ai-cache-probe-status' );
ok( is_array( $a ) && 'diagnostics' === $a['category'], 'category is diagnostics' );
ok( is_array( $a ) && 'snt_ability_perm_manage_options' === $a['permission_callback'], 'permission is the named manage_options callback, not an inline closure' );
ok( is_array( $a ) && true === $a['meta']['show_in_rest'], 'show_in_rest is true (reachable over REST/MCP)' );

// The readonly annotation is not cosmetic: the run controller derives the HTTP
// verb from it, so dropping it turns every GET call into a 405. Assert it by
// the property that matters rather than by the annotation array's shape.
ok( is_array( $a ) && true === $a['meta']['annotations']['readonly'], 'readonly annotation is true — the run controller requires GET for this ability' );
ok( is_array( $a ) && true === $a['meta']['annotations']['idempotent'], 'idempotent annotation is true (pure read of a recorded log)' );

// A read that takes no arguments should refuse invented ones rather than
// silently ignoring them.
ok( is_array( $a ) && false === $a['input_schema']['additionalProperties'], 'input schema refuses unknown properties' );

// ── Delegation ───────────────────────────────────────────────────────────
$r = snt_ability_ai_cache_probe_status( null );
ok( is_array( $r ) && 'candidate' === $r['state'], 'the verdict state is propagated verbatim' );
ok( is_array( $r ) && 12 === $r['summary']['calls'], 'the summary is propagated, not recomputed' );
ok( is_array( $r ) && is_array( $r['best'] ) && 'claude-sonnet-5' === $r['best']['model'], 'the best-model row is propagated' );

// Every state the derive layer can produce must survive the wrapper. This is a
// relationship assertion: whatever the verdict says, the ability says.
foreach ( array( 'no_data', 'caching_active', 'candidate', 'no_repeats', 'below_floor', 'unknown_floor' ) as $state ) {
	$GLOBALS['__probe_verdict']['state'] = $state;
	$out = snt_ability_ai_cache_probe_status( null );
	ok( is_array( $out ) && $state === $out['state'], "state '$state' passes through the wrapper unchanged" );
}
$GLOBALS['__probe_verdict']['state'] = 'candidate';

// A null verdict (module present but nothing derived) must not fatal.
$GLOBALS['__probe_verdict'] = null;
$rn = snt_ability_ai_cache_probe_status( null );
ok( is_wp_error( $rn ) || is_array( $rn ), 'a null verdict yields a WP_Error or an array, never a fatal' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );

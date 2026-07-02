<?php
/**
 * v7.7.0 abilities consolidation — new/extended ability contracts.
 *
 * Covers the consolidation targets that ADD surface (the deprecation side
 * lives in tests/abilities-deprecations.php; the unified dismiss ability in
 * tests/abilities-dismiss.php):
 *   - signal-noise/get-audit-log      (NEW — merges the 3 audit reads via a
 *     required `view` enum; exactly one of summary/counters/logins is non-null)
 *   - signal-noise/list-cron-events   (EXTENDED — optional hook/args_signature
 *     filters that subsume get-cron-event)
 *   - signal-noise/get-deploy-status  (EXTENDED — optional force_refresh that
 *     subsumes force-check-updates)
 *   - signal-noise/draft-release-notes (recategorized diagnostics → ai-generation)
 *
 * @since 7.7.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs (before the SUT loads) ─────────────────────────────────
$GLOBALS['__test_actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function current_user_can( $cap = '', $id = null ) { return true; }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// Registry capture.
$GLOBALS['__ab'] = array();
function wp_register_ability( $name, $config ) { $GLOBALS['__ab'][ $name ] = $config; return true; }

// Deprecation recorder — the canonical paths under test must NEVER hit this.
$GLOBALS['__dep_calls'] = array();
function _deprecated_function( $fn, $ver, $repl = '' ) { $GLOBALS['__dep_calls'][] = array( $fn, $ver, $repl ); }

// ─── Impl fixtures ───────────────────────────────────────────────────
$GLOBALS['__audit_summary_fixture'] = array(
	'last_24h'             => array( 'all_total' => 5, 'failed_total' => 2, 'recon_total' => 1 ),
	'last_7d_vs_prior'     => array( 'pct_delta' => 12 ),
	'unique_attackers_24h' => 3,
	'lla'                  => array( 'active_lockouts' => 0 ),
);
function snt_audit_get_summary_impl() { return $GLOBALS['__audit_summary_fixture']; }

$GLOBALS['__audit_counters_days'] = null;
function snt_audit_get_counters_impl( $days ) {
	$GLOBALS['__audit_counters_days'] = $days;
	return array( array( 'day' => '2026-07-01', 'failed' => 4 ) );
}

$GLOBALS['__audit_logins_days'] = null;
function snt_audit_get_login_successes_impl( $days ) {
	$GLOBALS['__audit_logins_days'] = $days;
	return array( array( 'formatted' => 'Jul 1', 'user' => 'juan' ) );
}

$GLOBALS['__cron_sn_only'] = null;
function snt_cron_get_events_impl( $sn_only = false ) {
	$GLOBALS['__cron_sn_only'] = $sn_only;
	return array(
		array( 'hook' => 'hook_a', 'args_signature' => 'sig1', 'next_run_ts' => 100 ),
		array( 'hook' => 'hook_a', 'args_signature' => 'sig2', 'next_run_ts' => 200 ),
		array( 'hook' => 'hook_b', 'args_signature' => '',     'next_run_ts' => 300 ),
	);
}

$GLOBALS['__force_check_calls'] = 0;
function snt_cmd_impl_force_check() {
	$GLOBALS['__force_check_calls']++;
	return array( 'ok' => true, 'message' => 'Update caches cleared.' );
}

function snt_deploy_status_for( $which ) {
	return array( 'current' => '7.7.0', 'latest' => '7.7.0', 'state' => 'ok' );
}
function snt_gh_recent_runs_merged( $repos, $limit ) {
	return array( array( 'created_at' => '2026-07-01T00:00:00Z' ) );
}
function human_time_diff( $from, $to ) { return '5 minutes'; }

// ─── Load the SUT + fire registrations ───────────────────────────────
$dep_helper = __DIR__ . '/../inc/abilities-deprecations.php';
if ( file_exists( $dep_helper ) ) {
	require_once $dep_helper;
}
require_once __DIR__ . '/../inc/abilities-audit.php';
require_once __DIR__ . '/../inc/abilities-cron.php';
require_once __DIR__ . '/../inc/abilities-system.php';

foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) {
	call_user_func( $cb );
}

// ─── Harness ─────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function t( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}
function t_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $expected, true ) . "\n    Actual:   " . var_export( $actual, true ) . "\n"; }
}

// ════ get-audit-log — registration contract ══════════════════════════
echo "\nGroup A: get-audit-log registration\n";
$al = $GLOBALS['__ab']['signal-noise/get-audit-log'] ?? null;
t( is_array( $al ), 'A.1 get-audit-log: registered' );
t_eq( 'diagnostics', $al['category'] ?? '', 'A.2 category diagnostics' );
t_eq( 'snt_ability_perm_manage_options', $al['permission_callback'] ?? '', 'A.3 gated on manage_options' );
t( ( $al['meta']['show_in_rest'] ?? false ) === true, 'A.4 show_in_rest' );
t( ( $al['meta']['annotations']['readonly'] ?? null ) === true && ( $al['meta']['annotations']['idempotent'] ?? null ) === true, 'A.5 readonly + idempotent' );
t_eq( array( 'view' ), $al['input_schema']['required'] ?? null, 'A.6 view is the only required input' );
t_eq( array( 'summary', 'counters', 'logins' ), $al['input_schema']['properties']['view']['enum'] ?? null, 'A.7 view enum exact' );
$days = $al['input_schema']['properties']['days'] ?? array();
t( ( $days['minimum'] ?? 0 ) === 1 && ( $days['maximum'] ?? 0 ) === 90 && ( $days['default'] ?? 0 ) === 30, 'A.8 days bounds 1–90 default 30' );
$op = $al['output_schema']['properties'] ?? array();
t( isset( $op['view'], $op['summary'], $op['counters'], $op['logins'] ), 'A.9 output declares view + the 3 nullable payload keys' );

// ════ get-audit-log — dispatch behavior ══════════════════════════════
echo "\nGroup B: get-audit-log dispatch\n";
if ( function_exists( 'snt_ability_get_audit_log' ) ) {
	$out = snt_ability_get_audit_log( array( 'view' => 'summary' ) );
	t_eq( 'summary', $out['view'] ?? null, 'B.1 view echoed' );
	t_eq( $GLOBALS['__audit_summary_fixture'], $out['summary'] ?? null, 'B.2 summary payload passthrough' );
	t( array_key_exists( 'counters', $out ) && null === $out['counters'] && null === $out['logins'], 'B.3 unselected views are null' );

	$out = snt_ability_get_audit_log( array( 'view' => 'counters', 'days' => 7 ) );
	t_eq( 7, $GLOBALS['__audit_counters_days'], 'B.4 days passthrough to counters impl' );
	t( is_array( $out['counters'] ?? null ) && null === $out['summary'], 'B.5 counters payload under counters key' );

	$out = snt_ability_get_audit_log( array( 'view' => 'logins' ) );
	t_eq( 30, $GLOBALS['__audit_logins_days'], 'B.6 days defaults to 30 for logins' );
	t( is_array( $out['logins'] ?? null ) && 'juan' === ( $out['logins'][0]['user'] ?? '' ), 'B.7 logins payload passthrough' );

	$out = snt_ability_get_audit_log( array( 'view' => 'nope' ) );
	t( is_wp_error( $out ), 'B.8 unknown view → WP_Error (defense in depth beyond schema enum)' );
} else {
	t( false, 'B.1 snt_ability_get_audit_log() exists' );
	t( false, 'B.2 summary payload passthrough' );
	t( false, 'B.3 unselected views are null' );
	t( false, 'B.4 days passthrough to counters impl' );
	t( false, 'B.5 counters payload under counters key' );
	t( false, 'B.6 days defaults to 30 for logins' );
	t( false, 'B.7 logins payload passthrough' );
	t( false, 'B.8 unknown view → WP_Error' );
}

// ════ list-cron-events — extended filters ════════════════════════════
echo "\nGroup C: list-cron-events filters\n";
$lc = $GLOBALS['__ab']['signal-noise/list-cron-events'] ?? null;
t( isset( $lc['input_schema']['properties']['hook'] ) && 'string' === ( $lc['input_schema']['properties']['hook']['type'] ?? '' ), 'C.1 schema gains optional hook filter' );
t( isset( $lc['input_schema']['properties']['args_signature'] ), 'C.2 schema gains optional args_signature filter' );
t( ! in_array( 'hook', $lc['input_schema']['required'] ?? array(), true ), 'C.3 hook stays optional (list still callable bare)' );

$out = snt_ability_list_cron_events( array() );
t_eq( 3, count( $out ), 'C.4 no filter → all 3 events' );
$out = snt_ability_list_cron_events( array( 'hook' => 'hook_a' ) );
t_eq( 2, count( $out ), 'C.5 hook filter → 2 events' );
$out = snt_ability_list_cron_events( array( 'hook' => 'hook_a', 'args_signature' => 'sig2' ) );
t( 1 === count( $out ) && 'sig2' === ( $out[0]['args_signature'] ?? '' ), 'C.6 hook + args_signature → the exact event (subsumes get-cron-event)' );
$out = snt_ability_list_cron_events( array( 'hook' => 'not_scheduled' ) );
t( is_array( $out ) && 0 === count( $out ), 'C.7 no match → empty array, not an error' );
snt_ability_list_cron_events( array( 'sn_only' => true ) );
t_eq( true, $GLOBALS['__cron_sn_only'], 'C.8 sn_only passthrough unchanged' );
// v8.0.4: the v7.7.1 audit's noted fragility — args_signature standalone
// silently filtered ACROSS hooks though the description says "combined with
// hook". Invalid parameter combinations are input errors, distinct from the
// C.7 no-MATCH contract (which stays an empty array).
$out = snt_ability_list_cron_events( array( 'args_signature' => 'sig1' ) );
t( is_wp_error( $out ), 'C.9 args_signature without hook → WP_Error nudge, not a silent cross-hook filter' );

// ════ get-deploy-status — force_refresh ══════════════════════════════
echo "\nGroup D: get-deploy-status force_refresh\n";
$ds = $GLOBALS['__ab']['signal-noise/get-deploy-status'] ?? null;
$fr = $ds['input_schema']['properties']['force_refresh'] ?? array();
t( 'boolean' === ( $fr['type'] ?? '' ) && false === ( $fr['default'] ?? null ), 'D.1 schema gains force_refresh boolean default false' );

$GLOBALS['__force_check_calls'] = 0;
$out = snt_ability_get_deploy_status( array() );
t_eq( 0, $GLOBALS['__force_check_calls'], 'D.2 plain read does NOT clear update transients' );
t( isset( $out['theme'], $out['plugin'] ) && array_key_exists( 'last_deploy', $out ), 'D.3 plain read output shape intact' );

$out = snt_ability_get_deploy_status( array( 'force_refresh' => true ) );
t_eq( 1, $GLOBALS['__force_check_calls'], 'D.4 force_refresh=true clears update transients first (subsumes force-check-updates)' );
t( isset( $out['theme'], $out['plugin'] ), 'D.5 force_refresh still returns the status shape' );

$out = snt_ability_get_deploy_status( null );
t( isset( $out['theme'], $out['plugin'] ), 'D.6 null input (?input= omitted) keeps working' );

// ════ draft-release-notes — recategorization ═════════════════════════
echo "\nGroup E: draft-release-notes hygiene\n";
t_eq( 'ai-generation', $GLOBALS['__ab']['signal-noise/draft-release-notes']['category'] ?? '', 'E.1 category is ai-generation (was diagnostics)' );

// ════ canonical paths never warn ═════════════════════════════════════
echo "\nGroup F: canonical paths are deprecation-silent\n";
t_eq( 0, count( $GLOBALS['__dep_calls'] ), 'F.1 zero _deprecated_function calls across every canonical execution above' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

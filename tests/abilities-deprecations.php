<?php
/**
 * v7.7.0 ability deprecation ladder — step 1 (warn window opens).
 *
 * Nine abilities are deprecated in favor of consolidated replacements
 * (removal lands in v8.0.0 after the soak window):
 *
 *   full-reset                → purge-all-caches {include_template_overrides:true}
 *   get-audit-summary         → get-audit-log {view:"summary"}
 *   get-audit-counters        → get-audit-log {view:"counters"}
 *   get-audit-login-successes → get-audit-log {view:"logins"}
 *   get-cron-event            → list-cron-events {hook, args_signature}
 *   force-check-updates       → get-deploy-status {force_refresh:true}
 *   block-migrations-dismiss  → dismiss-candidate {surface:"block-migrations"}
 *   pattern-adoption-dismiss  → dismiss-candidate {surface:"pattern-adoption"}
 *   list-abilities            → core GET /wp-abilities/v1/abilities
 *
 * Contract per deprecated ability (mirrors the v6.54.0 REST ladder):
 *   1. description gains a leading "DEPRECATED" migration hint (the only
 *      signal an LLM tool-caller reads when choosing among tools);
 *   2. meta.deprecated = { since: '7.7.0', use: <replacement> } (machine-
 *      readable for explorers/dashboards);
 *   3. the execute wrapper emits snt_ability_deprecated_notice() — at the
 *      ENTRY POINT only, never in a shared impl (the canonical replacement
 *      paths must stay warning-free);
 *   4. behavior is fully preserved until v8.0.0.
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

// ─── WP stubs ────────────────────────────────────────────────────────
$GLOBALS['__test_actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function current_user_can( $cap = '', $id = null ) { return true; }
function get_current_user_id() { return 7; }
function human_time_diff( $from, $to ) { return '5 minutes'; }

$GLOBALS['__test_filters'] = array();
function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_filters'][ $tag ][] = $cb; return true; }
function has_filter( $tag ) { return ! empty( $GLOBALS['__test_filters'][ $tag ] ); }
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );
	foreach ( $GLOBALS['__test_filters'][ $tag ] ?? array() as $cb ) {
		$value   = call_user_func_array( $cb, $args );
		$args[0] = $value;
	}
	return $value;
}

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// Post-meta + transient store.
$GLOBALS['__meta'] = array();
function get_post_meta( $post_id, $key, $single = false ) { return $GLOBALS['__meta'][ $post_id ][ $key ] ?? ''; }
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['__meta'][ $post_id ][ $key ] = $value; return true; }
function delete_transient( $key ) { return true; }

// Registry capture + a minimal ability object for wp_get_abilities().
$GLOBALS['__ab'] = array();
function wp_register_ability( $name, $config ) { $GLOBALS['__ab'][ $name ] = $config; return true; }
class SN_Test_Ability {
	private $name; private $config;
	public function __construct( $name, $config ) { $this->name = $name; $this->config = $config; }
	public function get_name() { return $this->name; }
	public function get_label() { return $this->config['label'] ?? ''; }
	public function get_description() { return $this->config['description'] ?? ''; }
	public function get_category() { return $this->config['category'] ?? ''; }
	public function get_meta() { return $this->config['meta'] ?? array(); }
}
function wp_get_abilities() {
	$out = array();
	foreach ( $GLOBALS['__ab'] as $name => $config ) {
		$out[ $name ] = new SN_Test_Ability( $name, $config );
	}
	return $out;
}

// Deprecation recorder.
$GLOBALS['__dep_calls'] = array();
function _deprecated_function( $fn, $ver, $repl = '' ) { $GLOBALS['__dep_calls'][] = array( $fn, $ver, $repl ); }
function dep_reset() { $GLOBALS['__dep_calls'] = array(); }
function dep_last_repl() { $c = $GLOBALS['__dep_calls']; return $c ? ( end( $c )[2] ?? '' ) : ''; }

// ─── Impl fixtures ───────────────────────────────────────────────────
function snt_audit_get_summary_impl() { return array( 'last_24h' => array( 'all_total' => 5 ) ); }
function snt_audit_get_counters_impl( $days ) { return array( array( 'day' => '2026-07-01' ) ); }
function snt_audit_get_login_successes_impl( $days ) { return array( array( 'user' => 'juan' ) ); }
function snt_cron_get_events_impl( $sn_only = false ) {
	return array( array( 'hook' => 'hook_a', 'args_signature' => 'sig1', 'next_run_ts' => 100 ) );
}
function snt_cron_get_event_impl( $hook, $sig ) {
	foreach ( snt_cron_get_events_impl() as $row ) {
		if ( $row['hook'] === $hook && $row['args_signature'] === $sig ) { return $row; }
	}
	return null;
}
$GLOBALS['__force_check_calls'] = 0;
function snt_cmd_impl_force_check() { $GLOBALS['__force_check_calls']++; return array( 'ok' => true, 'message' => 'cleared' ); }
$GLOBALS['__full_reset_calls'] = 0;
function snt_cmd_impl_full_reset() { $GLOBALS['__full_reset_calls']++; return array( 'ok' => true, 'message' => 'reset', 'data' => array( 'count' => 2 ) ); }
function snt_deploy_status_for( $which ) { return array( 'current' => '7.7.0', 'latest' => '7.7.0', 'state' => 'ok' ); }
function snt_gh_recent_runs_merged( $repos, $limit ) { return array(); }
$GLOBALS['__pa_dismiss_args'] = null;
function snt_pattern_adoption_dismiss_impl( $post_id, $pattern_type, $fingerprint ) {
	$GLOBALS['__pa_dismiss_args'] = array( $post_id, $pattern_type, $fingerprint );
	return array( 'ok' => true, 'message' => 'Candidate dismissed.' );
}

// ─── Load the SUT + fire registrations ───────────────────────────────
$dep_helper = __DIR__ . '/../inc/abilities-deprecations.php';
$helper_exists = file_exists( $dep_helper );
if ( $helper_exists ) {
	require_once $dep_helper;
}
require_once __DIR__ . '/../inc/abilities-audit.php';
require_once __DIR__ . '/../inc/abilities-cron.php';
require_once __DIR__ . '/../inc/abilities-system.php';
require_once __DIR__ . '/../inc/abilities-block-migrations.php';
require_once __DIR__ . '/../inc/abilities-pattern-adoption.php';
$dismiss_module = __DIR__ . '/../inc/abilities-dismiss.php';
if ( file_exists( $dismiss_module ) ) {
	require_once $dismiss_module;
}

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

// ════ helper ═════════════════════════════════════════════════════════
echo "\nGroup A: snt_ability_deprecated_notice helper\n";
t( $helper_exists, 'A.1 inc/abilities-deprecations.php exists' );
if ( function_exists( 'snt_ability_deprecated_notice' ) ) {
	dep_reset();
	snt_ability_deprecated_notice( 'signal-noise/old-thing', 'signal-noise/new-thing with x=1' );
	t_eq( 1, count( $GLOBALS['__dep_calls'] ), 'A.2 helper emits exactly one _deprecated_function' );
	list( $fn, $ver, $repl ) = $GLOBALS['__dep_calls'][0];
	t( false !== strpos( $fn, 'signal-noise/old-thing' ), 'A.3 notice names the deprecated ability' );
	t_eq( '7.7.0', $ver, 'A.4 since-version 7.7.0' );
	t( false !== strpos( $repl, 'signal-noise/new-thing' ), 'A.5 replacement hint passes through' );
} else {
	t( false, 'A.2 snt_ability_deprecated_notice() exists' );
	t( false, 'A.3 notice names the deprecated ability' );
	t( false, 'A.4 since-version 7.7.0' );
	t( false, 'A.5 replacement hint passes through' );
}

// ════ registration contract for all 9 ═══════════════════════════════
echo "\nGroup B: deprecated registration contract (description prefix + meta.deprecated)\n";
$DEPRECATED = array(
	'signal-noise/full-reset'                => 'purge-all-caches',
	'signal-noise/get-audit-summary'         => 'get-audit-log',
	'signal-noise/get-audit-counters'        => 'get-audit-log',
	'signal-noise/get-audit-login-successes' => 'get-audit-log',
	'signal-noise/get-cron-event'            => 'list-cron-events',
	'signal-noise/force-check-updates'       => 'get-deploy-status',
	'signal-noise/block-migrations-dismiss'  => 'dismiss-candidate',
	'signal-noise/pattern-adoption-dismiss'  => 'dismiss-candidate',
	'signal-noise/list-abilities'            => '/wp-abilities/v1/abilities',
);
foreach ( $DEPRECATED as $slug => $replacement ) {
	$cfg  = $GLOBALS['__ab'][ $slug ] ?? null;
	$desc = $cfg['description'] ?? '';
	$dep  = $cfg['meta']['deprecated'] ?? array();
	t( 0 === strpos( $desc, 'DEPRECATED' ), "B $slug: description leads with DEPRECATED" );
	t( false !== strpos( $desc, $replacement ), "B $slug: description names the replacement" );
	t_eq( '7.7.0', $dep['since'] ?? null, "B $slug: meta.deprecated.since = 7.7.0" );
	t( false !== strpos( (string) ( $dep['use'] ?? '' ), $replacement ), "B $slug: meta.deprecated.use points at replacement" );
}

// ════ behavior preserved + notice at entry point ═════════════════════
echo "\nGroup C: deprecated wrappers — behavior preserved, exactly one notice each\n";

dep_reset();
$out = snt_ability_get_audit_summary();
t( is_array( $out ) && isset( $out['last_24h'] ), 'C.1 get-audit-summary payload preserved' );
t( 1 === count( $GLOBALS['__dep_calls'] ) && false !== strpos( dep_last_repl(), 'get-audit-log' ), 'C.2 get-audit-summary notices once → get-audit-log' );

dep_reset();
$out = snt_ability_get_audit_counters( array( 'days' => 7 ) );
t( is_array( $out ) && 1 === count( $GLOBALS['__dep_calls'] ), 'C.3 get-audit-counters preserved + notices once' );

dep_reset();
$out = snt_ability_get_audit_login_successes( array() );
t( is_array( $out ) && 1 === count( $GLOBALS['__dep_calls'] ), 'C.4 get-audit-login-successes preserved + notices once' );

dep_reset();
$out = snt_ability_get_cron_event( array( 'hook' => 'hook_a', 'args_signature' => 'sig1' ) );
t( is_array( $out ) && 'hook_a' === ( $out['hook'] ?? '' ), 'C.5 get-cron-event payload preserved' );
t( 1 === count( $GLOBALS['__dep_calls'] ) && false !== strpos( dep_last_repl(), 'list-cron-events' ), 'C.6 get-cron-event notices once → list-cron-events' );

dep_reset();
$GLOBALS['__force_check_calls'] = 0;
$out = snt_ability_force_check_updates();
t( 1 === $GLOBALS['__force_check_calls'] && true === ( $out['ok'] ?? false ), 'C.7 force-check-updates still clears transients' );
t( 1 === count( $GLOBALS['__dep_calls'] ) && false !== strpos( dep_last_repl(), 'get-deploy-status' ), 'C.8 force-check-updates notices once → get-deploy-status' );

dep_reset();
$GLOBALS['__full_reset_calls'] = 0;
$out = snt_ability_full_reset();
t( 1 === $GLOBALS['__full_reset_calls'] && true === ( $out['ok'] ?? false ), 'C.9 full-reset still resets' );
t( 1 === count( $GLOBALS['__dep_calls'] ) && false !== strpos( dep_last_repl(), 'purge-all-caches' ), 'C.10 full-reset notices once → purge-all-caches' );

dep_reset();
$FP  = str_repeat( 'b', 32 );
$out = snt_ability_block_migrations_dismiss( array( 'post_id' => 9, 'block_fingerprint' => $FP, 'migration_type' => 'heading-hierarchy-skip' ) );
t( true === ( $out['ok'] ?? false ) && in_array( 'heading-hierarchy-skip:' . $FP, (array) ( $GLOBALS['__meta'][9]['_snt_block_migrations_dismissed'] ?? array() ), true ), 'C.11 block-migrations-dismiss still writes the store' );
t( 1 === count( $GLOBALS['__dep_calls'] ) && false !== strpos( dep_last_repl(), 'dismiss-candidate' ), 'C.12 block-migrations-dismiss notices once → dismiss-candidate' );

dep_reset();
$out = snt_ability_pattern_adoption_dismiss( array( 'post_id' => 8, 'pattern_type' => 'pull-quote', 'block_fingerprint' => 'fp8' ) );
t( true === ( $out['ok'] ?? false ) && array( 8, 'pull-quote', 'fp8' ) === $GLOBALS['__pa_dismiss_args'], 'C.13 pattern-adoption-dismiss still delegates to the impl' );
t( 1 === count( $GLOBALS['__dep_calls'] ) && false !== strpos( dep_last_repl(), 'dismiss-candidate' ), 'C.14 pattern-adoption-dismiss notices once → dismiss-candidate' );

dep_reset();
$out = snt_ability_list_abilities( array( 'namespace' => 'signal-noise' ) );
t( is_array( $out ) && true === ( $out['ok'] ?? false ) && $out['count'] > 0, 'C.15 list-abilities catalogue preserved' );
t( 1 === count( $GLOBALS['__dep_calls'] ) && false !== strpos( dep_last_repl(), 'wp-abilities/v1/abilities' ), 'C.16 list-abilities notices once → core catalogue endpoint' );

// ════ canonical replacements stay silent ═════════════════════════════
echo "\nGroup D: canonical replacements never notice (placement rule)\n";
dep_reset();
add_filter( 'sn_purge_all_caches_result', function ( $count, $args = array() ) { return 3; } );
if ( function_exists( 'snt_ability_get_audit_log' ) ) { snt_ability_get_audit_log( array( 'view' => 'summary' ) ); }
snt_ability_list_cron_events( array( 'hook' => 'hook_a', 'args_signature' => 'sig1' ) );
snt_ability_get_deploy_status( array( 'force_refresh' => true ) );
snt_ability_purge_all_caches( array( 'include_template_overrides' => true ) );
if ( function_exists( 'snt_ability_dismiss_candidate' ) ) {
	snt_ability_dismiss_candidate( array( 'surface' => 'block-migrations', 'post_id' => 3, 'block_fingerprint' => str_repeat( 'c', 32 ), 'candidate_type' => 'heading-hierarchy-skip' ) );
	snt_ability_dismiss_candidate( array( 'surface' => 'pattern-adoption', 'post_id' => 3, 'block_fingerprint' => 'fp3', 'candidate_type' => 'pull-quote' ) );
}
t_eq( 0, count( $GLOBALS['__dep_calls'] ), 'D.1 zero notices across every canonical replacement execution' );

// ════ non-deprecated neighbors untouched ═════════════════════════════
echo "\nGroup E: non-deprecated neighbors untouched\n";
foreach ( array( 'signal-noise/purge-all-caches', 'signal-noise/get-deploy-status', 'signal-noise/list-cron-events', 'signal-noise/export-audit-log', 'signal-noise/run-audit-prune' ) as $slug ) {
	$cfg = $GLOBALS['__ab'][ $slug ] ?? array();
	t( 0 !== strpos( (string) ( $cfg['description'] ?? '' ), 'DEPRECATED' ) && ! isset( $cfg['meta']['deprecated'] ), "E $slug: not marked deprecated" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

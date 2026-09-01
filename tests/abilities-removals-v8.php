<?php
/**
 * v8.0.0 removal guard — the v7.7.0 deprecation ladder CLOSES here.
 *
 * Successor to tests/abilities-deprecations.php (deleted with the ladder).
 * Asserts the terminal state of the v7.7.0 → v8.0.0 arc:
 *   - the 9 deprecated abilities are NOT registered (53 → 44 site-wide);
 *   - their execute wrappers no longer exist;
 *   - the `updates` category is retired (its only member was force-check-updates);
 *   - inc/abilities-deprecations.php is deleted and nothing references it;
 *   - the two orphaned impls (snt_cmd_impl_full_reset, snt_cron_get_event_impl)
 *     are gone from inc/ entirely;
 *   - every canonical replacement is still registered AND its callback works;
 *   - no execution path fires _deprecated_function (the ladder is silent
 *     because it is GONE, not because WP_DEBUG is off).
 *
 * Removed → replacement mapping (CHANGELOG v8.0.0 carries the same table):
 *   full-reset                 → purge-all-caches {include_template_overrides:true}
 *   get-audit-summary          → get-audit-log {view:"summary"}
 *   get-audit-counters         → get-audit-log {view:"counters"}
 *   get-audit-login-successes  → get-audit-log {view:"logins"}
 *   get-cron-event             → list-cron-events {hook, args_signature}
 *   force-check-updates        → get-deploy-status {force_refresh:true}
 *   block-migrations-dismiss   → dismiss-candidate {surface:"block-migrations"}
 *   pattern-adoption-dismiss   → dismiss-candidate {surface:"pattern-adoption"}
 *   list-abilities             → core GET /wp-abilities/v1/abilities
 *
 * @since 8.0.0
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

// Ability + category registry capture.
$GLOBALS['__ab'] = array();
function wp_register_ability( $name, $config ) { $GLOBALS['__ab'][ $name ] = $config; return true; }
$GLOBALS['__cats'] = array();
function wp_register_ability_category( $slug, $config ) { $GLOBALS['__cats'][ $slug ] = $config; return true; }
function wp_has_ability_category( $slug ) { return isset( $GLOBALS['__cats'][ $slug ] ); }

// Deprecation recorder — after v8.0.0 NOTHING may fire this, ever.
$GLOBALS['__dep_calls'] = array();
function _deprecated_function( $fn, $ver, $repl = '' ) { $GLOBALS['__dep_calls'][] = array( $fn, $ver, $repl ); }

// purge-all-caches theme-filter bridge.
$GLOBALS['__purge_filter_args'] = null;
function has_filter( $tag ) { return 'sn_purge_all_caches_result' === $tag; }
function apply_filters( $tag, $default, $args = array() ) {
	if ( 'sn_purge_all_caches_result' === $tag ) {
		$GLOBALS['__purge_filter_args'] = $args;
		return ! empty( $args['template_overrides'] ) ? 3 : 0;
	}
	return $default;
}

// ─── Impl fixtures (canonical replacements delegate to these) ────────
$GLOBALS['__audit_summary_fixture'] = array(
	'last_24h'             => array( 'all_total' => 5, 'failed_total' => 2, 'recon_total' => 1 ),
	'last_7d_vs_prior'     => array( 'pct_delta' => 12 ),
	'unique_attackers_24h' => 3,
	'lla'                  => array( 'active_lockouts' => 0 ),
);
function snt_audit_get_summary_impl() { return $GLOBALS['__audit_summary_fixture']; }
function snt_audit_get_counters_impl( $days ) { return array( array( 'day' => '2026-07-01', 'failed' => 4 ) ); }
function snt_audit_get_login_successes_impl( $days ) { return array( array( 'formatted' => 'Jul 1', 'user' => 'juan' ) ); }

function snt_cron_get_events_impl( $sn_only = false ) {
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

if ( ! function_exists( 'snt_deploy_workers_status' ) ) {
	function snt_deploy_workers_status( $opts = array() ) {
		return array();
	}
}
function snt_deploy_status_for( $which ) {
	return array( 'current' => '8.0.0', 'latest' => '8.0.0', 'state' => 'ok' );
}
function snt_gh_recent_runs_merged( $repos, $limit ) {
	return array( array( 'created_at' => '2026-07-01T00:00:00Z' ) );
}
function human_time_diff( $from, $to ) { return '5 minutes'; }

// dismiss-candidate surface=block-migrations exercises the REAL
// snt_block_migrations_dismiss_impl (it ships inside the ability file).
$GLOBALS['__post_meta'] = array();
$GLOBALS['__deleted_transients'] = array();
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__post_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['__post_meta'][ $id ][ $key ] = $value; return true; }
function delete_transient( $key ) { $GLOBALS['__deleted_transients'][] = $key; return true; }
function get_current_user_id() { return 7; }

// dismiss-candidate surface=pattern-adoption dispatches to this impl
// (real one lives in inc/pattern-adoption-admin.php, not loaded here);
// capture the arg ORDER — ( post_id, pattern_type, fingerprint ).
$GLOBALS['__pa_dismiss_args'] = null;
function snt_pattern_adoption_dismiss_impl( $post_id, $pattern_type, $fingerprint ) {
	$GLOBALS['__pa_dismiss_args'] = array( $post_id, $pattern_type, $fingerprint );
	return array( 'ok' => true, 'message' => 'Candidate dismissed.' );
}

// ─── Load the SUT + fire registrations ───────────────────────────────
$inc = dirname( __DIR__ ) . '/inc';

// Conditional so this fixture runs RED against the pre-removal tree
// (where the wrappers still call snt_ability_deprecated_notice).
if ( file_exists( $inc . '/abilities-deprecations.php' ) ) {
	require_once $inc . '/abilities-deprecations.php';
}

require_once $inc . '/abilities-categories.php';
require_once $inc . '/abilities-system.php';
require_once $inc . '/abilities-cron.php';
require_once $inc . '/abilities-audit.php';
require_once $inc . '/abilities-block-migrations.php';
require_once $inc . '/abilities-pattern-adoption.php';
require_once $inc . '/abilities-dismiss.php';

foreach ( $GLOBALS['__test_actions']['wp_abilities_api_categories_init'] ?? array() as $cb ) {
	call_user_func( $cb );
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

// ════ Group A: the 9 deprecated abilities are NOT registered ═════════
echo "\nGroup A: removed registrations\n";
$removed = array(
	'signal-noise/full-reset',
	'signal-noise/get-audit-summary',
	'signal-noise/get-audit-counters',
	'signal-noise/get-audit-login-successes',
	'signal-noise/get-cron-event',
	'signal-noise/force-check-updates',
	'signal-noise/block-migrations-dismiss',
	'signal-noise/pattern-adoption-dismiss',
	'signal-noise/list-abilities',
);
foreach ( $removed as $i => $slug ) {
	t( ! isset( $GLOBALS['__ab'][ $slug ] ), 'A.' . ( $i + 1 ) . " $slug NOT registered" );
}
// The 7 loaded files register exactly 18 abilities post-removal
// (17 post-v8 + run-health-scan, added v9.78.0 for the DM mirror)
// (system 5, cron 4, audit 3, block-migrations 3, pattern-adoption 1, dismiss 1).
//
// v10.1.0: signal-noise/get-machine-readers-summary joined the site-wide set
// (inc/abilities-machine-readers.php, required from the orchestrator). The
// count below is UNCHANGED on purpose: this fixture loads the 7 files named
// above one by one, not the orchestrator, and the new file is not among them.
// Bump this number only when one of THOSE 7 gains or loses an ability.
t_eq( 19, count( $GLOBALS['__ab'] ), 'A.10 loaded files register exactly 19 abilities (v13.52.0 ADDED cron-health-summary, the model-never-levers section the remote partition asked for; v13.49.0 ADDED schedule-cron-event, the booking half of the cron pair — run-cron-event stays off every door because dispatch is the hazard and booking is not; v10.0.0 retired draft-release-notes; no stray additions, no over-removal)' );

// ════ Group B: the `updates` category is retired ═════════════════════
echo "\nGroup B: category retirement\n";
t( ! isset( $GLOBALS['__cats']['updates'] ), 'B.1 updates category NOT registered (only member was force-check-updates)' );
// 'analytics' joined the survivors (WP 6.9.0 doing_it_wrong fix) — it backs
// inc/abilities-analytics.php, which this fixture doesn't load, but the
// category itself registers unconditionally alongside the other 5.
$expected_cats = array( 'ai-generation', 'analytics', 'content', 'diagnostics', 'maintenance', 'tools' );
$actual_cats   = array_keys( $GLOBALS['__cats'] );
sort( $actual_cats );
t_eq( $expected_cats, $actual_cats, 'B.2 remaining categories are exactly the 6 survivors' );

// ════ Group C: ladder artifacts deleted from the tree ════════════════
echo "\nGroup C: ladder artifacts gone\n";
t( ! file_exists( $inc . '/abilities-deprecations.php' ), 'C.1 inc/abilities-deprecations.php deleted' );
t( ! preg_match( '/^\s*require\w*\s.*abilities-deprecations/m', (string) file_get_contents( $inc . '/abilities-registration.php' ) ), 'C.2 orchestrator no longer requires abilities-deprecations (history mentions in comments are fine)' );
t( ! function_exists( 'snt_ability_deprecated_notice' ), 'C.3 snt_ability_deprecated_notice() gone' );
$orphan_hits = array();
foreach ( glob( $inc . '/*.php' ) as $file ) {
	$src = (string) file_get_contents( $file );
	foreach ( array( 'snt_cmd_impl_full_reset', 'snt_cron_get_event_impl' ) as $orphan ) {
		if ( false !== strpos( $src, $orphan ) ) {
			$orphan_hits[] = basename( $file ) . ':' . $orphan;
		}
	}
}
t_eq( array(), $orphan_hits, 'C.4 orphaned impls (snt_cmd_impl_full_reset, snt_cron_get_event_impl) referenced nowhere in inc/' );

// ════ Group D: the 9 execute wrappers no longer exist ════════════════
echo "\nGroup D: removed wrappers\n";
$gone_wrappers = array(
	'snt_ability_full_reset',
	'snt_ability_get_audit_summary',
	'snt_ability_get_audit_counters',
	'snt_ability_get_audit_login_successes',
	'snt_ability_get_cron_event',
	'snt_ability_force_check_updates',
	'snt_ability_block_migrations_dismiss',
	'snt_ability_pattern_adoption_dismiss',
	'snt_ability_list_abilities',
);
foreach ( $gone_wrappers as $i => $fn ) {
	t( ! function_exists( $fn ), 'D.' . ( $i + 1 ) . " $fn() gone" );
}

// ════ Group E: canonical replacements still registered ═══════════════
echo "\nGroup E: replacements registered\n";
$canonical = array(
	'signal-noise/purge-all-caches',
	'signal-noise/get-audit-log',
	'signal-noise/list-cron-events',
	'signal-noise/get-deploy-status',
	'signal-noise/dismiss-candidate',
);
foreach ( $canonical as $i => $slug ) {
	t( isset( $GLOBALS['__ab'][ $slug ] ), 'E.' . ( $i + 1 ) . " $slug registered" );
}

// ════ Group F: canonical callbacks still work ════════════════════════
echo "\nGroup F: replacement behavior\n";

// No sn_cf_is_configured in this harness, so the v10.4.1 fail-loud contract
// reports the CF leg as not_configured (ok=false); delegation + counts are
// what Group F pins, and those are unchanged.
$out = snt_ability_purge_all_caches( array( 'include_template_overrides' => true ) );
t( false === ( $out['ok'] ?? null ) && 3 === ( $out['count'] ?? null ) && 'not_configured' === ( $out['cloudflare']['status'] ?? null ),
	'F.1 purge-all-caches include_template_overrides=true → override count + fail-loud CF verdict (full-reset semantics)' );
t( true === ( $GLOBALS['__purge_filter_args']['template_overrides'] ?? null ), 'F.2 template_overrides=true reaches the theme filter' );
t( true === ( $GLOBALS['__purge_filter_args']['verified'] ?? null ), 'F.2b verified=true reaches the theme filter (blocking CF leg, v10.4.1)' );
$out = snt_ability_purge_all_caches( null );
t( false === ( $out['ok'] ?? null ) && 0 === ( $out['count'] ?? null ), 'F.3 bare purge (null input) still delegates, clears no overrides' );

$out = snt_ability_get_audit_log( array( 'view' => 'summary' ) );
t( 'summary' === ( $out['view'] ?? null ) && $GLOBALS['__audit_summary_fixture'] === ( $out['summary'] ?? null ), 'F.4 get-audit-log view=summary passthrough' );
$out = snt_ability_get_audit_log( array( 'view' => 'counters', 'days' => 7 ) );
t( is_array( $out['counters'] ?? null ), 'F.5 get-audit-log view=counters works' );
$out = snt_ability_get_audit_log( array( 'view' => 'logins' ) );
t( 'j***' === ( $out['logins'][0]['user'] ?? '' ), 'F.6 get-audit-log view=logins masks usernames by default (PII cap R8)' );
$out = snt_ability_get_audit_log( array( 'view' => 'logins', 'include_pii' => true ) );
t( 'juan' === ( $out['logins'][0]['user'] ?? '' ), 'F.6b get-audit-log include_pii:true reveals plaintext' );

$out = snt_ability_list_cron_events( array( 'hook' => 'hook_a', 'args_signature' => 'sig2' ) );
t( 1 === count( $out ) && 'sig2' === ( $out[0]['args_signature'] ?? '' ), 'F.7 list-cron-events hook+args_signature → the exact event (get-cron-event semantics)' );

$GLOBALS['__force_check_calls'] = 0;
$out = snt_ability_get_deploy_status( array( 'force_refresh' => true ) );
t( 1 === $GLOBALS['__force_check_calls'] && isset( $out['theme'], $out['plugin'] ), 'F.8 get-deploy-status force_refresh=true clears update caches (force-check semantics)' );

$out = snt_ability_dismiss_candidate( array(
	'surface'           => 'block-migrations',
	'post_id'           => 42,
	'block_fingerprint' => 'fp1',
	'candidate_type'    => 'heading-hierarchy-skip',
) );
t( true === ( $out['ok'] ?? null ), 'F.9 dismiss-candidate surface=block-migrations → ok' );
t( in_array( 'heading-hierarchy-skip:fp1', $GLOBALS['__post_meta'][42]['_snt_block_migrations_dismissed'] ?? array(), true ), 'F.10 block-migrations dismiss writes the real meta store' );
t( in_array( 'snt_block_migrations_candidates_7', $GLOBALS['__deleted_transients'], true ), 'F.11 block-migrations dismiss invalidates the user scan transient' );

$out = snt_ability_dismiss_candidate( array(
	'surface'           => 'pattern-adoption',
	'post_id'           => 43,
	'block_fingerprint' => 'fp2',
	'candidate_type'    => 'pull-quote',
) );
t( true === ( $out['ok'] ?? null ), 'F.12 dismiss-candidate surface=pattern-adoption → ok' );
t_eq( array( 43, 'pull-quote', 'fp2' ), $GLOBALS['__pa_dismiss_args'], 'F.13 pattern-adoption impl receives ( post_id, pattern_type, fingerprint ) order' );

// ════ Group G: the ladder is silent because it is gone ═══════════════
echo "\nGroup G: no deprecation machinery fires\n";
t_eq( 0, count( $GLOBALS['__dep_calls'] ), 'G.1 zero _deprecated_function calls across every execution above' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

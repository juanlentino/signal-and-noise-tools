<?php
/**
 * Standalone tests for per-scan_type run telemetry (v10.60.0):
 * inc/sn-scan-telemetry.php + the observability wrapper in
 * inc/abilities-sn-scan.php.
 *
 * Pins the four claims that matter:
 *   1. The metrics row carries every measured field, success AND error
 *      (the success-only-readout trap — an error run must produce a row
 *      with its WP_Error code and the caller's scan_type).
 *   2. The ability itself stays PURE: firing the action is not a write,
 *      and with no listener registered nothing is persisted (the
 *      zero-writes guard in tests/abilities-sn-scan.php remains the
 *      structural proof; this suite proves the listener side).
 *   3. The listener persists exactly the metrics row (wpdb spy), and the
 *      kill switch + fail-open contract hold.
 *   4. total_candidates (the new envelope field) is the FULL pre-pagination
 *      count, not the page size.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg\n"; }
}
function eq( $expected, $actual, $msg ) {
	ok( $expected === $actual, $msg . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_data( $key = '' ) { return $this->data; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

// Real action plumbing — the wrapper/listener contract is the SUT here.
$GLOBALS['__actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function do_action( $tag, ...$args ) { foreach ( $GLOBALS['__actions'][ $tag ] ?? array() as $cb ) { $cb( ...$args ); } }
$GLOBALS['__filters'] = array();
function apply_filters( $h, $v ) { foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); } return $v; }
function add_filter( $h, $cb ) { $GLOBALS['__filters'][ $h ][] = $cb; }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function wp_rand( $min, $max ) { return $GLOBALS['__wp_rand'] ?? 2; } // Never 1 by default: prune gate closed.

// wpdb spy.
class Test_WPDB {
	public $prefix = 'wp_';
	public $inserts = array();
	public $queries = array();
	public $throw_on_insert = false;
	public function insert( $table, $row ) {
		if ( $this->throw_on_insert ) { throw new Exception( 'db down' ); }
		$this->inserts[] = array( $table, $row );
		return 1;
	}
	public $last_error = '';
	public $results = array();
	public function prepare( $sql, ...$args ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args ); }
	public function query( $sql ) { $this->queries[] = $sql; return 0; }
	public $fail_on_select = false;
	public function get_results( $sql, $output = 'OBJECT' ) {
		$this->queries[] = $sql;
		if ( $this->fail_on_select ) { $this->last_error = "Table 'wp_sn_scan_run' doesn't exist"; return array(); }
		return $this->results;
	}
	public function get_charset_collate() { return ''; }
}
$GLOBALS['wpdb'] = new Test_WPDB();

require __DIR__ . '/../inc/sn-scan-telemetry.php';

echo "sn-scan telemetry — v10.60.0\n\n";

/* ════════════════════════════════════════════════════════════════════════
 * 1. Metrics builder — success shape
 * ════════════════════════════════════════════════════════════════════════ */

$envelope = array(
	'scan_type'        => 'anchor_violations',
	'scan_run_id'      => str_repeat( 'a', 64 ),
	'freshness'        => 'fresh',
	'corpus_state'     => array( 'posts_examined' => 40, 'posts_skipped' => 2, 'corpus_fingerprint' => str_repeat( 'b', 64 ) ),
	'candidates'       => array(
		array( 'candidate_id' => 'c1', 'apply_hint' => array( 'tool' => 'x' ) ),
		array( 'candidate_id' => 'c2', 'apply_hint' => null ),
	),
	'total_candidates' => 12,
	'nextCursor'       => 'Mg==',
	'truncated'        => false,
);
$input = array( 'scan_type' => 'anchor_violations', 'max_candidates' => 2, 'cursor' => 'Mg==', 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 1, 2, 3 ) ), 'include_dismissed' => true );

$m = snt_sn_scan_run_metrics( $input, $envelope, microtime( true ) - 0.05 );
eq( 'ok', $m['outcome'], 'metrics: success outcome' );
eq( 'anchor_violations', $m['scan_type'], 'metrics: scan_type recorded — the whole point' );
eq( 12, $m['total_candidates'], 'metrics: total_candidates is the FULL pre-pagination count' );
eq( 2, $m['candidates_returned'], 'metrics: candidates_returned is the page size' );
eq( 1, $m['candidates_with_apply_hint'], 'metrics: apply-hint coverage counted (actionability)' );
eq( 40, $m['posts_examined'], 'metrics: corpus coverage carried' );
eq( 'post_ids', $m['scope_kind'], 'metrics: scope kind carried' );
eq( 3, $m['scope_size'], 'metrics: scoped-id count carried' );
eq( 1, $m['cursor_used'], 'metrics: pagination use is visible' );
eq( 1, $m['include_dismissed'], 'metrics: the inert flag is measured (evidence for/against ever wiring it)' );
ok( $m['duration_ms'] >= 50, 'metrics: duration measured from the caller-provided t0' );
eq( str_repeat( 'b', 64 ), $m['corpus_fingerprint'], 'metrics: corpus_fingerprint carried (identical-rerun detection)' );
eq( '', $m['error_code'], 'metrics: no error code on success' );

/* ════════════════════════════════════════════════════════════════════════
 * 2. Metrics builder — error shape (never success-only)
 * ════════════════════════════════════════════════════════════════════════ */

$err = new WP_Error( 'snt_scan_bad_cursor', 'cursor is malformed.', array( 'status' => 422 ) );
$m   = snt_sn_scan_run_metrics( array( 'scan_type' => 'near_duplicate', 'cursor' => '!!' ), $err, microtime( true ) );
eq( 'error', $m['outcome'], 'metrics: error outcome recorded — a failed run is a row, not silence' );
eq( 'snt_scan_bad_cursor', $m['error_code'], 'metrics: the WP_Error code survives (which gate refused)' );
eq( 'near_duplicate', $m['scan_type'], 'metrics: per-type failure attribution even on error' );
eq( 0, $m['total_candidates'], 'metrics: zero counts on error, never fabricated' );

/* ════════════════════════════════════════════════════════════════════════
 * 3. Listener — persists the row; kill switch; fail-open
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['wpdb']->inserts = array();
do_action( 'sn_scan_completed', snt_sn_scan_run_metrics( $input, $envelope, microtime( true ) ) );
eq( 1, count( $GLOBALS['wpdb']->inserts ), 'listener: one row per completed scan' );
list( $table, $row ) = $GLOBALS['wpdb']->inserts[0];
eq( 'wp_sn_scan_run', $table, 'listener: writes the dedicated sn_scan_run table (never the rw audit log — read-door contract)' );
eq( 'anchor_violations', $row['scan_type'], 'listener: the row is the metrics row' );
ok( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['ts'] ), 'listener: ts normalized to DATETIME' );

// Kill switch.
add_filter( 'sn_scan_telemetry_enabled', function () { return false; } );
$GLOBALS['wpdb']->inserts = array();
do_action( 'sn_scan_completed', snt_sn_scan_run_metrics( $input, $envelope, microtime( true ) ) );
eq( 0, count( $GLOBALS['wpdb']->inserts ), 'listener: kill switch stops persistence with no code change' );
$GLOBALS['__filters']['sn_scan_telemetry_enabled'] = array();

// Fail-open: a throwing insert never escapes.
$GLOBALS['wpdb']->throw_on_insert = true;
$threw = false;
try {
	do_action( 'sn_scan_completed', snt_sn_scan_run_metrics( $input, $envelope, microtime( true ) ) );
} catch ( \Throwable $e ) {
	$threw = true;
}
ok( ! $threw, 'listener: fail-open — a DB failure never propagates into the scan response path' );
$GLOBALS['wpdb']->throw_on_insert = false;

// Retention: prune fires only on the 1-in-N gate.
$GLOBALS['__wp_rand'] = 1;
$GLOBALS['wpdb']->queries = array();
do_action( 'sn_scan_completed', snt_sn_scan_run_metrics( $input, $envelope, microtime( true ) ) );
ok( 1 === count( $GLOBALS['wpdb']->queries ) && false !== strpos( $GLOBALS['wpdb']->queries[0], 'DELETE' ), 'listener: opportunistic retention DELETE fires on the gate' );
$GLOBALS['__wp_rand'] = 2;

/* ════════════════════════════════════════════════════════════════════════
 * 4. Wrapper — the ability fires the action on the ERROR path too, and
 *    stays write-free when no listener exists (proven structurally in
 *    tests/abilities-sn-scan.php; here: the action payload itself).
 * ════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../inc/sn-scan-adapters.php';
require __DIR__ . '/../inc/abilities-sn-scan.php';

$GLOBALS['__captured'] = array();
$GLOBALS['__actions']['sn_scan_completed'][] = function ( $metrics ) { $GLOBALS['__captured'][] = $metrics; };

$r = snt_ability_sn_scan( array( 'scan_type' => 'not_a_real_type' ) );
ok( is_wp_error( $r ), 'wrapper: invalid scan_type still errors as before' );
$rows = array_values( array_filter( $GLOBALS['__captured'], static function ( $m ) { return 'not_a_real_type' === $m['scan_type']; } ) );
eq( 1, count( $rows ), 'wrapper: the ERROR path fired exactly one sn_scan_completed (never success-only)' );
eq( 'error', $rows[0]['outcome'], 'wrapper: error outcome in the fired metrics' );
eq( 'snt_scan_bad_type', $rows[0]['error_code'], 'wrapper: the refusing gate\'s code in the fired metrics' );

/* ════════════════════════════════════════════════════════════════════════
 * 5. Summary read surface (v10.61.0) — the sn_site_facts "scan_telemetry"
 *    fact's backing rollup: real rows, honest zero, and failed-query
 *    detection (zero-vs-null).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nGroup: summary rollup\n";

$GLOBALS['wpdb']->results = array(
	array( 'scan_type' => 'anchor_violations', 'outcome' => 'ok', 'runs' => '3', 'avg_duration_ms' => '41.6667', 'avg_total_candidates' => '12.0', 'avg_with_apply_hint' => '0.0', 'last_run' => '2026-08-08 00:11:41' ),
	array( 'scan_type' => 'near_duplicate', 'outcome' => 'error', 'runs' => '1', 'avg_duration_ms' => '5', 'avg_total_candidates' => '0', 'avg_with_apply_hint' => '0', 'last_run' => '2026-08-08 00:10:00' ),
);
$sum = snt_scan_telemetry_summary( 30 );
eq( true, $sum['table_present'], 'summary: table_present true on a clean query' );
eq( 4, $sum['total_runs'], 'summary: total_runs sums grouped rows' );
eq( 'anchor_violations', $sum['rows'][0]['scan_type'], 'summary: per-type rows carried' );
eq( 41.7, $sum['rows'][0]['avg_duration_ms'], 'summary: averages rounded to one decimal' );
eq( 'error', $sum['rows'][1]['outcome'], 'summary: error outcomes appear as their own rows (per-type failure rate visible)' );

// Honest zero: no rows, clean query.
$GLOBALS['wpdb']->results = array();
$sum = snt_scan_telemetry_summary();
ok( true === $sum['table_present'] && 0 === $sum['total_runs'] && array() === $sum['rows'], 'summary: empty window with a clean query is table_present:true + zero rows — a measurement, not an unknown' );

// Failed query (missing table): wpdb sets last_error DURING the select
// (the summary resets it beforehand, so pre-seeding would be reset — the
// stub models the real failure timing).
$GLOBALS['wpdb']->fail_on_select = true;
$sum = snt_scan_telemetry_summary();
ok( false === $sum['table_present'] && 0 === $sum['total_runs'], 'summary: failed query is table_present:false — zero and null are different answers' );
$GLOBALS['wpdb']->fail_on_select = false;

echo "\nGroup: no PHP notices/warnings anywhere in the suite\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings raised: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

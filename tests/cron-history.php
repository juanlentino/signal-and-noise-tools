<?php
/**
 * Standalone fixture tests for inc/cron-history.php.
 *
 * Stubs the wpdb global with an in-memory backing store so we can
 * exercise the INSERT / SELECT / DELETE pure-logic without a live DB.
 * The retention logic + per-hook cap pass are both verified.
 *
 * Run: php tests/cron-history.php
 *
 * @since plugin v3.2.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
// wpdb output-type constants (WP defines these in wp-db.php).
if ( ! defined( 'OBJECT' )   ) define( 'OBJECT',   'OBJECT' );
if ( ! defined( 'OBJECT_K' ) ) define( 'OBJECT_K', 'OBJECT_K' );
if ( ! defined( 'ARRAY_A' )  ) define( 'ARRAY_A',  'ARRAY_A' );
if ( ! defined( 'ARRAY_N' )  ) define( 'ARRAY_N',  'ARRAY_N' );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) { return false; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event() {}
}

$GLOBALS['__test_options'] = array();
function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__test_options'][ $key ] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}

function current_action() {
	return isset( $GLOBALS['__test_current_action'] ) ? $GLOBALS['__test_current_action'] : '';
}

class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
}

// ─── wpdb stub ────────────────────────────────────────────────────────
class Stub_wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $rows = array(); // table => array of row arrays
	private $auto_id = 1;

	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }

	public function prepare( $query, ...$args ) {
		// Args were unpacked already if they came as ...$args; if a
		// single array was passed (wp's old call pattern), re-flatten.
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$out = $query;
		foreach ( $args as $a ) {
			$rep = is_int( $a ) ? (string) $a
				: ( is_float( $a ) ? (string) $a
				: "'" . addslashes( (string) $a ) . "'" );
			$out = preg_replace( '/%s|%d|%f/', $rep, $out, 1 );
		}
		return $out;
	}

	public function insert( $table, $row, $formats = null ) {
		if ( ! isset( $this->rows[ $table ] ) ) {
			$this->rows[ $table ] = array();
		}
		$row = array_merge( array( 'id' => $this->auto_id++ ), $row );
		$this->rows[ $table ][] = $row;
		return 1;
	}

	public function get_results( $query, $output = OBJECT_K ) {
		// Crude parse of "SELECT ... FROM <table> WHERE hook = '<hook>' ORDER BY ... LIMIT <n>"
		if ( ! preg_match( '/FROM\s+(\S+)/', $query, $tm ) ) return array();
		$table = $tm[1];
		$rows = isset( $this->rows[ $table ] ) ? $this->rows[ $table ] : array();

		if ( preg_match( "/WHERE hook = '([^']*)'/", $query, $hm ) ) {
			$rows = array_values( array_filter( $rows, function( $r ) use ( $hm ) {
				return isset( $r['hook'] ) && $r['hook'] === $hm[1];
			} ) );
		}
		// Sort by fired_at desc + id desc (mirrors the impl's ORDER BY).
		usort( $rows, function( $a, $b ) {
			$cmp = strcmp( $b['fired_at'], $a['fired_at'] );
			if ( $cmp !== 0 ) return $cmp;
			return $b['id'] - $a['id'];
		} );
		if ( preg_match( '/LIMIT (\d+)/', $query, $lm ) ) {
			$rows = array_slice( $rows, 0, (int) $lm[1] );
		}
		return $rows;
	}

	public function get_col( $query ) {
		// Distinct-hooks query OR id-keep query.
		if ( strpos( $query, 'SELECT DISTINCT hook' ) === 0 ) {
			if ( ! preg_match( '/FROM\s+(\S+)/', $query, $tm ) ) return array();
			$rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
			$out = array();
			foreach ( $rows as $r ) { $out[ $r['hook'] ] = true; }
			return array_keys( $out );
		}
		if ( strpos( $query, 'SELECT id FROM' ) === 0 ) {
			$rows = $this->get_results( $query, ARRAY_A );
			return array_map( function( $r ) { return $r['id']; }, $rows );
		}
		return array();
	}

	public function query( $query ) {
		// Handle DELETE only — that's all the impl uses query() for.
		if ( strpos( $query, 'DELETE FROM' ) !== 0 ) return false;
		if ( ! preg_match( '/DELETE FROM\s+(\S+)/', $query, $tm ) ) return false;
		$table = $tm[1];
		if ( ! isset( $this->rows[ $table ] ) ) return 0;

		// Two delete shapes the impl uses:
		//   a. window: WHERE fired_at < ( UTC_TIMESTAMP() - INTERVAL N DAY )
		//   b. cap:    WHERE hook = '<h>' AND id NOT IN ( id1,id2,... )
		$before = count( $this->rows[ $table ] );

		if ( preg_match( '/INTERVAL (\d+) DAY/', $query, $dm ) ) {
			$cutoff = strtotime( gmdate( 'Y-m-d H:i:s' ) ) - ( (int) $dm[1] * DAY_IN_SECONDS );
			$this->rows[ $table ] = array_values( array_filter( $this->rows[ $table ], function( $r ) use ( $cutoff ) {
				return strtotime( $r['fired_at'] . ' UTC' ) >= $cutoff;
			} ) );
		} elseif ( preg_match( "/WHERE hook = '([^']*)' AND id NOT IN \( ([^)]+) \)/", $query, $m ) ) {
			$hook = $m[1];
			$keep_ids = array_map( 'intval', explode( ',', $m[2] ) );
			$this->rows[ $table ] = array_values( array_filter( $this->rows[ $table ], function( $r ) use ( $hook, $keep_ids ) {
				if ( $r['hook'] !== $hook ) return true;
				return in_array( (int) $r['id'], $keep_ids, true );
			} ) );
		}
		return $before - count( $this->rows[ $table ] );
	}
}

$GLOBALS['wpdb'] = new Stub_wpdb();
// Pretend the install already ran so maybe_install short-circuits.
$GLOBALS['__test_options']['snt_cron_history_db_version'] = '1';

require_once __DIR__ . '/../inc/cron-history.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ch_assert_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $expected, true ) . "\n    Actual:   " . var_export( $actual, true ) . "\n"; }
}
function ch_assert_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test 1: record + read round-trip ────────────────────────────────
echo "\nTest 1: snt_cron_history_record + snt_cron_history_for_hook\n";
ch_assert_true( snt_cron_history_record( 'my_hook', array(), 42.7 ), 'record returns true' );
$rows = snt_cron_history_for_hook( 'my_hook' );
ch_assert_eq( 1, count( $rows ), 'one row read back' );
ch_assert_eq( 'my_hook', $rows[0]['hook'], 'hook echoed' );
ch_assert_eq( 43, $rows[0]['elapsed_ms'], 'elapsed_ms rounded from 42.7 → 43' );
ch_assert_eq( true, $rows[0]['success'], 'success defaults true' );
ch_assert_eq( null, $rows[0]['error_message'], 'no error_message on success' );

// ─── Test 2: failure path captures error ──────────────────────────────
echo "\nTest 2: failure path captures error_message\n";
snt_cron_history_record( 'fail_hook', array(), 12.0, false, 'boom' );
$rows = snt_cron_history_for_hook( 'fail_hook' );
ch_assert_eq( 1, count( $rows ), 'one fail row' );
ch_assert_eq( false, $rows[0]['success'], 'success=false' );
ch_assert_eq( 'boom', $rows[0]['error_message'], 'error_message captured' );

// ─── Test 3: empty hook rejected ──────────────────────────────────────
echo "\nTest 3: empty hook is rejected\n";
ch_assert_eq( false, snt_cron_history_record( '', array(), 1.0 ), 'empty hook returns false' );
ch_assert_eq( false, snt_cron_history_record( null, array(), 1.0 ), 'null hook returns false' );

// ─── Test 4: elapsed_ms boundary clamp ────────────────────────────────
echo "\nTest 4: elapsed_ms clamps to mediumint range\n";
snt_cron_history_record( 'big_hook', array(), 99999999 );
$rows = snt_cron_history_for_hook( 'big_hook' );
ch_assert_eq( 16777215, $rows[0]['elapsed_ms'], 'huge elapsed clamps to mediumint max' );
snt_cron_history_record( 'neg_hook', array(), -50 );
$rows = snt_cron_history_for_hook( 'neg_hook' );
ch_assert_eq( 0, $rows[0]['elapsed_ms'], 'negative elapsed clamps to 0' );

// ─── Test 5: error_message truncation ─────────────────────────────────
echo "\nTest 5: error_message truncates to 4096\n";
$huge = str_repeat( 'x', 5000 );
snt_cron_history_record( 'msg_hook', array(), null, false, $huge );
$rows = snt_cron_history_for_hook( 'msg_hook' );
ch_assert_eq( 4096, strlen( $rows[0]['error_message'] ), 'message truncated at 4096 chars' );

// ─── Test 6: limit bounds ─────────────────────────────────────────────
echo "\nTest 6: snt_cron_history_for_hook limit bounds (1–100)\n";
// Seed 15 rows for one hook.
for ( $i = 0; $i < 15; $i++ ) {
	snt_cron_history_record( 'paged_hook', array(), $i );
}
ch_assert_eq( 10, count( snt_cron_history_for_hook( 'paged_hook' ) ), 'default limit is 10' );
ch_assert_eq( 15, count( snt_cron_history_for_hook( 'paged_hook', 50 ) ), 'limit 50 returns all 15' );
ch_assert_eq( 1, count( snt_cron_history_for_hook( 'paged_hook', 0 ) ), 'limit 0 clamps to 1' );
ch_assert_eq( 15, count( snt_cron_history_for_hook( 'paged_hook', 9999 ) ), 'limit 9999 clamps to 100 (only 15 rows exist)' );

// ─── Test 7: newest-first ordering ────────────────────────────────────
echo "\nTest 7: rows returned newest-first\n";
$rows = snt_cron_history_for_hook( 'paged_hook', 3 );
ch_assert_true( $rows[0]['id'] > $rows[1]['id'] && $rows[1]['id'] > $rows[2]['id'], 'ids descend (newest first)' );

// ─── Test 8: per-hook isolation ───────────────────────────────────────
echo "\nTest 8: per-hook isolation in SELECT\n";
ch_assert_eq( 1, count( snt_cron_history_for_hook( 'my_hook' ) ), 'my_hook still has 1 row after paged_hook seeded 15' );

// ─── Test 9: prune — 30-day window ────────────────────────────────────
echo "\nTest 9: snt_cron_history_prune drops rows older than 30 days\n";
// Inject a row 60 days old.
$old_table = $GLOBALS['wpdb']->prefix . 'snt_cron_history';
$GLOBALS['wpdb']->rows[ $old_table ][] = array(
	'id'             => 9999,
	'hook'           => 'paged_hook',
	'args_signature' => '',
	'fired_at'       => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
	'elapsed_ms'     => 1,
	'success'        => 1,
	'error_message'  => null,
);
$before_prune = count( $GLOBALS['wpdb']->rows[ $old_table ] );
snt_cron_history_prune();
$after_prune  = count( $GLOBALS['wpdb']->rows[ $old_table ] );
ch_assert_true( $after_prune < $before_prune, 'prune removed at least one row' );
$has_old = false;
foreach ( $GLOBALS['wpdb']->rows[ $old_table ] as $r ) {
	if ( $r['id'] === 9999 ) { $has_old = true; break; }
}
ch_assert_eq( false, $has_old, '60-day-old row no longer present' );

// ─── Test 10: prune — per-hook cap ────────────────────────────────────
echo "\nTest 10: snt_cron_history_prune enforces per-hook cap\n";
// Reset table; seed 1010 rows for one hook with fresh timestamps.
$GLOBALS['wpdb']->rows[ $old_table ] = array();
for ( $i = 0; $i < 1010; $i++ ) {
	snt_cron_history_record( 'noisy_hook', array(), $i );
}
ch_assert_eq( 1010, count( snt_cron_history_for_hook( 'noisy_hook', 100 ) ) >= 100 ? count( $GLOBALS['wpdb']->rows[ $old_table ] ) : -1, 'fixture seeded 1010 rows' );
snt_cron_history_prune();
$remaining = count( $GLOBALS['wpdb']->rows[ $old_table ] );
ch_assert_eq( 1000, $remaining, 'cap pass trims to exactly 1000 rows' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

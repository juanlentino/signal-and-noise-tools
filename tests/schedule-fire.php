<?php
/**
 * Standalone fixture tests for the Task 6 fire + reconcile half of
 * inc/schedule-engine.php.
 *
 * Exercises sn_schedule_fire + sn_schedule_reconcile against:
 *   - the REAL engine accessors (sn_schedule_upsert / _get / _all /
 *     _update_status) over the SAME in-memory $wpdb row store the engine test
 *     uses, so a status flip is a genuine round-trip through prepare()-bound
 *     update + read-back (a wrong WHERE / wrong column makes a test FAIL, it does
 *     not pass on a canned return);
 *   - an INPUT-AWARE sn_schedule_purge_urls stub recording the EXACT $urls it
 *     received per call, plus a toggle for its return (TRUE = dispatched /
 *     FALSE = unconfigured) so the advance-vs-error retry path is real;
 *   - a stubbable current_time so each boundary case pins a fixed UTC "now";
 *   - record-only wp_next_scheduled / wp_schedule_single_event so reconcile's
 *     re-arm is asserted without a live cron.
 *
 * The stub $wpdb is a trimmed copy of the engine test's: it stores rows, and
 * implements insert/update/get_row/get_results/get_var/query for the exact query
 * shapes the engine emits. update() matching on id is what makes
 * sn_schedule_update_status real here.
 *
 * Run: php tests/schedule-fire.php
 *
 * @since plugin v6.40.0
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module. CLI / WP-CLI
// only, mirroring tests/schedule-engine.php.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'OBJECT' ) )   { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'OBJECT_K' ) ) { define( 'OBJECT_K', 'OBJECT_K' ); }
if ( ! defined( 'ARRAY_A' ) )  { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) )  { define( 'ARRAY_N', 'ARRAY_N' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

// ─── WP function stubs (engine load-time + fire/reconcile deps) ────────
if ( ! function_exists( 'add_action' ) )    { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'add_filter' ) )    { function add_filter( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__test_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = false ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'dbDelta' ) )       { function dbDelta( $sql ) { $GLOBALS['__test_dbdelta'][] = $sql; return array(); } }
if ( ! function_exists( '__' ) )            { function __( $t, $d = null ) { return $t; } }

// Stubbable UTC "now" (current_time('timestamp', true) returns UTC unix).
$GLOBALS['__now'] = 0;
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) { return $GLOBALS['__now']; }
}

// Input-aware purge seam. Records each $urls call + toggleable return.
$GLOBALS['__purge_calls']  = array();
$GLOBALS['__purge_return'] = true;
if ( ! function_exists( 'sn_schedule_purge_urls' ) ) {
	function sn_schedule_purge_urls( array $urls ) {
		$GLOBALS['__purge_calls'][] = $urls;
		return $GLOBALS['__purge_return'];
	}
}

// Record-only cron stubs for reconcile's re-arm. wp_next_scheduled consults a
// per-test map keyed by the row id arg so "already scheduled" can be simulated.
$GLOBALS['__armed']            = array(); // list of array( 'ts' => , 'id' => )
$GLOBALS['__next_scheduled']   = array(); // row_id => bool (already armed?)
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		return ! empty( $GLOBALS['__next_scheduled'][ $id ] );
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		$GLOBALS['__armed'][] = array( 'ts' => $ts, 'id' => isset( $args[0] ) ? (int) $args[0] : 0 );
		return true;
	}
}

$GLOBALS['__test_options'] = array();
$GLOBALS['__test_dbdelta'] = array();

/**
 * In-memory wpdb stub: a genuine row store (trimmed copy of the engine test's).
 * insert auto-ids; update mutates rows whose WHERE columns all match; get_row /
 * get_results / get_var read back; query handles the engine's DELETE shapes.
 */
class SF_Stub_wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $rows       = array();
	private $auto_id   = 1;

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? '';
			++$i;
			switch ( $m[0] ) {
				case '%d':
					return (string) (int) $a;
				case '%f':
					return (string) (float) $a;
				default:
					return "'" . addslashes( (string) $a ) . "'";
			}
		}, $query );
	}

	public function insert( $table, $row, $formats = null ) {
		if ( ! isset( $this->rows[ $table ] ) ) {
			$this->rows[ $table ] = array();
		}
		$id     = $this->auto_id++;
		$stored = array_merge( array( 'id' => $id ), $row );
		$this->rows[ $table ][] = $stored;
		$this->insert_id = $id;
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( ! isset( $this->rows[ $table ] ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $this->rows[ $table ] as &$r ) {
			$match = true;
			foreach ( $where as $col => $val ) {
				if ( ! isset( $r[ $col ] ) || (string) $r[ $col ] !== (string) $val ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				foreach ( $data as $col => $val ) {
					$r[ $col ] = $val;
				}
				++$n;
			}
		}
		unset( $r );
		return $n;
	}

	public function get_row( $query, $output = OBJECT ) {
		$rows = $this->select_rows( $query );
		if ( empty( $rows ) ) {
			return null;
		}
		$row = $rows[0];
		return ARRAY_A === $output ? $row : (object) $row;
	}

	public function get_results( $query, $output = OBJECT ) {
		$rows = $this->select_rows( $query );
		if ( ARRAY_A === $output ) {
			return $rows;
		}
		return array_map( function ( $r ) { return (object) $r; }, $rows );
	}

	public function get_var( $query ) {
		$rows = $this->select_rows( $query );
		if ( empty( $rows ) ) {
			return null;
		}
		$first = $rows[0];
		return isset( $first['id'] ) ? $first['id'] : reset( $first );
	}

	private function select_rows( $query ) {
		if ( ! preg_match( '/FROM\s+(\S+)/i', $query, $tm ) ) {
			return array();
		}
		$table = $tm[1];
		$rows  = isset( $this->rows[ $table ] ) ? $this->rows[ $table ] : array();

		if ( preg_match( '/WHERE\s+id\s*=\s*(\d+)/i', $query, $im ) ) {
			$id   = (int) $im[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $id ) {
				return (int) $r['id'] === $id;
			} ) );
		} elseif ( preg_match( "/WHERE\s+schedule_id\s*=\s*'([^']*)'/i", $query, $sm ) ) {
			$sid  = $sm[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $sid ) {
				return (string) $r['schedule_id'] === $sid;
			} ) );
		}

		if ( preg_match( '/ORDER BY id ASC/i', $query ) ) {
			usort( $rows, function ( $a, $b ) { return (int) $a['id'] - (int) $b['id']; } );
		}
		if ( preg_match( '/LIMIT (\d+)/i', $query, $lm ) ) {
			$rows = array_slice( $rows, 0, (int) $lm[1] );
		}
		return $rows;
	}

	public function query( $query ) {
		if ( 0 !== strpos( $query, 'DELETE FROM' ) ) {
			return false;
		}
		if ( ! preg_match( '/DELETE FROM\s+(\S+)/i', $query, $tm ) ) {
			return false;
		}
		$table = $tm[1];
		if ( ! isset( $this->rows[ $table ] ) ) {
			return 0;
		}
		$before = count( $this->rows[ $table ] );
		if ( preg_match( '/WHERE\s+id\s*=\s*(\d+)/i', $query, $im ) ) {
			$id = (int) $im[1];
			$this->rows[ $table ] = array_values( array_filter( $this->rows[ $table ], function ( $r ) use ( $id ) {
				return (int) $r['id'] !== $id;
			} ) );
			return $before - count( $this->rows[ $table ] );
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new SF_Stub_wpdb();
$GLOBALS['__test_options']['sn_schedules_db_version'] = '1';

require_once __DIR__ . '/../inc/schedule-engine.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $msg\n";
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}

/** Reset the row store + all recorders between cases. */
function reset_world() {
	$GLOBALS['wpdb']->rows = array();
	$GLOBALS['__purge_calls']    = array();
	$GLOBALS['__purge_return']   = true;
	$GLOBALS['__armed']          = array();
	$GLOBALS['__next_scheduled'] = array();
}

// Fixed UTC instants. gmmktime builds a UTC unix timestamp regardless of the
// server timezone, matching how the engine parses its boundary strings.
$START_STR = '2026-07-01 00:00:00';
$END_STR   = '2026-08-01 00:00:00';
$START_TS  = gmmktime( 0, 0, 0, 7, 1, 2026 );
$END_TS    = gmmktime( 0, 0, 0, 8, 1, 2026 );

// The exact purge URL list every fixture row carries. Asserting the stub
// receives THIS verbatim is the EXACT-purge-URLs check (falsified below).
$PURGE_URLS = array( 'https://example.com/notes/scheduled-post/', 'https://example.com/notes/' );
$PURGE_JSON = json_encode( $PURGE_URLS );

echo "schedule-fire: boundary fire state machine + reconcile\n\n";

// ─── Case: REVEAL (queued, start passed) -> purge + active ────────────
echo "Group: reveal transition\n";
reset_world();
$id = sn_schedule_upsert( array(
	'schedule_id' => 'rev-1',
	'target_type' => 'fragment',
	'target_ref'  => '10',
	'action'      => 'reveal',
	'starts_at'   => $START_STR,
	'ends_at'     => null,
	'status'      => 'queued',
	'purge_urls'  => $PURGE_JSON,
) );
$GLOBALS['__now'] = $START_TS;          // exactly at the reveal boundary.
$GLOBALS['__purge_return'] = true;
sn_schedule_fire( $id );
$row = sn_schedule_get( $id );
ok( count( $GLOBALS['__purge_calls'] ) === 1, 'reveal: purge dispatched exactly once' );
ok( $GLOBALS['__purge_calls'][0] === $PURGE_URLS, 'reveal: purge received the row\'s EXACT purge_urls' );
ok( $row['status'] === 'active', 'reveal: status advanced queued -> active' );
ok( ! empty( $row['last_run'] ), 'reveal: last_run stamped' );

// ─── Case: HIDE (active, end passed) -> purge + done ──────────────────
echo "\nGroup: hide transition\n";
reset_world();
$id = sn_schedule_upsert( array(
	'schedule_id' => 'hide-1',
	'target_type' => 'fragment',
	'target_ref'  => '11',
	'action'      => 'reveal',
	'starts_at'   => $START_STR,
	'ends_at'     => $END_STR,
	'status'      => 'active',
	'purge_urls'  => $PURGE_JSON,
) );
$GLOBALS['__now'] = $END_TS;            // at the hide boundary.
sn_schedule_fire( $id );
$row = sn_schedule_get( $id );
ok( count( $GLOBALS['__purge_calls'] ) === 1, 'hide: purge dispatched exactly once' );
ok( $GLOBALS['__purge_calls'][0] === $PURGE_URLS, 'hide: purge received the EXACT purge_urls' );
ok( $row['status'] === 'done', 'hide: status advanced active -> done' );

// ─── Case: BOTH boundaries past (missed) -> ONE purge + done ──────────
echo "\nGroup: both-boundaries-past (missed event)\n";
reset_world();
$id = sn_schedule_upsert( array(
	'schedule_id' => 'both-1',
	'target_type' => 'fragment',
	'target_ref'  => '12',
	'action'      => 'reveal',
	'starts_at'   => $START_STR,
	'ends_at'     => $END_STR,
	'status'      => 'queued',
	'purge_urls'  => $PURGE_JSON,
) );
$GLOBALS['__now'] = $END_TS + 86400;    // a day past the close: both passed.
sn_schedule_fire( $id );
$row = sn_schedule_get( $id );
ok( count( $GLOBALS['__purge_calls'] ) === 1, 'both-past: exactly ONE purge for the single fire' );
ok( $row['status'] === 'done', 'both-past: status advances straight to done' );

// ─── Case: purge FALSE (unconfigured) -> error, NOT advanced; retry ───
echo "\nGroup: purge FALSE -> error hold, then retry\n";
reset_world();
$id = sn_schedule_upsert( array(
	'schedule_id' => 'err-1',
	'target_type' => 'fragment',
	'target_ref'  => '13',
	'action'      => 'reveal',
	'starts_at'   => $START_STR,
	'ends_at'     => null,
	'status'      => 'queued',
	'purge_urls'  => $PURGE_JSON,
) );
$GLOBALS['__now'] = $START_TS + 10;
$GLOBALS['__purge_return'] = false;     // unconfigured: purge cannot dispatch.
sn_schedule_fire( $id );
$row = sn_schedule_get( $id );
ok( $row['status'] === 'error', 'purge-false: status set to error (boundary NOT advanced past reveal)' );
ok( $row['status'] !== 'active', 'purge-false: did NOT advance to active on a failed purge' );
ok( ! empty( $row['last_run'] ), 'purge-false: last_run still stamped on the failed attempt' );

// Retry: creds appear (purge now TRUE), re-fire the SAME row -> advances.
$GLOBALS['__purge_return'] = true;
$GLOBALS['__purge_calls']  = array();
sn_schedule_fire( $id );
$row = sn_schedule_get( $id );
ok( count( $GLOBALS['__purge_calls'] ) === 1, 'retry: purge dispatched on the re-fire' );
ok( $GLOBALS['__purge_calls'][0] === $PURGE_URLS, 'retry: purge received the EXACT purge_urls' );
ok( $row['status'] === 'active', 'retry: error row with reveal due advances to active when purge succeeds' );

// ─── Case: missing row -> fire is a safe no-op ────────────────────────
echo "\nGroup: missing row\n";
reset_world();
$GLOBALS['__now'] = $START_TS;
sn_schedule_fire( 999999 );
ok( count( $GLOBALS['__purge_calls'] ) === 0, 'missing row: no purge, no fatal' );

// ─── Case: reconcile catches up a missed boundary, idempotently ───────
echo "\nGroup: reconcile catch-up + idempotency\n";
reset_world();
// A queued row whose start passed while WP-Cron was idle (no event fired).
$id = sn_schedule_upsert( array(
	'schedule_id' => 'rec-1',
	'target_type' => 'fragment',
	'target_ref'  => '14',
	'action'      => 'reveal',
	'starts_at'   => $START_STR,
	'ends_at'     => null,
	'status'      => 'queued',
	'purge_urls'  => $PURGE_JSON,
) );
$GLOBALS['__now'] = $START_TS + 3600;   // an hour past the reveal: overdue.
$GLOBALS['__next_scheduled'] = array( $id => false ); // event was dropped.
sn_schedule_reconcile();
$row = sn_schedule_get( $id );
ok( count( $GLOBALS['__purge_calls'] ) === 1, 'reconcile: fires the missed reveal exactly once' );
ok( $GLOBALS['__purge_calls'][0] === $PURGE_URLS, 'reconcile: purge received the EXACT purge_urls' );
ok( $row['status'] === 'active', 'reconcile: caught up queued -> active' );

// Second reconcile pass: the row is now active with no end (correctly open), so
// nothing is due -> no further purge (idempotent).
$GLOBALS['__purge_calls'] = array();
sn_schedule_reconcile();
ok( count( $GLOBALS['__purge_calls'] ) === 0, 'reconcile: second pass is a no-op (idempotent)' );
$row = sn_schedule_get( $id );
ok( $row['status'] === 'active', 'reconcile: status unchanged on the idempotent second pass' );

// ─── Case: reconcile re-arms a future boundary with no event ──────────
echo "\nGroup: reconcile re-arm of a future boundary\n";
reset_world();
$id = sn_schedule_upsert( array(
	'schedule_id' => 'future-1',
	'target_type' => 'fragment',
	'target_ref'  => '15',
	'action'      => 'reveal',
	'starts_at'   => $START_STR,
	'ends_at'     => null,
	'status'      => 'queued',
	'purge_urls'  => $PURGE_JSON,
) );
$GLOBALS['__now'] = $START_TS - 3600;   // an hour BEFORE the reveal: future.
$GLOBALS['__next_scheduled'] = array( $id => false ); // its event was dropped.
sn_schedule_reconcile();
ok( count( $GLOBALS['__purge_calls'] ) === 0, 're-arm: a future boundary is NOT fired (nothing due)' );
ok( count( $GLOBALS['__armed'] ) === 1, 're-arm: the dropped future event is re-scheduled' );
ok( $GLOBALS['__armed'][0]['id'] === $id && (int) $GLOBALS['__armed'][0]['ts'] === $START_TS, 're-arm: re-scheduled at the row\'s start_ts for the row id' );

// And when the future event IS already scheduled, reconcile does NOT double-arm.
reset_world();
$id = sn_schedule_upsert( array(
	'schedule_id' => 'future-2',
	'target_type' => 'fragment',
	'target_ref'  => '16',
	'action'      => 'reveal',
	'starts_at'   => $START_STR,
	'ends_at'     => null,
	'status'      => 'queued',
	'purge_urls'  => $PURGE_JSON,
) );
$GLOBALS['__now'] = $START_TS - 3600;
$GLOBALS['__next_scheduled'] = array( $id => true );  // already armed.
sn_schedule_reconcile();
ok( count( $GLOBALS['__armed'] ) === 0, 're-arm: an already-scheduled future event is NOT double-armed' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

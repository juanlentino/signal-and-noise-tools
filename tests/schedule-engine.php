<?php
/**
 * Standalone fixture tests for inc/schedule-engine.php (table half).
 *
 * Exercises the wp_sn_schedules row accessors against an in-memory wpdb
 * stub that genuinely STORES rows in a PHP array and implements
 * insert/update/get_row/get_results/query so that:
 *   - sn_schedule_upsert idempotency (same schedule_id = ONE row) is real,
 *   - sn_schedule_delete_missing WHERE filtering is real (a wrong WHERE
 *     clause makes a test FAIL, not pass on a canned return value).
 *
 * Run: php tests/schedule-engine.php
 *
 * @since plugin v6.40.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Allow only CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
// wpdb output-type constants (WP defines these in wp-db.php).
if ( ! defined( 'OBJECT' ) )   { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'OBJECT_K' ) ) { define( 'OBJECT_K', 'OBJECT_K' ); }
if ( ! defined( 'ARRAY_A' ) )  { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) )  { define( 'ARRAY_N', 'ARRAY_N' ); }

if ( ! function_exists( 'add_action' ) )    { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'add_filter' ) )    { function add_filter( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__test_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = false ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'dbDelta' ) )       { function dbDelta( $sql ) { $GLOBALS['__test_dbdelta'][] = $sql; return array(); } }
if ( ! function_exists( '__' ) )            { function __( $t, $d = null ) { return $t; } }
if ( ! defined( 'MINUTE_IN_SECONDS' ) )     { define( 'MINUTE_IN_SECONDS', 60 ); }

$GLOBALS['__test_options'] = array();
$GLOBALS['__test_dbdelta'] = array();

/**
 * In-memory wpdb stub: a genuine row store. insert assigns an auto id,
 * update mutates matching rows, get_row / get_results read back, query
 * handles the DELETE shapes the engine uses. The crude SQL parsing mirrors
 * the engine's exact query text, so a divergent WHERE clause fails a test.
 */
class SE_Stub_wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $rows       = array(); // table => list of row arrays
	private $auto_id   = 1;

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( &$i, $args ) {
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
			},
			$query
		);
	}

	public function insert( $table, $row, $formats = null ) {
		if ( ! isset( $this->rows[ $table ] ) ) {
			$this->rows[ $table ] = array();
		}
		$id        = $this->auto_id++;
		$stored    = array_merge( array( 'id' => $id ), $row );
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
		// SELECT id FROM <t> WHERE schedule_id = '<sid>' LIMIT 1
		$rows = $this->select_rows( $query );
		if ( empty( $rows ) ) {
			return null;
		}
		// Return first column value (the engine selects `id`).
		$first = $rows[0];
		return isset( $first['id'] ) ? $first['id'] : reset( $first );
	}

	/**
	 * Crude SELECT executor covering the shapes the engine emits:
	 *   SELECT ... FROM <t> WHERE id = <n>
	 *   SELECT ... FROM <t> WHERE schedule_id = '<sid>' ...
	 *   SELECT ... FROM <t>            (all rows)
	 */
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

	/**
	 * DELETE executor covering:
	 *   DELETE FROM <t> WHERE id = <n>
	 *   DELETE FROM <t> WHERE target_type = '<tt>' AND target_ref = '<ref>'
	 *       AND schedule_id NOT IN ( '<a>','<b>',... )
	 *   DELETE FROM <t> WHERE target_type = '<tt>' AND target_ref = '<ref>'
	 *       (empty keep set = delete all that post's rows)
	 */
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

		if ( preg_match( "/target_type = '([^']*)'\s+AND\s+target_ref = '([^']*)'/i", $query, $m ) ) {
			$type = $m[1];
			$ref  = $m[2];
			$keep = array();
			if ( preg_match( '/schedule_id NOT IN \(([^)]*)\)/i', $query, $km ) ) {
				preg_match_all( "/'([^']*)'/", $km[1], $qm );
				$keep = $qm[1];
			}
			$has_not_in = (bool) preg_match( '/NOT IN/i', $query );
			$this->rows[ $table ] = array_values( array_filter(
				$this->rows[ $table ],
				function ( $r ) use ( $type, $ref, $keep, $has_not_in ) {
					$is_target = (string) $r['target_type'] === $type
						&& (string) $r['target_ref'] === $ref;
					if ( ! $is_target ) {
						return true; // not this post's fragment row; keep.
					}
					if ( ! $has_not_in ) {
						return false; // empty keep set: delete all.
					}
					// Keep only if its schedule_id is in the keep list.
					return in_array( (string) $r['schedule_id'], $keep, true );
				}
			) );
			return $before - count( $this->rows[ $table ] );
		}

		return 0;
	}
}

$GLOBALS['wpdb'] = new SE_Stub_wpdb();
// Pretend install already ran so maybe_install short-circuits during require.
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

echo "schedule-engine: wp_sn_schedules table + row accessors\n\n";

// ─── Group: schema_sql / dbDelta columns ──────────────────────────────
echo "Group: schema_sql\n";
$sql = sn_schedules_schema_sql();
ok( is_string( $sql ) && '' !== $sql, 'schema_sql: returns a non-empty string' );
ok( strpos( $sql, 'wp_sn_schedules' ) !== false, 'schema_sql: references the wp_sn_schedules table' );
$expected_cols = array(
	'id', 'schedule_id', 'target_type', 'target_ref', 'action',
	'starts_at', 'ends_at', 'recurrence', 'payload', 'status',
	'last_run', 'purge_urls', 'updated',
);
foreach ( $expected_cols as $col ) {
	ok( preg_match( '/\b' . preg_quote( $col, '/' ) . '\b/', $sql ) === 1, "schema_sql: defines column `$col`" );
}
ok( strpos( $sql, 'PRIMARY KEY' ) !== false, 'schema_sql: declares a PRIMARY KEY' );
ok( strpos( $sql, 'KEY schedule_id' ) !== false, 'schema_sql: indexes schedule_id' );
ok( strpos( $sql, 'target_ref(191)' ) !== false, 'schema_sql: indexes target_ref with a 191 prefix' );
ok( stripos( $sql, 'ENUM' ) === false, 'schema_sql: uses no MySQL ENUM (dbDelta-idempotent VARCHARs)' );

// ─── Group: constants ─────────────────────────────────────────────────
echo "\nGroup: constants\n";
ok( defined( 'SN_SCHEDULES_TABLE' ) && SN_SCHEDULES_TABLE === 'sn_schedules', 'SN_SCHEDULES_TABLE constant defined' );
ok( defined( 'SN_SCHEDULES_DB_VERSION' ), 'SN_SCHEDULES_DB_VERSION constant defined' );
ok( defined( 'SN_SCHEDULES_DB_VERSION_OPT' ), 'SN_SCHEDULES_DB_VERSION_OPT constant defined' );

// ─── Group: upsert insert then idempotent update ──────────────────────
echo "\nGroup: sn_schedule_upsert\n";
$id1 = sn_schedule_upsert( array(
	'schedule_id' => 'uuid-abc',
	'target_type' => 'fragment',
	'target_ref'  => '42',
	'action'      => 'reveal',
	'starts_at'   => '2026-07-01 00:00:00',
	'status'      => 'queued',
) );
ok( is_int( $id1 ) && $id1 > 0, 'upsert: insert returns a positive int id' );

$table = $GLOBALS['wpdb']->prefix . 'sn_schedules';
ok( count( $GLOBALS['wpdb']->rows[ $table ] ) === 1, 'upsert: exactly one row stored after first insert' );

// Same schedule_id again: must UPDATE the existing row, not create a dupe.
$id2 = sn_schedule_upsert( array(
	'schedule_id' => 'uuid-abc',
	'target_type' => 'fragment',
	'target_ref'  => '42',
	'action'      => 'hide',
	'starts_at'   => '2026-08-01 00:00:00',
	'status'      => 'active',
) );
ok( $id2 === $id1, 'upsert: same schedule_id returns the SAME id (idempotent)' );
ok( count( $GLOBALS['wpdb']->rows[ $table ] ) === 1, 'upsert: still exactly one row (no dupe)' );
$after = sn_schedule_get( $id1 );
ok( is_array( $after ) && $after['action'] === 'hide', 'upsert: existing row was UPDATED (action now hide)' );
ok( $after['status'] === 'active', 'upsert: existing row status updated to active' );
ok( $after['starts_at'] === '2026-08-01 00:00:00', 'upsert: existing row starts_at updated' );

// Empty schedule_id rows are table-canonical: each insert is its own row.
$id3 = sn_schedule_upsert( array(
	'schedule_id' => '',
	'target_type' => 'page',
	'target_ref'  => 'https://example.com/x',
	'action'      => 'reveal',
) );
$id4 = sn_schedule_upsert( array(
	'schedule_id' => '',
	'target_type' => 'page',
	'target_ref'  => 'https://example.com/y',
	'action'      => 'reveal',
) );
ok( $id3 !== $id4 && $id3 > 0 && $id4 > 0, 'upsert: empty schedule_id rows are never coalesced (two distinct ids)' );
ok( count( $GLOBALS['wpdb']->rows[ $table ] ) === 3, 'upsert: three rows total (1 uuid + 2 empty-sid)' );

// ─── Group: get / all round-trip ──────────────────────────────────────
echo "\nGroup: sn_schedule_get / sn_schedule_all\n";
ok( sn_schedule_get( 999999 ) === null, 'get: unknown id returns null' );
// Guard paths: non-positive ids are rejected without touching the DB.
ok( sn_schedule_get( 0 ) === null, 'get: id 0 returns null (guard)' );
ok( sn_schedule_get( -1 ) === null, 'get: id -1 returns null (guard)' );
$got = sn_schedule_get( $id1 );
ok( is_array( $got ) && $got['schedule_id'] === 'uuid-abc', 'get: returns the row as an assoc array' );
ok( isset( $got['id'] ) && (int) $got['id'] === $id1, 'get: row carries its id' );

$all = sn_schedule_all();
ok( is_array( $all ) && count( $all ) === 3, 'all: returns every row (3)' );
$ids = array_map( function ( $r ) { return (int) $r['id']; }, $all );
sort( $ids );
ok( $ids === array( $id1, $id3, $id4 ), 'all: returns the expected ids ordered ascending' );

// ─── Group: delete round-trip ─────────────────────────────────────────
echo "\nGroup: sn_schedule_delete\n";
ok( sn_schedule_delete( $id3 ) === true, 'delete: existing id returns true' );
ok( sn_schedule_get( $id3 ) === null, 'delete: deleted row is gone' );
ok( count( sn_schedule_all() ) === 2, 'delete: row count drops to 2' );
ok( sn_schedule_delete( 999999 ) === false, 'delete: unknown id returns false' );
// Guard paths: non-positive ids are rejected without touching the DB.
ok( sn_schedule_delete( 0 ) === false, 'delete: id 0 returns false (guard)' );
ok( sn_schedule_delete( -1 ) === false, 'delete: id -1 returns false (guard)' );

// ─── Group: delete_missing filtering ──────────────────────────────────
echo "\nGroup: sn_schedule_delete_missing\n";
// Reset to a known fixture: post 100 has 3 fragment rows; post 200 has 1;
// plus an unrelated page row that must never be touched.
$GLOBALS['wpdb']->rows[ $table ] = array();
sn_schedule_upsert( array( 'schedule_id' => 'f1', 'target_type' => 'fragment', 'target_ref' => '100', 'action' => 'reveal' ) );
sn_schedule_upsert( array( 'schedule_id' => 'f2', 'target_type' => 'fragment', 'target_ref' => '100', 'action' => 'reveal' ) );
sn_schedule_upsert( array( 'schedule_id' => 'f3', 'target_type' => 'fragment', 'target_ref' => '100', 'action' => 'hide' ) );
sn_schedule_upsert( array( 'schedule_id' => 'g1', 'target_type' => 'fragment', 'target_ref' => '200', 'action' => 'reveal' ) );
sn_schedule_upsert( array( 'schedule_id' => '', 'target_type' => 'page', 'target_ref' => '100', 'action' => 'reveal' ) );

// Keep only f1 + f3 for post 100 => f2 deleted; f3 kept; post 200 + page row untouched.
$deleted = sn_schedule_delete_missing( 100, array( 'f1', 'f3' ) );
ok( $deleted === 1, 'delete_missing: removes exactly the one stale row (f2)' );

$remaining_sids = array_map( function ( $r ) { return $r['schedule_id']; }, sn_schedule_all() );
sort( $remaining_sids );
ok( ! in_array( 'f2', $remaining_sids, true ), 'delete_missing: f2 (not in keep set) is gone' );
ok( in_array( 'f1', $remaining_sids, true ), 'delete_missing: f1 (in keep set) survives' );
ok( in_array( 'f3', $remaining_sids, true ), 'delete_missing: f3 (in keep set) survives' );
ok( in_array( 'g1', $remaining_sids, true ), 'delete_missing: post 200 fragment row untouched' );
ok( in_array( '', $remaining_sids, true ), 'delete_missing: the page row (same target_ref, diff type) untouched' );

// Empty keep set deletes ALL of that post's fragment rows (f1 + f3 here).
$deleted_all = sn_schedule_delete_missing( 100, array() );
ok( $deleted_all === 2, 'delete_missing: empty keep set deletes all remaining fragment rows for the post (f1+f3)' );
$final_sids = array_map( function ( $r ) { return $r['schedule_id']; }, sn_schedule_all() );
ok( ! in_array( 'f1', $final_sids, true ) && ! in_array( 'f3', $final_sids, true ), 'delete_missing: post 100 fragment rows all gone' );
ok( in_array( 'g1', $final_sids, true ), 'delete_missing: post 200 row STILL untouched after empty-keep purge' );
ok( in_array( '', $final_sids, true ), 'delete_missing: page row STILL untouched after empty-keep purge' );

// ─── Group: sn_schedule_is_open window gate (pure) ────────────────────
echo "\nGroup: sn_schedule_is_open\n";

// Fixed UTC instants used across the boundary cases. gmmktime builds the
// Unix timestamp in UTC regardless of the server timezone, which is exactly
// what the gate parses its string boundaries against.
$from_str = '2026-07-01 00:00:00';
$until_str = '2026-08-01 00:00:00';
$from_ts  = gmmktime( 0, 0, 0, 7, 1, 2026 );  // 2026-07-01 00:00:00 UTC
$until_ts = gmmktime( 0, 0, 0, 8, 1, 2026 );  // 2026-08-01 00:00:00 UTC

// now < from => closed (before the window opens).
ok( sn_schedule_is_open( $from_str, $until_str, $from_ts - 1 ) === false, 'is_open: now < from is closed' );
// now == from => open (inclusive start).
ok( sn_schedule_is_open( $from_str, $until_str, $from_ts ) === true, 'is_open: now == from is open (inclusive start)' );
// from < now < until => open.
ok( sn_schedule_is_open( $from_str, $until_str, $from_ts + 1 ) === true, 'is_open: from < now < until is open' );
// now == until => closed (exclusive end).
ok( sn_schedule_is_open( $from_str, $until_str, $until_ts ) === false, 'is_open: now == until is closed (exclusive end)' );
// now > until => closed.
ok( sn_schedule_is_open( $from_str, $until_str, $until_ts + 1 ) === false, 'is_open: now > until is closed' );

// from null/empty => open from the start; only until bounds.
ok( sn_schedule_is_open( null, $until_str, $from_ts - 100000 ) === true, 'is_open: null from is unbounded start (open before until)' );
ok( sn_schedule_is_open( '', $until_str, $from_ts - 100000 ) === true, 'is_open: empty from is unbounded start (treated as null)' );
ok( sn_schedule_is_open( null, $until_str, $until_ts ) === false, 'is_open: null from still respects exclusive until' );

// until null/empty => open forever; only from bounds.
ok( sn_schedule_is_open( $from_str, null, $until_ts + 100000 ) === true, 'is_open: null until is unbounded end (open after from)' );
ok( sn_schedule_is_open( $from_str, '', $until_ts + 100000 ) === true, 'is_open: empty until is unbounded end (treated as null)' );
ok( sn_schedule_is_open( $from_str, null, $from_ts - 1 ) === false, 'is_open: null until still respects inclusive from' );

// both null/empty => always open.
ok( sn_schedule_is_open( null, null, 0 ) === true, 'is_open: both null is always open (epoch)' );
ok( sn_schedule_is_open( '', '', 4102444800 ) === true, 'is_open: both empty is always open (far future)' );

// UTC boundary exactness: the string boundary parsed explicitly as UTC must
// equal the matching Unix timestamp built by strtotime(... UTC) too.
$from_ts_strtotime = strtotime( $from_str . ' UTC' );
ok( $from_ts_strtotime === $from_ts, 'is_open: fixture sanity, gmmktime matches strtotime(... UTC)' );
ok( sn_schedule_is_open( $from_str, null, $from_ts_strtotime ) === true, 'is_open: exact UTC instant lands on the inclusive boundary' );

// Unparseable-boundary policy: an unparseable from is "not yet open" (closed);
// an unparseable until is "no end" (unbounded).
ok( sn_schedule_is_open( 'not-a-date', null, 4102444800 ) === false, 'is_open: unparseable from is closed (fail-safe)' );
ok( sn_schedule_is_open( null, 'not-a-date', 0 ) === true, 'is_open: unparseable until is treated as no end' );

// Regression guard: the result must NOT depend on the server default timezone.
// Set a deliberately non-UTC default tz and assert the boundary still lands on
// the same UTC instant. A bare strtotime($s) (server-tz parse) would shift the
// boundary by the offset and flip these assertions.
$saved_tz = date_default_timezone_get(); // capture BEFORE mutating (the setter returns bool).
date_default_timezone_set( 'America/New_York' );
ok( sn_schedule_is_open( $from_str, $until_str, $from_ts ) === true, 'is_open: tz-regression, inclusive start holds under non-UTC default tz' );
ok( sn_schedule_is_open( $from_str, $until_str, $from_ts - 1 ) === false, 'is_open: tz-regression, pre-start still closed under non-UTC default tz' );
ok( sn_schedule_is_open( $from_str, $until_str, $until_ts ) === false, 'is_open: tz-regression, exclusive end holds under non-UTC default tz' );
// Restore the original default tz so later code is unaffected.
date_default_timezone_set( $saved_tz );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

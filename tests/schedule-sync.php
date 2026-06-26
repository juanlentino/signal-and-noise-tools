<?php
/**
 * Standalone fixture tests for inc/schedule-sync.php (the save_post sync layer).
 *
 * Task 5 of the scheduled-content subsystem: when a post is saved, mirror its
 * signal-noise/scheduled blocks into wp_sn_schedules and (re)arm the boundary
 * cron events. This test exercises the sync handler against:
 *   - the REAL engine accessors (sn_schedule_upsert / _get / _all /
 *     _delete_missing) over the SAME in-memory $wpdb row store the engine test
 *     uses, so the upsert idempotency + delete_missing WHERE contract is
 *     genuinely crossed (a wrong row shape / target_ref linkage FAILS a test,
 *     it does not pass on a canned return);
 *   - an INPUT-AWARE parse_blocks stub returning a controlled block tree in the
 *     real shape (blockName / attrs / innerBlocks), driven per-post via a global;
 *   - record-only stubs for wp_schedule_single_event + wp_clear_scheduled_hook
 *     so the cron re-arm calls (timestamp + array( row_id ) arg) are asserted.
 *
 * Run: php tests/schedule-sync.php
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
// Skip the schedule-block init hooks if that file is ever pulled in transitively.
define( 'SN_SCHEDULE_BLOCK_TEST', true );
// Skip the sync save_post add_action at file load so requiring the module here
// does not register a live WP hook against our stubbed add_action.
define( 'SN_SCHEDULE_SYNC_TEST', true );

// wpdb output-type constants (WP defines these in wp-db.php).
if ( ! defined( 'OBJECT' ) )   { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'OBJECT_K' ) ) { define( 'OBJECT_K', 'OBJECT_K' ); }
if ( ! defined( 'ARRAY_A' ) )  { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) )  { define( 'ARRAY_N', 'ARRAY_N' ); }

// ─── WP function stubs ────────────────────────────────────────────────
// Engine-side stubs (mirror tests/schedule-engine.php).
if ( ! function_exists( 'add_action' ) )    { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'add_filter' ) )    { function add_filter( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__test_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = false ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'dbDelta' ) )       { function dbDelta( $sql ) { $GLOBALS['__test_dbdelta'][] = $sql; return array(); } }
if ( ! function_exists( '__' ) )            { function __( $t, $d = null ) { return $t; } }
if ( ! defined( 'MINUTE_IN_SECONDS' ) )     { define( 'MINUTE_IN_SECONDS', 60 ); }

// Sync-side stubs. All record-only or input-aware; values are driven by the
// globals the test sets per case so the same handler is exercised under
// guard-pass and guard-fail conditions without re-requiring the module.
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post_id ) { return ! empty( $GLOBALS['__test_is_autosave'] ); }
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post_id ) { return ! empty( $GLOBALS['__test_is_revision'] ); }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, ...$args ) {
		// Default-allow; a case sets __test_can = false to exercise the cap bail.
		return ! isset( $GLOBALS['__test_can'] ) || (bool) $GLOBALS['__test_can'];
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) { return 'https://example.com/?p=' . (int) $post_id; }
}
// Fallback fetch path: every test passes $post explicitly, so this is only a
// guard against an undefined-function fatal if a future one-arg call hits it.
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) { return $GLOBALS['__test_posts'][ (int) $post_id ] ?? null; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
}
if ( ! function_exists( 'parse_blocks' ) ) {
	// INPUT-AWARE: keyed on the post_content string the handler passes in, so a
	// handler that fails to read $post->post_content (or reads the wrong field)
	// gets an empty tree and writes no rows: a real divergence, not a free pass.
	function parse_blocks( $content ) {
		return $GLOBALS['__test_blocks'][ $content ] ?? array();
	}
}
// Cron re-arm: record-only. Each call appends a [ event, args, ts? ] tuple.
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		$GLOBALS['__test_cleared'][] = array( 'hook' => $hook, 'args' => $args );
		return 0;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		$GLOBALS['__test_scheduled'][] = array( 'ts' => $timestamp, 'hook' => $hook, 'args' => $args );
		return true;
	}
}

$GLOBALS['__test_options']   = array();
$GLOBALS['__test_dbdelta']   = array();
$GLOBALS['__test_blocks']    = array();
$GLOBALS['__test_cleared']   = array();
$GLOBALS['__test_scheduled'] = array();

/**
 * In-memory wpdb stub: the SAME genuine row store as tests/schedule-engine.php.
 * insert/update/get_row/get_results/get_var/query parse the engine's exact
 * query shapes, so the real accessors operate on real rows and a divergent
 * WHERE / row shape FAILS a test rather than passing on a canned value.
 */
class SS_Stub_wpdb {
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
		} elseif ( preg_match( "/WHERE\s+schedule_id\s*=\s*'([^']*)'\s+AND\s+target_ref\s*=\s*'([^']*)'/i", $query, $cm ) ) {
			// Composite upsert lookup: schedule_id AND target_ref. Matched before
			// the schedule_id-only branch so the same schedule_id on two posts
			// resolves to two distinct rows (the cross-post collision fix).
			$sid  = $cm[1];
			$ref  = $cm[2];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $sid, $ref ) {
				return (string) $r['schedule_id'] === $sid
					&& (string) ( $r['target_ref'] ?? '' ) === $ref;
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
						return true;
					}
					if ( ! $has_not_in ) {
						return false;
					}
					return in_array( (string) $r['schedule_id'], $keep, true );
				}
			) );
			return $before - count( $this->rows[ $table ] );
		}

		return 0;
	}
}

$GLOBALS['wpdb'] = new SS_Stub_wpdb();
// Pretend the install already ran so maybe_install short-circuits on require.
$GLOBALS['__test_options']['sn_schedules_db_version'] = '1';

require_once __DIR__ . '/../inc/schedule-engine.php';
require_once __DIR__ . '/../inc/schedule-sync.php';

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

/**
 * Build a sn/scheduled block node in the real parse_blocks shape.
 */
function ss_block( $schedule_id, $from = '', $until = '', $inner = array() ) {
	return array(
		'blockName'   => 'signal-noise/scheduled',
		'attrs'       => array(
			'scheduleId' => $schedule_id,
			'from'       => $from,
			'until'      => $until,
		),
		'innerBlocks' => $inner,
		'innerHTML'   => '',
	);
}

/**
 * Build a generic (non-scheduled) wrapper block carrying inner blocks.
 */
function ss_wrap( $name, $inner ) {
	return array(
		'blockName'   => $name,
		'attrs'       => array(),
		'innerBlocks' => $inner,
		'innerHTML'   => '',
	);
}

/**
 * A minimal $post object: only the fields the handler reads (ID, post_content,
 * post_status). The handler is passed ($post_id, $post).
 */
function ss_post( $id, $content, $status = 'publish' ) {
	return (object) array(
		'ID'           => $id,
		'post_content' => $content,
		'post_status'  => $status,
	);
}

/** Reset all per-case recorders + guard flags. */
function ss_reset() {
	$GLOBALS['__test_blocks']    = array();
	$GLOBALS['__test_cleared']   = array();
	$GLOBALS['__test_scheduled'] = array();
	unset( $GLOBALS['__test_is_autosave'], $GLOBALS['__test_is_revision'], $GLOBALS['__test_can'] );
}

/** Rows in the schedules table for a given post id (target_ref linkage). */
function ss_rows_for( $post_id ) {
	$out = array();
	foreach ( sn_schedule_all() as $r ) {
		if ( (string) $r['target_ref'] === (string) $post_id && 'fragment' === $r['target_type'] ) {
			$out[] = $r;
		}
	}
	return $out;
}

$table = $GLOBALS['wpdb']->prefix . 'sn_schedules';

echo "schedule-sync: save_post mirror + cron re-arm\n\n";

// ─── Group: two blocks mirror into two rows ───────────────────────────
echo "Group: two scheduled blocks -> two rows\n";
ss_reset();
$GLOBALS['wpdb']->rows[ $table ] = array();
$content = 'POST-100-CONTENT';
$GLOBALS['__test_blocks'][ $content ] = array(
	ss_block( 'uuid-a', '2026-07-01T00:00:00', '2026-08-01T00:00:00' ),
	ss_block( 'uuid-b', '2026-09-01T12:30:00', '' ),
);
sn_schedule_sync_post( 100, ss_post( 100, $content ) );

$rows = ss_rows_for( 100 );
ok( count( $rows ) === 2, 'two blocks -> exactly two fragment rows for the post' );

$by_sid = array();
foreach ( $rows as $r ) {
	$by_sid[ $r['schedule_id'] ] = $r;
}
ok( isset( $by_sid['uuid-a'], $by_sid['uuid-b'] ), 'rows carry the right schedule_ids' );
ok( $by_sid['uuid-a']['target_ref'] === '100', 'target_ref is the post id as a string (delete_missing linkage)' );
ok( $by_sid['uuid-a']['target_type'] === 'fragment', 'target_type is fragment' );
ok( $by_sid['uuid-a']['action'] === 'reveal', 'action is reveal' );
ok( $by_sid['uuid-a']['status'] === 'queued', 'status is queued' );
ok( $by_sid['uuid-a']['starts_at'] === '2026-07-01 00:00:00', 'from normalized to MySQL UTC DATETIME' );
ok( $by_sid['uuid-a']['ends_at'] === '2026-08-01 00:00:00', 'until normalized to MySQL UTC DATETIME' );
ok( $by_sid['uuid-a']['purge_urls'] === wp_json_encode( array( get_permalink( 100 ) ) ), 'purge_urls is the JSON permalink array' );
ok( $by_sid['uuid-b']['starts_at'] === '2026-09-01 12:30:00', 'block with only from: starts_at set' );
ok( $by_sid['uuid-b']['ends_at'] === null, 'block with only from: ends_at is NULL' );

// Normalization sanity: the stored UTC string parses back to the same instant
// the gate would parse, proving sync + gate agree on the instant.
ok(
	strtotime( $by_sid['uuid-a']['starts_at'] . ' UTC' ) === strtotime( '2026-07-01T00:00:00 UTC' ),
	'normalized starts_at parses to the same UTC instant as the raw block value'
);

// ─── Group: idempotent re-save ────────────────────────────────────────
echo "\nGroup: idempotent re-save (same blocks)\n";
ss_reset();
$GLOBALS['__test_blocks'][ $content ] = array(
	ss_block( 'uuid-a', '2026-07-01T00:00:00', '2026-08-01T00:00:00' ),
	ss_block( 'uuid-b', '2026-09-01T12:30:00', '' ),
);
sn_schedule_sync_post( 100, ss_post( 100, $content ) );
ok( count( ss_rows_for( 100 ) ) === 2, 're-saving the same two blocks still yields exactly two rows (no dupes)' );

// ─── Group: remove a block -> its row + cron cleared ──────────────────
echo "\nGroup: remove a block -> delete_missing + cron cleared\n";
// Capture the row id of uuid-b BEFORE removal so we can assert it was cleared.
$rows_before = ss_rows_for( 100 );
$uuid_b_id   = null;
foreach ( $rows_before as $r ) {
	if ( 'uuid-b' === $r['schedule_id'] ) {
		$uuid_b_id = (int) $r['id'];
	}
}
ok( null !== $uuid_b_id, 'precondition: uuid-b row exists before removal' );

ss_reset();
$GLOBALS['__test_blocks'][ $content ] = array(
	ss_block( 'uuid-a', '2026-07-01T00:00:00', '2026-08-01T00:00:00' ),
);
sn_schedule_sync_post( 100, ss_post( 100, $content ) );

$rows_after = ss_rows_for( 100 );
$sids_after = array_map( function ( $r ) { return $r['schedule_id']; }, $rows_after );
ok( count( $rows_after ) === 1, 'after removing uuid-b: one row remains' );
ok( in_array( 'uuid-a', $sids_after, true ) && ! in_array( 'uuid-b', $sids_after, true ), 'uuid-a kept, uuid-b swept by delete_missing' );

// uuid-b's cron events must have been EXPLICITLY cleared by row id before the
// row was deleted (the handler reads the about-to-be-dropped rows, clears their
// hooks, then calls delete_missing). Assert wp_clear_scheduled_hook was called
// with array( uuid_b_id ).
$cleared_args = array_map( function ( $c ) { return $c['args']; }, $GLOBALS['__test_cleared'] );
ok( in_array( array( $uuid_b_id ), $cleared_args, true ), 'removed block: wp_clear_scheduled_hook called with the removed row id' );
// And the deleted row must NOT have been re-armed.
$rearmed = false;
foreach ( $GLOBALS['__test_scheduled'] as $s ) {
	if ( array( $uuid_b_id ) === $s['args'] ) {
		$rearmed = true;
	}
}
ok( ! $rearmed, 'removed block: its cron events are not re-armed' );

// ─── Group: nested scheduled block is found (recursive walk) ──────────
echo "\nGroup: nested scheduled block (recursive walk)\n";
ss_reset();
$GLOBALS['wpdb']->rows[ $table ] = array();
$nested = 'POST-200-NESTED';
$GLOBALS['__test_blocks'][ $nested ] = array(
	ss_wrap( 'core/group', array(
		ss_wrap( 'core/columns', array(
			ss_block( 'deep-1', '2026-10-01T00:00:00', '' ),
		) ),
	) ),
	ss_block( 'top-1', '', '2026-11-01T00:00:00' ),
);
sn_schedule_sync_post( 200, ss_post( 200, $nested ) );
$rows200 = ss_rows_for( 200 );
$sids200 = array_map( function ( $r ) { return $r['schedule_id']; }, $rows200 );
sort( $sids200 );
ok( count( $rows200 ) === 2, 'nested + top-level scheduled blocks -> two rows' );
ok( $sids200 === array( 'deep-1', 'top-1' ), 'the deeply-nested block was found by the recursive walk' );

// ─── Group: empty scheduleId is skipped ───────────────────────────────
echo "\nGroup: empty scheduleId is skipped (no anonymous row)\n";
ss_reset();
$GLOBALS['wpdb']->rows[ $table ] = array();
$mixed = 'POST-300-MIXED';
$GLOBALS['__test_blocks'][ $mixed ] = array(
	ss_block( '', '2026-07-01T00:00:00', '' ),         // uninitialized: skip
	ss_block( 'real-1', '2026-07-02T00:00:00', '' ),   // valid: keep
);
sn_schedule_sync_post( 300, ss_post( 300, $mixed ) );
$rows300 = ss_rows_for( 300 );
ok( count( $rows300 ) === 1, 'block with empty scheduleId produces no row' );
ok( $rows300[0]['schedule_id'] === 'real-1', 'only the initialized block is mirrored' );

// ─── Group: cron arms one event per non-null boundary ─────────────────
echo "\nGroup: cron events per non-null boundary\n";
ss_reset();
$GLOBALS['wpdb']->rows[ $table ] = array();
$cronc = 'POST-400-CRON';
$GLOBALS['__test_blocks'][ $cronc ] = array(
	ss_block( 'both', '2026-07-01T00:00:00', '2026-08-01T00:00:00' ), // 2 boundaries
	ss_block( 'startonly', '2026-09-01T00:00:00', '' ),               // 1 boundary
);
sn_schedule_sync_post( 400, ss_post( 400, $cronc ) );

$rows400  = ss_rows_for( 400 );
$id_both  = null;
$id_start = null;
foreach ( $rows400 as $r ) {
	if ( 'both' === $r['schedule_id'] ) {
		$id_both = (int) $r['id'];
	}
	if ( 'startonly' === $r['schedule_id'] ) {
		$id_start = (int) $r['id'];
	}
}

$sched = $GLOBALS['__test_scheduled'];
ok( count( $sched ) === 3, 'three events armed total (2 for both-boundary row + 1 for start-only)' );

// Every scheduled event must target the fire hook with array( row_id ).
$all_fire   = true;
$all_intarg = true;
foreach ( $sched as $s ) {
	if ( 'sn_schedule_fire' !== $s['hook'] ) {
		$all_fire = false;
	}
	if ( ! ( is_array( $s['args'] ) && count( $s['args'] ) === 1 && is_int( $s['args'][0] ) ) ) {
		$all_intarg = false;
	}
}
ok( $all_fire, 'every event uses the sn_schedule_fire hook' );
ok( $all_intarg, 'every event arg is array( (int) row_id )' );

// The both-boundary row arms exactly two events: at the from-instant and the
// until-instant, each with that row id.
$both_ts = array();
foreach ( $sched as $s ) {
	if ( array( $id_both ) === $s['args'] ) {
		$both_ts[] = $s['ts'];
	}
}
sort( $both_ts );
$expect_both = array( strtotime( '2026-07-01T00:00:00 UTC' ), strtotime( '2026-08-01T00:00:00 UTC' ) );
sort( $expect_both );
ok( $both_ts === $expect_both, 'both-boundary row: events at the from and until UTC instants' );

// The start-only row arms exactly ONE event, at its from-instant.
$start_ts = array();
foreach ( $sched as $s ) {
	if ( array( $id_start ) === $s['args'] ) {
		$start_ts[] = $s['ts'];
	}
}
ok( count( $start_ts ) === 1, 'start-only row arms exactly one event' );
ok( $start_ts[0] === strtotime( '2026-09-01T00:00:00 UTC' ), 'start-only event lands on the from UTC instant' );

// Re-arm clears the row's stale hook first (idempotent re-arm after a date edit).
$cleared_for_both = false;
foreach ( $GLOBALS['__test_cleared'] as $c ) {
	if ( 'sn_schedule_fire' === $c['hook'] && array( $id_both ) === $c['args'] ) {
		$cleared_for_both = true;
	}
}
ok( $cleared_for_both, 're-arm clears the row hook before scheduling (idempotent)' );

// ─── Group: guards (autosave / revision / missing-cap) ────────────────
echo "\nGroup: guards short-circuit (no rows, no events)\n";

// Autosave.
ss_reset();
$GLOBALS['wpdb']->rows[ $table ] = array();
$g = 'POST-500-GUARD';
$GLOBALS['__test_blocks'][ $g ] = array( ss_block( 'g-1', '2026-07-01T00:00:00', '' ) );
$GLOBALS['__test_is_autosave']  = true;
sn_schedule_sync_post( 500, ss_post( 500, $g ) );
ok( count( ss_rows_for( 500 ) ) === 0, 'autosave: no rows written' );
ok( count( $GLOBALS['__test_scheduled'] ) === 0, 'autosave: no events scheduled' );

// Revision.
ss_reset();
$GLOBALS['__test_blocks'][ $g ] = array( ss_block( 'g-1', '2026-07-01T00:00:00', '' ) );
$GLOBALS['__test_is_revision']  = true;
sn_schedule_sync_post( 500, ss_post( 500, $g ) );
ok( count( ss_rows_for( 500 ) ) === 0, 'revision: no rows written' );
ok( count( $GLOBALS['__test_scheduled'] ) === 0, 'revision: no events scheduled' );

// Missing capability.
ss_reset();
$GLOBALS['__test_blocks'][ $g ] = array( ss_block( 'g-1', '2026-07-01T00:00:00', '' ) );
$GLOBALS['__test_can']          = false;
sn_schedule_sync_post( 500, ss_post( 500, $g ) );
ok( count( ss_rows_for( 500 ) ) === 0, 'missing cap: no rows written' );
ok( count( $GLOBALS['__test_scheduled'] ) === 0, 'missing cap: no events scheduled' );

// auto-draft status bail (no sense mirroring an empty placeholder draft).
ss_reset();
$GLOBALS['__test_blocks'][ $g ] = array( ss_block( 'g-1', '2026-07-01T00:00:00', '' ) );
sn_schedule_sync_post( 500, ss_post( 500, $g, 'auto-draft' ) );
ok( count( ss_rows_for( 500 ) ) === 0, 'auto-draft status: no rows written' );

// trash status bail.
ss_reset();
$GLOBALS['__test_blocks'][ $g ] = array( ss_block( 'g-1', '2026-07-01T00:00:00', '' ) );
sn_schedule_sync_post( 500, ss_post( 500, $g, 'trash' ) );
ok( count( ss_rows_for( 500 ) ) === 0, 'trash status: no rows written' );

// ─── Group: cross-post scheduleId collision (composite-key regression) ─
// The headline regression guard. A Scheduled block COPIED into a second post
// carries the SAME scheduleId. Each post's save_post upsert must produce its
// OWN row (keyed on schedule_id + target_ref), so post A is NOT clobbered by
// post B's save: each row keeps its own target_ref + purge_urls (its surgical
// purge URL list).
echo "\nGroup: cross-post same-scheduleId collision -> two rows, no clobber\n";
ss_reset();
$GLOBALS['wpdb']->rows[ $table ] = array();

// Post 600 saves a block with scheduleId 'dup'.
$content_a = 'POST-600-CONTENT';
$GLOBALS['__test_blocks'][ $content_a ] = array(
	ss_block( 'dup', '2026-07-01T00:00:00', '2026-08-01T00:00:00' ),
);
sn_schedule_sync_post( 600, ss_post( 600, $content_a ) );

// Post 601 (a DIFFERENT post) saves a block with the SAME scheduleId 'dup'
// (the block was copied across). Its permalink differs, so its purge_urls
// differ too.
$content_b = 'POST-601-CONTENT';
$GLOBALS['__test_blocks'][ $content_b ] = array(
	ss_block( 'dup', '2026-09-01T00:00:00', '2026-10-01T00:00:00' ),
);
sn_schedule_sync_post( 601, ss_post( 601, $content_b ) );

$rows_a = ss_rows_for( 600 );
$rows_b = ss_rows_for( 601 );
ok( count( $rows_a ) === 1, 'collision: post 600 still has its own one row after post 601 saved' );
ok( count( $rows_b ) === 1, 'collision: post 601 has its own one row' );
ok( count( sn_schedule_all() ) === 2, 'collision: TWO distinct rows total for the shared scheduleId (one per post)' );

$row_a = $rows_a[0];
$row_b = $rows_b[0];
ok( (int) $row_a['id'] !== (int) $row_b['id'], 'collision: the two posts have DISTINCT row ids' );
ok( $row_a['target_ref'] === '600', 'collision: post 600 row keeps target_ref 600 (NOT clobbered to 601)' );
ok( $row_b['target_ref'] === '601', 'collision: post 601 row carries target_ref 601' );
ok( $row_a['purge_urls'] === wp_json_encode( array( get_permalink( 600 ) ) ), 'collision: post 600 keeps its OWN purge_urls (surgical purge intact)' );
ok( $row_b['purge_urls'] === wp_json_encode( array( get_permalink( 601 ) ) ), 'collision: post 601 carries its own purge_urls' );
// Post 600 retains its own window boundaries, not post 601's.
ok( $row_a['starts_at'] === '2026-07-01 00:00:00' && $row_a['ends_at'] === '2026-08-01 00:00:00', 'collision: post 600 keeps its own window boundaries' );

// ─── Group: orphan cleanup on permanent delete (before_delete_post) ───
// A permanently-deleted post must leave NO orphaned schedule rows or armed
// cron events. The before_delete_post handler clears every fragment row's cron
// (by row id) then deletes the rows.
echo "\nGroup: before_delete_post orphan cleanup\n";
ss_reset();
$GLOBALS['wpdb']->rows[ $table ] = array();

// Post 900 with N=3 fragment rows (two windowed, one boundary-less).
$content_d = 'POST-900-CONTENT';
$GLOBALS['__test_blocks'][ $content_d ] = array(
	ss_block( 'd-1', '2026-07-01T00:00:00', '2026-08-01T00:00:00' ),
	ss_block( 'd-2', '2026-09-01T00:00:00', '' ),
	ss_block( 'd-3', '', '2026-10-01T00:00:00' ),
);
sn_schedule_sync_post( 900, ss_post( 900, $content_d ) );

// An unrelated post 901 row that must survive the delete.
$content_e = 'POST-901-CONTENT';
$GLOBALS['__test_blocks'][ $content_e ] = array(
	ss_block( 'e-1', '2026-07-01T00:00:00', '' ),
);
sn_schedule_sync_post( 901, ss_post( 901, $content_e ) );

$rows_900 = ss_rows_for( 900 );
ok( count( $rows_900 ) === 3, 'orphan precondition: post 900 has 3 fragment rows' );
$ids_900 = array_map( function ( $r ) { return (int) $r['id']; }, $rows_900 );
sort( $ids_900 );

// Reset recorders so we observe ONLY the delete handler's cron clears.
$GLOBALS['__test_cleared']   = array();
$GLOBALS['__test_scheduled'] = array();

sn_schedule_before_delete_post( 900 );

// All 3 of post 900's rows are gone; post 901 survives.
ok( count( ss_rows_for( 900 ) ) === 0, 'orphan: all 3 fragment rows for the deleted post are gone' );
ok( count( ss_rows_for( 901 ) ) === 1, 'orphan: the unrelated post 901 row is untouched' );

// Each of post 900's row ids must have had its cron explicitly cleared.
$cleared_ids = array();
foreach ( $GLOBALS['__test_cleared'] as $c ) {
	if ( 'sn_schedule_fire' === $c['hook'] && is_array( $c['args'] ) && isset( $c['args'][0] ) ) {
		$cleared_ids[] = (int) $c['args'][0];
	}
}
$cleared_ids = array_values( array_unique( $cleared_ids ) );
sort( $cleared_ids );
ok( $cleared_ids === $ids_900, 'orphan: wp_clear_scheduled_hook called with EACH of the deleted rows ids' );

// Falsify-guard: post 901's row id must NOT have been cleared.
$row_901_id = (int) ss_rows_for( 901 )[0]['id'];
ok( ! in_array( $row_901_id, $cleared_ids, true ), 'orphan: the surviving post 901 row id was NOT cleared' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

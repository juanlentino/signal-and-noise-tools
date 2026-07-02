<?php
/**
 * Standalone fixture tests for inc/schedule-swap.php — whole-page version
 * swaps as a first-class operation (scheduled-content Phase 3, v8.0.0).
 *
 * A "version swap" is the two-container pattern made operational: two
 * sn/scheduled fragments on the same host post whose boundaries meet at one
 * instant T (old container `until` = T, new container `from` = T). Three
 * guarantees under test:
 *
 *   1. PAIRING (pure): sn_schedule_swap_pairs() derives swap pairs from
 *      existing queue rows by boundary equality on the same target — no new
 *      DB column, works for pre-v8 content authored by hand.
 *   2. ONE BOUNDARY PURGE: the per-request purge memo in
 *      sn_schedule_purge_urls collapses the second same-URL-set dispatch, so
 *      firing both sides of a swap costs exactly ONE Cloudflare call. Correct
 *      because the render gate (sn_schedule_is_open) is pure time: once T has
 *      passed, a single edge refetch sees both flips.
 *   3. ATOMIC RUN: sn_schedule_swap_run() drives BOTH rows through the REAL
 *      fire state machine (hide -> done, show -> active) against the same
 *      in-memory $wpdb row store the engine tests use, with the CF seam
 *      stubbed INPUT-AWARE at the real boundary (sn_cf_purge_urls /
 *      sn_cf_purge_everything) per the stub-at-the-bug-boundary lesson.
 *
 * Run: php tests/schedule-swap.php
 *
 * @since plugin v8.0.0
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
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

// ─── WP function stubs (engine + cache load-time deps) ─────────────────
if ( ! function_exists( 'add_action' ) )    { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'add_filter' ) )    { function add_filter( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v, ...$a ) { return $v; } }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__test_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = false ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'dbDelta' ) )       { function dbDelta( $sql ) { return array(); } }
if ( ! function_exists( '__' ) )            { function __( $t, $d = null ) { return $t; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }

// Stubbable UTC "now".
$GLOBALS['__now'] = 0;
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) { return $GLOBALS['__now']; }
}

// Host-post stubs for sn_schedule_fire_purge (union + escalation logic).
// Post 42 is an ordinary post; post 99 hosts a reused container (wp_block).
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) { return 99 === (int) $id ? 'wp_block' : 'post'; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return 'https://example.test/?p=' . (int) $id; }
}

// ─── INPUT-AWARE Cloudflare seam stubs (the REAL boundary) ─────────────
$GLOBALS['__cf_configured'] = true;
$GLOBALS['__cf_url_calls']  = array(); // list of exact $urls arrays dispatched.
$GLOBALS['__cf_zone_calls'] = 0;
if ( ! function_exists( 'sn_cf_is_configured' ) ) {
	function sn_cf_is_configured() { return ! empty( $GLOBALS['__cf_configured'] ); }
}
if ( ! function_exists( 'sn_cf_purge_urls' ) ) {
	function sn_cf_purge_urls( array $urls ) {
		$GLOBALS['__cf_url_calls'][] = $urls;
		return true;
	}
}
if ( ! function_exists( 'sn_cf_purge_everything' ) ) {
	function sn_cf_purge_everything() {
		$GLOBALS['__cf_zone_calls']++;
		return true;
	}
}

// Record-only cron stubs (fire() itself never arms, but keep parity).
if ( ! function_exists( 'wp_next_scheduled' ) )       { function wp_next_scheduled( $h, $a = array() ) { return false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $ts, $h, $a = array() ) { return true; } }
if ( ! function_exists( 'wp_schedule_event' ) )        { function wp_schedule_event( $ts, $r, $h ) { return true; } }

$GLOBALS['__test_options'] = array();

/**
 * In-memory wpdb stub — same genuine row store the engine/fire fixtures use
 * (insert auto-ids; update mutates on full WHERE match; get_row/get_results/
 * get_var read back; query handles the DELETE shapes).
 */
class SW_Stub_wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $rows       = array();
	private $auto_id   = 1;

	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? '';
			++$i;
			switch ( $m[0] ) {
				case '%d': return (string) (int) $a;
				case '%f': return (string) (float) $a;
				default:   return "'" . addslashes( (string) $a ) . "'";
			}
		}, $query );
	}

	public function insert( $table, $row, $formats = null ) {
		if ( ! isset( $this->rows[ $table ] ) ) { $this->rows[ $table ] = array(); }
		$id = $this->auto_id++;
		$this->rows[ $table ][] = array_merge( array( 'id' => $id ), $row );
		$this->insert_id = $id;
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( ! isset( $this->rows[ $table ] ) ) { return 0; }
		$n = 0;
		foreach ( $this->rows[ $table ] as &$r ) {
			$match = true;
			foreach ( $where as $col => $val ) {
				if ( ! isset( $r[ $col ] ) || (string) $r[ $col ] !== (string) $val ) { $match = false; break; }
			}
			if ( $match ) {
				foreach ( $data as $col => $val ) { $r[ $col ] = $val; }
				++$n;
			}
		}
		unset( $r );
		return $n;
	}

	public function get_row( $query, $output = OBJECT ) {
		$rows = $this->select_rows( $query );
		if ( empty( $rows ) ) { return null; }
		return ARRAY_A === $output ? $rows[0] : (object) $rows[0];
	}

	public function get_results( $query, $output = OBJECT ) {
		$rows = $this->select_rows( $query );
		return ARRAY_A === $output ? $rows : array_map( function ( $r ) { return (object) $r; }, $rows );
	}

	public function get_var( $query ) {
		$rows = $this->select_rows( $query );
		if ( empty( $rows ) ) { return null; }
		$first = $rows[0];
		return isset( $first['id'] ) ? $first['id'] : reset( $first );
	}

	private function select_rows( $query ) {
		if ( ! preg_match( '/FROM\s+(\S+)/i', $query, $tm ) ) { return array(); }
		$rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
		if ( preg_match( '/WHERE\s+id\s*=\s*(\d+)/i', $query, $im ) ) {
			$id   = (int) $im[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $id ) { return (int) $r['id'] === $id; } ) );
		} elseif ( preg_match( "/WHERE\s+schedule_id\s*=\s*'([^']*)'/i", $query, $sm ) ) {
			$sid  = $sm[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $sid ) { return (string) $r['schedule_id'] === $sid; } ) );
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
		if ( ! preg_match( '/DELETE FROM\s+(\S+)/i', $query, $tm ) ) { return false; }
		if ( ! isset( $this->rows[ $tm[1] ] ) ) { return 0; }
		$before = count( $this->rows[ $tm[1] ] );
		if ( preg_match( '/WHERE\s+id\s*=\s*(\d+)/i', $query, $im ) ) {
			$id = (int) $im[1];
			$this->rows[ $tm[1] ] = array_values( array_filter( $this->rows[ $tm[1] ], function ( $r ) use ( $id ) { return (int) $r['id'] !== $id; } ) );
			return $before - count( $this->rows[ $tm[1] ] );
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new SW_Stub_wpdb();
$GLOBALS['__test_options']['sn_schedules_db_version'] = '1';

// ─── Load the SUTs ─────────────────────────────────────────────────────
require_once __DIR__ . '/../inc/schedule-engine.php';
require_once __DIR__ . '/../inc/schedule-cache.php';
$swap_module = __DIR__ . '/../inc/schedule-swap.php';
if ( file_exists( $swap_module ) ) {
	require_once $swap_module;
}

// ─── Harness ───────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { ++$pass; echo "PASS: $msg\n"; }
	else { ++$fail; echo "FAIL: $msg\n"; }
}
function eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) { ++$pass; echo "PASS: $msg\n"; }
	else { ++$fail; echo "FAIL: $msg\n  Expected: " . var_export( $expected, true ) . "\n  Actual:   " . var_export( $actual, true ) . "\n"; }
}

$T  = '2026-07-20 10:00:00';
$T2 = '2026-08-01 08:00:00';

function sw_row( $id, $target, $starts, $ends, $extra = array() ) {
	return array_merge( array(
		'id'          => $id,
		'schedule_id' => 'sid-' . $id,
		'target_type' => 'fragment',
		'target_ref'  => (string) $target,
		'action'      => 'reveal',
		'starts_at'   => $starts,
		'ends_at'     => $ends,
		'purge_urls'  => json_encode( array( 'https://example.test/?p=' . $target ) ),
		'status'      => 'queued',
	), $extra );
}

// ════ Group A: pair derivation (pure) ═══════════════════════════════════
echo "Group A: sn_schedule_swap_pairs derivation\n";
if ( function_exists( 'sn_schedule_swap_pairs' ) ) {
	// A.1 the canonical pair: hide.ends_at === show.starts_at, same target.
	$pairs = sn_schedule_swap_pairs( array(
		sw_row( 1, 42, null, $T ),   // old version: visible until T
		sw_row( 2, 42, $T, null ),   // new version: visible from T
	) );
	eq( 1, count( $pairs ), 'A.1 one pair detected' );
	eq( $T, $pairs[0]['swap_at'] ?? null, 'A.1b swap_at is the shared boundary' );
	eq( 1, (int) ( $pairs[0]['hide']['id'] ?? 0 ), 'A.1c hide side is the until-T row' );
	eq( 2, (int) ( $pairs[0]['show']['id'] ?? 0 ), 'A.1d show side is the from-T row' );

	// A.2 a chain of three containers = two consecutive swaps, ordered by time.
	$pairs = sn_schedule_swap_pairs( array(
		sw_row( 1, 42, null, $T ),
		sw_row( 2, 42, $T, $T2 ),
		sw_row( 3, 42, $T2, null ),
	) );
	eq( 2, count( $pairs ), 'A.2 chain of 3 → 2 pairs' );
	eq( $T, $pairs[0]['swap_at'] ?? null, 'A.2b first pair at T' );
	eq( $T2, $pairs[1]['swap_at'] ?? null, 'A.2c second pair at T2' );

	// A.3 same boundary on DIFFERENT targets never pairs.
	$pairs = sn_schedule_swap_pairs( array(
		sw_row( 1, 42, null, $T ),
		sw_row( 2, 43, $T, null ),
	) );
	eq( 0, count( $pairs ), 'A.3 cross-target rows never pair' );

	// A.4 unbounded boundaries never pair (null/empty is not an instant).
	$pairs = sn_schedule_swap_pairs( array(
		sw_row( 1, 42, null, null ),
		sw_row( 2, 42, null, null ),
		sw_row( 3, 42, '', '' ),
	) );
	eq( 0, count( $pairs ), 'A.4 null/empty boundaries never pair' );

	// A.5 boundaries that do not meet exactly never pair.
	$pairs = sn_schedule_swap_pairs( array(
		sw_row( 1, 42, null, $T ),
		sw_row( 2, 42, $T2, null ),
	) );
	eq( 0, count( $pairs ), 'A.5 unequal boundaries never pair' );

	// A.6 non-fragment rows are ignored.
	$pairs = sn_schedule_swap_pairs( array(
		sw_row( 1, 42, null, $T, array( 'target_type' => 'page' ) ),
		sw_row( 2, 42, $T, null ),
	) );
	eq( 0, count( $pairs ), 'A.6 non-fragment rows ignored' );

	// A.7 a row never pairs with itself (from T until T on one row).
	$pairs = sn_schedule_swap_pairs( array(
		sw_row( 1, 42, $T, $T ),
	) );
	eq( 0, count( $pairs ), 'A.7 a row never pairs with itself' );
} else {
	for ( $i = 0; $i < 11; $i++ ) { ok( false, 'A.* sn_schedule_swap_pairs() exists' ); }
}

// ════ Group B: per-request purge memo (one boundary = one CF call) ═══════
echo "\nGroup B: purge memo coalescing\n";
if ( function_exists( 'sn_schedule_purge_memo_reset' ) ) {
	sn_schedule_purge_memo_reset();
	$GLOBALS['__cf_url_calls'] = array();

	$urls = array( 'https://example.test/?p=42' );
	ok( true === sn_schedule_purge_urls( $urls ), 'B.1 first dispatch returns true' );
	ok( true === sn_schedule_purge_urls( $urls ), 'B.1b second same-set call still returns true (memo hit)' );
	eq( 1, count( $GLOBALS['__cf_url_calls'] ), 'B.1c exactly ONE CF dispatch for the repeated set' );
	eq( $urls, $GLOBALS['__cf_url_calls'][0], 'B.1d the dispatched URLs are exactly the input set' );

	// B.2 a different URL set dispatches again.
	sn_schedule_purge_urls( array( 'https://example.test/?p=43' ) );
	eq( 2, count( $GLOBALS['__cf_url_calls'] ), 'B.2 a different set dispatches a second CF call' );

	// B.3 order-insensitive key: [a,b] then [b,a] coalesce.
	sn_schedule_purge_memo_reset();
	$GLOBALS['__cf_url_calls'] = array();
	sn_schedule_purge_urls( array( 'https://a.test/', 'https://b.test/' ) );
	sn_schedule_purge_urls( array( 'https://b.test/', 'https://a.test/' ) );
	eq( 1, count( $GLOBALS['__cf_url_calls'] ), 'B.3 URL order does not defeat the memo' );

	// B.4 a FAILED (unconfigured) attempt must not poison the memo.
	sn_schedule_purge_memo_reset();
	$GLOBALS['__cf_url_calls']  = array();
	$GLOBALS['__cf_configured'] = false;
	ok( false === sn_schedule_purge_urls( $urls ), 'B.4 unconfigured returns false' );
	$GLOBALS['__cf_configured'] = true;
	ok( true === sn_schedule_purge_urls( $urls ), 'B.4b retry after configuring dispatches' );
	eq( 1, count( $GLOBALS['__cf_url_calls'] ), 'B.4c the failed attempt did not mark the memo' );

	// B.5 reset clears the memo.
	sn_schedule_purge_memo_reset();
	sn_schedule_purge_urls( $urls );
	eq( 2, count( $GLOBALS['__cf_url_calls'] ), 'B.5 reset clears the memo (dispatches again)' );
} else {
	for ( $i = 0; $i < 9; $i++ ) { ok( false, 'B.* sn_schedule_purge_memo_reset() exists' ); }
}

// ════ Group C: atomic swap run through the REAL fire state machine ═══════
echo "\nGroup C: sn_schedule_swap_run\n";
if ( function_exists( 'sn_schedule_swap_run' ) && function_exists( 'sn_schedule_purge_memo_reset' ) ) {
	// Seed the pair through the REAL upsert (row ids 1 + 2 in the fresh store).
	sn_schedule_upsert( sw_row( 0, 42, null, $T ) );
	$hide_id = (int) $GLOBALS['wpdb']->insert_id;
	sn_schedule_upsert( sw_row( 0, 42, $T, null, array( 'schedule_id' => 'sid-show' ) ) );
	$show_id = (int) $GLOBALS['wpdb']->insert_id;

	// Now is 60s past the swap instant; both boundaries are due.
	$GLOBALS['__now'] = strtotime( $T . ' UTC' ) + 60;
	sn_schedule_purge_memo_reset();
	$GLOBALS['__cf_url_calls'] = array();

	ok( true === sn_schedule_swap_run( $hide_id, $show_id ), 'C.1 swap run returns true' );
	eq( 1, count( $GLOBALS['__cf_url_calls'] ), 'C.2 the whole swap costs exactly ONE CF dispatch' );
	eq( array( 'https://example.test/?p=42' ), $GLOBALS['__cf_url_calls'][0], 'C.2b the dispatched set is the host URL (snapshot ∪ permalink deduped)' );

	$hide_row = sn_schedule_get( $hide_id );
	$show_row = sn_schedule_get( $show_id );
	eq( 'done', (string) ( $hide_row['status'] ?? '' ), 'C.3 hide row advanced to done' );
	eq( 'active', (string) ( $show_row['status'] ?? '' ), 'C.4 show row advanced to active' );

	// C.5 invalid ids are a safe no-op.
	$GLOBALS['__cf_url_calls'] = array();
	ok( false === sn_schedule_swap_run( 0, $show_id ), 'C.5 invalid hide id → false' );
	ok( false === sn_schedule_swap_run( $hide_id, 999 ), 'C.5b missing show row → false' );
	eq( 0, count( $GLOBALS['__cf_url_calls'] ), 'C.5c no dispatch on invalid input' );

	// C.6 rows that are NOT a pair are refused (no fire, no purge).
	sn_schedule_upsert( sw_row( 0, 42, $T2, null, array( 'schedule_id' => 'sid-odd' ) ) );
	$odd_id = (int) $GLOBALS['wpdb']->insert_id;
	$GLOBALS['__cf_url_calls'] = array();
	ok( false === sn_schedule_swap_run( $hide_id, $odd_id ), 'C.6 non-pair rows refused' );
	eq( 0, count( $GLOBALS['__cf_url_calls'] ), 'C.6b no dispatch for a refused swap' );
} else {
	for ( $i = 0; $i < 10; $i++ ) { ok( false, 'C.* sn_schedule_swap_run() exists' ); }
}

// ════ Group D: reused-container swap coalesces the ZONE purge too ════════
echo "\nGroup D: zone-purge coalescing\n";
if ( function_exists( 'sn_schedule_swap_run' ) && function_exists( 'sn_schedule_purge_memo_reset' ) ) {
	// Post 99 stubs as wp_block → fire_purge escalates to purge_everything.
	sn_schedule_upsert( sw_row( 0, 99, null, $T, array( 'schedule_id' => 'sid-z-hide' ) ) );
	$zh = (int) $GLOBALS['wpdb']->insert_id;
	sn_schedule_upsert( sw_row( 0, 99, $T, null, array( 'schedule_id' => 'sid-z-show' ) ) );
	$zs = (int) $GLOBALS['wpdb']->insert_id;

	$GLOBALS['__now'] = strtotime( $T . ' UTC' ) + 60;
	sn_schedule_purge_memo_reset();
	$GLOBALS['__cf_zone_calls'] = 0;

	ok( true === sn_schedule_swap_run( $zh, $zs ), 'D.1 reused-container swap runs' );
	eq( 1, $GLOBALS['__cf_zone_calls'], 'D.2 exactly ONE zone purge for the whole swap' );
	eq( 'done', (string) ( sn_schedule_get( $zh )['status'] ?? '' ), 'D.3 hide side done' );
	eq( 'active', (string) ( sn_schedule_get( $zs )['status'] ?? '' ), 'D.4 show side active' );
} else {
	for ( $i = 0; $i < 4; $i++ ) { ok( false, 'D.* swap module exists' ); }
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

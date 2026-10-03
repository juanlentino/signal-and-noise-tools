<?php
/**
 * A re-roll REPLACES the days it read (2026-09-27: a recompute that newly
 * excluded an over-cap visitor left their paths stored, 139 human views against
 * ~62 in AE). A complete read deletes the window's stored days before it writes;
 * a failed or truncated read deletes nothing; days outside the window and rows
 * outside a writer's scope (another dim, another role) are untouched.
 * Real modules, a row-keeping fake $wpdb; only WP and the AE transport are stubbed.
 * Run: php tests/analytics-rollup-replace.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/tmp/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'ARRAY_A', 'ARRAY_A' );
define( 'SN_CF_ANALYTICS_TOKEN', 't' ); define( 'SN_CF_ACCOUNT_ID', 'a' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; $c ? ++$pass : ++$fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; }

$GLOBALS['o'] = array(); $GLOBALS['t'] = array();
function add_action() {} function __( $s ) { return $s; } function esc_html__( $s ) { return $s; }
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['o'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['t'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t'][ $k ] ); return true; }
function wp_schedule_single_event() { return true; } function wp_clear_scheduled_hook() {} function wp_next_scheduled() { return false; }
function home_url() { return 'https://example.test/'; } function wp_parse_url( $u, $c ) { return parse_url( $u, $c ); }
function is_wp_error() { return false; } function wp_timezone_string() { return 'America/New_York'; }
function wp_remote_retrieve_response_code( $r ) { return 200; } function wp_remote_retrieve_body( $r ) { return $r['body']; }

// AE: $GLOBALS['ae'] maps an SQL needle to [rows, truncated?] or null (failed read).
function wp_remote_post( $url, $args ) {
	$sql = $args['body'];
	if ( false !== strpos( $sql, sn_analytics_overcap_sql() ) ) {
		return array( 'body' => json_encode( array( 'data' => array(), 'rows' => 0 ) ) );
	}
	foreach ( $GLOBALS['ae'] as $needle => $res ) {
		if ( false !== strpos( $sql, $needle ) ) {
			if ( null === $res ) { return array( 'body' => '{}' ); }
			return array( 'body' => json_encode( array( 'data' => $res[0], 'rows' => $res[1] ? 10000 : count( $res[0] ), 'rows_before_limit_at_least' => $res[1] ? 20000 : count( $res[0] ) ) ) );
		}
	}
	return array( 'body' => json_encode( array( 'data' => array(), 'rows' => 0 ) ) );
}

// A $wpdb that KEEPS rows: table => list of [day, key, scope-values...] as the INSERT bound them.
class Wpdb { public $prefix = 'wp_'; public $last_error = ''; public $rows = array(); public $log = array();
	function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } $i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ] ?? ''; return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'"; }, $q ); }
	public $snap = null; public $fail_insert = 0; public $inserts = 0;
	function query( $q ) {
		$this->log[] = $q;
		$this->last_error = ''; // real wpdb flushes it at the start of every query
		if ( 'START TRANSACTION' === $q ) { $this->snap = $this->rows; return 1; }
		if ( 'ROLLBACK' === $q ) { $this->rows = $this->snap; return 1; }
		if ( 0 === strpos( $q, 'INSERT' ) && ++$this->inserts === $this->fail_insert ) { $this->last_error = 'Lock wait timeout exceeded'; return false; }
		if ( preg_match( '/^INSERT INTO (\w+) \(([^)]*)\) VALUES (.*?)( ON DUPLICATE|$)/s', $q, $m ) ) {
			$cols = array_map( 'trim', explode( ',', $m[2] ) );
			preg_match_all( '/\(([^()]*)\)/', $m[3], $tuples );
			foreach ( $tuples[1] as $t ) {
				$vals = array_map( function ( $v ) { return trim( trim( $v ), "'" ); }, str_getcsv( $t, ',', "'", '\\' ) );
				$row  = array_combine( array_slice( $cols, 0, count( $vals ) ), $vals );
				$this->rows[ $m[1] ] = array_values( array_filter( $this->rows[ $m[1] ] ?? array(), function ( $r ) use ( $row ) { return ! $this->same( $r, $row ); } ) );
				$this->rows[ $m[1] ][] = $row;
			}
			return count( $tuples[1] );
		}
		if ( preg_match( "/^DELETE FROM (\w+) WHERE day (?:IN \(([^)]*)\)|= '([^']*)')(.*)$/", $q, $m ) ) {
			$days = '' !== $m[2] ? array_map( function ( $d ) { return trim( $d, " '" ); }, explode( ',', $m[2] ) ) : array( $m[3] );
			preg_match_all( "/AND (\w+) (?:IN \(([^)]*)\)|= ('[^']*'))/", $m[4], $sc, PREG_SET_ORDER );
			$sc = array_map( function ( $x ) { return array( 1 => $x[1], 2 => '' !== $x[2] ? $x[2] : $x[3] ); }, $sc );
			$before = count( $this->rows[ $m[1] ] ?? array() );
			$this->rows[ $m[1] ] = array_values( array_filter( $this->rows[ $m[1] ] ?? array(), function ( $r ) use ( $days, $sc ) {
				if ( ! in_array( $r['day'], $days, true ) ) { return true; }
				foreach ( $sc as $s ) { if ( ! in_array( "'" . ( $r[ $s[1] ] ?? '' ) . "'", array_map( 'trim', explode( ',', $s[2] ) ), true ) ) { return true; } }
				return false;
			} ) );
			return $before - count( $this->rows[ $m[1] ] );
		}
		return 1;
	}
	// Same unique key: every column but the measures.
	function same( $a, $b ) { foreach ( array( 'day', 'path', 'class', 'dim', 'value', 'metric', 'bucket', 'role', 'name', 'property', 'sig' ) as $k ) { if ( ( $a[ $k ] ?? null ) !== ( $b[ $k ] ?? null ) ) { return false; } } return true; }
	function has( $table, $day, $col, $val ) { foreach ( $this->rows[ 'wp_' . $table ] ?? array() as $r ) { if ( $r['day'] === $day && ( $r[ $col ] ?? null ) === $val ) { return true; } } return false; }
	function get_var() { return 0; } function get_results() { return array(); } function esc_like( $s ) { return $s; } function get_charset_collate() { return ''; } }
$GLOBALS['wpdb'] = new Wpdb();

function sn_analytics_fetch_session_events( $from, $to, $class ) { return array( 'summaries' => array( array( 'exit' => '/fresh-exit/' ) ), 'visits' => array(), 'capped' => $GLOBALS['capped'], 'configured' => true ); }
function sn_session_metrics() { return array( 'visits' => 0, 'bounce_rate' => 0, 'pages_per_visit' => 0, 'median_duration' => 0 ); }
foreach ( array( 'session-rollup', 'api', 'human-rule', 'rollup', 'dims', 'utm', 'buckets', 'pageroles', 'events', 'events-rollup' ) as $f ) {
	require_once dirname( __DIR__ ) . "/inc/analytics-{$f}.php";
}
$ny   = new DateTimeZone( 'America/New_York' );
$day  = function ( $ago, $zone = null ) use ( $ny ) { return ( new DateTimeImmutable( 'now', $zone ?? $ny ) )->modify( "-{$ago} days" )->format( 'Y-m-d' ); };
$D    = $day( 2 ); // inside the nightly 7-day window
$OLD  = $day( 9 ); // outside it
$pv   = function ( $d, $path ) { return array( 'day' => $d, 'path' => $path, 'class' => 'human', 'views' => 5, 'visits' => 2, 'scroll_avg' => 0, 'time_avg' => 0, 'scroll_sum' => 0, 'scroll_events' => 0, 'time_sum' => 0, 'time_events' => 0 ); };
$seed = function () use ( $pv, $D, $OLD ) {
	$GLOBALS['wpdb']->rows = array();
	sn_analytics_rollup_upsert( array( $pv( $D, '/kept/' ), $pv( $D, '/about/uses/' ), $pv( $OLD, '/old/' ) ) );
	$GLOBALS['wpdb']->log = array();
};
$main = "sumIf(_sample_interval, blob1 = 'pv') AS views";

// ── Window days: exactly the days the floored SQL window reads. ──
sn_analytics_rollup_window( false );
$w = sn_analytics_rollup_window_days( 'America/New_York' );
// The window reads its first day at now+300 s and its last at now-300 s (the
// edge skew in sn_analytics_rollup_window_days), so expect the same: within
// five minutes of New York midnight the plain $day() straddles the boundary.
$at  = static function ( $offset, $ago ) use ( $ny ) { return ( new DateTimeImmutable( '@' . ( time() + $offset ) ) )->setTimezone( $ny )->modify( "-{$ago} days" )->format( 'Y-m-d' ); };
$len = (int) round( ( strtotime( $at( -300, 0 ) . ' 12:00 UTC' ) - strtotime( $at( 300, 7 ) . ' 12:00 UTC' ) ) / 86400 ) + 1;
ok( $at( 300, 7 ) === $w[0] && $at( -300, 0 ) === end( $w ) && $len === count( $w ) && count( $w ) >= 7, 'nightly window: 7 days ago (the floored lower bound) through today, ' . count( $w ) . ' days' );
sn_analytics_rollup_window( array( 'days' => 90, 'until' => 83 ) );
$w = sn_analytics_rollup_window_days( '' );
ok( $day( 90, new DateTimeZone( 'UTC' ) ) === $w[0] && $day( 84, new DateTimeZone( 'UTC' ) ) === end( $w ) && 7 === count( $w ), 'bounded batch: 90..84 days ago in UTC; day 83 (the exclusive upper bound) is not named' );
sn_analytics_rollup_window( false );
ok( array() === sn_analytics_rollup_window_days( 'Not/AZone' ), 'an invalid zone names no days (so nothing is deleted)' );

// ── A complete re-roll drops the key the fresh result no longer has. ──
$seed();
$GLOBALS['ae'] = array( $main => array( array( $pv( $D, '/kept/' ) ), false ) );
sn_analytics_run_rollup();
$db = $GLOBALS['wpdb'];
ok( ! $db->has( 'sn_analytics_daily', $D, 'path', '/about/uses/' ), 'complete read: the stale /about/uses/ row on the re-rolled day is gone' );
ok( $db->has( 'sn_analytics_daily', $D, 'path', '/kept/' ), 'complete read: the fresh /kept/ row is written' );
ok( $db->has( 'sn_analytics_daily', $OLD, 'path', '/old/' ), 'a day outside the window is untouched' );
$del = key( preg_grep( '/^DELETE FROM wp_sn_analytics_daily/', $db->log ) );
$ins = key( preg_grep( '/^INSERT INTO wp_sn_analytics_daily/', $db->log ) );
$com = key( array_filter( $db->log, function ( $q, $i ) use ( $del ) { return $i > $del && 'COMMIT' === $q; }, ARRAY_FILTER_USE_BOTH ) );
ok( null !== $del && 'START TRANSACTION' === $db->log[ $del - 1 ] && $del < $ins && $ins < $com, 'the delete and the write share one committed transaction' );

// ── A failed FIRST chunk of a 3-chunk write rolls the whole day back. ──
$seed();
$many = array();
for ( $i = 0; $i < 250; $i++ ) { $many[] = $pv( $D, "/p{$i}/" ); }
$GLOBALS['ae'] = array( $main => array( $many, false ) );
$before = $GLOBALS['wpdb']->rows;
$GLOBALS["wpdb"]->inserts = 0; $GLOBALS["wpdb"]->fail_insert = 1;
sn_analytics_run_rollup();
$GLOBALS['wpdb']->fail_insert = 0;
$ins = preg_grep( '/^INSERT INTO wp_sn_analytics_daily/', $GLOBALS['wpdb']->log );
ok( 3 === count( $ins ) && in_array( 'ROLLBACK', $GLOBALS['wpdb']->log, true ) && $before['wp_sn_analytics_daily'] === $GLOBALS['wpdb']->rows['wp_sn_analytics_daily'], 'chunk 1 of 3 failed, chunk 3 succeeded: rolled back, stored rows unchanged (' . count( $ins ) . ' chunks)' );

// ── A failed read deletes nothing. ──
$seed();
$GLOBALS['ae'] = array( $main => null );
sn_analytics_run_rollup();
ok( $GLOBALS['wpdb']->has( 'sn_analytics_daily', $D, 'path', '/about/uses/' ) && array() === preg_grep( '/^DELETE/', $GLOBALS['wpdb']->log ), 'failed read: no DELETE, every stored row stays' );

// ── A truncated read deletes nothing (it still upserts, as before). ──
$seed();
$GLOBALS['ae'] = array( $main => array( array( $pv( $D, '/kept/' ) ), true ) );
sn_analytics_run_rollup();
ok( $GLOBALS['wpdb']->has( 'sn_analytics_daily', $D, 'path', '/about/uses/' ) && array() === preg_grep( '/^DELETE FROM wp_sn_analytics_daily/', $GLOBALS['wpdb']->log ), 'truncated read: no DELETE, the stale row is not blanked by a partial set' );

// ── An empty result is breakage-shaped, never a reason to clear a window. ──
$seed();
$GLOBALS['ae'] = array();
sn_analytics_run_rollup();
ok( $GLOBALS['wpdb']->has( 'sn_analytics_daily', $D, 'path', '/about/uses/' ) && array() === preg_grep( '/^DELETE/', $GLOBALS['wpdb']->log ), 'empty read: no DELETE in any rollup, every stored row stays' );

// ── Each sibling writer replaces only its own scope. ──
$db = $GLOBALS['wpdb']; $db->rows = array(); $db->log = array();
sn_analytics_dims_upsert( array( array( 'day' => $D, 'dim' => 'referrer', 'value' => 'gone.example', 'class' => 'human', 'views' => 1, 'visits' => 1 ), array( 'day' => $D, 'dim' => 'country', 'value' => 'US', 'class' => 'human', 'views' => 1, 'visits' => 1 ) ) );
sn_analytics_pageroles_upsert( array( array( 'day' => $D, 'role' => 'entry', 'path' => '/gone/', 'views' => 1, 'visits' => 1 ), array( 'day' => $D, 'role' => 'exit', 'path' => '/exit/', 'views' => 1, 'visits' => 1 ) ) );
sn_analytics_events_upsert( array( array( 'day' => $D, 'name' => 'gone', 'visitors' => 1, 'events' => 1 ) ) );
$fresh = function ( $d ) { return array( array( 'day' => $d, 'value' => 'fresh', 'class' => 'human', 'views' => 1, 'visits' => 1, 'path' => '/fresh/', 'name' => 'fresh', 'events' => 1, 'visitors' => 1 ) ); };
$GLOBALS['ae'] = array( 'blob3) AS value' => null, 'AS value,' => array( $fresh( $D ), false ) );
sn_analytics_dims_run_rollup();
ok( $db->has( 'sn_analytics_dims', $D, 'value', 'gone.example' ), 'dims: the referrer read FAILED, so its stored row stays' );
ok( ! $db->has( 'sn_analytics_dims', $D, 'value', 'US' ), 'dims: the country read was complete, so its stale value is cleared' );
$GLOBALS['ae'] = array( 'blob3 NOT IN' => array( $fresh( $D ), false ), 'blob16 AS name' => array( $fresh( $D ), false ) );
sn_analytics_pageroles_run_rollup();
sn_analytics_events_run_rollup();
ok( ! $db->has( 'sn_analytics_page_roles', $D, 'path', '/gone/' ) && $db->has( 'sn_analytics_page_roles', $D, 'path', '/exit/' ), 'page roles: the entry re-roll clears stale entry rows and leaves the session rollup\'s exit rows' );
ok( ! $db->has( 'sn_analytics_events', $D, 'name', 'gone' ), 'events: a complete read clears the stale event name' );
$dims_deletes = preg_grep( '/^DELETE FROM wp_sn_analytics_dims/', $db->log );
ok( 1 === count( $dims_deletes ) && false !== strpos( reset( $dims_deletes ), "AND dim IN ('country'," ) && array() === preg_grep( "/'referrer'/", $dims_deletes ), 'dims: the DELETE is scoped to the complete dims and never names the failed one' );

// ── The session rollup's exit bridge replaces its UTC day's exit rows. ──
$Y = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
foreach ( array( true, false ) as $capped ) {
	$db->rows = array();
	sn_analytics_pageroles_upsert( array( array( 'day' => $Y, 'role' => 'exit', 'path' => '/gone-exit/', 'views' => 1, 'visits' => 1 ), array( 'day' => $Y, 'role' => 'entry', 'path' => '/entry/', 'views' => 1, 'visits' => 1 ) ) );
	$GLOBALS['capped'] = $capped;
	sn_session_rollup_run( $Y );
	ok( $capped === $db->has( 'sn_analytics_page_roles', $Y, 'path', '/gone-exit/' ) && $db->has( 'sn_analytics_page_roles', $Y, 'path', '/entry/' ), $capped ? 'exit bridge: a row-capped read keeps the stored exit rows' : 'exit bridge: a complete read clears the stale exit row, entry rows untouched' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

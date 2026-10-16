<?php
/**
 * SN Traffic Lanes, the beacon run end to end: inc/analytics-lanes-beacon.php
 * sn_lanes_beacon_run() and the nightly sn_lanes_run_rollup().
 *
 * Pins: a truncated public read records `truncated`, a failed one
 * `read_failed`, and neither writes; the owner dataset is not read before
 * beacon lanes_since (it may not exist yet) and a failed owner read after it
 * skips the run; lanes_since is set from the stamped-row read; an over-cap
 * list that did not read skips the beacon only (the machine pass still runs);
 * over_cap outranks bot_score.
 *
 * Run: php tests/analytics-lanes-beacon-run.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
function add_action() {}
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
$GLOBALS['__overcap'] = array( 'hashes' => array(), 'ok' => true, 'truncated' => false );
function get_transient( $k ) { return SNT_ANALYTICS_VDAY_CACHE_KEY === $k ? $GLOBALS['__overcap'] : false; }
function set_transient( $k, $v, $e = 0 ) { return true; }
function sn_analytics_config() { return array( 'account_id' => 'a', 'token' => 't' ); }
// The AE seam: answers by which read it is; records every statement.
$GLOBALS['__ae']   = array();
$GLOBALS['__sql']  = array();
$GLOBALS['__trunc'] = false;
function sn_analytics_query( $sql ) {
	$GLOBALS['__sql'][] = $sql;
	$GLOBALS['__trunc'] = false;
	foreach ( $GLOBALS['__ae'] as $needle => $ans ) {
		if ( false !== strpos( $sql, $needle ) ) {
			if ( 'TRUNCATED' === $ans ) { $GLOBALS['__trunc'] = true; return array( array( 'day' => 'x' ) ); }
			return $ans;
		}
	}
	return null;
}
function sn_analytics_last_result_truncated() { return $GLOBALS['__trunc']; }
$GLOBALS['__machine'] = 0;
function snt_mr_fetch( $days = 30, $view = 'aggregate' ) { ++$GLOBALS['__machine']; return array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' ); }
function sn_owner_agents() { return array( 'browser', 'chrome-extension', 'claude-code', 'playwright', 'script', 'mcp-local', 'other' ); }

require __DIR__ . '/lib/lanes-fake-wpdb.php';
require __DIR__ . '/../inc/analytics-human-rule.php';
require __DIR__ . '/../inc/analytics-bot-signals.php';
require __DIR__ . '/../inc/analytics-lanes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$db   = $GLOBALS['wpdb'];
$days = sn_lanes_complete_days( '' );
$y    = end( $days );
$PUB  = 'GROUP BY day, vid, class, feed';
$OWN  = 'FROM sn_owner_hits';
$SINCE = 'double16 >= 1';
$pub  = array(
	array( 'day' => $y, 'vid' => str_repeat( 'a', 16 ), 'class' => 'human', 'feed' => '0', 'views' => '4' ),
	// Over the cap AND scoring: over_cap wins.
	array( 'day' => $y, 'vid' => str_repeat( 'b', 16 ), 'class' => 'human', 'feed' => '0', 'views' => '70', 'webdriver' => '1' ),
);
$outcome = static fn() => (string) ( sn_lanes_last_run()['beacon']['outcome'] ?? '' );
$reset   = static function () use ( $db ) { $db->rows = array(); $GLOBALS['__sql'] = array(); $GLOBALS['__opt'] = array(); $GLOBALS['__machine'] = 0; };

// --- a truncated public read writes nothing and says truncated ---------------
$reset();
$GLOBALS['__ae'] = array( $PUB => 'TRUNCATED' );
ok( ! sn_lanes_beacon_run() && array() === $db->rows, 'a truncated public read writes nothing' );
ok( 'truncated' === $outcome(), 'and is recorded as truncated, not read_failed (got ' . $outcome() . ')' );

// --- a failed public read -----------------------------------------------------
$reset();
$GLOBALS['__ae'] = array();
ok( ! sn_lanes_beacon_run() && array() === $db->rows && 'read_failed' === $outcome(), 'a failed public read writes nothing and says read_failed' );

// --- before lanes_since: the owner dataset is not read -------------------------
$reset();
$GLOBALS['__ae'] = array( $PUB => $pub, $SINCE => array() );
ok( sn_lanes_beacon_run() && 'wrote' === $outcome(), 'before lanes_since, the public lanes roll' );
ok( array() === array_filter( $GLOBALS['__sql'], static fn( $s ) => false !== strpos( $s, $OWN ) ), 'and sn_owner_hits is never queried (it may not exist yet)' );
ok( '' === sn_lanes_since( 'beacon' ), 'no stamped row, no lanes_since' );

// --- lanes_since set from the stamped-row read --------------------------------
$reset();
$GLOBALS['__ae'] = array( $PUB => $pub, $SINCE => array( array( 'day' => '2026-10-15', 'n' => '3' ) ), $OWN => array( array( 'day' => $y, 'vid' => str_repeat( 'c', 16 ), 'identity' => '1', 'agent' => '1', 'views' => '2' ) ) );
ok( sn_lanes_beacon_run() && '2026-10-15' === sn_lanes_since( 'beacon' ), 'the first stamped row sets beacon lanes_since' );
ok( 1 === count( array_filter( $db->rows, static fn( $r ) => 'owner' === $r['lane'] && 2 === $r['hits'] ) ), 'after lanes_since the owner read lands in lane owner' );

// --- after lanes_since: a failed owner read skips the run --------------------
$GLOBALS['__ae'] = array( $PUB => $pub, $OWN => null );
$before = $db->rows;
ok( ! sn_lanes_beacon_run() && $before === $db->rows && 'read_failed' === $outcome(), 'after lanes_since, a failed owner read keeps the stored days' );
$GLOBALS['__ae'] = array( $PUB => $pub, $OWN => 'TRUNCATED' );
ok( ! sn_lanes_beacon_run() && 'truncated' === $outcome(), 'and a truncated owner read says truncated' );

// --- over_cap outranks bot_score ------------------------------------------------
$reset();
$GLOBALS['__overcap'] = array( 'hashes' => array( str_repeat( 'b', 16 ) ), 'ok' => true, 'truncated' => false );
$GLOBALS['__ae']      = array( $PUB => $pub, $SINCE => array() );
sn_lanes_beacon_run();
$by = array_column( $db->rows, 'hits', 'reason' );
ok( 70 === ( $by['over_cap'] ?? null ) && ! isset( $by['bot_score'] ), 'an over-cap visitor-day that also scores is over_cap, never bot_score' );

// --- an over-cap list that did not read skips the beacon, not the machine ------
$reset();
$GLOBALS['__overcap'] = array( 'hashes' => array(), 'ok' => false, 'truncated' => false );
$GLOBALS['__ae']      = array( $PUB => $pub, $SINCE => array() );
sn_lanes_run_rollup();
ok( array() === $db->rows && 'overcap_failed' === $outcome(), 'the beacon is skipped and says why' );
ok( 1 === $GLOBALS['__machine'], 'the machine pass still runs' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );

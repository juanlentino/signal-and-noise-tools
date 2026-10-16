<?php
/**
 * SN Traffic Lanes, beacon sensor and the table: inc/analytics-lanes*.php.
 *
 * Pins: the schema (NULL-free unique key), exhaustiveness (every fixture hit
 * in exactly one lane, views summing to the input per day), precedence, lane
 * human against the dashboard's human rule, site-local complete days (never
 * today), the AE character cap, and the rule-version freeze on a re-roll.
 *
 * Run: php tests/analytics-lanes.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['__hooks'] = array();
function add_action( $h, $cb = null, $p = 10 ) { $GLOBALS['__hooks'][] = array( $h, $cb, $p ); }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function get_transient( $k ) { return SNT_ANALYTICS_VDAY_CACHE_KEY === $k ? array( 'hashes' => array(), 'ok' => true, 'truncated' => false ) : false; }
function set_transient( $k, $v, $e = 0 ) { return true; }
$GLOBALS['__dbdelta'] = array();
function dbDelta( $sql ) { $GLOBALS['__dbdelta'][] = $sql; return array(); }
function sn_analytics_query( $sql ) { return null; }
function sn_owner_agents() { return array( 'browser', 'chrome-extension', 'claude-code', 'playwright', 'script', 'mcp-local', 'other' ); }

require __DIR__ . '/lib/lanes-fake-wpdb.php';
require __DIR__ . '/../inc/analytics-human-rule.php';
require __DIR__ . '/../inc/analytics-bot-signals.php';
require __DIR__ . '/../inc/analytics-rollup.php';
require __DIR__ . '/../inc/analytics-lanes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// --- schema: key columns can never be NULL (MySQL never collides NULLs) -------
$schema = sn_lanes_schema_sql();
ok( false !== strpos( $schema, 'UNIQUE KEY lane_key (day, sensor, lane, reason, agent, family, purpose)' ), 'unique on day, sensor, lane, reason, agent, family, purpose' );
foreach ( array( 'sensor', 'lane', 'reason', 'agent', 'family', 'purpose' ) as $col ) {
	ok( 1 === preg_match( "/\\b{$col} VARCHAR\\(\\d+\\) NOT NULL DEFAULT ''/", $schema ), "key column {$col} is NOT NULL DEFAULT ''" );
}
foreach ( array( 'hits', 'visits', 'visitor_days' ) as $col ) {
	ok( false !== strpos( $schema, "{$col} INT UNSIGNED NULL DEFAULT NULL" ), "figure {$col} is NULL when never measured" );
}
ok( false !== strpos( $schema, 'rule_version INT UNSIGNED NOT NULL' ), 'every row carries its rule version' );
sn_lanes_maybe_install();
ok( 1 === count( $GLOBALS['__dbdelta'] ) && '1' === get_option( SN_LANES_DB_VERSION_OPT ), 'installs once and stamps the version' );
sn_lanes_maybe_install();
ok( 1 === count( $GLOBALS['__dbdelta'] ), 'a current version does not reinstall' );
ok( in_array( array( SN_ANALYTICS_ROLLUP_DAILY_HOOK, 'sn_lanes_run_rollup', 30 ), $GLOBALS['__hooks'], true ), 'the rollup runs on the daily hook at priority 30' );
ok( 1 === SN_LANE_RULE_VERSION && 1 === sn_lanes_rule_version(), 'lane_rule_version starts at 1' );

// --- the fixture: one site-local day, every precedence branch -----------------
$H = static fn( $c ) => str_repeat( $c, 16 );
$row = static fn( $vid, $class, $views, array $bits = array(), $feed = 0, $day = '2026-10-14' ) => array( 'day' => $day, 'vid' => $vid, 'class' => $class, 'feed' => $feed, 'views' => (string) $views ) + $bits;
$public = array(
	$row( $H( 'a' ), 'human', 4 ),                                                  // human / remainder
	$row( $H( 'b' ), 'bot', 9 ),                                                    // ua_list
	$row( $H( 'b' ), 'human', 2 ),                                                  // same visitor-day, a human-classed row: over_cap wins over remainder
	$row( $H( 'c' ), 'suspect', 5 ),                                                // network
	$row( $H( 'd' ), 'human', 60 ),                                                 // over_cap (listed)
	$row( $H( 'e' ), 'human', 3, array( 'no_input' => '2' ) ),                      // score 2: still human
	$row( $H( 'f' ), 'human', 2, array( 'no_input' => '2', 'tz_mismatch' => '16' ) ), // score 3: bot_score
	$row( $H( 'f' ), 'suspect', 1 ),                                                // the scored visitor-day's suspect row stays network
	$row( $H( '9' ), 'suspect', 0, array(), 1 ),                                    // RSS server event: feed_server, no views
	$row( $H( 'a' ), 'human', 1, array(), 0, '2026-10-13' ),                        // another day
);
$owner = array(
	array( 'day' => '2026-10-14', 'vid' => $H( '1' ), 'identity' => '1', 'agent' => '1', 'views' => '3' ),
	array( 'day' => '2026-10-14', 'vid' => $H( '2' ), 'identity' => '2', 'agent' => '3', 'views' => '2' ),
	array( 'day' => '2026-10-14', 'vid' => $H( '3' ), 'identity' => '3', 'agent' => '4', 'views' => '1' ),
	array( 'day' => '2026-10-14', 'vid' => $H( '4' ), 'identity' => '4', 'agent' => '0', 'views' => '2' ),
	array( 'day' => '2026-10-14', 'vid' => $H( '5' ), 'identity' => '5', 'agent' => '0', 'views' => '1' ),
);
$over = array( $H( 'd' ), $H( 'b' ) );
$out  = sn_lanes_beacon_derive( $public, $owner, $over );
$cell = static function ( $day, $lane, $reason, $agent = '' ) use ( $out ) {
	foreach ( $out[ $day ] ?? array() as $r ) {
		if ( $r['lane'] === $lane && $r['reason'] === $reason && $r['agent'] === $agent ) {
			return $r;
		}
	}
	return null;
};

// Exhaustiveness: views per day equal the input, every lane is a known lane.
$in = array();
foreach ( array_merge( $public, $owner ) as $r ) {
	$in[ $r['day'] ] = ( $in[ $r['day'] ] ?? 0 ) + (int) $r['views'];
}
foreach ( $in as $day => $views ) {
	ok( $views === array_sum( array_column( $out[ $day ], 'hits' ) ), "{$day}: lane views sum to the {$views} input views" );
}
ok( array() === array_diff( array_column( $out['2026-10-14'], 'lane' ), SN_LANES ), 'every row is in one of the nine lanes' );
$keys = array_map( static fn( $r ) => $r['lane'] . '|' . $r['reason'] . '|' . $r['agent'], $out['2026-10-14'] );
ok( count( $keys ) === count( array_unique( $keys ) ), 'one row per lane key, no duplicates' );
foreach ( $out['2026-10-14'] as $r ) {
	ok( is_string( $r['family'] ) && is_string( $r['purpose'] ) && is_string( $r['agent'] ) && is_string( $r['reason'] ), "{$r['lane']}/{$r['reason']}: no key is NULL" );
}

// Precedence.
ok( 7 === $cell( '2026-10-14', 'human', 'remainder' )['hits'], 'human / remainder holds a (4) and the score-2 visitor e (3)' );
ok( 9 === $cell( '2026-10-14', 'suspected_automation', 'ua_list' )['hits'], 'a stored bot is ua_list, before over-cap' );
ok( 62 === $cell( '2026-10-14', 'suspected_automation', 'over_cap' )['hits'], 'over-cap takes d (60) and b\'s human row (2)' );
ok( 6 === $cell( '2026-10-14', 'suspected_automation', 'network' )['hits'], 'read-time suspect is network (c 5, f 1)' );
ok( 2 === $cell( '2026-10-14', 'suspected_automation', 'bot_score' )['hits'], 'score 3 is bot_score, per visitor-day' );
$feed = $cell( '2026-10-14', 'human', 'feed_server' );
ok( null !== $feed && 0 === $feed['hits'] && 1 === $feed['visitor_days'] && 0 === $feed['visits'], 'the RSS server event is human / feed_server, no views, one visitor-day' );
ok( 3 === $cell( '2026-10-14', 'owner', 'owner_device', 'browser' )['hits'], 'identity 1: owner / owner_device, agent browser' );
ok( 2 === $cell( '2026-10-14', 'owner_agent', 'owner_token', 'claude-code' )['hits'], 'identity 2: owner_agent, the token\'s agent label' );
ok( 1 === $cell( '2026-10-14', 'unverified_owner', 'owner_token_invalid', 'playwright' )['hits'], 'identity 3: unverified_owner / owner_token_invalid' );
ok( 2 === $cell( '2026-10-14', 'unverified_owner', 'logged_in' )['hits'], 'identity 4: unverified_owner / logged_in' );
ok( 1 === $cell( '2026-10-14', 'unverified_owner', 'owner_legacy' )['hits'], 'identity 5: unverified_owner / owner_legacy' );
ok( 2 === $cell( '2026-10-14', 'human', 'remainder' )['visitor_days'], 'visitor-days are per lane row (a and e)' );

// Human against the dashboard's rule: the daily table stores class human minus
// over-cap visitor-days (sn_analytics_rollup_sql groups by the same class
// expression under the same over-cap list). Lane human (remainder) plus the
// observe-only bot_score equals it; feed_server adds no views.
$dash = 0;
foreach ( $public as $r ) {
	if ( '2026-10-14' === $r['day'] && 'human' === $r['class'] && ! in_array( $r['vid'], $over, true ) ) {
		$dash += (int) $r['views'];
	}
}
$lane_h = $cell( '2026-10-14', 'human', 'remainder' )['hits'] + $feed['hits'];
ok( $dash === $lane_h + $cell( '2026-10-14', 'suspected_automation', 'bot_score' )['hits'], "dashboard human ({$dash}) = lane human + bot_score" );
$no_score = array_values( array_filter( $public, static fn( $r ) => $H( 'f' ) !== $r['vid'] ) );
$o2       = sn_lanes_beacon_derive( $no_score, array(), $over );
$h2       = array_sum( array_map( static fn( $r ) => 'human' === $r['lane'] ? $r['hits'] : 0, $o2['2026-10-14'] ) );
ok( $dash - 2 === $h2, 'with no scored visitor-day, lane human equals the dashboard human exactly' );
$sql = sn_lanes_public_sql( 'sn_pageviews_v2', '', 'America/New_York' );
ok( false !== strpos( $sql, sn_analytics_class_select() . ' AS class' ) && false !== strpos( sn_analytics_rollup_sql( 7, 'America/New_York' ), sn_analytics_class_select() . ' AS class' ), 'both reads group by the one read-time class expression' );
ok( false !== strpos( $sql, sn_analytics_excluded_path_sql() ), 'admin, asset and file paths are dropped, as the daily table drops them' );
ok( false !== strpos( $sql, "blob17 = 'RSS Feed Request'" ) && false !== strpos( sn_lanes_public_sql( 'sn_pageviews', '', '' ), "blob16 = 'RSS Feed Request'" ), 'the feed event name is read from each dataset\'s own column' );

// --- site-local complete days, never today ------------------------------------
$now  = strtotime( '2026-10-15 03:30:00 UTC' ); // 23:30 on 10-14 in New York
$days = sn_lanes_complete_days( 'America/New_York', $now );
ok( array( '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13' ) === $days, 'New York: seven complete days ending the day before the local today (10-14 is today there)' );
$utc = sn_lanes_complete_days( '', $now );
ok( '2026-10-14' === end( $utc ), 'UTC: the UTC today (10-15) is pending, 10-14 is complete' );
$edge = sn_lanes_complete_days( 'America/New_York', strtotime( '2026-10-15 04:02:00 UTC' ) ); // 00:02 local
ok( '2026-10-13' === end( $edge ), 'two minutes past local midnight, the day that just ended is not yet written (clock skew guard)' );
list( , $window ) = sn_lanes_window_sql( 'America/New_York' );
ok( false !== strpos( $window, "timestamp < toStartOfInterval(now(), INTERVAL '1' DAY, 'America/New_York')" ), 'the read stops at the local start of today' );

// --- AE limits -----------------------------------------------------------------
ok( ! sn_analytics_sql_too_long( $sql ) && ! sn_analytics_sql_too_long( sn_lanes_owner_sql( 'America/New_York' ) ), 'both reads fit the AE character cap (' . strlen( $sql ) . ')' );
ok( false !== strpos( sn_lanes_owner_sql( '' ), 'FROM sn_owner_hits' ) && false !== strpos( sn_lanes_owner_sql( '' ), 'toUInt32(double14) AS identity, toUInt32(double15) AS agent' ), 'the owner read groups by double14 identity and double15 agent' );
ok( false !== strpos( sn_lanes_beacon_since_sql( '' ), 'FROM sn_pageviews_v2 WHERE' ) && false !== strpos( sn_lanes_beacon_since_sql( '' ), 'double16 >= 1' ), 'beacon lanes_since: the first public v2 row stamped with a rule version' );

// --- the table: replace, freeze, dedupe ---------------------------------------
$db = $GLOBALS['wpdb'];
ok( sn_lanes_store_days( 'beacon', array( '2026-10-13', '2026-10-14' ), $out ), 'a write commits' );
$n = count( $db->rows );
ok( $n === count( $out['2026-10-13'] ) + count( $out['2026-10-14'] ), "one stored row per lane key ({$n})" );
ok( sn_lanes_store_days( 'beacon', array( '2026-10-13', '2026-10-14' ), $out ) && $n === count( $db->rows ), 're-rolling the same days replaces them: no duplicate keys' );
sn_lanes_rule_version( 2 ); // a planted bump
$relabel = array( '2026-10-13' => array( array( 'lane' => 'human', 'reason' => 'remainder', 'agent' => '', 'family' => '', 'purpose' => '', 'hits' => 999, 'visits' => 1, 'visitor_days' => 1 ) ), '2026-10-15' => array( array( 'lane' => 'human', 'reason' => 'remainder', 'agent' => '', 'family' => '', 'purpose' => '', 'hits' => 5, 'visits' => 1, 'visitor_days' => 1 ) ) );
ok( sn_lanes_store_days( 'beacon', array( '2026-10-13', '2026-10-15' ), $relabel ), 'the write commits: the frozen day is skipped, not a rollback' );
ok( array( '2026-10-13' ) === sn_lanes_frozen_days( 'beacon', array( '2026-10-13', '2026-10-15' ) ), 'the frozen day is named' );
$v1 = array_filter( $db->rows, static fn( $r ) => '2026-10-13' === $r['day'] );
ok( 1 === count( array_unique( array_column( $v1, 'rule_version' ) ) ) && 1 === (int) reset( $v1 )['rule_version'] && ! in_array( 999, array_column( $v1, 'hits' ), true ), 'after a bump, a day stored under rule 1 is never relabeled' );
ok( 1 === count( array_filter( $db->rows, static fn( $r ) => '2026-10-15' === $r['day'] && 2 === $r['rule_version'] ) ), 'a new day is written under the new rule' );
sn_lanes_rule_version( 0 );
ok( ! sn_lanes_store_days( 'machine', array(), $out ), 'no days, no write' );
$db->fail_insert = 2;
$before = $db->rows;
ok( ! sn_lanes_store_days( 'beacon', array( '2026-10-14' ), $out ) && $before === $db->rows, 'a failed insert rolls the whole day back' );

// --- lanes_since moves only earlier, from evidence -----------------------------
ok( '' === sn_lanes_since( 'beacon' ), 'no evidence, no date' );
sn_lanes_since( 'beacon', '2026-10-16' );
sn_lanes_since( 'beacon', '2026-10-20' );
sn_lanes_since( 'beacon', 'junk' );
ok( '2026-10-16' === sn_lanes_since( 'beacon' ) && '' === sn_lanes_since( 'machine' ), 'the earliest evidence day stands; junk is ignored' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );

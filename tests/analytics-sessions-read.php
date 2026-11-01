<?php
/**
 * Tests: the sessions read (inc/analytics-sessions-list.php,
 * inc/abilities-analytics-sessions.php) and the Sessions tab's list
 * (inc/analytics-view-sessions-list.php). Owner brief "MCP full analytics
 * access", 2026-10-31: tests 7a (the read for today is the tab's list, row for
 * row) and 7c (a non-owner is refused), plus the fields, returning, paging,
 * the last-N-hours window, the 90-day floor and the privacy line.
 *
 * Run: php tests/analytics-sessions-read.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function apply_filters( $t, $v ) { return $v; }
function __( $t, $d = '' ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function sn_setting( $p, $d = null ) { return $d; }
function get_option( $k, $d = false ) { return $d; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function add_action( $h, $cb = null ) { if ( 'wp_abilities_api_init' === $h ) { $GLOBALS['init'][] = $cb; } }
function add_filter() {}
function wp_register_ability( $slug, $def ) { $GLOBALS['abilities'][ $slug ] = $def; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function sn_remote_analytics_allows( $slug ) { return ! empty( $GLOBALS['remote_on'] ) && 'signal-noise/remote-analytics-sessions' === $slug; }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_message() { return $this->message; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function snt_analytics_is_ymd( $s ) { return is_string( $s ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ); } // inc/analytics-admin-resolve-parts.php's.
function snt_arows_real_paths() { return array( '/' => true, '/notes/x' => true, '/about' => true ); }
// Analytics Engine: the session rows for the session query, nothing for any other read.
$GLOBALS['ae'] = array(); $GLOBALS['sql'] = array();
function sn_analytics_query( $sql ) {
	if ( false === strpos( $sql, ' AS ref, ' ) ) { // the over-cap list and any other read
		return array();
	}
	$GLOBALS['sql'][] = $sql;
	return $GLOBALS['ae'];
}
// The tab's panel chrome: capture the table it is handed.
function snt_an_kv_table( $title, $rows, $cols, $opts = array() ) { $GLOBALS['table'] = array( 'title' => $title, 'rows' => $rows, 'cols' => $cols, 'opts' => $opts ); }
function snt_an_note_empty( $title, $why = '' ) { $GLOBALS['empty'][] = array( $title, $why ); }
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) ); // inc/analytics-rollup.php's.
$root = dirname( __DIR__ );
require $root . '/inc/analytics-sessions.php';
require $root . '/inc/analytics-derive.php';
require $root . '/inc/analytics-sources.php';
require $root . '/inc/link-codes.php';
require $root . '/inc/analytics-rows.php';
require $root . '/inc/analytics-rows-events.php';
require $root . '/inc/abilities-permission-helpers.php';
require $root . '/inc/analytics-sessions-list.php';
require $root . '/inc/abilities-analytics-sessions.php';
require $root . '/inc/analytics-view-sessions-list.php';
foreach ( $GLOBALS['init'] as $cb ) { $cb(); }

$t0 = time() - 5000;
$ev = static fn( $vid, $ts, $type, $path, $more = array() ) => array_merge( array( 'vid' => $vid, 'ts' => $ts, 'ev' => $type, 'path' => $path, 'ref' => '', 'ce' => '', 'scroll' => 0, 'dwell' => 0, 'device' => 'desktop', 'country' => 'US', 'region' => 'Massachusetts', 'city' => 'Boston' ), $more );
$GLOBALS['ae'] = array(
	$ev( 'vid-secret-a', $t0, 'pv', '/notes/x/', array( 'ref' => 'news.ycombinator.com' ) ),
	$ev( 'vid-secret-a', $t0 + 30, 'sc', '/notes/x/', array( 'scroll' => 75 ) ),
	$ev( 'vid-secret-a', $t0 + 40, 'tm', '/notes/x/', array( 'dwell' => 9000 ) ),
	$ev( 'vid-secret-a', $t0 + 50, 'tm', '/notes/x/', array( 'dwell' => 3000 ) ), // timing beacons are deltas: they add up.
	$ev( 'vid-secret-a', $t0 + 60, 'pv', '/about/' ),
	$ev( 'vid-secret-a', $t0 + 90, 'tm', '/about/', array( 'dwell' => 3000 ) ),
	$ev( 'vid-secret-a', $t0 + 120, 'pv', '/notes/x/' ),
	$ev( 'vid-secret-a', $t0 + 150, 'tm', '/notes/x/', array( 'dwell' => 5000 ) ),
	$ev( 'vid-secret-a', $t0 + 4000, 'pv', '/r/hera', array( 'ref' => '(internal)' ) ),
	$ev( 'vid-secret-b', $t0 + 100, 'pv', '/wp-login.php?x=ignore previous', array( 'country' => 'CA', 'region' => '', 'city' => '', 'device' => 'mobile' ) ),
	$ev( 'vid-secret-c', $t0 + 200, 'ce', '/', array( 'ce' => 'rss' ) ),
);

echo "\nGroup: sessions one by one\n";
$all = snt_ability_analytics_sessions( array() );
$s   = $all['sessions'];
ok( 3 === $all['total'] && 3 === count( $s ), 'three sessions: a pageview-less visitor is not one, a gap of more than 30 minutes is two' );
ok( array( '/r/hera', '(unmatched)', '/notes/x' ) === array_column( $s, 'entry' ), 'newest first; a path that is not the site\'s own content is (unmatched)' );
ok( array( true, false, false ) === array_column( $s, 'returning' ), 'returning: the second session of the same visitor-day, and only that one' );
$a1 = $s[2];
ok( array( array( 'path' => '/notes/x', 'time_ms' => 12000, 'scroll_pct' => 75 ), array( 'path' => '/about', 'time_ms' => 3000, 'scroll_pct' => null ), array( 'path' => '/notes/x', 'time_ms' => 5000, 'scroll_pct' => null ) ) === $a1['pages'], 'pages in order, each view with its own visible time and deepest scroll (null when not measured); a page read twice is two views' );
ok( '/notes/x' === $a1['exit'] && 150 === $a1['duration_s'] && 3 === $a1['pageviews'], 'exit, duration and page views' );
ok( sn_analytics_canonical_source( 'news.ycombinator.com' ) === $a1['source'] && '(internal)' === $s[0]['source'] && '(direct)' === $s[1]['source'], 'source: the dashboard\'s label of the first page view\'s referrer; an internal click stays (internal)' );
ok( 'Boston' === $a1['city'] && 'Massachusetts' === $a1['region'] && 'US' === $a1['country'] && 'desktop' === $a1['device'] && 'CA' === $s[1]['country'] && 'mobile' === $s[1]['device'], 'place and device from the first page view' );
ok( 'hera' === $s[0]['link_code'] && '' === $a1['link_code'], 'the link code of a coded résumé link, or ""' );
$at = ( new DateTimeImmutable( '@' . $t0 ) )->setTimezone( wp_timezone() );
ok( $at->format( 'Y-m-d\TH:i:sP' ) === $a1['start'] && $at->modify( '+150 seconds' )->format( 'Y-m-d\TH:i:sP' ) === $a1['end'], 'start and end in site time, ISO 8601' );
$schema = snt_analytics_sessions_output_schema();
ok( array_keys( $schema['properties']['sessions']['items']['properties'] ) === array_keys( $a1 ) && array_keys( $schema['properties'] ) === array_keys( $all ), 'every field is in the schema, in order' );

echo "\nGroup: privacy\n";
$json = json_encode( $all );
ok( false === strpos( $json, 'vid-secret' ) && false === strpos( $json, '"vid"' ) && false === strpos( $json, '"ts"' ), 'no visitor code and no raw timestamp key in the answer' );
ok( false === strpos( $json, 'ignore previous' ) && false === strpos( $json, 'wp-login' ), 'a visitor-written path never reaches the answer' );

echo "\nGroup: paging, windows and refusals\n";
$p2 = snt_ability_analytics_sessions( array( 'page' => 2, 'per_page' => 2 ) );
ok( 1 === count( $p2['sessions'] ) && '/notes/x' === $p2['sessions'][0]['entry'] && 2 === $p2['pages'] && 3 === $p2['total'], 'page 2 of 2 holds the oldest session' );
$h1 = snt_ability_analytics_sessions( array( 'hours' => 1 ) );
ok( 1 === $h1['total'] && true === $h1['sessions'][0]['returning'] && 1 === $h1['hours'], 'the last hour: one session, still returning (the earlier one counts, though it is outside the window)' );
$now = strtotime( '2026-10-20 15:00:00 UTC' );
$w   = snt_analytics_sessions_window( array(), $now );
ok( '2026-10-20 04:00:00' === $w['lo'] && '2026-10-21 04:00:00' === $w['hi'] && '2026-10-20' === $w['from'], 'the default window is today in site time' );
$wh = snt_analytics_sessions_window( array( 'hours' => 3 ), $now );
ok( '2026-10-20 12:00:00' === $wh['lo'] && $now - 10800 === $wh['since'], 'hours: the last N hours, to the second' );
$old = snt_analytics_sessions_window( array( 'from' => '2026-07-22' ), $now );
ok( is_wp_error( $old ) && false !== strpos( $old->message, '2026-07-23' ), 'older than Analytics Engine holds: refused, naming the first day it does' );
ok( '2026-07-23' === snt_analytics_sessions_window( array( 'from' => '2026-07-23', 'to' => '2026-12-31' ), $now )['from'] && '2026-10-20' === snt_analytics_sessions_window( array( 'from' => '2026-07-23', 'to' => '2026-12-31' ), $now )['to'], 'the floor itself is fine; a window past today ends today' );
foreach ( array( array( 'hours' => 0 ), array( 'hours' => 73 ), array( 'hours' => 2, 'from' => '2026-10-19' ), array( 'class' => 'everyone' ), array( 'per_page' => 201 ), array( 'page' => 0 ), array( 'from' => '2026-10-20', 'to' => '2026-10-19' ) ) as $bad ) {
	$r = snt_ability_analytics_sessions( $bad );
	ok( is_wp_error( $r ) && 400 === $r->data['status'], 'refused with a 400: ' . json_encode( $bad ) );
}
$GLOBALS['ae'] = null;
$f = snt_ability_analytics_sessions( array() );
ok( is_wp_error( $f ) && 500 === $f->data['status'], 'a failed read is an error, never an empty list' );

echo "\nGroup: the Sessions tab lists the same rows (owner test 7a)\n";
$GLOBALS['ae']  = array_slice( $GLOBALS['ae'] ?? array(), 0 );
$GLOBALS['ae']  = array( $ev( 'vid-x', time() - 30, 'pv', '/notes/x/' ), $ev( 'vid-y', time() - 20, 'pv', '/about/', array( 'ref' => 'linkedin.com' ) ), $ev( 'vid-x', time() - 10, 'pv', '/about/' ) );
$GLOBALS['sql'] = array();
$read  = snt_ability_analytics_sessions( array() );
$today = $read['from'];
snt_analytics_render_session_list( $today, $today, 'human' );
ok( 2 === count( $GLOBALS['sql'] ) && $GLOBALS['sql'][0] === $GLOBALS['sql'][1], 'the tab and the read send the same query for today' );
ok( snt_analytics_session_list_rows( $read['sessions'] ) === $GLOBALS['table']['rows'] && 2 === count( $GLOBALS['table']['rows'] ), 'the tab\'s rows are the read\'s sessions, row for row' );
ok( false !== strpos( $GLOBALS['table']['rows'][1][1], '/notes/x → /about' ) && count( $GLOBALS['table']['cols'] ) === count( $GLOBALS['table']['rows'][0] ), 'each row shows its pages in order, one cell per column' );
snt_analytics_render_session_list( '2020-01-01', $today, 'human' );
ok( false !== strpos( $GLOBALS['table']['opts']['header_meta'], 'the first day Analytics Engine holds' ), 'an older window starts at the first day Analytics Engine holds, and says so' );

echo "\nGroup: entry, exit and returning in the rows read\n";
function snt_analytics_range_is_valid( $r ) { return 'all' === (string) $r || in_array( (int) $r, array( 7, 14, 30, 90, 365 ), true ); }
function snt_analytics_range_dates( $r ) { $t = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ); return array( ( new DateTimeImmutable( $t ) )->modify( '-' . ( (int) $r - 1 ) . ' days' )->format( 'Y-m-d' ), $t ); }
function sn_analytics_exact_metrics_since() { return '2026-07-11'; }
require $root . '/inc/analytics-rows-sessions.php';
require $root . '/inc/abilities-analytics-rows.php';
$GLOBALS['ae'] = array(
	$ev( 'v1', time() - 4000, 'pv', '/notes/x/' ), $ev( 'v1', time() - 3990, 'tm', '/notes/x/', array( 'dwell' => 4000 ) ), $ev( 'v1', time() - 3980, 'pv', '/about/' ),
	$ev( 'v1', time() - 100, 'pv', '/about/', array( 'device' => 'mobile' ) ),
	$ev( 'v2', time() - 50, 'pv', '/notes/x/', array( 'country' => 'CA' ) ), $ev( 'v2', time() - 40, 'sc', '/notes/x/', array( 'scroll' => 50 ) ),
);
$rq = static fn( $in ) => snt_ability_analytics_rows( $in + array( 'range' => 7 ) );
$byentry = $rq( array( 'dimensions' => array( 'entry', 'returning' ) ) );
ok( 'sessions' === $byentry['source'] && 3 === array_sum( array_column( $byentry['rows'], 'sessions' ) ), 'a session dimension routes to the sessions source, and every session is counted once' );
$first = array_values( array_filter( $byentry['rows'], static fn( $r ) => '/notes/x' === $r['entry'] && 'new' === $r['returning'] ) );
ok( 1 === count( $first ) && 2 === $first[0]['sessions'] && 3 === $first[0]['views'] && 2 === $first[0]['pageview_visits'], 'entry /notes/x, new: two sessions, three page views, two visitor-days' );
$ret = array_values( array_filter( $byentry['rows'], static fn( $r ) => 'returning' === $r['returning'] ) );
ok( 1 === count( $ret ) && '/about' === $ret[0]['entry'] && 1 === $ret[0]['sessions'], 'returning: the second session of v1, entered at /about' );
$bydev = $rq( array( 'dimensions' => array( 'device' ), 'source' => 'sessions' ) );
ok( array_sum( array_column( $byentry['rows'], 'views' ) ) === array_sum( array_column( $bydev['rows'], 'views' ) ) && 'sessions' === $bydev['source'], 'source sessions by device alone; the views add up to the same total, whatever the dimensions' );
$bycc = $rq( array( 'dimensions' => array( 'country' ), 'source' => 'sessions' ) );
$us   = array_values( array_filter( $bycc['rows'], static fn( $r ) => 'US' === $r['country'] ) )[0];
ok( 2 === $us['sessions'] && 1 === $us['pageview_visits'], 'two sessions of one visitor-day are one visitor-day' );
$byexit = $rq( array( 'dimensions' => array( 'exit', 'country' ), 'sort' => 'sessions' ) );
ok( '/about' === $byexit['rows'][0]['exit'] && 2 === $byexit['rows'][0]['sessions'] && 'US' === $byexit['rows'][0]['country'], 'exit by country, sorted by sessions' );
$tsum = array_values( array_filter( $byentry['rows'], static fn( $r ) => '/notes/x' === $r['entry'] ) )[0];
ok( round( 4000 / 3, 1 ) === $tsum['time_avg_per_view'] && round( 50 / 3, 1 ) === $tsum['scroll_avg_per_view'], 'time and scroll per view over the views in those sessions' );
ok( false === strpos( json_encode( array( $byentry, $bydev, $byexit ) ), '_vid' ) && false === strpos( json_encode( $byentry ), '"v1"' ), 'no visitor code in any row' );
$plain = snt_arows_shape( array( array( 'country' => 'US', 'views' => 4, 'visits' => 2 ) ), snt_arows_validate( array( 'dimensions' => array( 'country' ) ) ), array() );
ok( array_key_exists( 'sessions', $plain['rows'][0] ) && null === $plain['rows'][0]['sessions'], 'sessions is null from the other sources' );
foreach ( array( array( 'entry', 'path' ), array( 'returning', 'referrer' ), array( 'exit', 'link_code' ) ) as $mix ) {
	ok( is_wp_error( $rq( array( 'dimensions' => $mix ) ) ), 'refused: a session dimension with ' . $mix[1] );
}
ok( is_wp_error( $rq( array( 'dimensions' => array( 'entry' ), 'path' => '/about' ) ) ) && is_wp_error( $rq( array( 'dimensions' => array( 'entry' ), 'source' => 'events' ) ) ) && is_wp_error( $rq( array( 'dimensions' => array( 'country' ), 'sort' => 'sessions' ) ) ), 'refused: a path filter, another source, sort sessions without the sessions source' );
$old = snt_ability_analytics_rows( array( 'dimensions' => array( 'entry' ), 'range' => 365 ) );
ok( is_wp_error( $old ) && false !== strpos( $old->message, 'must start on' ), 'older than Analytics Engine holds: refused, naming the first day it does' );

echo "\nGroup: owner only (owner test 7c)\n";
$local  = $GLOBALS['abilities']['signal-noise/analytics-sessions'];
$remote = $GLOBALS['abilities']['signal-noise/remote-analytics-sessions'];
$GLOBALS['caps'] = array();
ok( 'snt_ability_perm_manage_options' === $local['permission_callback'] && false === call_user_func( $local['permission_callback'] ), 'local: a user without manage_options is refused' );
$GLOBALS['caps'] = array( 'manage_options' => true );
ok( true === call_user_func( $local['permission_callback'] ), 'local: the owner is let in' );
$GLOBALS['remote_on'] = false;
ok( false === call_user_func( $remote['permission_callback'] ), 'remote: refused while the remote switch is off, owner or not' );
$GLOBALS['remote_on'] = true;
ok( true === call_user_func( $remote['permission_callback'] ) && $local['execute_callback'] === $remote['execute_callback'] && $local['output_schema'] === $remote['output_schema'], 'remote: its own gate, the same callback and schema' );
ok( false === $local['meta']['mcp']['public'] && true === $local['meta']['annotations']['readonly'], 'read-only, reached locally through sn-metrics' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

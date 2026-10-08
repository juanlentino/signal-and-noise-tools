<?php
/**
 * Tests for inc/analytics-live.php: the live "reading now / views today" pair,
 * public on /stats (human only) and gated in admin (every class).
 *
 * Covers the payload (null vs 0), the stale-refresh throttle (no capability
 * needed, schedules once), the two routes (public human-only, gated all
 * classes), the edge cache header, and the markup hooks every surface carries.
 *
 * Run: php tests/analytics-live.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );

function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {}
$GLOBALS['t'] = array(); $GLOBALS['o'] = array(); $GLOBALS['sched'] = array(); $GLOBALS['cap'] = false; $GLOBALS['cfg'] = true; $GLOBALS['spawned'] = 0; $GLOBALS['routes'] = array();
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['o'][ $k ] = $v; return true; }
function wp_next_scheduled( $h ) { return in_array( $h, $GLOBALS['sched'], true ) ? time() + 100 : false; }
function wp_schedule_single_event( $ts, $h ) { $GLOBALS['sched'][] = $h; return true; }
function spawn_cron() { $GLOBALS['spawned']++; }
function current_user_can( $c ) { return (bool) $GLOBALS['cap']; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function sn_analytics_config() { return $GLOBALS['cfg'] ? array( 'account_id' => 'a' ) : null; }
function sn_analytics_query( $sql ) { return null; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $ns . $route ] = $args; }

require dirname( __DIR__ ) . '/inc/analytics-realtime.php';
require dirname( __DIR__ ) . '/inc/analytics-live.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function reset_all() { $GLOBALS['t'] = array(); $GLOBALS['o'] = array(); $GLOBALS['sched'] = array(); $GLOBALS['cap'] = false; $GLOBALS['cfg'] = true; $GLOBALS['spawned'] = 0; }

echo "Group: the payload keeps null and 0 apart\n";
reset_all();
$p = sn_analytics_live_payload( false );
ok( null === $p['now'] && null === $p['today'] && null === $p['fetched'], 'a cold cache is null for both figures, never 0' );
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array(), 'views_today' => 0, 'fetched' => 1000 );
$p = sn_analytics_live_payload( false );
ok( 0 === $p['now'] && 0 === $p['today'] && 1000 === $p['fetched'], 'a warmed quiet site is a real 0 for both' );
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array( 'human' => 3, 'bot' => 9, 'suspect' => 2 ), 'views_today' => 41, 'fetched' => 1000 );
$p = sn_analytics_live_payload( false );
ok( 3 === $p['now'] && 41 === $p['today'] && ! array_key_exists( 'classes', $p ), 'the public payload is human only and carries no class breakdown' );
$a = sn_analytics_live_payload( true );
ok( array( 'human' => 3, 'suspect' => 2, 'bot' => 9 ) === $a['classes'], 'the admin payload carries every class' );

echo "\nGroup: a stale cache schedules one refresh, with no capability needed\n";
reset_all();
ok( true === sn_analytics_realtime_schedule_if_stale() && array( SN_ANALYTICS_REALTIME_HOOK ) === $GLOBALS['sched'], 'cold: one refresh scheduled for an anonymous caller' );
ok( false === sn_analytics_realtime_schedule_if_stale() && 1 === count( $GLOBALS['sched'] ), 'already scheduled: no second event' );
reset_all();
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array(), 'fetched' => time() );
ok( false === sn_analytics_realtime_schedule_if_stale() && array() === $GLOBALS['sched'], 'fresh: nothing scheduled' );
reset_all();
$GLOBALS['cfg'] = false;
ok( false === sn_analytics_realtime_schedule_if_stale() && array() === $GLOBALS['sched'], 'unconfigured: nothing scheduled' );
reset_all();
sn_analytics_realtime_warm();
ok( array() === $GLOBALS['sched'], 'the admin warmer still refuses a caller without the capability' );
$GLOBALS['cap'] = true;
sn_analytics_realtime_warm();
ok( array( SN_ANALYTICS_REALTIME_HOOK ) === $GLOBALS['sched'], 'and still warms for one with it' );

echo "\nGroup: two routes, the public one human only\n";
sn_analytics_live_register_routes();
$pub = $GLOBALS['routes']['signal-noise/v1/live'] ?? null;
$adm = $GLOBALS['routes']['signal-noise/v1/live/admin'] ?? null;
ok( is_array( $pub ) && '__return_true' === $pub['permission_callback'], 'the public route is registered and open' );
ok( is_array( $adm ) && 'sn_analytics_live_can_read' === $adm['permission_callback'], 'the admin route is gated by a named callback' );
$GLOBALS['cap'] = false;
ok( false === sn_analytics_live_can_read(), 'the gate refuses a visitor' );
$GLOBALS['cap'] = true;
ok( true === sn_analytics_live_can_read(), 'and admits a stats reader' );
ok( 'public, max-age=0, s-maxage=30' === SN_ANALYTICS_LIVE_CACHE_CONTROL, 'the public answer is edge-cacheable for 30 s and never by the browser' );

echo "\nGroup: every surface carries the live hooks\n";
$root = dirname( __DIR__ );
foreach ( array( 'apps/sn-analytics/parts/painters/chrome-header.php' => 'now', 'apps/sn-analytics/parts/painters/view-overview.php' => 'today', 'inc/analytics-view-overview.php' => 'today', 'inc/analytics-header-region.php' => 'now', 'inc/public-stats-live.php' => 'today' ) as $f => $kind ) {
	$src = (string) @file_get_contents( "$root/$f" );
	ok( false !== strpos( $src, 'data-sn-live' ) && false !== strpos( $src, $kind ), "$f carries data-sn-live ($kind)" );
}
ok( file_exists( "$root/assets/live-now.js" ), 'the shared updater exists' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

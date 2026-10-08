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

// The resolver's WordPress reads: id by path, and each post's facts.
$GLOBALS['posts'] = array(
	'/notes/alpha/' => array( 'id' => 1, 'status' => 'publish', 'type' => 'post', 'pw' => '', 'noindex' => false ),
	'/draft/'       => array( 'id' => 2, 'status' => 'draft', 'type' => 'post', 'pw' => '', 'noindex' => false ),
	'/locked/'      => array( 'id' => 3, 'status' => 'publish', 'type' => 'page', 'pw' => 'x', 'noindex' => false ),
	'/hidden/'      => array( 'id' => 4, 'status' => 'publish', 'type' => 'page', 'pw' => '', 'noindex' => true ),
	'/product/'     => array( 'id' => 5, 'status' => 'publish', 'type' => 'product', 'pw' => '', 'noindex' => false ),
);
function sn_test_post( $id ) { foreach ( $GLOBALS['posts'] as $p => $r ) { if ( $r['id'] === $id ) { return $r + array( 'path' => $p ); } } return null; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function url_to_postid( $u ) { $p = substr( $u, strlen( 'https://x.test' ) ); return $GLOBALS['posts'][ $p ]['id'] ?? 0; }
function get_post_status( $id ) { return sn_test_post( $id )['status'] ?? false; }
function post_password_required( $id ) { return '' !== ( sn_test_post( $id )['pw'] ?? '' ); }
function get_post_field( $f, $id ) { return 'post_password' === $f ? ( sn_test_post( $id )['pw'] ?? '' ) : ''; }
function get_post_type( $id ) { return sn_test_post( $id )['type'] ?? false; }
function sn_post_settings_get_noindex( $id ) { return (bool) ( sn_test_post( $id )['noindex'] ?? false ); }
function get_the_title( $id ) { return 'Title ' . $id; }
function get_permalink( $id ) { return 'https://x.test' . sn_test_post( $id )['path']; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
require dirname( __DIR__ ) . '/inc/analytics-realtime.php';
require dirname( __DIR__ ) . '/inc/analytics-live-pages.php';
require dirname( __DIR__ ) . '/inc/analytics-live-hour.php';
require dirname( __DIR__ ) . '/inc/analytics-sources.php';
require dirname( __DIR__ ) . '/inc/analytics-live-sources.php';
require dirname( __DIR__ ) . '/inc/analytics-live-admin.php';
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

echo "\nGroup: being read now\n";
$sql = sn_analytics_live_pages_sql();
ok( false !== strpos( $sql, 'count(DISTINCT index1) AS readers' ) && false !== strpos( $sql, "INTERVAL '5' MINUTE" ) && false !== strpos( $sql, 'GROUP BY path' ), 'one grouped query: distinct readers per path over the same 5-minute window as Reading now' );
ok( false !== strpos( $sql, "blob2 ILIKE '/wp-admin'" ), 'admin, login and asset paths are excluded in the query' );
$resolve = static function ( $path ) {
	$known = array( '/' => array( 'Home', 'https://x.test/' ), '/notes/alpha/' => array( 'Alpha', 'https://x.test/notes/alpha/' ), '/about/' => array( 'About', 'https://x.test/about/' ) );
	return $known[ $path ] ?? null;
};
$rows = array(
	array( 'path' => '/notes/alpha/', 'readers' => 2 ),
	array( 'path' => '/notes/alpha/?utm_source=x', 'readers' => 1 ),
	array( 'path' => '/draft-or-private/', 'readers' => 5 ),
	array( 'path' => '/', 'readers' => 1 ),
	array( 'path' => '/about', 'readers' => 1 ),
	array( 'path' => '', 'readers' => 3 ),
);
$pages = sn_analytics_live_pages_from_rows( $rows, $resolve );
ok( array( 'label' => 'Alpha', 'url' => 'https://x.test/notes/alpha/', 'readers' => 3 ) === $pages[0], 'a query string folds into its page, counts summed' );
ok( ! in_array( '/draft-or-private/', array_column( $pages, 'url' ), true ) && 3 === count( $pages ), 'a path that does not resolve to a public page is never listed' );
ok( 'About' === $pages[2]['label'] || 'About' === $pages[1]['label'], 'a missing trailing slash folds into the canonical page' );
$case = static function ( $path ) { return '/about/' === strtolower( $path ) ? array( 'About', 'https://x.test/about/' ) : null; };
$dup = sn_analytics_live_pages_from_rows( array( array( 'path' => '/About/', 'readers' => 1 ), array( 'path' => '/about/', 'readers' => 2 ) ), $case );
ok( 1 === count( $dup ) && 3 === $dup[0]['readers'], 'two spellings of one page are listed once, counts summed (keyed by the page, not the path)' );
$listed = array();
foreach ( array( '/notes/alpha/', '/draft/', '/locked/', '/hidden/', '/product/', '/' ) as $p ) { $listed[ $p ] = null !== sn_analytics_live_pages_resolve( $p ); }
ok( array( '/notes/alpha/' => true, '/draft/' => false, '/locked/' => false, '/hidden/' => false, '/product/' => false, '/' => true ) === $listed, 'only a published, password-free, indexable note or page (or Home) resolves: draft, password, noindex and other post types never do' );
$many = array();
for ( $i = 0; $i < 9; $i++ ) { $many[] = array( 'path' => '/', 'readers' => 1 ); }
ok( 1 === count( sn_analytics_live_pages_from_rows( $many, $resolve ) ), 'one page appears once' );
ok( array() === sn_analytics_live_pages_from_rows( array(), $resolve ), 'nobody active: an empty list, not null' );
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array( 'human' => 3 ), 'views_today' => 4, 'fetched' => 9, 'pages' => array( array( 'label' => 'Alpha', 'url' => 'u', 'readers' => 3 ) ) );
ok( 'Alpha' === sn_analytics_live_payload( false )['pages'][0]['label'], 'the public payload carries the list' );
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array(), 'views_today' => 0, 'fetched' => 9 );
ok( null === sn_analytics_live_payload( false )['pages'], 'a cache written before the list existed answers null, never an empty list' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/public-stats-live.php' ), 'data-sn-live-pages' ), 'the strip carries the list hook' );

echo "\nGroup: the last hour\n";
$sql = sn_analytics_live_hour_sql();
ok( false !== strpos( $sql, "toUnixTimestamp(toStartOfInterval(timestamp, INTERVAL '5' MINUTE)) AS slot" ) && false !== strpos( $sql, 'GROUP BY slot' ) && false === strpos( $sql, 'GROUP BY toStart' ), 'twelve 5-minute slots, grouped by the SELECT alias (Analytics Engine refuses a function in GROUP BY)' );
ok( false !== strpos( $sql, 'count(DISTINCT index1) AS readers' ) && false !== strpos( $sql, "INTERVAL '60' MINUTE" ), 'distinct readers per slot over the last hour' );
$now  = 1791490000; // 2026-10-08 16:06:40 UTC
$cur  = intdiv( $now, 300 ) * 300;
$rows = array( array( 'slot' => gmdate( 'Y-m-d H:i:s', $cur ), 'readers' => 3 ), array( 'slot' => gmdate( 'Y-m-d H:i:s', $cur - 600 ), 'readers' => 4 ), array( 'slot' => gmdate( 'Y-m-d H:i:s', $cur - 7200 ), 'readers' => 9 ) );
$hour = sn_analytics_live_hour_from_rows( $rows, $now );
ok( 12 === count( $hour ) && $cur === $hour[11]['t'] && $cur - 3300 === $hour[0]['t'], 'always twelve slots, oldest first, ending at the current slot' );
ok( 3 === $hour[11]['readers'] && 4 === $hour[9]['readers'] && 0 === $hour[10]['readers'], 'a slot the answer left out is a real 0, the others keep their counts' );
ok( 9 !== max( array_column( $hour, 'readers' ) ), 'a row outside the hour is ignored' );
ok( 5 === sn_analytics_live_hour_from_rows( array( array( 'slot' => (string) $cur, 'readers' => 5 ) ), $now )[11]['readers'], 'a slot in unix seconds (what the query now selects) lands in its place' );
ok( array_fill( 0, 12, 0 ) === array_column( sn_analytics_live_hour_from_rows( array(), $now ), 'readers' ), 'a quiet hour is twelve zeros, not null' );
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array(), 'views_today' => 0, 'fetched' => 9 );
ok( null === sn_analytics_live_payload( false )['hour'], 'a cache written before the hour existed answers null' );
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ]['hour'] = $hour;
ok( 12 === count( sn_analytics_live_payload( false )['hour'] ), 'the public payload carries the hour' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/public-stats-live.php' ), 'data-sn-live-hour' ), 'the strip carries the hour hook' );

echo "\nGroup: where current readers arrived from (admin only)\n";
$sql = sn_analytics_live_sources_sql();
ok( false !== strpos( $sql, 'blob3 AS host' ) && false !== strpos( $sql, "blob1 = 'pv'" ) && false !== strpos( $sql, "INTERVAL '5' MINUTE" ) && false !== strpos( $sql, 'GROUP BY host' ), 'pageviews in the last 5 minutes, distinct readers per referrer host' );
$src = sn_analytics_live_sources_from_rows( array(
	array( 'host' => 'www.google.com', 'readers' => 2 ),
	array( 'host' => 'google.com', 'readers' => 1 ),
	array( 'host' => '', 'readers' => 2 ),
	array( 'host' => SN_ANALYTICS_INTERNAL_REFERRER, 'readers' => 7 ),
	array( 'host' => 'news.ycombinator.com', 'readers' => 1 ),
), array() );
ok( array( 'label' => 'Google', 'readers' => 3 ) === $src[0], 'hosts fold into the dashboard\'s source names, counts summed' );
ok( in_array( 'Direct', array_column( $src, 'label' ), true ) && in_array( 'Hacker News', array_column( $src, 'label' ), true ), 'no referrer reads Direct; a known host gets its name' );
ok( ! in_array( SN_ANALYTICS_INTERNAL_REFERRER, array_column( $src, 'label' ), true ) && 3 === count( $src ), 'a click inside the site is not a source' );
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array( 'human' => 3 ), 'views_today' => 4, 'fetched' => 9, 'sources' => $src );
ok( ! array_key_exists( 'sources', sn_analytics_live_payload( false ) ), 'the public payload never carries sources' );
ok( 'Google' === sn_analytics_live_payload( true )['sources'][0]['label'], 'the admin payload does' );

echo "\nGroup: the admin Right now block\n";
$html = sn_analytics_live_admin_html();
foreach ( array( 'data-sn-live-hour', 'data-sn-live-pages', 'data-sn-live-sources', 'data-sn-live-meta', 'data-updated=', 'data-empty=' ) as $hook ) {
	ok( false !== strpos( $html, $hook ), "the block carries $hook" );
}
$root = dirname( __DIR__ );
ok( false !== strpos( (string) file_get_contents( "$root/inc/analytics-view-overview.php" ), 'sn_analytics_live_admin_html(' ), 'the classic Right now panel prints the block' );
ok( false !== strpos( (string) file_get_contents( "$root/apps/sn-analytics/parts/painters/view-overview.php" ), 'sn_analytics_live_admin_html(' ), 'the native Overview prints the block' );
$widget = (string) file_get_contents( "$root/assets/desktop-mode-widget-views.js" );
ok( false !== strpos( $widget, 'data-sn-live-hour' ) && false !== strpos( $widget, 'data-sn-live-top' ), 'the Traffic widget carries the bars and the top page' );
ok( false !== strpos( (string) file_get_contents( "$root/phpcs.xml.dist" ), 'sn_analytics_live_admin_html' ), 'the builder is declared an escaping function (everything in it is escaped inside)' );

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

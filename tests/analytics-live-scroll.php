<?php
/**
 * Tests for inc/analytics-live-scroll.php: how far the current readers of each
 * "Being read now" page have scrolled, from the beacon's 25/50/75/100
 * milestones over the same 5-minute window and human rule as the list.
 *
 * Covers the one grouped query (no reader key leaves Analytics Engine), the
 * fold onto the listed pages (same canonical path and resolver, monotone
 * milestones, never more than the page's readers), null (not read) apart from
 * 0 (read, nobody scrolled), the admin-only payload, the markup strings and
 * the admin row's plain-words line (assets/live-now.js, run under node).
 *
 * Run: php tests/analytics-live-scroll.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {}
$GLOBALS['t'] = array(); $GLOBALS['q'] = array(); $GLOBALS['answer'] = null;
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $d; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function sn_analytics_query( $sql ) { $GLOBALS['q'][] = $sql; return $GLOBALS['answer']; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$root = dirname( __DIR__ );
require $root . '/inc/analytics-realtime.php';
require $root . '/inc/analytics-live-pages.php';
require $root . '/inc/analytics-live-admin.php';
require $root . '/inc/analytics-live.php';
if ( is_file( $root . '/inc/analytics-live-scroll.php' ) ) { require $root . '/inc/analytics-live-scroll.php'; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$has = function_exists( 'sn_analytics_live_scroll_sql' ) && function_exists( 'sn_analytics_live_scroll_attach' );
ok( $has, 'the live scroll module exists' );

echo "\nGroup: one grouped query, aggregates only\n";
$sql = $has ? sn_analytics_live_scroll_sql() : '';
ok( false !== strpos( $sql, "blob1 = 'sc'" ) && false !== strpos( $sql, 'double1 AS pct' ) && false !== strpos( $sql, 'count(DISTINCT index1) AS readers' ), "scroll events only, distinct readers per milestone" );
ok( false !== strpos( $sql, "INTERVAL '5' MINUTE" ) && false !== strpos( $sql, sn_analytics_class_where( 'human' ) ) && false !== strpos( $sql, sn_analytics_excluded_path_sql() ), 'the same window, human rule and excluded paths as the list' );
ok( false !== strpos( $sql, 'GROUP BY path, pct' ) && false === stripos( preg_replace( '/count\(DISTINCT index1\)/', '', $sql ), 'index1' ), 'grouped by path and milestone; no reader key is selected or grouped' );

echo "\nGroup: the fold onto the listed pages\n";
$resolve = static function ( $path ) {
	$known = array( '/notes/alpha/' => array( 'Alpha', 'https://x.test/notes/alpha/' ), '/about/' => array( 'About', 'https://x.test/about/' ) );
	return $known[ $path ] ?? null;
};
$pages = array(
	array( 'label' => 'Alpha', 'url' => 'https://x.test/notes/alpha/', 'readers' => 3 ),
	array( 'label' => 'About', 'url' => 'https://x.test/about/', 'readers' => 2 ),
);
$rows = array(
	array( 'path' => '/notes/alpha/', 'pct' => 25, 'readers' => 1 ),
	array( 'path' => '/notes/alpha/?utm_source=x', 'pct' => 25, 'readers' => 1 ),
	array( 'path' => '/notes/alpha', 'pct' => 50, 'readers' => 1 ),
	array( 'path' => '/notes/alpha/', 'pct' => 100, 'readers' => 1 ),
	array( 'path' => '/draft/', 'pct' => 100, 'readers' => 9 ),
);
$out = $has ? sn_analytics_live_scroll_attach( $pages, $rows, $resolve ) : array();
$a = $out[0]['scroll'] ?? null;
ok( array( 'reached_25' => 2, 'reached_50' => 1, 'reached_75' => 1, 'reached_100' => 1, 'readers_with_scroll' => 2 ) === $a, 'variants fold into the page; a deeper milestone counts toward every shallower one (75 read from 100)' );
ok( array( 'reached_25' => 0, 'reached_50' => 0, 'reached_75' => 0, 'reached_100' => 0, 'readers_with_scroll' => 0 ) === ( $out[1]['scroll'] ?? null ), 'a listed page with no scroll rows in a successful read is 0, not null' );
ok( 'Alpha' === ( $out[0]['label'] ?? '' ) && 3 === ( $out[0]['readers'] ?? 0 ) && 2 === count( $out ), 'the list itself is unchanged: same pages, order and readers' );
$capped = $has ? sn_analytics_live_scroll_attach( array( array( 'label' => 'About', 'url' => 'https://x.test/about/', 'readers' => 1 ) ), array( array( 'path' => '/about/', 'pct' => 50, 'readers' => 4 ) ), $resolve ) : array();
ok( 1 === ( $capped[0]['scroll']['reached_25'] ?? null ) && 1 === ( $capped[0]['scroll']['readers_with_scroll'] ?? null ), 'never more readers scrolled than are on the page' );
$failed = $has ? sn_analytics_live_scroll_attach( $pages, null, $resolve ) : array();
ok( array_key_exists( 'scroll', $failed[0] ?? array() ) && null === $failed[0]['scroll'] && null === $failed[1]['scroll'], 'a failed read is null on every page (not measured), never 0' );
ok( ! $has || null === sn_analytics_live_scroll_attach( null, $rows, $resolve ), 'no list, nothing to attach to: null stays null' );

echo "\nGroup: the refresh makes one extra query\n";
$GLOBALS['answer'] = array();
$GLOBALS['q'] = array();
$r = $has ? sn_analytics_live_scroll_read( $pages ) : null;
ok( 1 === count( $GLOBALS['q'] ) && 0 === ( $r[0]['scroll']['readers_with_scroll'] ?? null ), 'one query for every listed page' );
$GLOBALS['q'] = array();
ok( ! $has || ( array() === sn_analytics_live_scroll_read( array() ) && 0 === count( $GLOBALS['q'] ) ), 'nobody on a page: no query' );
ok( false !== strpos( (string) file_get_contents( $root . '/inc/analytics-realtime.php' ), 'sn_analytics_live_scroll_read( $pages )' ), 'the realtime refresh attaches it to the cached list' );
ok( false !== strpos( (string) file_get_contents( $root . '/signal-and-noise-tools.php' ), "inc/analytics-live-scroll.php'" ), 'the plugin loads the module' );

echo "\nGroup: admin only\n";
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array( 'human' => 3 ), 'views_today' => 4, 'fetched' => 9, 'pages' => $out ?: $pages );
$pub = sn_analytics_live_payload( false )['pages'];
$adm = sn_analytics_live_payload( true )['pages'];
ok( ! array_key_exists( 'scroll', $pub[0] ) && 'Alpha' === $pub[0]['label'], 'the public answer (/stats, edge-cached) carries no scroll' );
ok( isset( $adm[0]['scroll'] ) && 1 === $adm[0]['scroll']['reached_100'], 'the admin answer, and so live-now, carries it' );
$ab = (string) file_get_contents( $root . '/inc/abilities-live-now.php' );
ok( false !== strpos( $ab, 'scroll { reached_25, reached_50, reached_75, reached_100, readers_with_scroll }' ), 'the live-now description names the field' );

echo "\nGroup: Being read now says it in words\n";
$html = sn_analytics_live_admin_html( array() );
ok( 1 === preg_match( '/data-sn-live-pages[^>]*data-scroll-none="not scrolled yet"[^>]*data-scroll-quarter="%s past a quarter"[^>]*data-scroll-half="%s past halfway"[^>]*data-scroll-end="%s at the end"/', $html ), 'the pages list carries its scroll strings; the sources list does not' );
ok( false === strpos( (string) preg_replace( '/^.*data-sn-live-sources/s', '', $html ), 'data-scroll-' ), 'the sources list carries none' );
$css = (string) file_get_contents( $root . '/assets/analytics/live-admin.css' );
ok( 1 === preg_match( '/\.sn-live-admin__depth\{[^}]*flex-basis:100%[^}]*font-size:11px[^}]*color:var\(--os-ui-color-text-subtle,#646970\)/', $css ) && 1 === preg_match( '/\.sn-live-admin__list li\{[^}]*flex-wrap:wrap/', $css ), 'the line sits under the title at the 11px floor in the muted token' );
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) {
	ok( false, 'node is needed to run assets/live-now.js' );
} else {
	$run = static fn( $ans, $mode = '' ) => json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/live-now-scroll.js' ) . ' ' . escapeshellarg( json_encode( $ans ) ) . ( $mode ? ' ' . $mode : '' ) ), true );
	$s = static fn( $q, $h, $e, $w ) => array( 'reached_25' => $q, 'reached_50' => $h, 'reached_75' => $h, 'reached_100' => $e, 'readers_with_scroll' => $w );
	$li = $run( array( 'fetched' => 1, 'pages' => array(
		array( 'label' => 'Alpha', 'url' => 'u1', 'readers' => 4, 'scroll' => $s( 4, 3, 1, 4 ) ),
		array( 'label' => 'Beta', 'url' => 'u2', 'readers' => 2, 'scroll' => $s( 0, 0, 0, 0 ) ),
		array( 'label' => 'Gamma', 'url' => 'u3', 'readers' => 1, 'scroll' => null ),
		array( 'label' => 'Delta', 'url' => 'u4', 'readers' => 1 ),
		array( 'label' => 'Eps', 'url' => 'u5', 'readers' => 2, 'scroll' => $s( 1, 0, 0, 1 ) ),
	) ) );
	ok( '1 past a quarter, 2 past halfway, 1 at the end' === ( $li[0]['depth'] ?? null ), 'deepest milestone per reader, each reader said once' );
	ok( 'not scrolled yet' === ( $li[1]['depth'] ?? null ), 'read, and nobody past a quarter: "not scrolled yet"' );
	ok( isset( $li[3] ) && null === $li[2]['depth'] && null === $li[3]['depth'], 'not read (null) or an older cache (absent): no line at all' );
	ok( '1 past a quarter' === ( $li[4]['depth'] ?? null ) && 'Alpha|4|1 past a quarter, 2 past halfway, 1 at the end' === ( $li[0]['text'] ?? '' ), 'zero parts are left out; the title and count stay as they were' );
	$p = $run( array( 'pages' => array( array( 'label' => 'Alpha', 'url' => 'u1', 'readers' => 4, 'scroll' => $s( 4, 3, 1, 4 ) ) ) ), 'public' );
	ok( isset( $p[0] ) && null === $p[0]['depth'] && false !== strpos( $p[0]['text'], 'Alpha' ), 'the public strip never draws it, even if an answer carried it' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

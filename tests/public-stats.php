<?php
/**
 * Standalone fixture tests for inc/public-stats.php — [sn_public_stats],
 * the public stats page (rollups read-only, no new collection).
 *
 * The rollup read functions are STUBBED here (the module treats them as a
 * data source; their own behavior is pinned by the analytics test files) —
 * what this file pins is the module's honesty rules: never-measured is
 * "unknown" and NEVER zeros, the no-data cache sentinel is distinguishable
 * from a cache miss, admin paths can never surface, everything escapes,
 * and the second render reads the transient instead of the rollup table.
 *
 * Run: php tests/public-stats.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) )        { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) )     { define( 'SNT_VERSION', 'test' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }

function __( $s, $d = null ) { return (string) $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }
$GLOBALS['__enq'] = array();
function wp_enqueue_style( $h, $s = '', $d = array(), $v = false, $m = 'all' ) { $GLOBALS['__enq'][] = $h; return true; }
function plugins_url( $path = '', $plugin = '' ) { return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' ); }
$GLOBALS['__transients'] = array();
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
// Path→title resolution: only /notes/alpha/ resolves; everything else falls
// back to the raw path (the "never a dead guess" contract).
function url_to_postid( $url ) { return false !== strpos( (string) $url, '/notes/alpha/' ) ? 77 : 0; }
function get_the_title( $id ) { return 77 === (int) $id ? 'Alpha & the <Signal>' : ''; }

// The rollup read layer — counting stubs, fixture-driven.
$GLOBALS['__ct_calls'] = 0; $GLOBALS['__ct_return'] = array();
$GLOBALS['__dr_calls'] = 0; $GLOBALS['__dr_return'] = array();
function sn_analytics_class_totals( $from, $to ) { $GLOBALS['__ct_calls']++; $GLOBALS['__ct_window'] = array( $from, $to ); return $GLOBALS['__ct_return']; }
function sn_analytics_daily_range( $from, $to, $class = 'human' ) { $GLOBALS['__dr_calls']++; $GLOBALS['__dr_class'] = $class; return $GLOBALS['__dr_return']; }
function sn_analytics_is_excluded_path( $path ) {
	$path = (string) $path;
	return '/wp-admin' === $path || 0 === strpos( $path, '/wp-admin/' ) || 0 === strpos( $path, '/wp-login.php' );
}

require __DIR__ . '/../inc/public-stats.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "[sn_public_stats] — the public stats page\n\n";

ok( isset( $GLOBALS['__shortcodes']['sn_public_stats'] ), 'shortcode registered' );

echo "\nGroup: the window\n";
list( $from, $to ) = sn_public_stats_window();
ok( $to === gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'window ends YESTERDAY (UTC) — today is partial and would undercount' );
ok( $from === gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), 'window spans 30 complete days' );

echo "\nGroup: assemble — the honesty rules (pure)\n";
ok( null === sn_public_stats_assemble( array(), array() ), 'nothing measured assembles to NULL — never-measured is not zero' );
$ct = array(
	'human'   => array( 'views' => 900, 'visits' => 1100 ),
	'suspect' => array( 'views' => 40, 'visits' => 40 ),
	'bot'     => array( 'views' => 60, 'visits' => 60 ),
);
$rows = array(
	array( 'day' => '2026-08-06', 'path' => '/notes/alpha/', 'views' => 300 ),
	array( 'day' => '2026-08-05', 'path' => '/notes/alpha/', 'views' => 200 ),
	array( 'day' => '2026-08-06', 'path' => '/notes/beta/', 'views' => 350 ),
	array( 'day' => '2026-08-06', 'path' => '/wp-admin/options.php', 'views' => 999 ),
	array( 'day' => '2026-08-06', 'path' => '/', 'views' => 50 ),
	array( 'day' => '2026-08-05', 'path' => '/notes/beta', 'views' => 100 ),
);
$a = sn_public_stats_assemble( $ct, $rows );
ok( 900 === $a['views'] && 1100 === $a['visits'], 'human totals pass through — visits CAN exceed views (reader-days, the structural fact, never "corrected")' );
ok( 100 === $a['automated_views'], 'automated = suspect + bot views summed' );
ok( array( '/notes/alpha/' => 500, '/notes/beta/' => 450, '/' => 50 ) === $a['top'], 'top aggregates a path ACROSS days AND across slash variants (/notes/beta + /notes/beta/ = one entry), sorts by views, and the admin path NEVER surfaces (v10.65.1: the live split-ranking fix)' );

echo "\nGroup: data — cache + the no-data sentinel\n";
$GLOBALS['__ct_return'] = array(); $GLOBALS['__dr_return'] = array();
ok( null === sn_public_stats_data(), 'no measurements: data() returns null' );
ok( array( 'none' => true ) === get_transient( SN_PUBLIC_STATS_CACHE_KEY ), 'the no-data SENTINEL is cached — distinguishable from a cache miss' );
$GLOBALS['__ct_return'] = $ct; // rollups now have data, but the sentinel is cached
ok( null === sn_public_stats_data(), 'a cached sentinel serves null without re-reading (TTL honesty: fresh data appears when the hour turns, not mid-cache)' );
$calls_before = $GLOBALS['__ct_calls'];
delete_transient( SN_PUBLIC_STATS_CACHE_KEY );
$GLOBALS['__dr_return'] = $rows;
$d = sn_public_stats_data();
ok( is_array( $d ) && 900 === $d['views'], 'cache miss reads the rollups and assembles' );
ok( $GLOBALS['__ct_calls'] === $calls_before + 1, 'exactly one rollup read per cache miss' );
ok( 'human' === $GLOBALS['__dr_class'], 'the per-path read asks for the HUMAN class only' );
sn_public_stats_data();
ok( $GLOBALS['__ct_calls'] === $calls_before + 1, 'second call serves the transient — zero further rollup reads' );

echo "\nGroup: render\n";
$html = call_user_func( $GLOBALS['__shortcodes']['sn_public_stats'] );
ok( in_array( 'sn-public-stats-front', $GLOBALS['__enq'], true ), 'enqueues its own front stylesheet' );
ok( false !== strpos( $html, '>900<' ) && false !== strpos( $html, '>1,100<' ), 'tiles render the human totals (i18n-formatted)' );
ok( false !== strpos( $html, '>100<' ), 'the automated tile renders — the filtered class is shown, not hidden' );
ok( false !== strpos( $html, 'Alpha &amp; the &lt;Signal&gt;' ), 'a resolved title renders ESCAPED' );
ok( false !== strpos( $html, '/notes/beta/' ), 'an unresolvable path falls back to the path itself' );
ok( false !== strpos( $html, 'Home' ), 'the homepage path renders as Home' );
ok( false === strpos( $html, 'wp-admin' ), 'no admin path anywhere in the public render' );
ok( false !== strpos( $html, 'reader-days' ), 'the visits tile carries the reader-days honesty line' );
ok( false !== strpos( $html, 'cookieless' ), 'the method note renders' );

echo "\nGroup: render — never-measured\n";
delete_transient( SN_PUBLIC_STATS_CACHE_KEY );
$GLOBALS['__ct_return'] = array(); $GLOBALS['__dr_return'] = array();
$html2 = call_user_func( $GLOBALS['__shortcodes']['sn_public_stats'] );
ok( false !== strpos( $html2, 'Not measured yet' ), 'never-measured renders the honest unknown line' );
ok( false === strpos( $html2, 'sn-public-stats__stat' ), 'and NO stat tiles — an unknown never renders as a zero' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

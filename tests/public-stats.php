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
function wp_enqueue_script( $h, $s = '', $d = array(), $v = false, $f = false ) { $GLOBALS['__enq'][] = $h; return true; }
function wp_localize_script( $h, $n, $d ) { $GLOBALS['__l10n'][ $h ] = $d; return true; }
function rest_url( $p = '' ) { return 'https://example.com/wp-json/' . ltrim( (string) $p, '/' ); }
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
// Sessions: one row per day of the session rollup; visits is site-wide sessions.
$GLOBALS['__sessions'] = array( array( 'day' => '2026-08-01', 'visits' => 400, 'bounce_pct' => 60, 'ppv' => 1.4, 'median_dur' => 50, 'two_pages' => 100, 'deep_pages' => 60 ), array( 'day' => '2026-08-02', 'visits' => 240, 'bounce_pct' => 60, 'ppv' => 1.4, 'median_dur' => 50, 'two_pages' => 60, 'deep_pages' => 36 ) );
// The render path reads a full window; the fixture rows plus a zero row for
// every window day, unless a test asks for the partial window as given.
$GLOBALS['__pad'] = true;
$GLOBALS['__pad_skip'] = 0; // leave the newest N window days unrolled (the nightly lag)
function sn_session_rollup_read( $from, $to, $class ) {
	$r = $GLOBALS['__sessions'];
	if ( ! is_array( $r ) || array() === $r || ! $GLOBALS['__pad'] ) {
		return $r;
	}
	for ( $t = strtotime( $from . ' UTC' ); $t <= strtotime( $to . ' UTC' ) - 86400 * $GLOBALS['__pad_skip']; $t += 86400 ) {
		$r[] = array( 'day' => gmdate( 'Y-m-d', $t ), 'visits' => 0, 'bounce_pct' => 0, 'ppv' => 0, 'median_dur' => 0, 'two_pages' => 0, 'deep_pages' => 0 );
	}
	return $r;
}
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
// pageview_visits is deliberately 999: Visits must never read it again.
$GLOBALS['__totals'] = array( 'views' => 900, 'visits' => 1100, 'pageview_visits' => 999, 'scroll_avg_per_view' => 47.6, 'time_avg_per_view' => 65000 );
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__totals']; }
$GLOBALS['__dist'] = array( array( 'label' => '0-25', 'views' => 300 ), array( 'label' => '25-50', 'views' => 200 ), array( 'label' => '50-75', 'views' => 270 ), array( 'label' => '75+', 'views' => 130 ) );
function sn_analytics_distribution( $m, $from, $to, $class = 'human' ) { return 'scroll' === $m ? $GLOBALS['__dist'] : array(); }
$GLOBALS['__sources'] = array(
	array( 'value' => 'Google', 'views' => 300, 'visits' => 200 ),
	array( 'value' => '(direct)', 'views' => 250, 'visits' => 180 ),
	array( 'value' => 'tiny.example', 'views' => 4, 'visits' => 2 ),
);
function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) { return $GLOBALS['__sources']; }
$GLOBALS['__countries'] = array(
	array( 'value' => 'US', 'views' => 898, 'visits' => 300 ), // with IS, every view of the window (900)
	array( 'value' => 'IS', 'views' => 2, 'visits' => 1 ),
);
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return 'country' === $dim ? $GLOBALS['__countries'] : array(); }
function snt_desktop_machine_readers_identity( array $rows ) {
	$out = array( 'verified' => 0, 'unverified' => 0, 'not_measured' => 0 );
	foreach ( $rows as $r ) { $out[ $r['id'] ] += $r['hits']; }
	return $out;
}
function snt_desktop_db_failed() { return false; }
function snt_desktop_pct( $part, $whole ) { return $whole > 0 ? round( 100 * $part / $whole ) . '%' : ''; }
require __DIR__ . '/../inc/desktop-mode-reading.php'; // the SN Reading rows, reused as is.
function sn_analytics_class_totals( $from, $to ) { $GLOBALS['__ct_calls']++; $GLOBALS['__ct_window'] = array( $from, $to ); return $GLOBALS['__ct_return']; }
function sn_analytics_daily_range( $from, $to, $class = 'human' ) { $GLOBALS['__dr_calls']++; $GLOBALS['__dr_class'] = $class; return $GLOBALS['__dr_return']; }
function sn_analytics_is_excluded_path( $path ) {
	$path = (string) $path;
	return '/wp-admin' === $path || 0 === strpos( $path, '/wp-admin/' ) || 0 === strpos( $path, '/wp-login.php' );
}

require __DIR__ . '/../inc/public-stats.php';
list( $__wf, $__wt ) = sn_public_stats_window();
$GLOBALS['__options'][ SN_PUBLIC_STATS_MACHINES_OPT ] = array( 'from' => $__wf, 'to' => $__wt, 'machines' => array( 'total' => 4200, 'split' => array( 'verified' => 2100, 'unverified' => 1680, 'not_measured' => 420 ) ) );
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
// Dated FROM THE LIVE WINDOW, never absolutely: the render group below feeds
// these rows to the shortcode, whose window is the last 30 UTC days ending
// yesterday. Absolute August dates sat inside that window until 2026-09-06
// UTC and then left it, and the chart assertions went red on a release cut
// that changed nothing they measure. A fixture inside a moving window must
// move with it.
$win_live = sn_public_stats_window();
$win_day  = static function ( $n ) use ( $win_live ) { return gmdate( 'Y-m-d', strtotime( $win_live[0] . ' UTC' ) + $n * DAY_IN_SECONDS ); };
$rows = array(
	array( 'day' => $win_day( 2 ), 'path' => '/notes/alpha/', 'views' => 300 ),
	array( 'day' => $win_day( 1 ), 'path' => '/notes/alpha/', 'views' => 200 ),
	array( 'day' => $win_day( 2 ), 'path' => '/notes/beta/', 'views' => 350 ),
	array( 'day' => $win_day( 2 ), 'path' => '/wp-admin/options.php', 'views' => 999 ),
	array( 'day' => $win_day( 2 ), 'path' => '/', 'views' => 50 ),
	array( 'day' => $win_day( 1 ), 'path' => '/notes/beta', 'views' => 100 ),
);
$a = sn_public_stats_assemble( $ct, $rows, null, 640 );
ok( 900 === $a['views'] && 640 === $a['visits'], 'assemble carries the visits figure it is handed' );
ok( 640 === sn_public_stats_sessions_total( $GLOBALS['__sessions'] ), 'Visits is site-wide sessions: the session rollup visits summed over the window (400 + 240)' );
// One reader, one sitting, two pages: the session rollup holds ONE visit for it.
ok( 1 === sn_public_stats_sessions_total( array( array( 'day' => '2026-08-01', 'visits' => 1, 'ppv' => 2 ) ) ), 'a reader opening two pages in one sitting counts once' );
ok( null === sn_public_stats_sessions_total( null ), 'a failed session read is null, not 0' );
ok( null === sn_public_stats_sessions_total( array() ), 'an empty session rollup is null: the tile is left out' );
ok( null === sn_public_stats_assemble( $ct, $rows )['visits'], 'unmeasured pageview visits stay null, never 0' );
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
ok( false !== strpos( $html, '>900<' ) && false !== strpos( $html, '>640<' ) && false === strpos( $html, '>1,100<' ) && false === strpos( $html, '>999<' ), 'tiles render views and site-wide sessions, never the rollup visits or pageview_visits' );
ok( false !== strpos( $html, '>100<' ), 'the automated tile renders — the filtered class is shown, not hidden' );
ok( false !== strpos( $html, 'Alpha &amp; the &lt;Signal&gt;' ), 'a resolved title renders ESCAPED' );
ok( false !== strpos( $html, '/notes/beta/' ), 'an unresolvable path falls back to the path itself' );
ok( false !== strpos( $html, 'Home' ), 'the homepage path renders as Home' );
ok( false === strpos( $html, 'wp-admin' ), 'no admin path anywhere in the public render' );
ok( false !== strpos( $html, 'times a reader came to the site; reading several pages in one sitting counts once' ), 'the visits tile says what it counts, in the owner-approved line' );
ok( false !== strpos( $html, 'cookieless' ), 'the method note renders' );

echo "\nGroup: render — never-measured\n";
delete_transient( SN_PUBLIC_STATS_CACHE_KEY );
$GLOBALS['__ct_return'] = array(); $GLOBALS['__dr_return'] = array();
$html2 = call_user_func( $GLOBALS['__shortcodes']['sn_public_stats'] );
ok( false !== strpos( $html2, 'Not measured yet' ), 'never-measured renders the honest unknown line' );
ok( false === strpos( $html2, 'sn-public-stats__stat' ), 'and NO stat tiles — an unknown never renders as a zero' );

echo "\nGroup: the daily series — charts that speak (assemble)\n";
// A fixed window makes the series testable: 10 days, rows on 3 of them.
$win  = array( '2026-08-01', '2026-08-10' );
$rows10 = array(
	array( 'day' => '2026-08-03', 'path' => '/notes/alpha/', 'views' => 40 ),
	array( 'day' => '2026-08-03', 'path' => '/notes/beta/', 'views' => 30 ),
	array( 'day' => '2026-08-07', 'path' => '/notes/alpha/', 'views' => 5 ),
	array( 'day' => '2026-08-09', 'path' => '/', 'views' => 25 ),
	array( 'day' => '2026-08-03', 'path' => '/wp-admin/x', 'views' => 999 ),
	array( 'day' => '2026-07-20', 'path' => '/notes/alpha/', 'views' => 77 ),
);
$a10 = sn_public_stats_assemble( $ct, $rows10, $win );
ok( isset( $a10['daily'] ) && 10 === count( $a10['daily'] ), 'daily series spans EVERY day of the window' );
ok( array( '2026-08-01', '2026-08-10' ) === array( array_key_first( $a10['daily'] ), array_key_last( $a10['daily'] ) ), 'series keys run from..to inclusive, in order' );
ok( 0 === $a10['daily']['2026-08-02'], 'a day with no rows inside a MEASURED window is a real zero — the inverse of never-measured-is-not-zero' );
ok( 70 === $a10['daily']['2026-08-03'], 'a day sums across paths (40+30) — and the admin row never enters the series' );
ok( ! array_key_exists( '2026-07-20', $a10['daily'] ), 'a row outside the window cannot leak into the series' );
ok( 100 === array_sum( $a10['daily'] ), 'series total is exactly the included views' );

echo "\nGroup: the rhythm sentence (pure, deterministic — no model near a reader)\n";
$sent = sn_public_stats_rhythm_sentence( $a10['daily'] );
ok( is_string( $sent ) && false !== strpos( $sent, '100' ), 'the sentence states the window total' );
ok( false !== strpos( $sent, '70' ), 'the sentence names the busiest day count' );
ok( false !== strpos( $sent, 'Aug 3' ) || false !== strpos( $sent, '3 Aug' ), 'the sentence names the busiest day itself' );
ok( false !== strpos( $sent, '70' ) && false !== strpos( $sent, '30' ), 'the halves comparison carries both halves (first 5 days = 70, last 5 = 30)' );
// Quietest-day ties resolve to the EARLIEST zero day so two runs agree.
ok( false !== strpos( $sent, 'Aug 1' ) || false !== strpos( $sent, '1 Aug' ), 'quietest-day ties resolve to the earliest day, deterministically' );
$flat = sn_public_stats_rhythm_sentence( array( '2026-08-01' => 0, '2026-08-02' => 0 ) );
ok( '' === $flat, 'an all-zero series yields NO sentence — the tiles already say the number; a rhythm section over silence is filler' );

echo "\nGroup: render — the chart, its table twin, and the prose (charts that speak)\n";
delete_transient( SN_PUBLIC_STATS_CACHE_KEY );
$GLOBALS['__ct_return'] = $ct; $GLOBALS['__dr_return'] = $rows;
$html3 = call_user_func( $GLOBALS['__shortcodes']['sn_public_stats'] );
ok( false !== strpos( $html3, 'sn-public-stats__chart' ), 'the daily chart renders when the series has reads' );
ok( false !== strpos( $html3, 'aria-hidden="true"' ) && false !== strpos( $html3, 'focusable="false"' ), 'the SVG is decorative — the twin and the prose carry the content, the picture never does' );
ok( false === strpos( $html3, '<details' ) && false === strpos( $html3, '<summary' ), 'the calendar is VISIBLE: not folded behind a details (owner call)' );
ok( 1 === preg_match( '/<div class="sn-public-stats__twin"[^>]*><table>/', $html3 ), 'the calendar table sits in a plain scroll wrapper' );
ok( false === strpos( $html3, 'screen-reader-text' ) && false === strpos( $html3, 'visually-hidden' ), 'and is not visually hidden' );
ok( false !== strpos( $html3, '<caption id="sn-public-stats-calendar">Daily human pageviews' ), 'the calendar keeps its caption, and the scroll region is named by it' );
// The twin is CALENDAR-shaped (owner call after the live 30-row column read
// as a wall): weeks as rows, weekdays as columns. This is MORE navigable,
// not merely shorter — a screen reader announces every cell with its row
// (the week) and column (the weekday) context.
ok( 8 === substr_count( $html3, '<th scope="col">' ), 'eight column headers: Week + the seven weekdays' );
$week_rows = substr_count( $html3, '<th scope="row">' );
ok( $week_rows >= 5 && $week_rows <= 6, 'each week is a row with its own scoped header (a 30-day window spans 5 or 6 Monday-start weeks; got ' . $week_rows . ')' );
ok( false !== strpos( $html3, '>Week of ' ), 'week row headers NAME their week' );
ok( false !== strpos( $html3, 'sn-public-stats__rhythm-summary' ), 'the one-paragraph prose summary renders beside the chart' );
// v13.97.5 (#1040): the shortcode sits directly under the page's H1 post
// title, so its section headings are H2 -- an H3 skipped a level.
ok( false !== strpos( $html3, '<h2>Reading rhythm</h2>' ), 'a11y: the rhythm heading is an H2 (H1 title -> H2 section, no skipped level)' );
$rhythm_block = substr( $html3, strpos( $html3, '<h2>Reading rhythm</h2>' ), strpos( $html3, '<h2>Where readers come from</h2>' ) - strpos( $html3, '<h2>Reading rhythm</h2>' ) );
ok( '' !== $rhythm_block && false === strpos( $rhythm_block, '<h3' ), 'a11y: no H3 in the rhythm block' );
// The chart never outranks the numbers: bars equal the window's day count,
// and so do the twin's day cells — the twin is the chart, not an excerpt.
$bar_count = substr_count( $html3, '<rect' );
ok( 30 === $bar_count, 'one bar per window day — 30 bars (got ' . $bar_count . ')' );
$day_cells = substr_count( $html3, 'sn-public-stats__twin-day' );
ok( 30 === $day_cells, 'one twin day-cell per window day (got ' . $day_cells . ')' );
$out_cells = substr_count( $html3, 'sn-public-stats__twin-out' );
ok( $week_rows * 7 - 30 === $out_cells, 'every calendar slot outside the window is an explicit em-dash cell, never a missing one — the grid stays rectangular for the screen reader (got ' . $out_cells . ')' );

echo "\nGroup: render — the rhythm section is absent when it would be filler\n";
delete_transient( SN_PUBLIC_STATS_CACHE_KEY );
$GLOBALS['__dr_return'] = array(); // totals exist, no daily rows -> all-zero series
$html4 = call_user_func( $GLOBALS['__shortcodes']['sn_public_stats'] );
ok( false === strpos( $html4, 'sn-public-stats__chart' ), 'an all-zero series renders NO chart' );
ok( false === strpos( $html4, 'sn-public-stats__twin' ), 'and no twin — a table of thirty zeros is noise wearing accessibility clothes' );
ok( false !== strpos( $html4, 'sn-public-stats__stat' ), 'while the tiles still render (totals exist)' );
// A stale cached payload from before the series existed must not fatal or
// half-render: the key carries a version so it can never be read again.
ok( 'sn_public_stats_v4' === SN_PUBLIC_STATS_CACHE_KEY, 'cache key bumped to _v4: the payload gained sessions, sources, countries, reading and machines' );

echo "\nGroup: the redesign (v4): tiles, peak, sections\n";
$fresh = static function () { delete_transient( SN_PUBLIC_STATS_CACHE_KEY ); return call_user_func( $GLOBALS['__shortcodes']['sn_public_stats'] ); };
$GLOBALS['__ct_return'] = $ct; $GLOBALS['__dr_return'] = $rows;
$h = $fresh();
$tile_n = static fn( $x ) => preg_match_all( '/<div class="sn-public-stats__tile[ "]/', $x );
ok( 3 === $tile_n( $h ) && 1 === substr_count( $h, '<div class="sn-public-stats__tiles">' ), 'Views, Visits and Automated render as ONE row of three tiles' );
$css = (string) file_get_contents( __DIR__ . '/../assets/public-stats-front.css' );
ok( false !== strpos( $css, '.sn-public-stats__tiles{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(0,1fr)' ) && false === strpos( $css, 'repeat(3' ), 'the tile row sizes itself to the tiles rendered: no fixed three-track grid' );
$GLOBALS['__sessions'] = null;
$h2t = $fresh();
ok( 2 === $tile_n( $h2t ) && false === strpos( $h2t, '>Visits<' ), 'a failed session read leaves the Visits tile out, and two tiles fill the row' );
$GLOBALS['__sessions'] = array( array( 'day' => '2026-08-01', 'visits' => 640 ) );

// The busiest day: one rule for the sentence, the red bar and the red cell.
ok( '2026-08-03' === sn_public_stats_busiest_day( array( '2026-08-01' => 5, '2026-08-03' => 9, '2026-08-05' => 9 ) ), 'busiest-day ties resolve to the earliest day' );
ok( null === sn_public_stats_busiest_day( array( '2026-08-01' => 0 ) ), 'an all-zero series has no busiest day' );
ok( 1 === substr_count( $h, '<rect class="sn-public-stats__peak"' ), 'exactly one bar, the busiest day, is marked red' );
ok( 1 === substr_count( $h, 'sn-public-stats__twin-day sn-public-stats__peak' ), 'exactly one calendar cell, the busiest day, is marked' );
ok( 1 === preg_match( '/sn-public-stats__peak">700</', $h ), 'the marked cell is the busiest day (300 + 350 + 50 = 700 views)' );

// Section order: h2 per section, in the approved order.
$order = array( 'Reading rhythm', 'Where readers come from', 'How they read', 'Humans and machines', 'Most read' );
$at    = array_map( static fn( $t ) => strpos( $h, '<h2>' . $t . '</h2>' ), $order );
ok( ! in_array( false, $at, true ) && $at === array_values( array_unique( $at ) ) && $at == ( function ( $a ) { sort( $a ); return $a; } )( $at ), 'every section has its h2, in order: rhythm, where, how, machines, most read' );
ok( false !== strpos( $h, '<div class="sn-public-stats__cols"><section class="sn-public-stats__col"><h2>Where readers come from' ), 'where and how sit side by side in one two-column row' );

// Privacy: fewer than 3 visits folds into Other.
ok( false !== strpos( $h, '>Google<' ) && false !== strpos( $h, '>Direct<' ), 'sources render by label, (direct) as Direct' );
ok( false === strpos( $h, 'tiny.example' ), 'a source with fewer than 3 visits is WITHHELD' );
ok( false === strpos( $h, 'Iceland' ) && false === strpos( $h, '>IS<' ), 'a country with fewer than 3 visits is WITHHELD' );
ok( 2 === substr_count( $h, '>Other<' ), 'withheld rows fold into an Other line (one per list)' );
$fold = sn_public_stats_fold( array( array( 'value' => 'a', 'views' => 2 ), array( 'value' => 'b', 'views' => 98 ) ), 'strval' );
ok( array( 'b', 'Other' ) === array_column( $fold, 'label' ) && 2 === $fold[1]['share'], 'without visits the threshold falls back to views; Other keeps its share' );
ok( false !== strpos( $h, '>United States<' ) || false !== strpos( $h, '>US<' ), 'a country renders by name (or its code without intl)' );
ok( false !== strpos( $h, 'Sources and countries with fewer than 3 visits are grouped as Other.' ), 'the page says so' );
ok( false !== strpos( $h, '>&lt;1%<' ), 'a folded group whose share rounds to zero reads <1%, never 0%' );
ok( 1 === preg_match( '/<span class="sn-public-stats__bar" aria-hidden="true">/', $h ) && false === strpos( $h, '<span class="sn-public-stats__bar">' ), 'every bar is decorative, the number is text beside it' );

// How they read: the SN Reading rows.
ok( false !== strpos( $h, '<dt>Views that reached half the page</dt><dd>30%</dd>' ), 'half the page: the 50% milestone over views (270 / 900)' );
ok( false !== strpos( $h, '<dt>Average depth reached</dt><dd>48%</dd>' ) && false !== strpos( $h, '<dt>Average time per view</dt><dd>1m 05s</dd>' ), 'average depth and time per view' );
ok( false !== strpos( $h, '<dt>One page only</dt>' ), 'one page only, from the session rollup' );

// Humans and machines.
ok( false !== strpos( $h, '>4,200<' ) && false !== strpos( $h, '>Human views<' ), 'human views against machine reads' );
ok( false !== strpos( $h, 'Verified by Cloudflare' ) && false !== strpos( $h, '>50%<' ) && false !== strpos( $h, 'Named themselves, not verified' ) && false !== strpos( $h, '>10%<' ), 'the identity split as shares of machine reads' );
ok( false !== strpos( $h, 'Automated counts bot pageviews that reached the tracker. Machine reads count every request the edge sensor saw. They are different measures.' ), 'the one line that keeps Automated and machine reads apart' );
ok( false !== strpos( $h, '<a href="https://github.com/juanlentino/signal-and-noise-provenance">Public ledger</a>' ), 'the public ledger link' );

// Failures leave sections out, never zeros.
$__snap = $GLOBALS['__options'][ SN_PUBLIC_STATS_MACHINES_OPT ];
$GLOBALS['__options'][ SN_PUBLIC_STATS_MACHINES_OPT ] = array( 'from' => '2026-01-01', 'to' => '2026-01-30', 'machines' => $__snap['machines'] ); // another window's snapshot
$GLOBALS['__sources']  = null; $GLOBALS['__countries'] = null;
$GLOBALS['__totals']   = array( 'views' => 0, 'visits' => 0 ); $GLOBALS['__dist'] = array(); $GLOBALS['__sessions'] = null;
$hf = $fresh();
ok( false === strpos( $hf, 'Humans and machines' ) && false === strpos( $hf, 'Public ledger' ), 'a snapshot of another window leaves the section out' );
ok( false === strpos( $hf, 'Where readers come from' ) && false === strpos( $hf, '>Other<' ), 'failed source and country reads leave the column out' );
ok( false === strpos( $hf, 'How they read' ), 'no readable reading figure leaves the column out' );
ok( false !== strpos( $hf, '<h2>Most read</h2>' ), 'while Most read still renders' );
$GLOBALS['__options'][ SN_PUBLIC_STATS_MACHINES_OPT ] = array( 'from' => $__wf, 'to' => $__wt, 'machines' => array( 'total' => 50, 'split' => null ) ); // capped aggregate: no split
$hc = $fresh();
ok( false !== strpos( $hc, 'Humans and machines' ) && false === strpos( $hc, 'Verified by Cloudflare' ), 'without a measured split the totals stay and the split is left out' );

// Codex on 37c3a84: the sensor stays off the render path, in the report window.
$src = (string) file_get_contents( __DIR__ . '/../inc/public-stats.php' );
ok( false === strpos( $src, 'snt_desktop_machine_readers_payload' ) && false === strpos( $src, 'snt_mr_fetch' ), 'the public render never calls the sensor; it reads the stored snapshot' );
$tot = array( 'ok' => true, 'truncated' => false, 'rows' => array( array( 'day' => '2026-08-01', 'hits' => 10 ), array( 'day' => '2026-08-02', 'hits' => 20 ), array( 'day' => '2026-08-03', 'hits' => 99 ) ) );
$agg = array( 'ok' => true, 'truncated' => false, 'rows' => array( array( 'day' => '2026-08-01', 'hits' => 10, 'id' => 'verified' ), array( 'day' => '2026-08-03', 'hits' => 99, 'id' => 'unverified' ) ) );
$m   = sn_public_stats_machines( $tot, $agg, '2026-08-01', '2026-08-02' );
ok( 30 === $m['total'] && 10 === $m['split']['verified'] && 0 === $m['split']['unverified'], 'machine reads are cut to the report window: today\'s partial day is out' );
ok( null === sn_public_stats_machines( $tot, $agg, '2026-07-31', '2026-08-02' ), 'a totals read that does not reach the window\'s first day leaves the section out' );
ok( null === sn_public_stats_machines( array( 'ok' => true, 'truncated' => true, 'rows' => $tot['rows'] ), $agg, '2026-08-01', '2026-08-02' ), 'a capped totals read leaves the section out' );
ok( null === sn_public_stats_machines( $tot, array( 'ok' => true, 'truncated' => true, 'rows' => array() ), '2026-08-01', '2026-08-02' )['split'], 'a capped aggregate read keeps the total and drops the split' );
ok( null === sn_public_stats_machines_stored( false, '2026-08-01', '2026-08-02' ), 'no snapshot yet: the section is left out' );
// A partial session window is never summed as the whole.
$cov = static fn( $days ) => sn_public_stats_session_coverage( array_map( static fn( $d ) => array( 'day' => $d ), $days ), '2026-08-01', '2026-08-03' );
ok( 3 === $cov( array( '2026-08-01', '2026-08-02', '2026-08-03' ) )['days'], 'every day rolled up: 3 of 3' );
ok( 2 === $cov( array( '2026-08-01', '2026-08-02' ) )['days'], 'the newest day not rolled up yet (the nightly lag): 2 of 3, still shown' );
ok( null === $cov( array( '2026-08-01', '2026-08-03' ) ), 'a hole inside the window is a failure: null' );
ok( null === $cov( array( '2026-08-02', '2026-08-03' ) ), 'a missing first day is a failure: null' );
ok( null === sn_public_stats_session_coverage( array(), '2026-08-01', '2026-08-03' ) && null === sn_public_stats_session_coverage( null, '2026-08-01', '2026-08-03' ), 'no rows or a failed read: null' );
$GLOBALS['__sessions'] = array( array( 'day' => '1999-01-01', 'visits' => 640, 'bounce_pct' => 60, 'ppv' => 1.4, 'median_dur' => 50, 'two_pages' => 100, 'deep_pages' => 60 ) );
$GLOBALS['__pad_skip'] = 1;
$hl = $fresh();
ok( false !== strpos( $hl, '>Visits<' ) && false !== strpos( $hl, '(29 of 30 days)' ) && false !== strpos( $hl, 'One page only' ), 'the nightly lag keeps Visits and One page only, and the tile says 29 of 30 days' );
$GLOBALS['__pad_skip'] = 0;
$hc30 = $fresh();
ok( false !== strpos( $hc30, '>Visits<' ) && false === strpos( $hc30, ' of 30 days)' ), 'a full window shows no coverage note' );
$GLOBALS['__pad'] = false; $GLOBALS['__sessions'] = array( array( 'day' => '2026-08-01', 'visits' => 640 ) );
$hp = $fresh();
ok( false === strpos( $hp, '>Visits<' ) && false === strpos( $hp, 'One page only' ), 'sessions that do not start at the window\'s first day leave Visits and One page only out' );
$GLOBALS['__pad'] = true;
// The watch that says why the machine figures are missing.
ok( false === snt_watch_ripe_public_stats_machines( array(), 1000, false )['ripe'], 'no refresh has run yet: quiet' );
// Codex on d641620: a refresh that never runs must still ripen. The schedule records a baseline once.
function add_option( $k, $v, $d = '', $a = null ) { if ( isset( $GLOBALS['__options'][ $k ] ) ) { return false; } $GLOBALS['__options'][ $k ] = $v; return true; }
unset( $GLOBALS['__options'][ SN_PUBLIC_STATS_MACHINES_LAST ] );
sn_public_stats_machines_schedule();
$base = $GLOBALS['__options'][ SN_PUBLIC_STATS_MACHINES_LAST ] ?? null;
ok( is_array( $base ) && '' === $base['why'] && abs( time() - (int) $base['at'] ) < 5, 'the first schedule call records a baseline' );
ok( true === snt_watch_ripe_public_stats_machines( array(), time() + 3 * 3600 + 5, $base )['ripe'], 'with no refresh after it, the baseline ripens the watch in three hours' );
$GLOBALS['__options'][ SN_PUBLIC_STATS_MACHINES_LAST ] = array( 'at' => 1, 'why' => 'x' );
sn_public_stats_machines_schedule();
ok( 'x' === $GLOBALS['__options'][ SN_PUBLIC_STATS_MACHINES_LAST ]['why'], 'a later schedule call never overwrites a real run' );
ok( true === snt_watch_ripe_public_stats_machines( array(), 1000, array( 'at' => 900, 'why' => 'totals read failed: network' ) )['ripe'] && 'totals read failed: network' === snt_watch_ripe_public_stats_machines( array(), 1000, array( 'at' => 900, 'why' => 'totals read failed: network' ) )['note'], 'a refresh that stored nothing ripens, and the note says why' );
ok( false === snt_watch_ripe_public_stats_machines( array(), 1000, array( 'at' => 900, 'why' => '' ) )['ripe'], 'a refresh that stored the snapshot is quiet' );
ok( true === snt_watch_ripe_public_stats_machines( array(), 900 + 3 * 3600 + 1, array( 'at' => 900, 'why' => '' ) )['ripe'], 'no refresh in three hours ripens' );
ok( 'totals read capped' === sn_public_stats_machines_why( array( 'ok' => true, 'truncated' => true ), '2026-08-01' ) && 0 === strpos( sn_public_stats_machines_why( array( 'ok' => true, 'rows' => array( array( 'day' => '2026-08-02' ) ) ), '2026-08-01' ), 'totals read does not reach 2026-08-01' ) && '' === sn_public_stats_machines_why( array( 'ok' => true, 'rows' => array( array( 'day' => '2026-08-01' ) ) ), '2026-08-01' ), 'the refresh names why it stored nothing' );
ok( 1 === preg_match( '/<div class="sn-public-stats__rhythm"><p class="sn-public-stats__rhythm-summary">.*<svg class="sn-public-stats__chart".*<div class="sn-public-stats__twin".*<\/table><\/div><\/div>/s', $h ), 'Reading rhythm keeps its reading order (sentence, chart, calendar) inside one wrapper' );
ok( false !== strpos( $css, 'grid-template-areas:"chart chart" "summary twin"' ), 'on a wide page the chart spans and the calendar sits beside the sentence' );
// Accurate bars (owner 2026-10-05): drawn from the exact proportion, never the rounded text.
ok( false !== strpos( sn_public_stats_bars_html( array( array( 'label' => 'Human views', 'text' => '356', 'pct' => 100 * 356 / 89578 ) ) ), 'style="width:0.397419%"' ), '356 beside 89,578 draws 0.397419% of the track, not 0%' );
ok( false !== strpos( sn_public_stats_bars_html( array( array( 'label' => 'x', 'text' => '3', 'pct' => 100 * 3 / 100000 ) ) ), 'style="width:0.003%"' ), 'Codex on e04225d: 3 of 100,000 keeps a nonzero width (two decimals printed 0.00%)' );
ok( false !== strpos( sn_public_stats_bars_html( array( array( 'label' => 'x', 'text' => '0', 'pct' => 0 ) ) ), 'style="width:0%"' ) && false !== strpos( sn_public_stats_bars_html( array( array( 'label' => 'x', 'text' => '', 'pct' => 100 ) ) ), 'style="width:100%"' ), 'zero and full widths print plainly' );
ok( false !== strpos( $h, 'style="width:21.428571%"' ) && false !== strpos( $h, 'style="width:100%"' ), 'human views against machine reads at their exact proportion (900 / 4,200)' );
ok( false !== strpos( $css, 'border:2px solid currentColor;padding:1px' ), 'a 1px gap keeps a hairline fill apart from the outline' );
// Wide: machines is the third column; the page takes the shared page track.
ok( 1 === preg_match( '/<div class="sn-public-stats__cols">.*<section class="sn-public-stats__col sn-public-stats__machines">/s', $h ), 'Humans and machines is a column of the section row' );
ok( false !== strpos( $css, 'var(--wp--custom--page-track,1320px)' ) && false !== strpos( $css, 'repeat(auto-fit,minmax(18rem,1fr))' ), 'the page takes the theme\'s page track; the columns fit three, two or one' );
// Shares are of every view, not of the 500 rows the accessor kept.
$full = array_merge( array( array( 'value' => 'a', 'views' => 60, 'visits' => 10 ) ), array_fill( 0, SN_PUBLIC_STATS_READ_CAP - 1, array( 'value' => '', 'views' => 0 ) ) );
$cap  = sn_public_stats_fold( $full, 'strval', 200 );
ok( 30 === $cap[0]['share'] && 'Other' === end( $cap )['label'] && 140 === end( $cap )['views'], 'a read that filled the cap: the dropped tail is Other, shares of all views' );
$two = array( array( 'value' => 'a', 'views' => 60, 'visits' => 10 ), array( 'value' => 'b', 'views' => 40, 'visits' => 10 ) );
ok( 60 === sn_public_stats_fold( $two, 'strval', 200 )[0]['share'] && 2 === count( sn_public_stats_fold( $two, 'strval', 200 ) ), 'a short read short of the total is missing coverage, never Other' );
ok( 60 === sn_public_stats_fold( $two, 'strval', 50 )[0]['share'], 'a window total below the rows\' sum never pushes shares past 100' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

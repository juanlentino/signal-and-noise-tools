<?php
/**
 * Tests: the Search Console store — path normalisation and the merge math.
 *
 * Run: php tests/search-console-store.php
 *
 * Both pieces fail SILENTLY when wrong. A bad path key joins nothing and
 * renders as "no search traffic"; bad merge math renders a plausible number
 * that is not true. Neither raises an error, so both are pinned by value.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function close_to( $a, $b, $eps = 0.0001 ) { return abs( (float) $a - (float) $b ) < $eps; }

$GLOBALS['__opts'] = array();
$GLOBALS['__autoload'] = array();
$GLOBALS['__property'] = '';
$GLOBALS['__rows'] = array( 'page' => array(), 'query' => array() );

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opts'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $autoload; return true; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function sn_setting( $p, $d = null ) { return 'search_console.property' === $p ? $GLOBALS['__property'] : $d; }
function __( $s, $d = null ) { return $s; }
class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

// Stand in for the client. Defined BEFORE the store is required so the store's
// calls bind to these; the client file is not loaded here at all.
function snt_gsc_window( $days = 28, $lag = 3 ) { return $GLOBALS['__window_override'] ?? array( 'start' => '2026-07-01', 'end' => '2026-07-28' ); }
function snt_gsc_query( $property, $dimensions, $window, $row_limit = 250 ) {
	return $GLOBALS['__rows'][ $dimensions[0] ];
}

require __DIR__ . '/../inc/search-console-store.php';

echo "Group: the join key — Google sends URLs, the rollups store paths\n";
$cases = array(
	array( 'https://juanlentino.com/notes/foo',        '/notes/foo' ),
	array( 'https://juanlentino.com/notes/foo/',       '/notes/foo' ),
	array( 'http://juanlentino.com/notes/foo',         '/notes/foo' ),
	array( 'https://juanlentino.com/',                 '/' ),
	array( 'https://juanlentino.com',                  '/' ),
	array( '/notes/foo',                               '/notes/foo' ),
	array( '/notes/foo/',                              '/notes/foo' ),
	array( 'notes/foo',                                '/notes/foo' ),
	array( '',                                         '' ),
	array( 'https://juanlentino.com/notes/foo?utm=x',  '/notes/foo' ),
);
foreach ( $cases as $i => $c ) {
	ok( $c[1] === snt_gsc_url_to_path( $c[0] ), "path[$i]: '{$c[0]}' -> '{$c[1]}'" );
}
ok( 10 === count( $cases ), 'all 10 normalisation cases present' );
ok( '/' === snt_gsc_url_to_path( 'https://x.test/' ), 'the ROOT keeps its slash — stripping it would key the home page as an empty string' );
ok( snt_gsc_url_to_path( 'https://x.test/a/' ) === snt_gsc_url_to_path( 'https://x.test/a' ),
	'trailing slash normalises away elsewhere, so both forms are ONE key' );

echo "\nGroup: null is not zero\n";
$GLOBALS['__opts'] = array();
ok( null === snt_gsc_metrics_for_path( '/anything' ), 'no sync yet -> null, never a zero row' );
ok( array() === snt_gsc_top_queries(), 'no sync yet -> empty query list' );

$GLOBALS['__opts']['snt_gsc_data'] = array(
	'synced_at' => 1000,
	'property'  => 'sc-domain:x.test',
	'window'    => array( 'start' => '2026-07-01', 'end' => '2026-07-28' ),
	'pages'     => array( '/seen' => array( 'clicks' => 0, 'impressions' => 400, 'ctr' => 0.0, 'position' => 34.2 ) ),
	'queries'   => array( array( 'key' => 'provenance', 'clicks' => 5, 'impressions' => 90, 'ctr' => 0.0556, 'position' => 8.1 ) ),
);
ok( null === snt_gsc_metrics_for_path( '/never-shown' ), 'a path Google never showed -> null' );
$seen = snt_gsc_metrics_for_path( '/seen' );
ok( is_array( $seen ) && 0 === $seen['clicks'] && 400 === $seen['impressions'],
	'shown 400 times with zero clicks -> a REAL zero, a different fact from null' );
ok( $seen === snt_gsc_metrics_for_path( 'https://x.test/seen/' ),
	'and the accessor normalises its own argument, so the URL form finds it too' );
ok( 1 === count( snt_gsc_top_queries( 10 ) ), 'top queries reads the stored list' );

echo "\nGroup: sync refuses without a property\n";
$GLOBALS['__property'] = '';
$r = snt_gsc_sync();
ok( is_wp_error( $r ) && 'snt_gsc_no_property' === $r->get_error_code(), 'no property selected -> a named WP_Error, not an empty sync' );

echo "\nGroup: merging two Google rows onto one path\n";
// http/https and trailing-slash variants both appear when a URL-prefix property
// overlaps a domain property — two rows, one page.
$GLOBALS['__property'] = 'sc-domain:x.test';
$GLOBALS['__rows'] = array(
	'page' => array(
		array( 'key' => 'https://x.test/dup',  'clicks' => 10, 'impressions' => 1000, 'ctr' => 0.01, 'position' => 5.0 ),
		array( 'key' => 'https://x.test/dup/', 'clicks' => 2,  'impressions' => 10,   'ctr' => 0.20, 'position' => 50.0 ),
	),
	'query' => array( array( 'key' => 'q', 'clicks' => 1, 'impressions' => 2, 'ctr' => 0.5, 'position' => 1.0 ) ),
);
$GLOBALS['__opts'] = array();
$payload = snt_gsc_sync();
ok( ! is_wp_error( $payload ), 'sync returns a payload' );
ok( 1 === count( $payload['pages'] ), 'the two rows collapsed to ONE path key' );
$m = $payload['pages']['/dup'];
ok( 12 === $m['clicks'] && 1010 === $m['impressions'], 'counts SUM (10+2 clicks, 1000+10 impressions)' );
// Unweighted, position would be (5+50)/2 = 27.5 — a 3-impression row dragging a
// 1000-impression one to a rank the page never held.
ok( close_to( $m['position'], ( 5.0 * 1000 + 50.0 * 10 ) / 1010 ), 'position is IMPRESSION-WEIGHTED, not a mean of means' );
ok( $m['position'] < 6.0, 'so the tiny row barely moves it (unweighted would be 27.5)' );
// CTR of the merged pair is 12/1010, not (0.01+0.20)/2 = 0.105.
ok( close_to( $m['ctr'], 12 / 1010 ), 'CTR is DERIVED after merging, never averaged' );
ok( ! close_to( $m['ctr'], 0.105 ), 'and is not the mean of the two rates' );

echo "\nGroup: the payload is stored unautoloaded\n";
ok( false === $GLOBALS['__autoload'][ SNT_GSC_DATA_OPTION ], 'the option is written with autoload=false' );
ok( 'sc-domain:x.test' === $payload['property'] && isset( $payload['window']['start'] ), 'the payload records WHICH property and WHICH window it describes' );
ok( isset( $payload['synced_at'] ), 'and when it was taken — a stale window must be visible as stale' );

// ── window totals (v11.28.0) ────────────────────────────────────────────────
// The Dashboard's clicks figure reads this. NULL vs 0 is the whole contract: a
// property that has never synced and one Google reports no clicks for are
// different facts, and a 0 would state the second while meaning the first.
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__opts'] = array();
ok( null === snt_gsc_window_totals(), 'NEVER SYNCED IS NULL, NOT ZERO CLICKS' );

$GLOBALS['__opts'][ SNT_GSC_DATA_OPTION ] = array(
	'synced_at' => 1,
	'window'    => array( 'start' => '2026-07-01', 'end' => '2026-07-28' ),
	'pages'     => array(
		'/a' => array( 'clicks' => 10, 'impressions' => 100 ),
		'/b' => array( 'clicks' => 5,  'impressions' => 50 ),
	),
);
$t = snt_gsc_window_totals();
ok( 15 === $t['clicks'], 'clicks sum across the stored pages' );
ok( 28 === $t['days'], 'the window length counts BOTH endpoints — 07-01..07-28 is 28 days, not 27' );

// A synced property with genuinely no clicks is a measured zero.
$GLOBALS['__opts'][ SNT_GSC_DATA_OPTION ] = array(
	'synced_at' => 1,
	'window'    => array( 'start' => '2026-07-01', 'end' => '2026-07-28' ),
	'pages'     => array(),
);
$t = snt_gsc_window_totals();
ok( is_array( $t ) && 0 === $t['clicks'], 'A SYNCED PROPERTY WITH NO CLICKS IS A MEASURED ZERO, not null' );

// A payload with no window still reports its clicks, and says the length is
// unknown rather than guessing one.
$GLOBALS['__opts'][ SNT_GSC_DATA_OPTION ] = array( 'synced_at' => 1, 'pages' => array( '/a' => array( 'clicks' => 3 ) ) );
$t = snt_gsc_window_totals();
ok( 3 === $t['clicks'] && 0 === $t['days'], 'a missing window yields days=0, which the label reads as unknown' );

// ── ITEM 5: THE 250-ROW CAP MUST BE VISIBLE, NOT JUST DOCUMENTED ────────────
// v11.29.2. snt_gsc_query() fetches the page dimension with rowLimit 250, so
// snt_gsc_window_totals() sums AT MOST 250 pages. On a site with more ranked
// pages the clicks figure silently undercounts, and a number that is wrong in
// a knowable direction while presenting as exact is worse than one labelled
// approximate. The docblock said so; the RETURN VALUE did not, so no caller
// could act on it.
echo "\nGroup: the page cap is reported, not just documented\n";

$GLOBALS['__opts']['snt_gsc_data'] = array(
	'synced_at' => 1000,
	'window'    => array( 'start' => '2026-07-01', 'end' => '2026-07-28' ),
	'pages'     => array_fill( 0, 250, array( 'clicks' => 1 ) ),
	'queries'   => array(),
);
$capped = snt_gsc_window_totals();
ok( 250 === $capped['clicks'], 'it still sums what it has' );
ok( true === $capped['capped'], 'AT THE CAP IT SAYS SO — the total is a floor, not a measurement' );

$GLOBALS['__opts']['snt_gsc_data']['pages'] = array_fill( 0, 12, array( 'clicks' => 1 ) );
$under = snt_gsc_window_totals();
ok( false === $under['capped'], 'under the cap it does not, so the label is not permanently hedged' );

echo "\nGroup: history — bounded, deduped, appended by the REAL sync (v13.11.0)\n";
// The harness drives real snt_gsc_sync() through the stubbed client; history
// rides the same call, so these assertions exercise the true producer.
$GLOBALS['__opts'] = array();
$GLOBALS['__property'] = 'sc-domain:example.com';
$GLOBALS['__rows']['page'] = array( array( 'key' => 'https://example.com/a/', 'clicks' => 2, 'impressions' => 100, 'ctr' => 0.02, 'position' => 5.04 ) );
snt_gsc_sync();
$h = snt_gsc_history();
ok( 1 === count( $h ), 'first sync appends one snapshot' );
$entry = array_values( $h )[0];
ok( 5.0 === $entry['pages']['/a']['position'], 'position stored at display grain (5.04 -> 5.0), keyed by the NORMALISED path (/a, slash gone — the join key rule)' );
snt_gsc_sync();
ok( 1 === count( snt_gsc_history() ), 'same window end re-synced REPLACES — a manual Sync-now on cron day is one observation, not two' );
for ( $i = 1; $i <= 12; $i++ ) {
	$GLOBALS['__window_override'] = array( 'start' => '2026-07-01', 'end' => sprintf( '2026-08-%02d', $i ) );
	snt_gsc_sync();
}
$h = snt_gsc_history();
ok( SNT_GSC_HISTORY_MAX === count( $h ), 'the history caps at ' . SNT_GSC_HISTORY_MAX . ' snapshots' );
ok( ! isset( $h['2026-08-01'] ) && isset( $h['2026-08-12'] ), 'oldest dropped, newest kept — chronological, ksorted' );
unset( $GLOBALS['__window_override'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );

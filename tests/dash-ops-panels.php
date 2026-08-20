<?php
/**
 * Tests: the ops wall's panel builder.
 *
 * "Everything without bloating" (owner, 2026-08-19) has a consequence the rest
 * of this codebase already believes: a source that is ABSENT still gets its
 * panel, saying it is not measured. Omitting it would make the wall silently
 * smaller on the exact day something stopped reporting — the failure mode is
 * a page that looks complete while a fact has gone missing.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $f, $t = 0 ) { return '19 minutes'; } }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
$GLOBALS['__site_transients'] = array();
if ( ! function_exists( 'get_site_transient' ) ) { function get_site_transient( $k ) { return $GLOBALS['__site_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_site_transient' ) ) { function set_site_transient( $k, $v, $t = 0 ) { $GLOBALS['__site_transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $k ) ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
// The REAL accessor, not a stand-in: a fixture I write cannot catch a shape I
// misread, because I would write the fixture from the same wrong belief.
require __DIR__ . '/../inc/api-rate-monitor.php';
require __DIR__ . '/../inc/dash-ops-panels.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function titles( $panels ) { return array_map( function( $p ) { return $p['title']; }, $panels ); }
function by_title( $panels, $t ) {
	foreach ( $panels as $p ) { if ( $p['title'] === $t ) { return $p; } }
	return null;
}
echo "ops wall panel builder\n\n";

$all = sn_dash_ops_panels( array(
	'deploys' => array(
		array( 'repo' => 'juanlentino/signal-and-noise-tools', 'ref' => 'main', 'conclusion' => 'success', 'created_at' => '2026-08-19T15:00:00Z' ),
		array( 'repo' => 'juanlentino/signal-and-noise', 'ref' => 'main', 'conclusion' => 'failure', 'created_at' => '2026-08-19T13:00:00Z' ),
	),
	// SHAPES COPIED FROM THE PRODUCERS, NOT INVENTED.
	//
	// The first version of this suite made these up — `label` for a source and
	// `query` for a query — and both were wrong. sn_analytics_top_sources()
	// returns `value`; snt_gsc_top_queries() returns `key`. The tests passed
	// green while the shipped wall rendered a column of bare numbers with no
	// labels at all, because a stub that models the caller's WISH tests nothing
	// about the callee. Bitten enough times that the shape is now quoted with
	// its source.
	'pages'   => array( array( 'path' => '/notes/two-kinds', 'views' => 41 ) ),          // sn_analytics_top_paths(): path, views
	'sources' => array( array( 'value' => 'google.com', 'visits' => 12, 'views' => 20 ) ), // sn_analytics_top_sources(): value, views, visits, hosts
	'queries' => array( array( 'key' => 'provenance over detection', 'clicks' => 4 ) ),   // snt_gsc_top_queries(): key, clicks, impressions, ctr, position
	'api'     => array( 'github' => array( 'remaining' => 4200, 'limit' => 5000, 'kind' => 'ok' ) ),
) );

// ── every source present ────────────────────────────────────────────────────
ok( 5 === count( $all ), 'FIVE PANELS FOR FIVE SOURCES — the wall is however many the call site can source' );
ok( in_array( 'Recent deploys', titles( $all ), true ), 'recent deploys' );
ok( in_array( 'Top pages', titles( $all ), true ),      'top pages' );
ok( in_array( 'Top sources', titles( $all ), true ),    'top sources' );
ok( in_array( 'Top queries', titles( $all ), true ),    'top queries' );
ok( in_array( 'API limits', titles( $all ), true ),     'api limits' );

$dep = by_title( $all, 'Recent deploys' );
ok( 2 === count( $dep['rows'] ), 'a deploy row per run' );
// v11.30.0, MONOCHROME FIRST (Few): a healthy row carries NO dot. Painting
// green on every successful deploy and every healthy API host put a field of
// colour on a screen whose whole job is to make one amber cell obvious.
ok( '' === $dep['rows'][0]['dot'],   'A SUCCESSFUL RUN PAINTS NOTHING — healthy is the absence of a state, not a green one' );
ok( 'err' === $dep['rows'][1]['dot'], 'A FAILED RUN PAINTS AN ERR DOT — the wall is where you would see it' );
ok( false !== strpos( $dep['rows'][0]['label'], 'plugin' ), 'the repo is shortened, not printed whole' );

// v11.31.2: the SECOND row was in this fixture from the day the file was
// written and nothing ever read it — so `sn_dash_ops_repo_label()` shipped
// returning the bare repo NAME for every non-tools repo, and Recent deploys
// printed "signal-and-noise v11.12.3" beside "plugin v11.31.1": a repo name
// standing next to a role. Both rows are asserted now, because a mapper with
// two branches needs two assertions.
ok( false !== strpos( $dep['rows'][1]['label'], 'theme' ), 'a NON-tools repo reads THEME — a role, never the bare repo name' );
ok( false === strpos( $dep['rows'][1]['label'], 'signal-and-noise' ), 'the bare repo name never reaches the label' );

// The deleted duplicate DID have one behaviour worth keeping: an absent repo
// rendered an em dash, not an empty gap. That is this file's own stated rule —
// "a source that is absent still gets its panel, saying it is not measured" —
// so it survives the deletion, at the display layer where it belongs.
$norepo = sn_dash_ops_panels( array( 'deploys' => array( array( 'ref' => 'main', 'conclusion' => 'success' ) ) ) );
ok( 0 === strpos( by_title( $norepo, 'Recent deploys' )['rows'][0]['label'], "\xe2\x80\x94" ), 'AN ABSENT REPO STILL SAYS SO — em dash, never a silent gap' );

// THE GAP THAT LET THE BUG SHIP. This suite asserted the DEPLOYS rows and the
// PAGES rows and never once asserted a source or a query label — so changing
// the fixture to the producers' real shapes changed nothing, and the wall
// rendered a column of bare numbers in production while the suite stayed green.
// A stub is only evidence if something asserts on what it produced.
$src = by_title( $all, 'Top sources' );
ok( 'google.com' === $src['rows'][0]['label'],
	'A SOURCE ROW CARRIES ITS NAME — sn_analytics_top_sources() returns `value`, not `label`' );
ok( '12' === $src['rows'][0]['value'], 'and its visit count' );

$qs = by_title( $all, 'Top queries' );
ok( 'provenance over detection' === $qs['rows'][0]['label'],
	'A QUERY ROW CARRIES ITS TEXT — snt_gsc_top_queries() returns `key`, not `query`' );
ok( '4' === $qs['rows'][0]['value'], 'and its click count' );

$pg = by_title( $all, 'Top pages' );
ok( '/notes/two-kinds' === $pg['rows'][0]['label'] && '41' === $pg['rows'][0]['value'], 'a page row is path + views' );

// ── ABSENT IS NOT OMITTED, and not zero either ──────────────────────────────
$none = sn_dash_ops_panels( array() );
ok( 5 === count( $none ), 'AN ABSENT SOURCE STILL GETS ITS PANEL — a wall that silently shrinks hides the fact that went missing' );
foreach ( $none as $p ) {
	ok( null === $p['rows'], $p['title'] . ': rows are NULL (never fetched), not an empty list' );
	ok( '' !== (string) $p['unmeasured'], $p['title'] . ': carries its own not-measured wording' );
}

// ── measured-and-empty is a THIRD state ─────────────────────────────────────
$empty = sn_dash_ops_panels( array( 'pages' => array() ) );
$ep    = by_title( $empty, 'Top pages' );
ok( is_array( $ep['rows'] ) && 0 === count( $ep['rows'] ), 'a fetched-but-empty source yields an EMPTY ARRAY, not null' );
ok( '' !== (string) $ep['empty'], 'and its own measured-empty wording, distinct from the not-measured one' );
ok( $ep['empty'] !== $ep['unmeasured'], 'THE TWO STRINGS DIFFER — one for both would state a zero while meaning silence' );

// ── API LIMITS, DRIVEN BY THE REAL PRODUCER ─────────────────────────────────
// v11.31.1. This shipped reading $st['limit'], $st['remaining'], $st['kind'] and
// the array KEY for a label. Every one was wrong: snt_rate_limit_all_statuses()
// returns [ host => { label, snapshot } ] with the numbers nested inside
// `snapshot` and the state derived by snt_rate_limit_state(). So the panel
// rendered raw hostnames against em dashes — "api.github.com  —".
//
// That is the THIRD invented shape in this file's history (sources -> `value`,
// queries -> `key`, and now this). A fixture I write cannot catch it, because I
// write the fixture from the same wrong belief as the code. So this block calls
// the REAL accessor and feeds its REAL output straight into the panel builder.
echo "\nGroup: API limits, fed by the real accessor\n";
// Keys BUILT from the real constant, not typed from memory — the first pass
// invented `snt_rl_…` and the lookups silently missed, which is the same class
// of mistake as inventing the return shape.
$GLOBALS['__site_transients'] = array(
	SNT_RATE_CACHE_KEY_PREFIX . sanitize_key( 'api.github.com' )     => array( 'remaining' => 4231, 'limit' => 5000, 'reset' => 0 ),
	SNT_RATE_CACHE_KEY_PREFIX . sanitize_key( 'api.cloudflare.com' ) => array( 'remaining' => 92,   'limit' => 1200, 'reset' => 0 ),
);
$real = snt_rate_limit_all_statuses();
$api  = by_title( sn_dash_ops_panels( array( 'api' => $real ) ), 'API limits' );

ok( is_array( $api['rows'] ) && count( $api['rows'] ) >= 2, 'a row per tracked host' );
$labels = array_map( function ( $r ) { return $r['label']; }, $api['rows'] );
ok( in_array( 'GitHub API', $labels, true ),
	'THE HOST RENDERS ITS HUMAN LABEL — snt_rate_limit_hosts() has carried "GitHub API" all along; the panel printed the array KEY instead' );
ok( in_array( 'Cloudflare API', $labels, true ), 'and Cloudflare API, not api.cloudflare.com' );

$gh = null;
foreach ( $api['rows'] as $r ) { if ( 'GitHub API' === $r['label'] ) { $gh = $r; } }
ok( false !== strpos( $gh['value'], '4,231' ) && false !== strpos( $gh['value'], '5,000' ),
	'AND ITS ACTUAL NUMBERS — the values live under `snapshot`, which is why every row read as an em dash' );
ok( '' === $gh['dot'], 'a host with headroom paints no dot' );

$cf = null;
foreach ( $api['rows'] as $r ) { if ( 'Cloudflare API' === $r['label'] ) { $cf = $r; } }
ok( 'err' === $cf['dot'],
	'A HOST UNDER 10% PAINTS err — snt_rate_limit_state() calls that "crit"; the dot vocabulary calls it err, and the mapping is explicit' );

// NEVER SEEN IS NOT ZERO. snapshot === null means no request has been observed.
$GLOBALS['__site_transients'] = array();
$none = by_title( sn_dash_ops_panels( array( 'api' => snt_rate_limit_all_statuses() ) ), 'API limits' );
$row0 = $none['rows'][0];
ok( 'unknown' === $row0['dot'], 'AN UNSEEN HOST IS `unknown`, NOT `ok` — no request observed is not a healthy limit' );
ok( false === strpos( $row0['value'], '0' ), 'and it must not render a zero it never measured' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

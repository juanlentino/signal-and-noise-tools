<?php
/**
 * Tests for inc/analytics-sources.php — referrer host → canonical source folding.
 * Pure PHP over stubbed read accessors: no AE query, no DB, no dialect risk.
 * Run: php tests/analytics-sources.php
 * @since plugin v6.25.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );

// ── WP seams the mapper touches.
function home_url( $path = '' ) { return 'https://juanlentino.com' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $tag, $value ) { return $value; }

// ── Read accessors the fold/series compose. Keyed referrer rows + per-host series.
$GLOBALS['__src_dim']    = array();   // "referrer|human" → rows
$GLOBALS['__src_series'] = array();   // host → [{day,views}]
$GLOBALS['__src_dim_calls'] = array();
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) {
	$GLOBALS['__src_dim_calls'][] = array( $dim, $from, $to, $class, $limit );
	return $GLOBALS['__src_dim'][ $dim . '|' . $class ] ?? array();
}
function sn_analytics_dimension_series( $dim, $values, $from, $to, $class = 'human', $granularity = 'day' ) {
	$out = array();
	foreach ( (array) $values as $v ) {
		if ( isset( $GLOBALS['__src_series'][ $v ] ) ) {
			$out[ $v ] = $GLOBALS['__src_series'][ $v ];
		}
	}
	return $out;
}

require_once __DIR__ . '/../inc/analytics-sources.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }

echo "Analytics source folding\n\n";

echo "Group: normalize_host\n";
ok( sn_analytics_normalize_host( 'www.Google.com' ) === 'google.com', 'normalize: lowercases + strips leading www.' );
ok( sn_analytics_normalize_host( 'https://x.com/path?q=1#f' ) === 'x.com', 'normalize: strips scheme/path/query/fragment' );
ok( sn_analytics_normalize_host( 'android-app://com.google.android.gm/' ) === 'com.google.android.gm', 'normalize: app-uri → host' );
ok( sn_analytics_normalize_host( '  M.Facebook.COM  ' ) === 'm.facebook.com', 'normalize: trims + lowercases (non-www subdomain kept)' );
ok( sn_analytics_normalize_host( '(direct)' ) === '(direct)', 'normalize: (direct) sentinel passes through' );
ok( sn_analytics_normalize_host( '' ) === '', 'normalize: empty stays empty' );

echo "\nGroup: canonical_source — self-referral + sentinels → (direct)\n";
ok( sn_analytics_canonical_source( 'juanlentino.com' ) === '(direct)', 'self host → (direct)' );
ok( sn_analytics_canonical_source( 'www.juanlentino.com' ) === '(direct)', 'www self host → (direct)' );
ok( sn_analytics_canonical_source( '' ) === '(direct)', 'empty → (direct)' );
ok( sn_analytics_canonical_source( '(direct)' ) === '(direct)', '(direct) sentinel → (direct)' );
ok( sn_analytics_canonical_source( '(unknown)' ) === '(direct)', '(unknown) sentinel → (direct)' );
ok( sn_analytics_canonical_source( 'example.com', array( 'example.com' ) ) === '(direct)', 'explicit self_hosts param folds to (direct)' );

echo "\nGroup: canonical_source — brand grouping\n";
ok( sn_analytics_canonical_source( 'www.google.com' ) === 'Google', 'www.google.com → Google' );
ok( sn_analytics_canonical_source( 'news.google.com' ) === 'Google', 'news.google.com → Google' );
ok( sn_analytics_canonical_source( 'com.google.android.gm' ) === 'Google', 'gmail app uri → Google' );
ok( sn_analytics_canonical_source( 't.co' ) === 'X', 't.co → X' );
ok( sn_analytics_canonical_source( 'x.com' ) === 'X', 'x.com → X' );
ok( sn_analytics_canonical_source( 'mobile.twitter.com' ) === 'X', 'mobile.twitter.com → X' );
ok( sn_analytics_canonical_source( 'www.facebook.com' ) === 'Facebook', 'www.facebook.com → Facebook' );
ok( sn_analytics_canonical_source( 'm.facebook.com' ) === 'Facebook', 'm.facebook.com → Facebook' );
ok( sn_analytics_canonical_source( 'lnkd.in' ) === 'LinkedIn', 'lnkd.in → LinkedIn' );
ok( sn_analytics_canonical_source( 'news.ycombinator.com' ) === 'Hacker News', 'HN → Hacker News' );
ok( sn_analytics_canonical_source( 'duckduckgo.com' ) === 'DuckDuckGo', 'duckduckgo → DuckDuckGo' );
ok( sn_analytics_canonical_source( 'chatgpt.com' ) === 'ChatGPT', 'chatgpt.com → ChatGPT' );

echo "\nGroup: canonical_source — unknown host folds to bare host\n";
ok( sn_analytics_canonical_source( 'some-blog.example' ) === 'some-blog.example', 'unknown host kept as bare host' );
ok( sn_analytics_canonical_source( 'www.some-blog.example' ) === 'some-blog.example', 'unknown host: www stripped' );

echo "\nGroup: canonical_source — brand match is domain-label-boundary aware (no fragment false-positives)\n";
ok( sn_analytics_canonical_source( 'notfacebook.com' ) === 'notfacebook.com', 'notfacebook.com is NOT Facebook (boundary)' );
ok( sn_analytics_canonical_source( 'reddit-clone.example' ) === 'reddit-clone.example', 'reddit-clone is NOT Reddit (boundary)' );
ok( sn_analytics_canonical_source( 'mygoogle.example' ) === 'mygoogle.example', 'mygoogle is NOT Google (boundary)' );
ok( sn_analytics_canonical_source( 'example-searx-blog.com' ) === 'example-searx-blog.com', 'mid-domain searx is NOT Searx (boundary)' );
// …but genuine subdomains + bare brand domains still resolve:
ok( sn_analytics_canonical_source( 'mobile.facebook.com' ) === 'Facebook', 'real subdomain mobile.facebook.com → Facebook' );
ok( sn_analytics_canonical_source( 'searxng.example' ) === 'Searx', 'searxng instance → Searx (start-of-host match)' );

echo "\nGroup: source_category_of_label\n";
ok( sn_analytics_source_category_of_label( 'Google' ) === 'search', 'Google → search' );
ok( sn_analytics_source_category_of_label( 'X' ) === 'social', 'X → social' );
ok( sn_analytics_source_category_of_label( '(direct)' ) === 'direct', '(direct) → direct' );
ok( sn_analytics_source_category_of_label( 'some-blog.example' ) === 'other', 'unknown label → other' );

echo "\nGroup: top_sources — fold + merge + member hosts\n";
$GLOBALS['__src_dim']['referrer|human'] = array(
	array( 'value' => 'www.google.com',   'views' => 100, 'visits' => 60 ),
	array( 'value' => 'news.google.com',  'views' => 20,  'visits' => 10 ),
	array( 'value' => 't.co',             'views' => 50,  'visits' => 30 ),
	array( 'value' => 'juanlentino.com',  'views' => 200, 'visits' => 120 ), // self-referral
	array( 'value' => '(direct)',         'views' => 40,  'visits' => 20 ),
	array( 'value' => 'some-blog.example','views' => 5,   'visits' => 3 ),
);
$src = sn_analytics_top_sources( '2026-06-01', '2026-06-07', 'human', 10 );
$by = array();
foreach ( $src as $row ) { $by[ $row['value'] ] = $row; }
ok( isset( $by['Google'] ) && $by['Google']['views'] === 120 && $by['Google']['visits'] === 70, 'Google merges www + news (100+20 / 60+10)' );
ok( isset( $by['X'] ) && $by['X']['views'] === 50, 'X = t.co' );
ok( isset( $by['(direct)'] ) && $by['(direct)']['views'] === 240, '(direct) = sentinel 40 + self-referral 200' );
ok( isset( $by['some-blog.example'] ) && $by['some-blog.example']['views'] === 5, 'unknown host kept' );
ok( $src[0]['value'] === '(direct)', 'sorted by views desc — (direct) 240 first' );
ok( $by['Google']['hosts'] === array( 'www.google.com', 'news.google.com' ), 'Google member hosts = its RAW hosts (www preserved for AE/drill match)' );
ok( $by['(direct)']['hosts'] === array(), '(direct) carries NO member hosts (not drillable)' );
ok( $by['some-blog.example']['hosts'] === array( 'some-blog.example' ), 'unknown host is its own member' );
// the fold reads a wide window (not the display limit) so a merge can't drop a top row
$wide = end( $GLOBALS['__src_dim_calls'] );
ok( (int) $wide[4] >= 100, 'top_sources fetches a wide raw top-N before folding' );

echo "\nGroup: top_sources limit\n";
$src2 = sn_analytics_top_sources( '2026-06-01', '2026-06-07', 'human', 2 );
ok( count( $src2 ) === 2, 'limit slices the folded+sorted list' );
ok( $src2[0]['value'] === '(direct)' && $src2[1]['value'] === 'Google', 'limit keeps the top 2 by views' );

echo "\nGroup: source_hosts (drill resolver)\n";
ok( sn_analytics_source_hosts( 'Google', '2026-06-01', '2026-06-07', 'human' ) === array( 'www.google.com', 'news.google.com' ), 'source_hosts: Google → member hosts' );
ok( sn_analytics_source_hosts( '(direct)', '2026-06-01', '2026-06-07', 'human' ) === array(), 'source_hosts: (direct) → empty (not drillable)' );
ok( sn_analytics_source_hosts( 'Nope', '2026-06-01', '2026-06-07', 'human' ) === array(), 'source_hosts: unknown label → empty' );

echo "\nGroup: top_sources_series (label-keyed, summed across member hosts)\n";
$GLOBALS['__src_series']['www.google.com']  = array( array( 'day' => '2026-06-01', 'views' => 60 ), array( 'day' => '2026-06-02', 'views' => 40 ) );
$GLOBALS['__src_series']['news.google.com'] = array( array( 'day' => '2026-06-01', 'views' => 10 ) );
$GLOBALS['__src_series']['t.co']            = array( array( 'day' => '2026-06-02', 'views' => 50 ) );
$ser = sn_analytics_top_sources_series( $src, '2026-06-01', '2026-06-07', 'human', 'day' );
ok( isset( $ser['Google'] ), 'series: Google present' );
$g = array();
foreach ( $ser['Google'] as $pt ) { $g[ $pt['day'] ] = $pt['views']; }
ok( $g['2026-06-01'] === 70 && $g['2026-06-02'] === 40, 'series: Google sums google.com + news.google.com per day' );
ok( isset( $ser['X'] ) && $ser['X'][0]['views'] === 50, 'series: X from t.co' );
ok( ! isset( $ser['(direct)'] ), 'series: (direct) has no member hosts → no series row' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

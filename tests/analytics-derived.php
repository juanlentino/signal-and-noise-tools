<?php
/**
 * Tests for inc/analytics-derived.php — referrer categories, period-over-period
 * deltas, and the bot breakdown. Pure PHP over the existing rollup accessors:
 * no AE query, no DB, no dialect risk. Drives through stubbed accessors.
 * Run: php tests/analytics-derived.php
 * @since plugin v5.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );

// WP seams the canonical-source mapper (delegated to by the categorizer) touches.
function home_url( $path = '' ) { return 'https://juanlentino.com' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $tag, $value ) { return $value; }

// Accessor seams the derived layer composes.
$GLOBALS['__de_dim']    = array();   // referrer rows (and network rows for the bot test)
$GLOBALS['__de_totals'] = array();   // keyed "$from|$to" → totals
$GLOBALS['__de_class']  = array();   // class_totals
$GLOBALS['__de_dim_calls'] = array();
$GLOBALS['__totals_by_window'] = array(); // D2: explicit-cwin override, keyed "$from|$to" — takes priority over __de_totals
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) {
	$GLOBALS['__de_dim_calls'][] = array( $dim, $from, $to, $class, $limit );
	// array_key_exists, not ?? — a stored NULL is the v9.68.1 failed-read
	// verdict and must reach the caller (?? would silently swap it for []).
	$key = $dim . '|' . $class;
	return array_key_exists( $key, $GLOBALS['__de_dim'] ) ? $GLOBALS['__de_dim'][ $key ] : array();
}
function sn_analytics_range_totals( $from, $to, $class = 'human' ) {
	$key = "$from|$to";
	if ( isset( $GLOBALS['__totals_by_window'][ $key ] ) ) {
		return $GLOBALS['__totals_by_window'][ $key ];
	}
	return $GLOBALS['__de_totals'][ $key ] ?? array( 'views' => 0, 'visits' => 0, 'scroll_avg' => 0, 'time_avg' => 0 );
}
function sn_analytics_class_totals( $from, $to ) {
	return $GLOBALS['__de_class'];
}

require_once __DIR__ . '/../inc/analytics-sources.php'; // categorizer delegates to the canonical-source mapper
require_once __DIR__ . '/../inc/analytics-derived.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }

echo "Analytics derived views\n\n";

echo "Group: referrer host → category\n";
ok( sn_analytics_referrer_category( 'www.google.com' ) === 'search', 'category: google → search' );
ok( sn_analytics_referrer_category( 'duckduckgo.com' ) === 'search', 'category: duckduckgo → search' );
ok( sn_analytics_referrer_category( 't.co' ) === 'social', 'category: t.co → social' );
ok( sn_analytics_referrer_category( 'news.ycombinator.com' ) === 'social', 'category: HN → social' );
ok( sn_analytics_referrer_category( 'old.reddit.com' ) === 'social', 'category: reddit → social' );
ok( sn_analytics_referrer_category( '(direct)' ) === 'direct', 'category: (direct) sentinel → direct' );
ok( sn_analytics_referrer_category( '' ) === 'direct', 'category: empty host → direct' );
ok( sn_analytics_referrer_category( 'example.com' ) === 'other', 'category: unknown host → other' );
ok( sn_analytics_referrer_category( 'juanlentino.com' ) === 'direct', 'category: self-referral → direct (not other)' );
ok( sn_analytics_referrer_category( 'www.juanlentino.com' ) === 'direct', 'category: www self-referral → direct' );

echo "\nGroup: referrer categories aggregation\n";
$GLOBALS['__de_dim']['referrer|human'] = array(
	array( 'value' => 'www.google.com',       'views' => 100, 'visits' => 60 ),
	array( 'value' => 'news.google.com',       'views' => 20,  'visits' => 10 ),
	array( 'value' => 't.co',                  'views' => 50,  'visits' => 30 ),
	array( 'value' => '(direct)',              'views' => 200, 'visits' => 120 ),
	array( 'value' => 'some-blog.example',     'views' => 5,   'visits' => 3 ),
);
$cats = sn_analytics_referrer_categories( '2026-06-01', '2026-06-07', 'human' );
ok( count( $cats ) === 4, 'categories: returns all 4 categories (zero-filled)' );
$by = array();
foreach ( $cats as $c ) { $by[ $c['category'] ] = $c; }
ok( $by['search']['views'] === 120, 'categories: search sums google + news.google (100+20)' );
ok( $by['social']['views'] === 50, 'categories: social = t.co' );
ok( $by['direct']['views'] === 200, 'categories: direct = (direct) sentinel' );
ok( $by['other']['views'] === 5, 'categories: other = the unknown host' );
ok( isset( $by['search']['label'] ) && $by['search']['label'] === 'Search', 'categories: carries a display label' );

echo "\nGroup: period-over-period deltas\n";
// Current 7d window vs the immediately-preceding 7d window.
$GLOBALS['__de_totals']['2026-06-08|2026-06-14'] = array( 'views' => 200, 'visits' => 80,  'scroll_avg' => 60, 'time_avg' => 120 );
$GLOBALS['__de_totals']['2026-06-01|2026-06-07'] = array( 'views' => 100, 'visits' => 100, 'scroll_avg' => 60, 'time_avg' => 60 );
$d = sn_analytics_period_deltas( '2026-06-08', '2026-06-14', 'human' );
ok( $d['views']['current'] === 200 && $d['views']['previous'] === 100, 'deltas: queries the correct prior window (preceding 7 days)' );
ok( $d['views']['pct'] === 100 && $d['views']['dir'] === 'up', 'deltas: views +100% up' );
ok( $d['visits']['pct'] === -20 && $d['visits']['dir'] === 'down', 'deltas: visits -20% down' );
ok( $d['scroll_avg']['pct'] === 0 && $d['scroll_avg']['dir'] === 'flat', 'deltas: unchanged → 0% flat' );
ok( $d['time_avg']['dir'] === 'up', 'deltas: time up' );
// Previous window all-zero → pct null (no division), not a bogus +∞.
$GLOBALS['__de_totals']['2026-07-08|2026-07-14'] = array( 'views' => 50, 'visits' => 20, 'scroll_avg' => 0, 'time_avg' => 0 );
// (prior window 2026-07-01|2026-07-07 unset → zeros)
$d2 = sn_analytics_period_deltas( '2026-07-08', '2026-07-14', 'human' );
ok( $d2['views']['pct'] === null && $d2['views']['dir'] === 'up', 'deltas: prior=0 → pct null but dir up (new traffic, no divide-by-zero)' );

echo "\nGroup: D2 — explicit compare window (one frame)\n";
$GLOBALS['__totals_by_window'] = array(
	'2026-07-06|2026-07-12' => array( 'views' => 200, 'visits' => 60, 'scroll_avg' => 50.0, 'time_avg' => 90.0 ),
	'2026-06-29|2026-07-05' => array( 'views' => 100, 'visits' => 30, 'scroll_avg' => 40.0, 'time_avg' => 80.0 ),
	'2025-07-06|2025-07-12' => array( 'views' => 400, 'visits' => 90, 'scroll_avg' => 60.0, 'time_avg' => 70.0 ),
);
$d_prev = sn_analytics_period_deltas( '2026-07-06', '2026-07-12', 'human' );
ok( 100 === (int) ( $d_prev['views']['previous'] ?? -1 ) && 'up' === ( $d_prev['views']['dir'] ?? '' ), 'period_deltas: null cwin keeps the prior-window basis (back-compat pin)' );
$d_yoy = sn_analytics_period_deltas( '2026-07-06', '2026-07-12', 'human', array( '2025-07-06', '2025-07-12' ) );
ok( 400 === (int) ( $d_yoy['views']['previous'] ?? -1 ) && 'down' === ( $d_yoy['views']['dir'] ?? '' ), 'period_deltas: explicit cwin is the basis (yoy window read, prior window ignored)' );
$d_trunc = sn_analytics_period_deltas( '2026-07-06', '2026-07-12', 'human', array( '2025-07-06' ) );
ok( 100 === (int) ( $d_trunc['views']['previous'] ?? -1 ), 'period_deltas: truncated cwin falls back to the prior window (no silent zero-row read)' );
$d_sentinel = sn_analytics_period_deltas( '2026-07-06', '2026-07-12', 'human', array( '', '' ) );
ok( 100 === (int) ( $d_sentinel['views']['previous'] ?? -1 ), 'period_deltas: the off-sentinel array(\'\',\'\') falls back to the prior window' );
ok( array( '2025-07-06', '2025-07-12' ) === sn_analytics_resolve_cwin( array( '2025-07-06', '2025-07-12' ), '2026-07-06', '2026-07-12' ), 'resolve_cwin: well-formed tuple wins' );
ok( array( '2026-06-29', '2026-07-05' ) === sn_analytics_resolve_cwin( null, '2026-07-06', '2026-07-12' ), 'resolve_cwin: null falls back to the prior window' );
$GLOBALS['__totals_by_window'] = array();

echo "\nGroup: nullable Phase A delta metrics (v9.64.0 — the honest Overview's badges)\n";
// range_totals has carried the spec-§4 derived fields since v9.63.0; the
// deltas layer now mirrors three of them for the Overview strip. These are
// NULLABLE (legacy / pre-backfill / failed read = "never measured"): the
// verdict follows the engaged-rate precedent — no verdict unless BOTH sides
// are known, and null is NEVER coerced to a confident 0.
$GLOBALS['__de_totals']['2026-08-08|2026-08-14'] = array(
	'views' => 300, 'visits' => 90, 'scroll_avg' => 50, 'time_avg' => 100,
	'pageview_visits' => 60, 'scroll_avg_per_view' => 40.0, 'time_avg_per_view' => 12000.0,
);
$GLOBALS['__de_totals']['2026-08-01|2026-08-07'] = array(
	'views' => 200, 'visits' => 80, 'scroll_avg' => 50, 'time_avg' => 100,
	'pageview_visits' => 50, 'scroll_avg_per_view' => 50.0, 'time_avg_per_view' => 10000.0,
);
$dn = sn_analytics_period_deltas( '2026-08-08', '2026-08-14', 'human' );
ok( 60 === ( $dn['pageview_visits']['current'] ?? null ) && 50 === ( $dn['pageview_visits']['previous'] ?? null ), 'nullable: pageview_visits current/previous carried as ints' );
ok( 20 === ( $dn['pageview_visits']['pct'] ?? null ) && 'up' === ( $dn['pageview_visits']['dir'] ?? '' ), 'nullable: pageview_visits +20% up (both sides known)' );
ok( -20 === ( $dn['scroll_avg_per_view']['pct'] ?? null ) && 'down' === ( $dn['scroll_avg_per_view']['dir'] ?? '' ), 'nullable: scroll_avg_per_view -20% down (40 vs 50, the v9.64.0 depth unit)' );
ok( 20 === ( $dn['time_avg_per_view']['pct'] ?? null ) && 'up' === ( $dn['time_avg_per_view']['dir'] ?? '' ), 'nullable: time_avg_per_view +20% up' );
ok( 40.0 === ( $dn['scroll_avg_per_view']['current'] ?? null ) && is_float( $dn['scroll_avg_per_view']['current'] ), 'nullable: engagement current stays a float' );
// Legacy quartet untouched by the extension.
ok( 50 === (int) ( $dn['views']['pct'] ?? -1 ) && 'up' === ( $dn['views']['dir'] ?? '' ), 'nullable: legacy views delta unchanged beside the new keys (300 vs 200 → +50%)' );
// Prior window pre-backfill (derived keys ABSENT ≡ never measured): current is
// kept, previous is null (never a fabricated 0), and there is NO verdict.
$GLOBALS['__de_totals']['2026-09-08|2026-09-14'] = array(
	'views' => 100, 'visits' => 40, 'scroll_avg' => 50, 'time_avg' => 100,
	'pageview_visits' => 30, 'scroll_avg_per_view' => 45.0, 'time_avg_per_view' => 9000.0,
);
// (prior window 2026-09-01|2026-09-07 unset → the stub's legacy-quartet default)
$dl = sn_analytics_period_deltas( '2026-09-08', '2026-09-14', 'human' );
// array_key_exists, never `??` — ( null ?? 'MISSING' ) === null is
// UNSATISFIABLE (the exact trap in project memory, caught here in RED).
$dl_pv = is_array( $dl['pageview_visits'] ?? null ) ? $dl['pageview_visits'] : array();
ok( 30 === ( $dl_pv['current'] ?? null ) && array_key_exists( 'previous', $dl_pv ) && null === $dl_pv['previous'], 'nullable: unknown prior side → previous null, never 0' );
ok( array_key_exists( 'pct', $dl_pv ) && null === $dl_pv['pct'] && 'flat' === ( $dl_pv['dir'] ?? '' ), 'nullable: no verdict without both sides (pct null, dir flat — the engaged-rate precedent)' );
ok( is_array( $dl['scroll_avg_per_view'] ?? null ) && array_key_exists( 'previous', $dl['scroll_avg_per_view'] ) && null === $dl['scroll_avg_per_view']['previous'], 'nullable: engagement previous null via array_key_exists (present-but-null, not missing)' );
// Both sides unknown (all-legacy windows): every slot null/flat, keys present.
$dz = sn_analytics_period_deltas( '2026-10-08', '2026-10-14', 'human' );
ok( array_key_exists( 'pageview_visits', $dz ) && null === $dz['pageview_visits']['current'] && null === $dz['pageview_visits']['previous'] && null === $dz['pageview_visits']['pct'] && 'flat' === $dz['pageview_visits']['dir'], 'nullable: all-legacy windows → fully null entry, keys still present' );

echo "\nGroup: bot breakdown\n";
$GLOBALS['__de_class'] = array(
	'human'   => array( 'views' => 1000, 'visits' => 400 ),
	'suspect' => array( 'views' => 120,  'visits' => 30 ),
	'bot'     => array( 'views' => 300,  'visits' => 15 ),
);
$GLOBALS['__de_dim']['network|bot'] = array(
	array( 'value' => 'Amazon.com, Inc.', 'views' => 180, 'visits' => 8 ),
	array( 'value' => 'Google LLC',       'views' => 90,  'visits' => 5 ),
);
$bb = sn_analytics_bot_breakdown( '2026-06-01', '2026-06-07', 10 );
ok( $bb['totals']['human'] === 1000 && $bb['totals']['bot'] === 300 && $bb['totals']['suspect'] === 120, 'bot-breakdown: per-class totals from class_totals' );
ok( $bb['totals']['total'] === 1420, 'bot-breakdown: total = human+suspect+bot' );
ok( count( $bb['top_bot_networks'] ) === 2 && $bb['top_bot_networks'][0]['value'] === 'Amazon.com, Inc.', 'bot-breakdown: top bot ASNs from the network dim (class=bot)' );
$last = end( $GLOBALS['__de_dim_calls'] );
ok( $last[0] === 'network' && $last[3] === 'bot', 'bot-breakdown: queries network dim filtered to class=bot' );

echo "\nGroup: v9.68.1 null-on-failure propagation\n";
$GLOBALS['__de_dim']['referrer|human'] = null; // the accessor's failed-read verdict
ok( null === sn_analytics_referrer_categories( '2026-09-01', '2026-09-07', 'human' ),
	'categories: a failed dims read propagates as NULL — never four fabricated zero-filled categories' );
unset( $GLOBALS['__de_dim']['referrer|human'] );
$empty_cats = sn_analytics_referrer_categories( '2026-09-01', '2026-09-07', 'human' );
ok( is_array( $empty_cats ) && 4 === count( $empty_cats ),
	'categories: an empty (successful) read still returns the 4 zero-filled categories — a real quiet window' );
$GLOBALS['__de_dim']['network|bot'] = null; // the bot-networks read fails; class totals still read fine
$bb_f = sn_analytics_bot_breakdown( '2026-09-01', '2026-09-07', 10 );
ok( 1420 === $bb_f['totals']['total'], 'bot-breakdown: the class totals (their own table) still serve' );
ok( array_key_exists( 'top_bot_networks', $bb_f ) && null === $bb_f['top_bot_networks'],
	'bot-breakdown: a failed networks read carries NULL through — never a quiet empty list' );
unset( $GLOBALS['__de_dim']['network|bot'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

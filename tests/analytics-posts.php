<?php
/**
 * Tests for the Posts (lifecycle) analytics view DATA LAYER — the age-alignment
 * math that makes "did it land" meaningful (cumulative-by-day-of-life, cohort
 * median baseline, velocity, decay classification, rank) + the post→path map.
 *
 * Run: php tests/analytics-posts.php
 * @since plugin v6.39.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_permalink( $id ) { return $GLOBALS['__perma'][ $id ] ?? false; }
// The verdict reuses the canonical delta primitive (stubbed to its real semantics).
function sn_analytics_delta( $cur, $prev ) {
	$cur = (float) $cur; $prev = (float) $prev;
	if ( $prev <= 0 ) { return array( 'pct' => null, 'dir' => $cur > 0 ? 'up' : 'flat' ); }
	$pct = (int) round( ( $cur - $prev ) / $prev * 100 );
	return array( 'pct' => $pct, 'dir' => $cur > $prev ? 'up' : ( $cur < $prev ? 'down' : 'flat' ) );
}

require_once __DIR__ . '/../inc/analytics-posts.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Posts lifecycle data layer\n\n";

echo "Group: post → stored path (matches the beacon's location.pathname)\n";
$GLOBALS['__perma'] = array( 7 => 'https://juanlentino.com/notes/the-signal/', 9 => 'https://juanlentino.com/about/uses/' );
ok( '/notes/the-signal/' === sn_analytics_post_path( 7 ), 'permalink → path keeps the trailing slash (pretty-permalink form)' );
ok( '' === sn_analytics_post_path( 404 ), 'unknown post → empty path (no crash)' );

echo "\nGroup: daily series → views indexed by day-of-life\n";
$pub = strtotime( '2026-06-01 00:00:00 UTC' );
$series = array(
	array( 'day' => '2026-06-01', 'views' => 10 ),
	array( 'day' => '2026-06-03', 'views' => 5 ),
	array( 'day' => '2026-06-04', 'views' => 2 ),
);
$by_dol = sn_analytics_posts_daily_by_dol( $series, $pub );
ok( ( $by_dol[0] ?? null ) === 10 && ( $by_dol[2] ?? null ) === 5 && ( $by_dol[3] ?? null ) === 2,
	'day-of-life index: publish day = 0, +2 days = 2, +3 days = 3' );
ok( ! isset( $by_dol[1] ), 'a day with no rows is simply absent (not zero-filled)' );
ok( array() === sn_analytics_posts_daily_by_dol( array( array( 'day' => '2026-05-30', 'views' => 9 ) ), $pub ),
	'a pre-publish day (negative day-of-life) is dropped' );

echo "\nGroup: cumulative views through a given age\n";
$bd = array( 0 => 10, 2 => 5, 3 => 2 );
ok( 10 === sn_analytics_posts_cumulative_at( $bd, 0 ), 'cumulative at age 0 = day-0 views' );
ok( 10 === sn_analytics_posts_cumulative_at( $bd, 1 ), 'cumulative at age 1 = still 10 (gap day)' );
ok( 17 === sn_analytics_posts_cumulative_at( $bd, 3 ), 'cumulative at age 3 = 10+5+2' );
ok( 17 === sn_analytics_posts_cumulative_at( $bd, 99 ), 'cumulative past the last day = lifetime so far' );

echo "\nGroup: median (the cohort baseline reducer)\n";
ok( 20 === sn_analytics_median( array( 30, 10, 20 ) ), 'odd count → middle value (sorted)' );
ok( 15.0 === sn_analytics_median( array( 10, 20 ) ), 'even count → mean of the two middles' );
ok( 5 === sn_analytics_median( array( 5 ) ), 'single value → itself' );
ok( 0 === sn_analytics_median( array() ), 'empty cohort → 0 (caller treats as "no baseline")' );

echo "\nGroup: launch velocity (first-N-day views)\n";
ok( 13 === sn_analytics_posts_velocity( array( 0 => 10, 1 => 3, 2 => 5 ), 2 ), 'velocity(2) = day0 + day1' );
ok( 10 === sn_analytics_posts_velocity( array( 0 => 10, 5 => 99 ), 2 ), 'velocity ignores days outside the window' );
ok( 0 === sn_analytics_posts_velocity( array(), 2 ), 'no data → 0 velocity (no crash)' );

echo "\nGroup: evergreen vs spike (decay classification)\n";
ok( 'spike' === sn_analytics_posts_decay( array( 0 => 90, 1 => 5, 8 => 5 ), 7 ), 'front-loaded (95% in week 1) → spike' );
ok( 'evergreen' === sn_analytics_posts_decay( array( 0 => 20, 10 => 30, 20 => 50 ), 7 ), 'sustained tail (20% in week 1) → evergreen' );
ok( 'cooling' === sn_analytics_posts_decay( array( 0 => 40, 2 => 25, 10 => 35 ), 7 ), 'mid-share (65% in week 1) → cooling' );
ok( '' === sn_analytics_posts_decay( array(), 7 ), 'no data → no classification (empty string)' );

echo "\nGroup: rank among the cohort at the same age\n";
$r = sn_analytics_posts_rank( 50, array( 80, 30, 40 ) );
ok( 2 === $r['rank'] && 4 === $r['of'], 'subject 50 vs [80,30,40] → rank 2 of 4' );
$r2 = sn_analytics_posts_rank( 100, array( 10, 20 ) );
ok( 1 === $r2['rank'] && 3 === $r2['of'], 'a clear winner → rank 1' );
$r3 = sn_analytics_posts_rank( 5, array() );
ok( 1 === $r3['rank'] && 1 === $r3['of'], 'no cohort → rank 1 of 1 (just the subject)' );

echo "\nGroup: subject verdict compares the newest post to the cohort AT ITS AGE\n";
// Newest = age 5, 100 views by day 5. Cohort: A (age 10, 60 by day 5), B (age 3 —
// YOUNGER than 5, must be excluded), C (age 8, 140 by day 5). Cohort@5 = [60,140].
$rows = array(
	array( 'id' => 1, 'title' => 'Newest', 'permalink' => '/n1/', 'age' => 5,  'by_dol' => array( 0 => 100 ), 'lifetime' => 100 ),
	array( 'id' => 2, 'title' => 'A',      'permalink' => '/n2/', 'age' => 10, 'by_dol' => array( 0 => 60 ),  'lifetime' => 200 ),
	array( 'id' => 3, 'title' => 'B',      'permalink' => '/n3/', 'age' => 3,  'by_dol' => array( 0 => 999 ), 'lifetime' => 999 ),
	array( 'id' => 4, 'title' => 'C',      'permalink' => '/n4/', 'age' => 8,  'by_dol' => array( 0 => 140 ), 'lifetime' => 300 ),
);
$s = sn_analytics_posts_subject( $rows );
ok( 100 === $s['views'], 'subject views-at-age = its cumulative through day 5' );
ok( 100.0 === $s['median'], 'cohort median uses only posts ≥ age 5 (B at age 3 excluded): median(60,140)=100.0' );
ok( 'flat' === $s['delta']['dir'] && 0 === $s['delta']['pct'], 'subject == median → flat 0%' );
ok( 2 === $s['rank']['rank'] && 3 === $s['rank']['of'], 'rank 2 of 3 (only subject + the 2 eligible cohort posts)' );
ok( true === $s['has_data'], 'subject with views has data' );

echo "\nGroup: subject degrades gracefully with no analytics yet\n";
$empty = array( array( 'id' => 9, 'title' => 'Fresh', 'permalink' => '/f/', 'age' => 2, 'by_dol' => array(), 'lifetime' => 0 ) );
$e = sn_analytics_posts_subject( $empty );
ok( 0 === $e['views'] && false === $e['has_data'], 'newest post with zero rows → views 0, has_data false (no divide-by-zero)' );
ok( 1 === $e['rank']['rank'] && 1 === $e['rank']['of'], 'no cohort → rank 1 of 1' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

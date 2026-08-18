<?php
/**
 * Tests for the A4 "path decay at scale" data layer — batching the decay
 * classifier across the whole catalogue, cross-referenced with the B5 evergreen
 * flag to surface refresh candidates (cooling AND not flagged evergreen).
 *
 * Pure helpers only (the $wpdb batch reader is a thin untested accessor, matching
 * the analytics-posts.php test philosophy). Run: php tests/analytics-posts-lifecycle.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_permalink( $id ) { return $GLOBALS['__perma'][ $id ] ?? false; }

require_once __DIR__ . '/../inc/analytics-posts-lifecycle.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "A4 lifecycle-at-scale data layer\n\n";

// ── classify: reuses the share-based decay, adds the refresh-candidate flag ──
echo "Group: classify (decay shape + refresh candidate)\n";
// 90% of lifetime in week 1 → spike; not a refresh candidate.
$spike = array( 0 => 90, 8 => 10 );
$c = sn_analytics_lifecycle_classify( $spike, SN_POSTS_DECAY_DAYS, false );
ok( 'spike' === $c['decay'] && false === $c['refresh_candidate'], 'spike (front-loaded) is not a refresh candidate' );

// 60% in week 1 (60 of 100) → cooling; not evergreen-flagged → refresh candidate.
$cooling = array( 0 => 40, 3 => 20, 30 => 40 );
$c = sn_analytics_lifecycle_classify( $cooling, SN_POSTS_DECAY_DAYS, false );
ok( 'cooling' === $c['decay'] && true === $c['refresh_candidate'], 'cooling + not evergreen → refresh candidate' );

// Same cooling series, but the editor flagged it evergreen → NOT a candidate.
$c = sn_analytics_lifecycle_classify( $cooling, SN_POSTS_DECAY_DAYS, true );
ok( 'cooling' === $c['decay'] && false === $c['refresh_candidate'], 'cooling + evergreen flag → editor overrode, not a candidate' );

// Sustained tail (≤50% in week 1) → sustained shape; never a candidate.
$eg = array( 0 => 10, 20 => 20, 60 => 30 );
$c = sn_analytics_lifecycle_classify( $eg, SN_POSTS_DECAY_DAYS, false );
ok( 'sustained' === $c['decay'] && false === $c['refresh_candidate'], 'sustained shape is never a refresh candidate' );

// No data → empty shape, not a candidate.
$c = sn_analytics_lifecycle_classify( array(), SN_POSTS_DECAY_DAYS, false );
ok( '' === $c['decay'] && false === $c['refresh_candidate'], 'no data → empty shape, not a candidate' );

// ── row builder: aligns each path's series to day-of-life, sums lifetime ──
echo "\nGroup: row builder (posts × batched series → lifecycle rows)\n";
$now = strtotime( '2026-07-01 00:00:00 UTC' );
$posts = array(
	array( 'id' => 1, 'title' => 'Old cooling', 'permalink' => 'https://x.test/a/', 'path' => '/a/', 'publish_ts' => strtotime( '2026-05-01 00:00:00 UTC' ), 'modified_ts' => strtotime( '2026-05-01 00:00:00 UTC' ), 'evergreen' => false ),
	array( 'id' => 2, 'title' => 'Flagged evergreen', 'permalink' => 'https://x.test/b/', 'path' => '/b/', 'publish_ts' => strtotime( '2026-05-01 00:00:00 UTC' ), 'modified_ts' => strtotime( '2026-06-20 00:00:00 UTC' ), 'evergreen' => true ),
	array( 'id' => 3, 'title' => 'No traffic', 'permalink' => 'https://x.test/c/', 'path' => '/c/', 'publish_ts' => strtotime( '2026-06-25 00:00:00 UTC' ), 'modified_ts' => strtotime( '2026-06-25 00:00:00 UTC' ), 'evergreen' => false ),
);
// Batched series keyed by path (what the single grouped query returns).
$series_by_path = array(
	// 60 of 100 views in week 1 → cooling shape (matches the classify group above).
	'/a/' => array( array( 'day' => '2026-05-01', 'views' => 40 ), array( 'day' => '2026-05-04', 'views' => 20 ), array( 'day' => '2026-06-15', 'views' => 40 ) ),
	'/b/' => array( array( 'day' => '2026-05-01', 'views' => 40 ), array( 'day' => '2026-05-04', 'views' => 20 ), array( 'day' => '2026-06-15', 'views' => 40 ) ),
	// '/c/' absent → no traffic.
);
$rows = sn_analytics_posts_lifecycle_rows( $posts, $series_by_path, $now );
ok( count( $rows ) === 3, 'one row per post' );
$byid = array(); foreach ( $rows as $r ) { $byid[ $r['id'] ] = $r; }
ok( $byid[1]['lifetime'] === 100, 'lifetime = sum of the path series (40+20+40)' );
ok( $byid[1]['decay'] === 'cooling' && $byid[1]['refresh_candidate'] === true, 'post 1: cooling + unflagged → candidate' );
ok( $byid[2]['decay'] === 'cooling' && $byid[2]['refresh_candidate'] === false, 'post 2: same shape but evergreen-flagged → not a candidate' );
ok( $byid[2]['evergreen'] === true, 'post 2 carries the evergreen flag through' );
ok( $byid[3]['lifetime'] === 0 && $byid[3]['decay'] === '' && $byid[3]['refresh_candidate'] === false, 'post 3: no traffic → empty shape, not a candidate' );
ok( $byid[1]['age'] === 61, 'age = whole days since publish (May 1 → Jul 1)' );

// ── summary: counts per shape + total refresh candidates ──
echo "\nGroup: summary rollup\n";
$sum = sn_analytics_lifecycle_summary( $rows );
ok( $sum['counts']['cooling'] === 2, 'summary counts both cooling posts' );
ok( $sum['counts']['unknown'] === 1, 'summary buckets the no-data post as unknown' );
ok( $sum['refresh_candidates'] === 1, 'summary: exactly one refresh candidate (post 1)' );
ok( $sum['total'] === 3, 'summary total = row count' );

// ── ordering helper: candidates first, then by lifetime desc ──
echo "\nGroup: leaderboard ordering (candidates surface to the top)\n";
$ordered = sn_analytics_lifecycle_sort( $rows );
ok( $ordered[0]['id'] === 1, 'refresh candidate sorts to the top' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );

<?php
/**
 * Tests for the Posts (lifecycle) view RENDER layer — 1:1 native treatment
 * (cloned .sn-kpi hero, shared trend/smooth-path trajectory, shared .wp-list-table
 * leaderboard, shared distribution bars) + graceful empty states.
 *
 * Run: php tests/analytics-posts-admin.php
 * @since plugin v6.39.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? (string) $single : (string) $plural; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function get_edit_post_link( $id ) { return '/wp-admin/post.php?post=' . (int) $id; }
// Reused shared helpers — stubbed to leave detectable markers (the real ones live
// in analytics-admin-render.php; the point is the view DELEGATES to them, 1:1).
function snt_analytics_render_distribution( $title, $rows, $empty = '' ) { echo '[DIST:' . $title . ':' . count( (array) $rows ) . ']'; }
function snt_analytics_smooth_path( $px, $py, $top, $base ) { return 'M0,0 SMOOTH'; }

require_once __DIR__ . '/../inc/analytics-posts.php';       // pure helpers the render reuses
require_once __DIR__ . '/../inc/analytics-posts-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function cap( $cb ) { ob_start(); $cb(); return ob_get_clean(); }

echo "Posts view render\n\n";

$subject = array(
	'id' => 7, 'title' => 'My Note', 'permalink' => '/notes/x/', 'age' => 3,
	'views' => 120, 'lifetime' => 120, 'median' => 80,
	'delta' => array( 'pct' => 50, 'dir' => 'up' ), 'rank' => array( 'rank' => 1, 'of' => 5 ),
	'by_dol' => array( 0 => 90, 1 => 20, 2 => 10 ), 'has_data' => true,
);
$leaderboard = array(
	array( 'id' => 7, 'title' => 'My Note', 'permalink' => '/notes/x/', 'age' => 3,  'by_dol' => array( 0 => 90, 1 => 20, 2 => 10 ), 'lifetime' => 120, 'per_day' => 30.0, 'velocity' => 110, 'decay' => 'spike' ),
	array( 'id' => 5, 'title' => 'Older Note', 'permalink' => '/notes/y/', 'age' => 30, 'by_dol' => array( 0 => 10, 20 => 390 ), 'lifetime' => 400, 'per_day' => 12.9, 'velocity' => 14, 'decay' => 'evergreen' ),
);
$bundle = array( 'subject' => $subject, 'leaderboard' => $leaderboard, 'generated' => 1750000000 );

echo "Group: hero clones the native .sn-kpi treatment (1:1)\n";
$hero = cap( function () use ( $subject ) { snt_analytics_render_post_hero( $subject ); } );
ok( strpos( $hero, 'sn-kpi-row' ) !== false, 'hero uses the shared .sn-kpi-row vocabulary (no new CSS)' );
ok( strpos( $hero, 'My Note' ) !== false, 'hero shows the post title' );
ok( strpos( $hero, '<p class="sn-kpi-value">120</p>' ) !== false, 'hero promotes views-since-publish (120)' );
ok( strpos( $hero, 'sn-kpi-delta sn-delta-up' ) !== false && strpos( $hero, '+50%' ) !== false,
	'verdict badge carries the real direction class + signed pct (▲ +50% vs typical)' );
ok( strpos( $hero, '#1' ) !== false && strpos( $hero, '5' ) !== false, 'hero shows the rank (#1 of 5)' );
// v9.40.0 D4: hero cards now route through the shared snt_an_kpi_row primitive —
// pin the sub_class fall-through for the always-flat cards (Lifetime has no
// real {pct,dir} pair, so it defaults to the primitive's flat class).
ok( strpos( $hero, 'sn-delta-flat">all-time' ) !== false, 'lifetime card falls through to the primitive default flat class' );
// v9.40.0 D4: the hero postbox now routes through the shared panel primitive —
// pin BOTH the new sn-an-postbox marker AND the KPI-scale sn-overview class
// (the D1 CSS ".sn-an-postbox.sn-overview" block depends on both being present).
ok( strpos( $hero, 'class="postbox sn-an-postbox sn-overview"' ) !== false, 'hero panel adopts the primitive and keeps the sn-overview KPI-scale class' );

echo "\nGroup: hero degrades gracefully (no analytics yet)\n";
// D4 §4: the no-data hero now folds entirely (title included) instead of
// opening a panel with just the title + an inline empty note — the fold is
// the only place this Note's "did it land?" copy survives to. The view-level
// null-bundle gate (snt_an_gate) still covers "no posts at all"; this is the
// narrower "one post exists, zero views yet" case.
$empty_subject = array( 'id' => 9, 'title' => 'Fresh', 'permalink' => '/f/', 'age' => 1, 'views' => 0, 'lifetime' => 0, 'median' => 0, 'delta' => array( 'pct' => null, 'dir' => 'flat' ), 'rank' => array( 'rank' => 1, 'of' => 1 ), 'by_dol' => array(), 'has_data' => false );
unset( $GLOBALS['sn_an_empty_panels'] );
$eh = cap( function () use ( $empty_subject ) { snt_analytics_render_post_hero( $empty_subject ); } );
ok( '' === $eh, 'no-data subject → hero folds instead of rendering inline (D4 §4)' );
ok( strpos( $eh, 'sn-delta-up' ) === false, 'no spurious verdict badge when there is no baseline' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Latest Note — did it land?' === $noted[0]['title'], 'no-data subject: hero title noted for the fold' );
// D5 §4: the fold why now NAMES the Note by its own title (was a generic
// "this Note has no recorded views…" — copy change, deliberate).
ok( false !== strpos( $noted[0]['why'], '"Fresh" has no recorded views yet' ), 'no-data subject: fold why names the Note by title' );

echo "\nGroup: leaderboard reuses the shared .wp-list-table chrome (bespoke columns)\n";
$lb = cap( function () use ( $leaderboard ) { snt_analytics_render_posts_leaderboard( $leaderboard ); } );
ok( strpos( $lb, 'wp-list-table widefat striped' ) !== false, 'leaderboard table uses the shared list-table chrome' );
ok( strpos( $lb, 'My Note' ) !== false && strpos( $lb, 'Older Note' ) !== false, 'every recent post is listed' );
ok( strpos( $lb, '>400<' ) !== false, 'lifetime views column rendered (400)' );
ok( strpos( $lb, 'spike' ) !== false && strpos( $lb, 'evergreen' ) !== false, 'decay verdict chip per post' );
// v9.40.0 D4: the catalog postbox is "plain" (no sn-overview) — adopts the primitive marker only.
ok( strpos( $lb, 'class="postbox sn-an-postbox"' ) !== false, 'catalog panel adopts the primitive (plain, no sn-overview)' );

echo "\nGroup: catalog panel empty state folds instead of opening chrome (D4 §4)\n";
unset( $GLOBALS['sn_an_empty_panels'] );
$lb_empty = cap( function () { snt_analytics_render_posts_leaderboard( array() ); } );
ok( '' === $lb_empty, 'empty catalog folds instead of opening a panel' );
ok( substr_count( $lb_empty, '<div' ) === substr_count( $lb_empty, '</div>' ), 'empty catalog keeps the div count balanced (trivially, nothing rendered)' );
$noted_lb = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted_lb ) && 'Your catalog' === $noted_lb[0]['title'] && 'No posts yet.' === $noted_lb[0]['why'], 'empty catalog keeps its exact empty-state copy, carried as the fold why' );

echo "\nGroup: trajectory reuses the shared smooth-path treatment, two curves\n";
$traj = cap( function () use ( $subject, $leaderboard ) { snt_analytics_render_post_trajectory( $subject, $leaderboard ); } );
ok( strpos( $traj, '<svg' ) !== false, 'trajectory renders an SVG' );
ok( substr_count( $traj, 'SMOOTH' ) >= 2, 'both the post line AND the baseline band use the shared snt_analytics_smooth_path (≥2 curves)' );
// v9.40.0 D4: plain postbox — no sn-overview.
ok( strpos( $traj, 'class="postbox sn-an-postbox"' ) !== false, 'trajectory panel adopts the primitive (plain, no sn-overview)' );

echo "\nGroup: the view delegates velocity + decay to the shared distribution bars\n";
$view = cap( function () use ( $bundle ) { snt_analytics_render_posts_view( $bundle ); } );
ok( strpos( $view, '[DIST:' ) !== false, 'view delegates at least one panel to snt_analytics_render_distribution' );
ok( strpos( $view, 'sn-kpi-row' ) !== false && strpos( $view, 'wp-list-table' ) !== false, 'view composes hero + leaderboard' );

echo "\nGroup: whole-view empty state\n";
$none = cap( function () { snt_analytics_render_posts_view( null ); } );
ok( strpos( $none, 'sn-an-empty' ) !== false, 'null bundle (no published posts) → a single empty-state note, no fatal' );
// v9.40.0 D4: the null-bundle notice adopts the unified snt_an_gate() idiom —
// a deliberate upgrade from the old titleless bare <p> to full postbox chrome.
ok( strpos( $none, 'sn-an-gate' ) !== false, 'null bundle → the unified gate (postbox chrome), not a bare paragraph' );
ok( strpos( $none, 'No published posts yet — this view tracks each Note over its lifetime once you publish and traffic arrives.' ) !== false,
	'null-bundle gate carries the exact original message' );
ok( strpos( $none, '<span>Posts</span>' ) !== false, 'null-bundle gate carries a title (upgrade from the old titleless bare <p>)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

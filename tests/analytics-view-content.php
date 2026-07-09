<?php
/**
 * Standalone fixture tests for the v8.5.0 Content view
 * (inc/analytics-view-content.php): the approved regrouped layout — Top pages
 * beside Top sources + Referrer-categories chips, then the Journeys &
 * diagnostics row (Entry / Exit / Low engagement) under a hairline label.
 * Everything the pre-v8.5.0 view rendered is still here, clamped not cut.
 *
 * Run: php tests/analytics-view-content.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

// Data accessors — recorders; the view's composition is under test.
function sn_analytics_top_paths( $f, $t, $c = 'human', $l = 25 ) { return $GLOBALS['__tp'] ?? array( array( 'path' => '/a/', 'views' => 1 ) ); }
function sn_analytics_top_sources( $f, $t, $c = 'human', $l = 10 ) { return array( array( 'value' => 'Direct', 'views' => 4, 'visits' => 2 ) ); }
function sn_analytics_top_sources_series( $rows, $f, $t, $c = 'human', $g = 'day' ) { return array(); }
function sn_analytics_referrer_categories( $f, $t, $c = 'human' ) { return $GLOBALS['__rc'] ?? array( array( 'category' => 'search', 'label' => 'Search', 'views' => 4, 'visits' => 2 ) ); }
function sn_analytics_low_engagement_paths( $f, $t, $c = 'human', $l = 15 ) { return array(); }
function sn_analytics_top_entry_pages( $f, $t, $l = 25 ) { return array( array( 'path' => '/', 'views' => 9, 'visits' => 8 ) ); }
function sn_analytics_top_exit_pages( $f, $t, $l = 25 ) { return array( array( 'path' => '/contact/', 'views' => 5, 'visits' => 5 ) ); }

// Renderer recorders — each panel has its own suite.
function snt_analytics_render_paths_table( $rows ) { echo '<!--PATHS-->'; }
function snt_analytics_render_dim_table( $title, $rows, $empty, $series = array(), $drill = '' ) { echo '<!--DIM:' . esc_html( $title ) . '-->'; }
function snt_analytics_render_referrer_categories( $cats ) { echo '<!--REFCATS-->'; }
function snt_analytics_render_lowengage( $rows ) { echo '<!--LOWENGAGE-->'; }
function snt_analytics_render_pageroles_table( $rows, $role ) { echo '<!--PAGEROLES:' . esc_html( $role ) . '-->'; }

require_once __DIR__ . '/../inc/analytics-annotations.php'; // v9.5.0: the read resolvers the view now calls
require_once __DIR__ . '/../inc/analytics-view-content.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-content suite - plugin v8.5.0\n";

echo "\nTest: regrouped composition\n";
ob_start();
snt_analytics_render_view_content( '2026-07-01', '2026-07-07', 'human', 'day' );
$html = (string) ob_get_clean();

ok( false !== strpos( $html, 'sn-an-content-grid' ), 'content grid wrapper present' );
ok( false !== strpos( $html, 'sn-an-content-main' ) && false !== strpos( $html, 'sn-an-content-side' ), 'main + side columns present' );
$main = strpos( $html, 'sn-an-content-main' );
$side = strpos( $html, 'sn-an-content-side' );
$paths = strpos( $html, '<!--PATHS-->' );
$dim   = strpos( $html, '<!--DIM:Top sources-->' );
$cats  = strpos( $html, '<!--REFCATS-->' );
ok( false !== $paths && $paths > $main && $paths < $side, 'Top pages lives in the main column' );
ok( false !== $dim && false !== $cats && $dim > $side && $cats > $dim, 'Top sources then Referrer categories stack in the side column' );

ok( false !== strpos( $html, 'sn-an-journeys-label' ), 'journeys hairline label present' );
ok( false !== stripos( $html, 'human only' ), 'human-only note lives in the section label' );
ok( false !== strpos( $html, 'sn-an-journeys-grid' ), 'journeys grid present' );
$jgrid = strpos( $html, 'sn-an-journeys-grid' );
$entry = strpos( $html, '<!--PAGEROLES:entry-->' );
$exit  = strpos( $html, '<!--PAGEROLES:exit-->' );
$lowe  = strpos( $html, '<!--LOWENGAGE-->' );
ok( false !== $entry && false !== $exit && false !== $lowe && $entry > $jgrid && $exit > $entry && $lowe > $exit, 'Entry, Exit, Low engagement are the journeys-row siblings, in order' );
ok( false === strpos( $html, 'sn-an-sep--full' ), 'the old separator paragraph is gone (label carries the note)' );
// v9.5.0: with the quiet default fixtures (one page, one search referrer) neither
// read trips, so the view emits no annotation.
ok( false === strpos( $html, 'sn-an-note' ), 'quiet range emits no annotation read' );

echo "\nTest: pageroles absence degrades (partial install)\n";
// The function_exists guard: simulate by asserting the guard exists in source
// (the functions are defined in this fixture, so runtime-simulating absence
// is impossible without namespaces — pin the guard textually).
$src = (string) file_get_contents( __DIR__ . '/../inc/analytics-view-content.php' );
ok( false !== strpos( $src, "function_exists( 'snt_analytics_render_pageroles_table' )" ), 'entry/exit stay behind the function_exists guard' );

echo "\nTest: v9.5.0 reads fire on concentration (render integration)\n";
$GLOBALS['__tp'] = array(
	array( 'path' => '/a/', 'views' => 61 ),
	array( 'path' => '/b/', 'views' => 15 ),
	array( 'path' => '/c/', 'views' => 14 ),
	array( 'path' => '/d/', 'views' => 10 ),
);
$GLOBALS['__rc'] = array(
	array( 'category' => 'direct', 'label' => 'Direct', 'views' => 90, 'visits' => 90 ),
	array( 'category' => 'search', 'label' => 'Search', 'views' => 6, 'visits' => 6 ),
	array( 'category' => 'social', 'label' => 'Social', 'views' => 3, 'visits' => 3 ),
	array( 'category' => 'other',  'label' => 'Other',  'views' => 1, 'visits' => 1 ),
);
ob_start();
snt_analytics_render_view_content( '2026-07-01', '2026-07-07', 'human', 'day' );
$hot = (string) ob_get_clean();
ok( substr_count( $hot, 'class="sn-an-note"' ) >= 2, 'both column reads emit on tripping data' );
ok( false !== strpos( $hot, 'top pages' ), 'top-pages read text is rendered' );
ok( false !== strpos( $hot, 'direct' ), 'sources read text is rendered' );
$note_pos = strpos( $hot, 'class="sn-an-note"' );
$side_pos = strpos( $hot, 'sn-an-content-side' );
ok( false !== $note_pos && $note_pos < $side_pos, 'the top-pages read sits in the main column, above the side column' );
$GLOBALS['__tp'] = null;
$GLOBALS['__rc'] = null;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Smoke test: the Topics panel renderer (v10.21.0) owns its THREE states —
 * not-built (fold, honest why), read-failed (the shared failure sentence,
 * never an empty fold), and rows (real panel, escaped, annotation pass over
 * member paths). Loads the REAL inc/analytics-panels.php like its siblings
 * (the barrel requires it unconditionally — pre-declaring stubs would fatal).
 * Run: php tests/analytics-render-topics.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return (string) $n; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

// The ML + accessor seams, controllable per phase. Both are function_exists-
// guarded in the renderer, so they must exist BEFORE the require (top-level
// declarations here are hoisted — which is fine: we WANT them present).
$GLOBALS['__topics_art'] = null;
function snt_ml_topics_get() { return $GLOBALS['__topics_art']; }
$GLOBALS['__topic_rows'] = null;
function sn_analytics_topic_totals( $from, $to ) {
	$GLOBALS['__topic_calls'][] = array( $from, $to );
	return $GLOBALS['__topic_rows'];
}

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-annotations.php';
require_once __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Analytics render — Topics panel\n\n";

// ── State 1: topic index never built → folds with the honest why. ──
unset( $GLOBALS['sn_an_empty_panels'] );
$GLOBALS['__topics_art'] = null;
$GLOBALS['__topic_calls'] = array();
ob_start();
snt_analytics_render_topics( '2026-07-01', '2026-07-30' );
$html = ob_get_clean();
ok( '' === trim( $html ), 'not built: emits no panel markup' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Topics' === $noted[0]['title'] && false !== stripos( (string) $noted[0]['why'], 'not built' ), 'not built: folds to an empty note that SAYS not-built (never a fake zero)' );
ok( array() === $GLOBALS['__topic_calls'], 'not built: the rollup accessor is never consulted' );

// ── State 2: read failed → the fold carries the SHARED failure sentence
// (the v9.68.1 geography idiom), never the empty-window copy. ──
unset( $GLOBALS['sn_an_empty_panels'] );
$GLOBALS['__topics_art'] = array( array( 'members' => array( 1 ), 'label' => 'x' ) );
$GLOBALS['__topic_rows'] = null;
ob_start();
snt_analytics_render_topics( '2026-07-01', '2026-07-30' );
$html = ob_get_clean();
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( '' === trim( $html ) && 1 === count( $noted ) && snt_an_read_failed_copy( 'Topics' ) === $noted[0]['why'], 'read failed: the fold speaks the shared failure copy — unknown is never dressed as zero' );

// ── State 3: empty rows → folds as a real zero. ──
unset( $GLOBALS['sn_an_empty_panels'] );
$GLOBALS['__topic_rows'] = array();
ob_start();
snt_analytics_render_topics( '2026-07-01', '2026-07-30' );
$html = ob_get_clean();
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( '' === trim( $html ) && 1 === count( $noted ) && 'Topics' === $noted[0]['title'], 'empty rows: folds (a quiet window is an ANSWER)' );

// ── State 4: rows → the panel, escaped, with the annotation pass. ──
unset( $GLOBALS['sn_an_empty_panels'] );
$GLOBALS['__topic_rows'] = array(
	array( 'label' => 'metadata <em>royalties</em>', 'notes' => 3, 'paths' => 2, 'views' => 90, 'visits' => 40, 'member_paths' => array( '/maturity/analytics/', '/notes/c/' ) ),
	array( 'label' => 'provenance', 'notes' => 2, 'paths' => 2, 'views' => 50, 'visits' => 15, 'member_paths' => array( '/notes/a/', '/notes/b/' ) ),
);
ob_start();
snt_analytics_render_topics( '2026-07-25', '2026-08-05' );
$html = ob_get_clean();
ok( false !== strpos( $html, 'Topics' ) && false !== strpos( $html, 'widefat' ), 'rows: real panel with the standard table idiom' );
ok( false !== strpos( $html, 'metadata &lt;em&gt;royalties&lt;/em&gt;' ) && false === strpos( $html, '<em>royalties' ), 'rows: labels escaped at output — markup in a label never renders' );
ok( false !== strpos( $html, '90' ) && false !== strpos( $html, '40' ), 'rows: views + visits present' );
// The window spans 2026-07-30 and a member path is on the affected list, so
// the REAL maturity-migration resolver (loaded above) must annotate.
ok( false !== stripos( $html, 'sn-an-note-body' ), 'rows: the maturity-migration annotation pass runs over member paths (window spans the cliff + an affected path present)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

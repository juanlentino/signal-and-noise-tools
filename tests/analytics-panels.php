<?php
/**
 * Standalone fixture tests for the v8.5.0 Analytics panel primitive
 * (inc/analytics-panels.php): the ONE place that emits panel chrome on the
 * Analytics page, plus the row-clamp wrapper.
 *
 * Run: php tests/analytics-panels.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); } }

require_once __DIR__ . '/../inc/analytics-panels.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

function capture( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "analytics-panels suite - plugin v8.5.0\n";

echo "\nTest: basic panel shell\n";
$html = capture( function () {
	snt_an_panel_open( 'Top pages' );
	echo 'BODY';
	snt_an_panel_close();
} );
ok( false !== strpos( $html, '<div class="postbox sn-an-postbox">' ), 'native .postbox shell + the sn-an-postbox marker (the token contract hook)' );
ok( false !== strpos( $html, '<h2 class="hndle"><span>Top pages</span></h2>' ), 'normal-case bold postbox header (owner: keep the postbox feel)' );
ok( false !== strpos( $html, '<div class="inside">BODY</div></div>' ), 'default inside class wraps the body' );
ok( 1 === substr_count( $html, 'postbox-header' ), 'exactly one header' );

echo "\nTest: args — inside_class, panel_class, header_meta\n";
$html = capture( function () {
	snt_an_panel_open( 'Uptime', array(
		'inside_class' => 'inside inside-flush',
		'panel_class'  => 'sn-an-rail-tile',
		'header_meta'  => 'vs prior period',
	) );
	snt_an_panel_close();
} );
ok( false !== strpos( $html, 'postbox sn-an-postbox sn-an-rail-tile' ), 'extra panel class appended' );
ok( false !== strpos( $html, '<div class="inside inside-flush">' ), 'inside class override' );
ok( false !== strpos( $html, '<span class="sn-an-head-meta">vs prior period</span>' ), 'header meta rendered right of the title' );

// 2026-11-10 core-first audit (index 3): a collapsible panel is a native
// <details>. Core's postbox.js could not be borrowed for one panel: its
// add_postbox_toggles() binds every `.postbox .hndle` on the page and its
// save_state() writes every `.postbox` into two user metas. The browser owns
// the toggle, the keyboard and the expanded state; no script stores it.
echo "\nTest: collapsible panel is a native disclosure\n";
$html = capture( function () {
	snt_an_panel_open( 'Uptime detail', array( 'collapsible' => true, 'collapsed' => true, 'header_meta' => 'last 7 days' ) );
	echo 'BODY';
	snt_an_panel_close();
} );
ok( 0 === strpos( $html, '<details class="postbox sn-an-postbox" data-sn-an-collapsible="uptime-detail">' ), 'a collapsed panel is a <details> with no open attribute, the postbox shell and the lazy-open marker' );
ok( false !== strpos( $html, '<summary class="postbox-header"><h2 class="hndle"><span>Uptime detail</span></h2><span class="sn-an-head-meta">last 7 days</span></summary>' ), 'the header is the <summary>: the panel title and its meta are the control, so the browser gives it keyboard and expanded state' );
ok( false !== strpos( $html, '<div class="inside">BODY</div></details>' ), 'the body sits inside the disclosure, which closes as a </details>' );
ok( false === strpos( $html, '<button' ) && false === strpos( $html, 'aria-expanded' ) && false === strpos( $html, 'sn-an-collapsed' ), 'no hand-rolled toggle: no extra button, no aria-expanded to keep in sync, no collapsed class' );
$html = capture( function () {
	snt_an_panel_open( 'Open one', array( 'collapsible' => true ) );
	snt_an_panel_close();
} );
ok( false !== strpos( $html, '<details class="postbox sn-an-postbox" data-sn-an-collapsible="open-one" open>' ), 'collapsible defaults to open' );

echo "\nTest: a plain panel after a collapsible one still closes as a div\n";
$html = capture( function () {
	snt_an_panel_open( 'Folded', array( 'collapsible' => true, 'collapsed' => true ) );
	snt_an_panel_open( 'Nested plain' );
	snt_an_panel_close();
	snt_an_panel_close();
	snt_an_panel_open( 'After' );
	snt_an_panel_close();
} );
ok( substr_count( $html, '</details>' ) === 1 && substr_count( $html, '<details' ) === 1, 'one disclosure opened, one closed' );
ok( false !== strpos( $html, '<div class="inside"></div></div></div></details>' ), 'the nested plain panel closes as a div, then the disclosure closes as a details' );
ok( '</div></div>' === substr( $html, -12 ) && false !== strpos( $html, '<div class="postbox sn-an-postbox"><div class="postbox-header"><h2 class="hndle"><span>After</span></h2></div>' ), 'a plain panel opened after the disclosure is the unchanged div shell' );

echo "\nTest: collapsed without collapsible is ignored\n";
$html = capture( function () {
	snt_an_panel_open( 'Static', array( 'collapsed' => true ) );
	snt_an_panel_close();
} );
ok( false === strpos( $html, '<details' ) && false === strpos( $html, '<summary' ), 'collapsed only applies to collapsible panels' );
ok( '<div class="postbox sn-an-postbox"><div class="postbox-header"><h2 class="hndle"><span>Static</span></h2></div><div class="inside"></div></div>' === $html, 'a plain panel is byte-identical to the shell every other panel prints' );

echo "\nTest: XSS — title and classes escape\n";
$html = capture( function () {
	snt_an_panel_open( '<script>x</script>', array( 'panel_class' => '"onmouseover="x' ) );
	snt_an_panel_close();
} );
ok( false === strpos( $html, '<script>' ), 'title is escaped' );
ok( false === strpos( $html, 'onmouseover="x' ), 'attr injection escaped' );

echo "\nTest: row clamp wrapper\n";
$html = capture( function () {
	snt_an_clamp_open( 25, 5 );
	echo '<table class="widefat"></table>';
	snt_an_clamp_close( 25, 5 );
} );
ok( false !== strpos( $html, '<div class="sn-an-clamp sn-an-clamp--5"' ), 'clamp wrapper carries the visible-rows class' );
ok( false !== strpos( $html, 'data-sn-an-total="25"' ), 'total row count on the wrapper' );
ok( false !== strpos( $html, 'class="sn-an-viewall"' ) && false !== strpos( $html, 'View all 25' ), 'view-all button with the real total' );
$html = capture( function () {
	snt_an_clamp_open( 3, 5 );
	snt_an_clamp_close( 3, 5 );
} );
ok( false === strpos( $html, 'sn-an-viewall' ), 'no view-all button when rows fit the clamp' );
ok( false !== strpos( $html, 'sn-an-clamp--5' ), 'wrapper still emitted (stable layout hook)' );

// ── snt_an_annotation: draw-on-content interpretation callout ──
echo "\nannotation renderer (draw-on-content)\n";
ok( '' === capture( function () { snt_an_annotation( null ); } ), 'null renders nothing' );
ok( '' === capture( function () { snt_an_annotation( '' ); } ), 'empty string renders nothing' );
ok( '' === capture( function () { snt_an_annotation( '   ' ); } ), 'whitespace-only renders nothing' );
$note = capture( function () { snt_an_annotation( 'Movement skews down.' ); } );
ok( false !== strpos( $note, 'sn-an-note' ), 'callout carries the .sn-an-note class' );
ok( false !== strpos( $note, 'Movement skews down.' ), 'the sentence appears in the output' );
$xss = capture( function () { snt_an_annotation( 'a <b>bold</b> & <script>x</script>' ); } );
ok( false === strpos( $xss, '<b>' ) && false === strpos( $xss, '<script>' ), 'sentence is esc_html-escaped (no raw tags survive)' );

echo "\nTest: v9.35.0 — shared maturity tier badge (I6)\n";
ok( '<span class="sn-an-tier sn-an-tier--predictive">Predictive</span>' === snt_analytics_tier_badge( 'predictive' ), 'badge: renders the tier label inside the shared class pair' );
ok( '' !== snt_analytics_tier_badge( 'descriptive' ) && '' !== snt_analytics_tier_badge( 'diagnostic' ) && '' !== snt_analytics_tier_badge( 'prescriptive' ), 'badge: all four tiers whitelisted' );
ok( '' === snt_analytics_tier_badge( 'quantum' ) && '' === snt_analytics_tier_badge( '' ), 'badge: unknown tier renders nothing (never guess a tier)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

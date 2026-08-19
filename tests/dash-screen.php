<?php
/**
 * Tests: the full Dashboard screen (v11.30.0, "The quiet instrument").
 *
 * The design is derived from Few's monitoring rules and Google's SRE dashboard
 * practice, and every rule below is pinned as a property because each one
 * condemns something a previous build shipped:
 *
 *   Single screen, no scroll ....... sized to FIT, not stretched to FILL
 *   Monochrome first ............... colour ONLY on what needs attention
 *   Data-pixel ratio ............... hairlines and whitespace, not drawn boxes
 *   Context over isolation ......... every signal carries a comparison
 *   One page, one decision ......... the verdict leads, detail follows
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
foreach ( array( 'esc_html', 'esc_attr', 'esc_html__', 'esc_attr__' ) as $f ) {
	if ( ! function_exists( $f ) ) { eval( "function $f(\$s,\$d=''){ return htmlspecialchars((string)\$s, ENT_QUOTES); }" ); }
}
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a, $n = '_wpnonce', $r = true ) { echo '<input type="hidden">'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return '/wp-admin/' . $p; } }
require __DIR__ . '/../inc/admin-glance.php';
require __DIR__ . '/../inc/dash-verdict.php';
require __DIR__ . '/../inc/dash-signals.php';
require __DIR__ . '/../inc/dash-systems.php';
require __DIR__ . '/../inc/dash-ops-panels.php';
require __DIR__ . '/../inc/dash-trend.php';
require __DIR__ . '/../inc/dash-ops-render.php';
require __DIR__ . '/../inc/dash-console.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "the dashboard screen\n\n";

function card( $l, $k, $v = '', $a = null ) {
	$c = array( 'label' => $l, 'value' => $v, 'pill' => array( 'kind' => $k, 'text' => $k ) );
	if ( null !== $a ) { $c['attention'] = $a; }
	return $c;
}
$healthy_cards = array( card( 'Health', 'ok', '0 findings' ), card( 'Cron', 'ok', '61 events' ), card( 'Caches', 'ok', '3/3 fresh' ) );
$components    = array( card( 'Theme', 'ok', '11.10.0' ), card( 'Remote MCP', 'warn', 'warming', false ) );
$signals = array(
	array( 'label' => 'Views · 7d', 'value' => '103', 'compare' => '+39 on prior 7d', 'dir' => 'up' ),
	array( 'label' => 'Citations',  'value' => '0',   'compare' => 'none yet · 0 prior', 'measured' => false ),
);
function screen( $cards, $components, $signals, $opts = array() ) {
	ob_start(); sn_dash_render_screen( $cards, $components, $signals, $opts ); return ob_get_clean();
}
$panels = sn_dash_ops_panels( array( 'deploys' => array( array( 'repo' => 'x/y-tools', 'ref' => 'main', 'conclusion' => 'success' ) ) ) );
$h = screen( $healthy_cards, $components, $signals, array( 'series' => array(), 'panels' => $panels ) );

// ── ONE PAGE, ONE DECISION: the verdict leads everything ───────────────────
ok( false !== strpos( $h, 'sn-scr__verdict' ), 'the verdict renders' );
ok( false !== strpos( $h, 'Everything is holding' ), 'and states the healthy case plainly' );
$p_v = strpos( $h, 'sn-scr__verdict' );
foreach ( array( 'sn-scr__signals', 'sn-scr__systems', 'sn-scr__detail' ) as $later ) {
	$p_l = strpos( $h, $later );
	ok( false !== $p_l && $p_v < $p_l, "THE VERDICT PRECEDES $later — the answer arrives before the evidence" );
}

// ── MONOCHROME FIRST. A healthy screen carries no state colour at all. ─────
ok( false === strpos( $h, 'sn-scr--warn' ) && false === strpos( $h, 'sn-scr--err' ),
	'A HEALTHY SCREEN IS GREY — no state class anywhere, so colour keeps its meaning for the day it appears' );
ok( false === strpos( $h, 'sn-sys--warn' ),
	'AND A WARMING COMPONENT DOES NOT TINT A CELL — cold is not broken (v11.16.0)' );

$bad = screen(
	array( card( 'Caches', 'warn', '1 of 3 zones stale' ), card( 'Cron', 'err', 'no scheduled events' ) ),
	$components, $signals, array( 'series' => array(), 'panels' => array() )
);
ok( false !== strpos( $bad, 'sn-scr--err' ), 'trouble puts a state on the screen' );
ok( false !== strpos( $bad, 'sn-scr__exceptions' ), 'and opens the exception band' );
ok( false !== strpos( $bad, '1 of 3 zones stale' ), 'which names what is wrong, not just that something is' );
ok( substr_count( $bad, 'sn-scr__ex ' ) === 2, 'ONE ROW PER EXCEPTION — the band is the verdict list, not a second tally' );

// ── CONTEXT OVER ISOLATION. A bare number cannot be judged. ────────────────
ok( false !== strpos( $h, '+39 on prior 7d' ), 'a signal renders its comparison' );
ok( 2 === substr_count( $h, 'class="sn-sig__compare' ), 'EVERY SIGNAL CARRIES ONE — a number with nothing to judge it against is decoration' );
$nocompare = screen( $healthy_cards, $components, array( array( 'label' => 'Clicks', 'value' => '5' ) ), array() );
ok( false !== strpos( $nocompare, 'sn-sig__compare' ),
	'AND A SIGNAL WITH NO COMPARISON STILL RENDERS THE SLOT — saying so, rather than silently dropping to a bare number' );

// ── absent is not zero, still ──────────────────────────────────────────────
ok( false !== strpos( $h, 'sn-sig--unmeasured' ), 'an unmeasured signal is marked, so a dim 0 never reads as data' );

// ── DATA-PIXEL RATIO: the wall is hairlines, not ten drawn boxes ───────────
ok( false === strpos( $h, 'sn-ops__panel' ),
	'THE BOXED OPS PANELS ARE GONE — the detail columns group with rules and whitespace instead' );

// ── AN ASYNC CARD MUST SPEAK THE FILLER'S LANGUAGE ──────────────────────────
// v11.30.2. Carrying the id (v11.30.1) was necessary and not sufficient:
// assets/freshness-dot.js finds the card by id, then replaces the text inside
// `.sn-glance-card__value` and reuses `.sn-pill`. The systems cell had neither,
// so the JS left "Checking…" in place and APPENDED its verdict pill underneath —
// the card ended up showing a stale placeholder and a fresh answer at once.
echo "\nGroup: async cells carry the contract their filler reads\n";
$async = array( 'label' => 'Caches', 'value' => 'Checking…', 'id' => 'snt-freshness-card', 'pill' => array( 'kind' => 'ok' ) );
ob_start(); sn_dash_render_system_cell( $async ); $cell = ob_get_clean();
ok( false !== strpos( $cell, 'id="snt-freshness-card"' ), 'the cell keeps its id' );
ok( false !== strpos( $cell, 'sn-glance-card__value' ),
	'AND THE VALUE CARRIES THE CLASS THE FILLER REPLACES — without it the placeholder is permanent' );

$plain = array( 'label' => 'Health', 'value' => '0 findings', 'pill' => array( 'kind' => 'ok' ) );
ob_start(); sn_dash_render_system_cell( $plain ); $plain_cell = ob_get_clean();
ok( false === strpos( $plain_cell, 'sn-glance-card__value' ),
	'a card with no id is not async and does not carry the hook — the coupling is declared, not sprayed' );

// ── SURFACE: FOUR CARDS, NOT TEN, AND NOT ZERO ──────────────────────────────
// v11.30.3. v11.30.0 removed every drawn box on Few's data-pixel argument and
// landed on a page that reads as a wireframe. The project's own standard —
// "generous spacing, shadows on cards, consistent type scale" — takes precedence
// over my reading of a book, and the two are reconcilable: the mistake was ten
// boxes competing at figure level, not surface as such.
//
// One card per REGION. Cards group; hairlines separate WITHIN a card. That is
// still Few's argument (grouping is what the pixels must earn) applied at the
// altitude where grouping actually happens.
echo "\nGroup: CSS — the screen has surface\n";
// Comments stripped before asserting: a rule whose comment explains a removed
// declaration still contains that declaration's text.
function block_for( $css, $sel ) {
	$i = strpos( $css, $sel );
	if ( false === $i ) {
		return '';
	}
	$block = substr( $css, $i, strpos( $css, '}', $i ) - $i );
	return (string) preg_replace( '#/\*.*?\*/#s', '', $block );
}
$css2 = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );
foreach ( array( '.sn-scr__signals', '.sn-stage__trend', '.sn-scr__systems', '.sn-scr__detail' ) as $sel ) {
	$blk = block_for( $css2, $sel . ' {' );
	ok( '' !== $blk, $sel . ' rule present' );
	ok( false !== strpos( $blk, 'box-shadow' ), $sel . ': CARRIES A SHADOW — the project standard is shadows on cards' );
	ok( false !== strpos( $blk, 'border-radius' ), $sel . ': and a radius, so it reads as a surface rather than a rule' );
}

// ── THE PAGE READS AS AN INSTRUMENT, NOT A REPORT ───────────────────────────
// v11.31.0. Each region gets a header band — a mono eyebrow plus, where the
// region has one, a reading. Without it the cards were unlabelled rectangles
// and the reader had to infer what each one was from its contents.
//
// The chart earns the most: a 30-day plot with no axis and no values is a
// decoration of a number nobody can read off it.
echo "\nGroup: the instrument face\n";
$plot = array();
foreach ( array( 10, 40, 22, 187, 60, 129 ) as $k => $v ) {
	$plot[] = array( 'day' => '2026-07-0' . ( $k + 1 ), 'views' => $v );
}
ob_start(); sn_dash_render_trend( $plot ); $t = ob_get_clean();

ok( false !== strpos( $t, 'sn-card__head' ), 'the trend card carries a header band' );
ok( false !== strpos( $t, 'sn-card__eyebrow' ), 'with a mono eyebrow naming the region' );
ok( false !== strpos( $t, '187' ),
	'THE PEAK IS READABLE AS A NUMBER — a plot whose maximum you cannot read is a decoration' );
ok( false !== strpos( $t, '129' ), 'and so is the latest value' );
ok( false !== strpos( $t, 'sn-trend__axis' ), 'the plot is bounded by an axis, so the window is stated rather than assumed' );

// Labels are HTML, never SVG text: the plot stretches with
// preserveAspectRatio="none", which would distort any glyph inside it.
$svg_inner = substr( $t, strpos( $t, '<svg' ), strpos( $t, '</svg>' ) - strpos( $t, '<svg' ) );
ok( false === strpos( $svg_inner, '<text' ),
	'NO TEXT INSIDE THE STRETCHED SVG — preserveAspectRatio="none" would distort every glyph' );

// Region headers on the other two cards.
ob_start(); sn_dash_render_systems( $healthy_cards, $components ); $sysout = ob_get_clean();
ok( false !== strpos( $sysout, 'sn-card__eyebrow' ), 'the systems card is labelled too' );
ob_start(); sn_dash_render_ops( $panels ); $opsout = ob_get_clean();
ok( false !== strpos( $opsout, 'sn-card__eyebrow' ), 'and so is the detail card' );

// ── THE CELL RENDERS WHAT THE CARD ACTUALLY CARRIES ─────────────────────────
// v11.31.0. Glance cards carry `meta_html` — a pre-escaped detail line built by
// the source (snt_freshness_report_meta() writes the "last purge" summary into
// it). The systems cell dropped it, so a fact the plugin had already computed
// never reached the screen. Rendering data you already hold costs nothing and
// is the cheapest density there is.
echo "\nGroup: cells carry their meta line\n";
ob_start(); sn_dash_render_system_cell( array(
	'label' => 'Caches', 'value' => '3 / 3 fresh', 'pill' => array( 'kind' => 'ok' ),
	'meta_html' => '<em>last purge 4m ago</em>',
) ); $withmeta = ob_get_clean();
ok( false !== strpos( $withmeta, 'sn-sys__meta' ), 'a card with meta_html renders a meta line' );
ok( false !== strpos( $withmeta, 'last purge 4m ago' ), 'carrying the source-built detail' );
ok( false !== strpos( $withmeta, '<em>' ),
	'AS HTML, NOT ESCAPED TEXT — the source escapes at build (snt_freshness_report_meta), and re-escaping would print the tags' );

ob_start(); sn_dash_render_system_cell( array( 'label' => 'Health', 'value' => '0 findings', 'pill' => array( 'kind' => 'ok' ) ) ); $nometa = ob_get_clean();
ok( false === strpos( $nometa, 'sn-sys__meta' ), 'a card without one renders no empty line' );

// A non-ok state names itself: "warming" is more use than an amber tint alone.
ob_start(); sn_dash_render_system_cell( array( 'label' => 'Remote MCP', 'value' => '0.4.0', 'pill' => array( 'kind' => 'warn', 'text' => 'warming' ), 'attention' => false ) ); $cold = ob_get_clean();
ok( false !== strpos( $cold, 'warming' ), 'a cold probe SAYS "warming" rather than relying on a tint that is not even applied to it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

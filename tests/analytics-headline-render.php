<?php
/**
 * Headline band tests (v9.37.0 dashboard D1): lead-sentence extraction,
 * chip glyph a11y, and the collapsed <details class="sn-an-headline"> that
 * replaces the full Insights band.
 * Run: php tests/analytics-headline-render.php
 * @since plugin v9.37.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }

// Stubs: signal engine + narrator (recorders/fixtures) — MUST precede the require.
$GLOBALS['__sig'] = array();
function sn_analytics_signals( $from, $to, $class = 'human', $opts = array() ) { return $GLOBALS['__sig']; }
$GLOBALS['__digest'] = array( 'digest' => '', 'source' => 'fallback' );
function sn_analytics_digest( $summary, $signals ) { return $GLOBALS['__digest']; }
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return array( 'views' => 1204, 'visits' => 389 ); }

require __DIR__ . '/../inc/analytics-insights.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function band( $sig, $digest ) {
	$GLOBALS['__sig']    = $sig;
	$GLOBALS['__digest'] = $digest;
	ob_start();
	snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' );
	return (string) ob_get_clean();
}

$sig  = array( 'kind' => 'anomaly', 'tier' => 'predictive', 'direction' => 'up', 'confidence' => 'high', 'plain_label' => 'Views ran above the 30-day norm' );
$sig2 = array( 'kind' => 'trend', 'tier' => 'predictive', 'direction' => 'down', 'confidence' => 'medium', 'plain_label' => 'Read time drifting down' );

echo "Group: lead extraction (prototyped in scratch, pinned here)\n";
ok( 'Views rose 42% week-over-week.' === snt_analytics_headline_lead( 'Views rose 42% week-over-week. Visits followed. Bots flat.' ), 'lead: first sentence of a multi-sentence narrative' );
ok( 'Traffic steady with no notable movement this week' === snt_analytics_headline_lead( 'Traffic steady with no notable movement this week' ), 'lead: no terminator returns the whole (short) string' );
ok( 'Big spike!' === snt_analytics_headline_lead( 'Big spike! Investigate the /now page.' ), 'lead: ! is a terminator' );
ok( '' === snt_analytics_headline_lead( '' ), 'lead: empty in, empty out' );
ok( 'Views rose.' === snt_analytics_headline_lead( '<p>Views rose. More detail.</p>' ), 'lead: HTML narrative is tag-stripped before extraction' );
$long = str_repeat( 'word ', 40 ) . 'end.';
$clamped = snt_analytics_headline_lead( $long );
ok( mb_strwidth( $clamped, 'UTF-8' ) <= 140 && '…' === mb_substr( $clamped, -1, 1, 'UTF-8' ), 'lead: >140-char sentence clamps to <=140 display width ending in the ellipsis' );
ok( 'Tráfico aumentó 42% — señal clara.' === snt_analytics_headline_lead( 'Tráfico aumentó 42% — señal clara. Más contexto aquí.' ), 'lead: mb-safe on multibyte narrative' );

echo "\nGroup: chip glyph a11y (D1 §6)\n";
$chip = snt_analytics_render_signal_chip( $sig );
ok( false !== strpos( $chip, '<span class="sn-an-signal-dir" aria-hidden="true">' ), 'chip: direction glyph wrapped aria-hidden (plain-text label carries the meaning)' );
ok( false !== strpos( $chip, 'Views ran above' ), 'chip: plain_label still present' );

echo "\nGroup: headline band — collapsed summary\n";
$h = band( array( $sig, $sig2 ), array( 'digest' => '<p>Views rose 42% this week. Second sentence with detail.</p>', 'source' => 'ai' ) );
ok( false !== strpos( $h, '<details class="sn-an-headline">' ), 'band: native details wrapper (WAI-APG disclosure for free)' );
ok( false === strpos( $h, ' open>' ) && false === strpos( $h, ' open ' ), 'band: collapsed by default (no open attribute)' );
$sum = substr( $h, 0, (int) strpos( $h, '</summary>' ) );
ok( false !== strpos( $sum, 'Views ran above' ), 'summary: chip 0 (top severity-sorted signal) present' );
ok( false !== strpos( $sum, 'Views rose 42% this week.' ) && false === strpos( $sum, 'Second sentence' ), 'summary: lead sentence only' );
ok( false !== strpos( $sum, 'Full insights (2 signals)' ), 'summary: static indicator with the signal count' );
ok( false === strpos( $sum, 'Read time drifting down' ), 'summary: chip 1 NOT in the summary' );

echo "\nGroup: headline band — expanded body\n";
$body = substr( $h, (int) strpos( $h, '</summary>' ) );
ok( false !== strpos( $body, 'Second sentence with detail' ) && false !== strpos( $body, 'data-source="ai"' ), 'expanded: full narrative + data-source preserved' );
ok( false !== strpos( $body, 'Read time drifting down' ), 'expanded: remaining chips present' );
ok( false === strpos( $body, 'Views ran above' ), 'expanded: chip 0 never repeats' );
ok( false !== strpos( $body, 'sn-an-methods-note' ) && false !== strpos( $body, 'median/MAD' ), 'expanded: methods note is the footer' );

echo "\nGroup: headline band — badge diet + old band retired\n";
ok( false === strpos( $h, 'sn-an-insights-head' ) && false === strpos( $h, 'sn-an-tier-badges' ), 'band head badge pair removed (chips alone carry tiers)' );
ok( false === strpos( $h, '<div class="sn-an-insights">' ), 'old .sn-an-insights wrapper gone' );

echo "\nGroup: headline band — empty states (D1 §9)\n";
$e = band( array(), array( 'digest' => '', 'source' => 'fallback' ) );
ok( false !== strpos( $e, 'needs ~2 weeks' ), 'empty: honest fallback note renders as the summary text' );
ok( false === strpos( $e, 'Full insights' ), 'empty: indicator hidden when N=0' );
ok( false === strpos( $e, 'sn-an-signal ' ) && false === strpos( $e, 'sn-an-signal"' ), 'empty: no chip in the summary' );
$one = band( array( $sig ), array( 'digest' => '<p>One thing happened.</p>', 'source' => 'ai' ) );
ok( false !== strpos( $one, 'Full insights (1 signal)' ), 'singular indicator for N=1' );
ok( false === strpos( $one, 'sn-an-signal-chips' ), 'N=1: no empty remaining-chips row in the body' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

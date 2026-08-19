<?php
/**
 * Tests: the signals builder — the five figures, each with something to be
 * judged against.
 *
 * CONTEXT OVER ISOLATION (Few). v11.29.x rendered five bare numbers, of which
 * exactly one carried a delta. "$0.61" and "33" and "0" cannot be read: they
 * are only good or bad relative to last month, to the total, and to zero-so-far.
 *
 * The honest half of this rule matters as much as the comparison itself: where
 * no prior period exists, the builder SAYS there is none. It never fabricates a
 * baseline, and it never silently drops the slot — a gap in the instrument is a
 * fact about the instrument.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
require __DIR__ . '/../inc/dash-signals.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function sig( $sigs, $needle ) {
	foreach ( $sigs as $s ) { if ( false !== stripos( $s['label'], $needle ) ) { return $s; } }
	return null;
}
echo "the signals builder\n\n";

$s = sn_dash_signals_from_measurement( array(
	'views_7d' => 103, 'views_delta' => 39,
	'search_clicks' => 5, 'search_clicks_days' => 28,
	'ai_spend_30d' => 0.61, 'anchored' => 33, 'citations' => 0,
) );

ok( 5 === count( $s ), 'five signals' );
ok( null !== sig( $s, 'views' ) && null !== sig( $s, 'clicks' ) && null !== sig( $s, 'anchored' ) && null !== sig( $s, 'citations' ),
	'the five are views, clicks, AI spend, anchored and citations' );

// ── EVERY signal carries a comparison slot ─────────────────────────────────
foreach ( $s as $one ) {
	ok( array_key_exists( 'compare', $one ), $one['label'] . ': carries a comparison slot' );
}

$v = sig( $s, 'views' );
ok( false !== strpos( $v['compare'], '39' ), 'views compares against the prior period with a real number' );
ok( 'up' === $v['dir'], 'and states the direction, so the colour is derived rather than guessed' );

// ── the window is DERIVED, never assumed ───────────────────────────────────
$c = sig( $s, 'clicks' );
ok( false !== strpos( $c['label'], '28' ),
	'THE CLICKS LABEL NAMES ITS REAL WINDOW — Search Console sums 28 days by default, and calling that "7d" would overstate a week by a month' );
$c2 = sig( sn_dash_signals_from_measurement( array( 'search_clicks' => 5 ) ), 'clicks' );
ok( false === strpos( $c2['label'], '0' ), 'an unknown window says "clicks", never "clicks 0d"' );

// ── ABSENT IS NOT ZERO ─────────────────────────────────────────────────────
$none = sn_dash_signals_from_measurement( array() );
ok( 5 === count( $none ), 'a measurement with nothing in it still renders five signals, not fewer' );
foreach ( $none as $one ) {
	ok( false === $one['measured'], $one['label'] . ': is marked unmeasured rather than shown as 0' );
}
$zero = sn_dash_signals_from_measurement( array( 'citations' => 0 ) );
ok( true === sig( $zero, 'citations' )['measured'],
	'A MEASURED ZERO IS MEASURED — nobody has cited you yet is a real finding, and dimming it to "—" would delete it' );

// ── never invent a baseline ────────────────────────────────────────────────
$novd = sn_dash_signals_from_measurement( array( 'views_7d' => 103 ) );
ok( '' === sig( $novd, 'views' )['compare'],
	'NO DELTA MEANS AN EMPTY COMPARISON, NOT A FABRICATED ZERO — the renderer says "no prior period"; the builder must not say "+0"' );
ok( '' === sig( $novd, 'views' )['dir'], 'and no direction, so nothing is coloured on invented evidence' );

// ── CONTEXT IS NOT ONLY A PRIOR PERIOD ──────────────────────────────────────
// v11.30.1. The first cut read Few's "context over isolation" as "compare to
// last week", so four of five signals rendered the literal words "no prior
// period" — five identical strings stacked across the page, which is worse than
// the bare numbers it replaced. A denominator is context. A companion metric is
// context. Only where NOTHING is available does the slot say so.
echo "\nGroup: every signal finds real context\n";
$rich = sn_dash_signals_from_measurement( array(
	'views_7d' => 128, 'views_delta' => 39, 'views_prior' => 89,
	'search_clicks' => 5, 'search_clicks_days' => 28, 'search_impressions' => 1240,
	'ai_spend_30d' => 0.61, 'ai_calls_30d' => 214,
	'anchored' => 33, 'anchored_total' => 33,
	'citations' => 0,
) );
foreach ( $rich as $one ) {
	ok( '' !== $one['compare'], $one['label'] . ': HAS REAL CONTEXT, not a placeholder' );
	ok( false === stripos( $one['compare'], 'no prior period' ), $one['label'] . ': and does not fall back to the placeholder' );
}
ok( false !== strpos( sig( $rich, 'views' )['compare'], '89' ), 'views names the prior period it beat' );
ok( false !== strpos( sig( $rich, 'clicks' )['compare'], '1,240' ), 'CLICKS IS READ AGAINST IMPRESSIONS — 5 of 1,240 is a rate; 5 alone is not' );
ok( false !== strpos( sig( $rich, 'AI' )['compare'], '214' ), 'spend is read across its call count' );
ok( false !== strpos( sig( $rich, 'anchored' )['compare'], '33' ), 'anchored carries its denominator' );
ok( '' !== sig( $rich, 'citations' )['compare'], 'citations says something rather than nothing' );

// The capped GSC window must SAY it is a floor, per v11.30.0.
$capped = sn_dash_signals_from_measurement( array( 'search_clicks' => 5, 'search_clicks_days' => 28, 'search_clicks_capped' => true ) );
ok( false !== strpos( sig( $capped, 'clicks' )['value'], '+' ),
	'A CAPPED WINDOW RENDERS "5+" — the 250-page cap makes the sum a floor, and a floor shown as an exact number is a lie with a decimal point' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

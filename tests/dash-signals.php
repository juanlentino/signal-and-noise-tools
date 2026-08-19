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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

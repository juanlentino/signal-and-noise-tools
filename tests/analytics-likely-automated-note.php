<?php
/**
 * Tests: the Analytics header's human-rule note carries likely_automated
 * beside the presumed-human totals (native and classic share it:
 * inc/analytics-header-region.php paints it on both).
 *
 * Run: php tests/analytics-likely-automated-note.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function sn_session_rollup_read( $from, $to, $class ) { return array(); }

require __DIR__ . '/../inc/analytics-bot-signals-by-day.php';
require __DIR__ . '/../inc/analytics-human-rule.php';
require __DIR__ . '/../inc/analytics-render-overview.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
$note = static function ( $rule ) {
	ob_start();
	snt_analytics_render_human_rule_note( $rule );
	return (string) ob_get_clean();
};

$GLOBALS['__opt'][ SNT_BOT_SIGNAL_BY_DAY_OPTION ] = array(
	'2026-10-09' => array( 'visitor_days' => 12, 'likely_automated' => 2 ),
	'2026-10-10' => array( 'visitor_days' => 8, 'likely_automated' => 1 ),
);
$r = sn_analytics_human_rule_reading( '2026-10-04', '2026-10-10', 'human' );
ok( array( 'visitor_days' => 3, 'scored_visitor_days' => 20, 'measured_days' => 2, 'unmeasured_days' => 5, 'basis' => 'inferred', 'subtracted' => false ) === ( $r['likely_automated'] ?? null ), 'the reading carries likely_automated for the range' );
$h = $note( $r );
ok( false !== strpos( $h, 'Presumed human (not flagged)' ), 'the note names the class presumed human (not flagged)' );
ok( false === strpos( $h, 'Likely automated' ), 'the figure is a tile beside the totals now, not a sentence in the note' );
$cards = static function ( $totals ) {
	ob_start();
	snt_analytics_render_cards( null, $totals + array( 'views' => 75, 'pageview_visits' => 62 ) );
	return (string) ob_get_clean();
};
$c = $cards( array( 'likely_automated' => $r['likely_automated'] ) );
ok( false !== strpos( $c, 'Likely automated (inferred)' ) && 1 === preg_match( '/Likely automated \(inferred\).{0,200}>3</s', $c ), 'classic: a Likely automated tile beside the totals, value 3' );
ok( false !== strpos( $c, 'of 20 presumed-human visitor-days, never subtracted; measured 2 of 7 days' ), 'with its denominator, never subtracted, and the unmeasured days said' );

$GLOBALS['__opt'] = array();
$none = sn_analytics_human_rule_reading( '2026-10-04', '2026-10-10', 'human' );
$c    = $cards( array( 'likely_automated' => $none['likely_automated'] ) );
ok( false !== strpos( $c, '>not measured<' ) && false !== strpos( $c, 'measured 0 of 7 days' ), 'no day measured reads not measured, never 0' );

$r = sn_analytics_human_rule_reading( '2026-10-04', '2026-10-10', 'bot' );
ok( null === $r['likely_automated'] && false === strpos( $cards( array( 'likely_automated' => null ) ), 'Likely automated' ), 'the bot class carries no likely-automated tile' );
$native = (string) file_get_contents( __DIR__ . '/../apps/sn-analytics/parts/painters/chrome-header.php' );
ok( false !== strpos( $native, '\\snt_ev_paint_likely_automated( \\sn_analytics_likely_automated( $from, $to, $class ), \'kit\' )' ) && false !== strpos( $native, 'Presumed human (not flagged)' ), 'native: the Overview paints the same tile and says presumed human (not flagged)' );

echo "\nResult: {$pass} passed, {$fail} failed.\n";
exit( $fail > 0 ? 1 : 0 );

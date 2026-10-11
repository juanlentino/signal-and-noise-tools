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
ok( false !== strpos( $h, 'Likely automated (inferred, never subtracted): 3 of 20 presumed-human visitor-days' ), 'likely automated beside the totals, with its basis and never subtracted: ' . strip_tags( $h ) );
ok( false !== strpos( $h, '5 of 7 days not measured' ), 'unmeasured days are said, not shown as zero' );

$GLOBALS['__opt'] = array();
$h = $note( sn_analytics_human_rule_reading( '2026-10-04', '2026-10-10', 'human' ) );
ok( false !== strpos( $h, 'Likely automated (inferred, never subtracted): not measured for this range (7 of 7 days not measured)' ) && false === strpos( $h, ': 0 of' ), 'no day measured reads not measured, never 0' );

$r = sn_analytics_human_rule_reading( '2026-10-04', '2026-10-10', 'bot' );
ok( null === $r['likely_automated'] && false === strpos( $note( $r ), 'Likely automated' ), 'the bot class carries no likely-automated line' );

echo "\nResult: {$pass} passed, {$fail} failed.\n";
exit( $fail > 0 ? 1 : 0 );

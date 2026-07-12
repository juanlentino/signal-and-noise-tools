<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }

// Stubs: signal engine + narrator (recorders/fixtures).
$GLOBALS['__sig'] = array();
function sn_analytics_signals( $from, $to, $class = 'human', $opts = array() ) { return $GLOBALS['__sig']; }
$GLOBALS['__narr'] = array( 'narrative' => '', 'source' => 'fallback' );
function sn_analytics_narrate( $summary, $signals ) { return $GLOBALS['__narr']; }

require __DIR__ . '/../inc/analytics-insights.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$sig = array(
	'kind' => 'anomaly', 'tier' => 'predictive', 'direction' => 'up',
	'confidence' => 'high', 'plain_label' => 'Views ran above the 30-day norm on 2026-06-20',
);
echo "Group: signal chip\n";
$chip = snt_analytics_render_signal_chip( $sig );
ok( false !== strpos( $chip, 'Predictive' ) && false !== strpos( $chip, 'high' ), 'chip: badges tier + confidence' );
ok( false !== strpos( $chip, 'Views ran above' ), 'chip: shows the plain_label' );

echo "\nGroup: insights band\n";
$GLOBALS['__sig'] = array( $sig );
$GLOBALS['__narr'] = array( 'narrative' => '<p>Spike on the 20th; consider why.</p>', 'source' => 'ai' );
ob_start(); snt_analytics_render_insights_band( '2026-06-14', '2026-06-20', 'human', 'day' ); $h = ob_get_clean();
ok( false !== strpos( $h, 'sn-an-insights' ) && false !== strpos( $h, 'Spike on the 20th' ), 'band: renders the narrative' );
ok( false !== strpos( $h, 'sn-an-signal' ) && false !== strpos( $h, 'data-source="ai"' ), 'band: renders badged chips + marks the source' );
$GLOBALS['__sig'] = array();
$GLOBALS['__narr'] = array( 'narrative' => '', 'source' => 'fallback' );
ob_start(); snt_analytics_render_insights_band( '2026-06-14', '2026-06-20', 'human', 'day' ); $e = ob_get_clean();
ok( false !== strpos( $e, 'needs ~2 weeks' ), 'band: honest empty-state when no signals/narrative' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

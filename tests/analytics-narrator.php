<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

// (near the top, before require) — mockable AI wrapper.
$GLOBALS['__ai_return'] = null;
function snt_ai_generate_with_constraints( $prompt, $system, $max = 256, $feature = 'generic' ) {
	$GLOBALS['__ai_prompt'] = $prompt; $GLOBALS['__ai_system'] = $system;
	return $GLOBALS['__ai_return'];
}

require __DIR__ . '/../inc/analytics-narrator.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$signals = array(
	array( 'kind' => 'anomaly', 'confidence' => 'high', 'plain_label' => 'Views ran above the 30-day norm on 2026-06-20 (4.2σ-robust)' ),
	array( 'kind' => 'trajectory', 'confidence' => 'medium', 'plain_label' => '/notes/x is decaying (-38% over the window)' ),
);
echo "Group: deterministic fallback\n";
$html = sn_analytics_narrate_fallback( array(), $signals );
ok( false !== strpos( $html, 'Views ran above' ) && false !== strpos( $html, 'decaying' ), 'fallback: composes the signal plain_labels' );
ok( false !== strpos( sn_analytics_narrate_fallback( array(), array() ), 'nothing needs attention' ), 'fallback: graceful empty when no signals' );

// (before Result:) — the seam.
echo "\nGroup: narrate() seam\n";
$GLOBALS['__ai_return'] = 'Views spiked on the 20th; /notes/x is fading — refresh it.';
$r = sn_analytics_narrate( array(), $signals );
ok( 'ai' === $r['source'] && false !== strpos( $r['narrative'], 'refresh it' ), 'narrate: uses AI when it returns text' );
ok( false !== strpos( (string) $GLOBALS['__ai_system'], 'NEVER invent' ) && false !== strpos( (string) $GLOBALS['__ai_prompt'], 'decaying' ), 'narrate: prompt carries signals + honest-uncertainty instruction' );
$GLOBALS['__ai_return'] = '';
$r2 = sn_analytics_narrate( array(), $signals );
ok( 'fallback' === $r2['source'] && false !== strpos( $r2['narrative'], 'decaying' ), 'narrate: falls back to deterministic when AI returns empty' );
$GLOBALS['__ai_return'] = null;
$r3 = sn_analytics_narrate( array(), array() );
ok( 'fallback' === $r3['source'], 'narrate: no signals → fallback empty-state' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

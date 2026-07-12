<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

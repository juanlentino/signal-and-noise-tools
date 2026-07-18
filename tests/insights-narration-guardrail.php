<?php
/**
 * Guards the cookieless narrator instruction. Run: php tests/insights-narration-guardrail.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$src = file_get_contents( __DIR__ . '/../inc/insights-narration.php' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nGroup: narrator cookieless guardrail\n";
// Case-insensitive: the guardrail text uses "WITHIN-DAY" (uppercase) for emphasis.
ok( false !== stripos( $src, 'within-day' ), 'permits within-day framing explicitly' );
ok( false !== stripos( $src, 'never a cross-day identity' ) || false !== stripos( $src, 'across days' ), 'still forbids cross-day identity' );
ok( false !== stripos( $src, 'new-vs-returning' ), 'still forbids new-vs-returning' );

echo "\nGroup: honest vocabulary (v9.64.1) — the explained is no anomaly\n";
ok( false !== strpos( $src, 'pageview_visits' ), 'instruction defines the gated visits field' );
ok( false !== stripos( $src, 'viewless' ), 'instruction defines the viewless visitor-days' );
ok( false !== stripos( $src, 'structural' ), 'instruction names the views-vs-visitor-days gap structural' );
ok( false !== strpos( $src, 'integrity_violation' ), 'genuine-anomaly branch is scoped to integrity_violation only' );

echo "\nGroup: plain-prose voice contract (v9.64.2)\n";
ok( false !== strpos( $src, 'never write sigma, σ, backtest, interval, robust, confidence, or point estimate' ), 'jargon ban present in the instruction source' );
ok( false !== strpos( $src, 'NO MARKDOWN' ), 'markdown ban present in the instruction source' );

echo "\nGroup: narrator anomaly-flags wiring\n";
ok( false !== stripos( $src, 'anomaly_flags' ), 'prompt/collector reference the anomaly_flags block' );
ok( false !== stripos( $src, 'typical' ), 'prompt frames anomalies as a typical-range comparison' );
ok( false !== stripos( $src, 'sn_analytics_baseline_movers' ), 'collector sources anomaly_flags from baseline movers' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

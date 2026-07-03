<?php
/**
 * Asset-contract tests for the v8.5.0 clamp + collapse behavior: the CSS
 * hides rows past the clamp, the JS toggles both the clamp and the
 * collapsible panels, and collapse state persists via localStorage.
 *
 * Run: php tests/analytics-clamp-asset.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$css = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-admin.css' );
$js  = (string) file_get_contents( __DIR__ . '/../assets/admin.js' );

echo "analytics-clamp-asset suite - plugin v8.5.0\n";

echo "\nTest: clamp CSS\n";
ok( false !== strpos( $css, '.sn-an-clamp--5' ), 'clamp-5 rule exists' );
ok( false !== strpos( $css, '.sn-an-clamp--3' ), 'clamp-3 rule exists (rail-scale override)' );
ok( false !== strpos( $css, '.sn-an-clamp--10' ), 'clamp-10 rule exists (the primary Top pages table fills its column)' );
ok( false !== strpos( $css, 'nth-child(n+6)' ), 'clamp-5 hides from row 6' );
ok( false !== strpos( $css, 'nth-child(n+4)' ), 'clamp-3 hides from row 4' );
ok( false !== strpos( $css, 'nth-child(n+11)' ), 'clamp-10 hides from row 11' );
ok( false !== strpos( $css, '.sn-an-clamp--open' ), 'open state defined' );
ok( false !== strpos( $css, '.sn-an-viewall' ), 'view-all button styled' );

echo "\nTest: collapse CSS\n";
ok( false !== strpos( $css, '.sn-an-collapsed' ), 'collapsed state defined' );
ok( false !== strpos( $css, '.sn-an-toggle' ), 'toggle button styled' );

echo "\nTest: JS contracts\n";
ok( false !== strpos( $js, 'sn-an-viewall' ), 'JS handles the view-all toggle' );
ok( false !== strpos( $js, 'sn-an-clamp--open' ), 'JS flips the open class' );
ok( false !== strpos( $js, 'data-sn-an-collapsible' ), 'JS handles collapsible panels' );
ok( false !== strpos( $js, 'localStorage' ), 'collapse state persists (wp-admin only; cookieless is a front-end rule)' );
ok( false !== strpos( $js, 'sn-an-panel-open' ), 'JS dispatches the sn-an-panel-open event (uptime lazy-detail hook)' );
ok( false !== strpos( $js, 'aria-expanded' ), 'toggle updates aria-expanded' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

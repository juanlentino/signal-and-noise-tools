<?php
/**
 * Asset-contract tests for the v8.5.0 clamp + collapse behavior: the CSS
 * hides rows past the clamp and dresses a collapsible panel's native
 * <details>; the JS toggles the clamp and announces a panel's opening.
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

// 2026-11-10 (core-first audit, index 3): a collapsible panel is a native
// <details> (inc/analytics-panels.php). The CSS dresses its <summary> as the
// postbox header; the browser owns the toggle, its keyboard and its state.
echo "\nTest: collapse CSS\n";
ok( false === strpos( $css, '.sn-an-collapsed' ) && false === strpos( $css, '.sn-an-toggle' ), 'the hand-rolled collapsed class and toggle button are gone' );
ok( 1 === preg_match( '/details\.sn-an-postbox > summary\.postbox-header \{[^}]*cursor: pointer;[^}]*list-style: none;/', $css ), 'the summary is the header and reads as a control (pointer, no list marker)' );
ok( false !== strpos( $css, 'details.sn-an-postbox > summary.postbox-header::-webkit-details-marker { display: none; }' ), 'the WebKit marker is hidden too, so only the chevron shows' );
ok( 1 === preg_match( '/details\.sn-an-postbox > summary\.postbox-header::after \{[^}]*content: "\\\\2303" \/ "";/', $css ), 'the chevron is decorative: alt text "" keeps it out of the summary\'s accessible name' );
ok( false !== strpos( $css, 'details.sn-an-postbox:not([open]) > summary.postbox-header::after { transform: rotate(180deg); }' ), 'the chevron turns when the panel is closed' );
ok( false !== strpos( $css, 'details.sn-an-postbox:not([open]) > summary.postbox-header { border-bottom: 0; }' ), 'a closed panel drops the header rule, as core does for .postbox.closed' );
ok( 1 === preg_match( '/details\.sn-an-postbox > summary\.postbox-header:focus-visible \{[^}]*box-shadow: inset 0 0 0 2px var\(--wp-admin-theme-color/', $css ), 'keyboard focus is visible, drawn inset so the panel\'s overflow clip cannot hide it' );

echo "\nTest: JS contracts\n";
ok( false !== strpos( $js, 'sn-an-viewall' ), 'JS handles the view-all toggle' );
ok( false !== strpos( $js, 'sn-an-clamp--open' ), 'JS flips the open class' );
ok( false !== strpos( $js, "matches( 'details[data-sn-an-collapsible]' )" ), 'JS listens to the collapsible panels\' own disclosure' );
ok( 1 === preg_match( "/addEventListener\\( 'toggle', function[\\s\\S]*?sn-an-panel-open[\\s\\S]*?\\}, true \\);/", $js ), 'an open fires sn-an-panel-open (the uptime lazy-detail hook) from a capture-phase toggle listener: toggle does not bubble' );
ok( false === strpos( $js, 'localStorage' ) && false === strpos( $js, 'sn-an-toggle' ) && false === strpos( $js, 'sn-an-collapsed' ), 'no per-browser state and no hand-rolled toggle: the page\'s own open attribute is the state, as for every other fold' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

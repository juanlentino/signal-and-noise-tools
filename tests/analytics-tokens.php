<?php
/**
 * Token-contract tests for the v8.5.0 crisp-console-on-postbox treatment
 * (the admin-polish-v647 idiom applied to the Analytics page): the panel
 * shell reads from tokens, KPI numerals match the v8.0.3 glance contract,
 * and uptime-status.css no longer duplicates frame chrome the primitive owns.
 *
 * Run: php tests/analytics-tokens.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$an = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-admin.css' );
$uw = (string) file_get_contents( __DIR__ . '/../assets/uptime-status.css' );

echo "analytics-tokens suite - plugin v8.5.0\n";

echo "\nTest: the sn-an-postbox shell treatment\n";
ok( false !== strpos( $an, '--sn-an-border: #dcdcde' ), 'local hairline token defined (settings pages keep their #c3c4c7 contract untouched)' );
ok( false !== strpos( $an, '.sn-an-postbox' ), 'shell treatment targets the primitive marker class' );
ok( false !== strpos( $an, 'border: 1px solid var(--sn-an-border' ), 'shell border reads the token' );
ok( false !== strpos( $an, 'border-radius: var(--sn-radius' ), 'shell radius reads the v8.0.3 token (3px crisp-console)' );

echo "\nTest: KPI numerals — the v8.0.3 glance contract\n";
ok( false !== strpos( $an, '.sn-an-postbox .sn-kpi-value' ), 'KPI override is scoped to primitive panels' );
$kpi_block = substr( $an, strpos( $an, '.sn-an-postbox .sn-kpi-value' ) );
$kpi_block = substr( $kpi_block, 0, strpos( $kpi_block, '}' ) );
ok( false !== strpos( $kpi_block, 'font-size: 1.35rem' ), 'glance value size 1.35rem (matches admin-polish-v647)' );
ok( false !== strpos( $kpi_block, 'font-weight: 600' ), 'glance value weight 600' );
ok( false !== strpos( $an, 'font-variant-numeric: tabular-nums' ), 'tabular numerals in the vocabulary' );

echo "\nTest: uptime-status.css frame duplication folded into the primitive\n";
ok( false === strpos( $uw, '#c3c4c7' ), 'no hardcoded frame border remains (the panel shell owns the frame)' );
ok( false !== strpos( $uw, '.sn-uw-table' ), 'table row styling stays (rows are the file\'s job, frames are not)' );

echo "\nTest: D1 modern-native block (v9.37.0)\n";
ok( false !== strpos( $an, '.sn-an-headline' ), 'D1: headline band styled' );
ok( false === strpos( $an, '.sn-an-insights{' ) && false === strpos( $an, '.sn-an-insights {' ), 'D1: dead .sn-an-insights band rules pruned' );
ok( false !== strpos( $an, '.sn-an-view-tabs .nav-tab' ), 'D1: underline tab treatment present' );
ok( preg_match( '/\.sn-an-view-tabs \.nav-tab-active\s*\{[^}]*border-bottom:\s*2px solid #2271b1/s', $an ) === 1, 'D1: active tab = 2px accent underline' );
ok( preg_match( '/\.sn-overview \.sn-kpi-promoted \.sn-kpi-value\s*\{[^}]*font-size:\s*22px/s', $an ) === 1, 'D1: promoted KPI 22px (the scale dead since v8.5.0, restored)' );
ok( preg_match( '/\.sn-overview \.sn-kpi-value\s*\{[^}]*font-size:\s*13px/s', $an ) === 1, 'D1: secondary KPI 13px' );
ok( false !== strpos( $an, '.sn-an-sep-meta' ), 'D1: toolbar separation meta styled (muted inline)' );
ok( false !== strpos( $an, '.sn-an-uptime' ), 'D1: merged uptime details card styled' );
ok( preg_match( '/\.sn-overview \.sn-an-note\s*\{[^}]*border-left:\s*3px solid #2271b1/s', $an ) === 1, 'D1: Overview annotation = accent-bar callout (the .sn-an-note idiom, reused)' );
$d1 = substr( $an, (int) strpos( $an, 'v9.37.0 D1' ) );
ok( false !== strpos( $d1, 'box-shadow: none' ) && preg_match( '/box-shadow:\s*0/', $d1 ) === 0, 'D1 block: flat — the core postbox shadow is explicitly switched off, no new shadows added' );
ok( false === strpos( $d1, '#2563eb' ) && false === strpos( $d1, '#6b7280' ) && false === strpos( $d1, '#e2e4e7' ), 'D1 block: native palette only' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

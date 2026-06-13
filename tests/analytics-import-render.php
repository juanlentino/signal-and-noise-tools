<?php
/**
 * Tests for snt_analytics_render_import() — specifically that custom-events
 * and custom-props counts surface in the import success notice.
 * Run: php tests/analytics-import-render.php
 * @since plugin v6.2.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

// WP stubs.
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }
function wp_nonce_field( $action ) { /* no-op in tests */ }

// Control what get_transient returns per test.
$GLOBALS['_snt_test_transient'] = false;
function get_transient( $key ) { return $GLOBALS['_snt_test_transient']; }
function delete_transient( $key ) { $GLOBALS['_snt_test_transient'] = false; }

// sn_analytics_import_types() is defined in analytics-import.php which we don't load here;
// stub it so the form section doesn't fatal.
function sn_analytics_import_types() { return array(); }

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else         { $fail++; echo "  FAIL: $msg\n"; }
}

echo "\nGroup: snt_analytics_render_import — events/props count display\n";

// --- Test 1: events count renders when $report['events'] is set ---
$GLOBALS['_snt_test_transient'] = array( 'daily' => 100, 'events' => 42, 'event_props' => 7 );
ob_start(); snt_analytics_render_import(); $h = ob_get_clean();
ok( strpos( $h, 'custom events: 42' ) !== false, 'events count renders when set' );
ok( strpos( $h, 'custom props: 7' ) !== false, 'event_props count renders when set' );

// --- Test 2: events/props absent when keys not in $report ---
$GLOBALS['_snt_test_transient'] = array( 'daily' => 50 );
ob_start(); snt_analytics_render_import(); $h2 = ob_get_clean();
ok( strpos( $h2, 'custom events' ) === false, 'no custom events label when key absent' );
ok( strpos( $h2, 'custom props' ) === false, 'no custom props label when key absent' );

// --- Test 3: events/props absent when no report at all ---
$GLOBALS['_snt_test_transient'] = false;
ob_start(); snt_analytics_render_import(); $h3 = ob_get_clean();
ok( strpos( $h3, 'custom events' ) === false, 'no custom events label when no report' );
ok( strpos( $h3, 'custom props' ) === false, 'no custom props label when no report' );

// --- Test 4: events renders, props absent when only events key present ---
$GLOBALS['_snt_test_transient'] = array( 'daily' => 10, 'events' => 5 );
ob_start(); snt_analytics_render_import(); $h4 = ob_get_clean();
ok( strpos( $h4, 'custom events: 5' ) !== false, 'events count renders when only events key set' );
ok( strpos( $h4, 'custom props' ) === false, 'no custom props label when event_props key absent' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

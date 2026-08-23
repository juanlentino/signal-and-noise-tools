<?php
/**
 * Tests the admin save handler clamps audit_retention_days into [7, 365].
 * Bypasses the full admin-page.php dispatcher — exercises only the
 * clamping logic via a fixture function.
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value ) {
    $GLOBALS['__options'][ $name ] = $value;
    return true;
}
function get_bloginfo( $what ) { return ''; }

require __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/admin-post-actions.php';

// Replicate the clamping logic the save handler uses, in isolation.
function audit_retention_clamp( $raw ) {
    return max( 7, min( 365, (int) $raw ) );
}

$pass = 0;
$fail = 0;

function assertEq( $expected, $actual, $label ) {
    global $pass, $fail;
    if ( $expected === $actual ) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n";
    }
}

assertEq( 7,   audit_retention_clamp( 2 ),    'clamps 2 to 7 (min)' );
assertEq( 7,   audit_retention_clamp( 0 ),    'clamps 0 to 7 (min)' );
assertEq( 7,   audit_retention_clamp( -50 ),  'clamps -50 to 7 (min)' );
assertEq( 365, audit_retention_clamp( 999 ),  'clamps 999 to 365 (max)' );
assertEq( 365, audit_retention_clamp( 366 ),  'clamps 366 to 365 (max)' );
assertEq( 90,  audit_retention_clamp( 90 ),   'passes 90 through' );
assertEq( 30,  audit_retention_clamp( 30 ),   'passes 30 through' );

// Real behavioral check (v4.5.3): the extracted handler clamps + persists.
// Replaces the old source-grep proxy now that the clamp is a standalone fn.
$GLOBALS['__options'] = array();
sn_setting_reset_cache();
sn_handle_audit_save_retention( array( 'audit_retention_days' => 999 ) );
assertEq( 365, sn_setting( 'audit.retention_days' ), 'handler clamps 999 to 365 (real call)' );
sn_handle_audit_save_retention( array( 'audit_retention_days' => 1 ) );
assertEq( 7, sn_setting( 'audit.retention_days' ), 'handler clamps 1 to 7 (real call)' );

// The clamp expression lives in inc/admin-post-actions/reports.php (admin-page.php
// before v4.5.4; the single admin-post-actions.php file before the v12.22.0 split).
// Reads the admin-post LAYER, not one file: the handlers live in
// inc/admin-post-actions/*.php behind a thin loader (v12.22.0), so scanning
// the loader alone would find nothing.
$actions_src = implode( '', array_map( 'file_get_contents', array_merge(
	array( __DIR__ . '/../inc/admin-post-actions.php' ),
	glob( __DIR__ . '/../inc/admin-post-actions/*.php' ) ?: array()
) ) );
if ( false !== strpos( $actions_src, "max( 7, min( 365" ) ) {
    $pass++;
    echo "PASS: the admin-post layer contains the clamp expression\n";
} else {
    $fail++;
    echo "FAIL: the admin-post layer does not contain expected clamp expression\n";
}

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

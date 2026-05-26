<?php
/**
 * Tests the admin save handler clamps audit_retention_days into [7, 365].
 * Bypasses the full admin-page.php dispatcher — exercises only the
 * clamping logic via a fixture function.
 */

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

// Also verify the production source actually uses this expression:
$admin_src = file_get_contents( __DIR__ . '/../inc/admin-page.php' );
if ( false !== strpos( $admin_src, "max( 7, min( 365" ) ) {
    $pass++;
    echo "PASS: admin-page.php contains the clamp expression\n";
} else {
    $fail++;
    echo "FAIL: admin-page.php does not contain expected clamp expression\n";
}

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

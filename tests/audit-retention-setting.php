<?php
/**
 * Tests that the audit-log prune impl reads retention from
 * sn_setting('audit.retention_days') instead of the hard-coded
 * SN_AUDIT_RETENTION_DAYS constant. v4.2.0 (D-features).
 */

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value ) {
    $GLOBALS['__options'][ $name ] = $value;
    return true;
}
function get_bloginfo( $what ) {
    return $what === 'name' ? 'TestSite' : '';
}

require __DIR__ . '/../inc/settings.php';

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

// === Test 1: default retention is 90 ===
sn_setting_reset_cache();  // ensure fresh
$default = sn_setting( 'audit.retention_days', null );
assertEq( 90, $default, 'audit.retention_days default is 90' );

// === Test 2: setting can be overridden ===
sn_setting_update( 'audit.retention_days', 30 );
$override = sn_setting( 'audit.retention_days', null );
assertEq( 30, $override, 'audit.retention_days respects user override' );

// === Test 3: setting persists across cache reset ===
sn_setting_reset_cache();
$persisted = sn_setting( 'audit.retention_days', null );
assertEq( 30, $persisted, 'audit.retention_days survives cache reset' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

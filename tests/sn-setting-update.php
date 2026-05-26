<?php
/**
 * Tests for sn_setting_update() + sn_setting_reset_cache() (v4.2.0).
 *
 * Validates the D-06 fix: any write to sn_settings via the new
 * sn_setting_update() helper busts the per-request static cache so
 * subsequent sn_setting() reads in the same request see the new value.
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

// In-memory option store.
$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value ) {
    $existed = array_key_exists( $name, $GLOBALS['__options'] );
    $prev    = $existed ? $GLOBALS['__options'][ $name ] : null;
    $GLOBALS['__options'][ $name ] = $value;
    return ! $existed || $prev !== $value;
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

// === Test 1: sn_setting_update writes the value ===
$ok = sn_setting_update( 'login.slug', 'backend2' );
assertEq( true, $ok, 'sn_setting_update returns true on successful write' );
$stored = get_option( 'sn_settings' );
assertEq( 'backend2', $stored['login']['slug'], 'option contains new value' );

// === Test 2: cache is busted — sn_setting reads fresh value AFTER update ===
// Properly prove cache-busting: populate cache, confirm stale-via-direct-write,
// then call sn_setting_update and confirm fresh value is visible.
// (Without sn_setting_update's internal sn_setting_reset_cache call,
// the assertion at the end of this block would fail — that's the test.)
sn_setting_reset_cache();  // clean slate
sn_setting( 'login.slug', 'sn-login' );  // populates static $merged with current = 'backend2'
$primed = sn_setting( 'login.slug', 'sn-login' );
assertEq( 'backend2', $primed, 'cache primed with current value' );

// Simulate the bug class: a direct update_option does NOT bust the cache.
update_option( 'sn_settings', array( 'login' => array( 'slug' => 'shadow-write' ) ) );
$still_stale = sn_setting( 'login.slug', 'sn-login' );
assertEq( 'backend2', $still_stale, 'cache stays stale after direct update_option (bug class confirmed)' );

// Now call sn_setting_update — it MUST bust the cache to make the new value visible.
sn_setting_update( 'login.slug', 'newval' );
$after = sn_setting( 'login.slug', 'sn-login' );
assertEq( 'newval', $after, 'sn_setting_update busts cache so the new value is visible in same request' );

// === Test 3: sn_setting_reset_cache works independently ===
update_option( 'sn_settings', array( 'login' => array( 'slug' => 'direct-write' ) ) );
$stale = sn_setting( 'login.slug', 'sn-login' );
assertEq( 'newval', $stale, 'direct update_option does NOT bust cache (confirms the bug class)' );
sn_setting_reset_cache();
$fresh = sn_setting( 'login.slug', 'sn-login' );
assertEq( 'direct-write', $fresh, 'sn_setting_reset_cache busts cache so direct writes become visible' );

// === Test 4: deep dot-paths work ===
sn_setting_update( 'audit.retention_days', 30 );
$retention = sn_setting( 'audit.retention_days', 90 );
assertEq( 30, $retention, 'deep dot-path write + read roundtrip' );

// === Summary ===
echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

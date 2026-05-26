<?php
/**
 * Roundtrip test for sn_setting_update() — confirms the helper produces
 * a final state indistinguishable from the direct get_option/update_option
 * pattern it replaces (no schema drift).
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

// === Direct pattern (the OLD save_login behavior) ===
$settings_a = (array) get_option( 'sn_settings', array() );
$settings_a['login'] = is_array( $settings_a['login'] ?? null ) ? $settings_a['login'] : array();
$settings_a['login']['slug'] = 'old-pattern';
update_option( 'sn_settings', $settings_a );
$direct_result = (array) get_option( 'sn_settings', array() );

// Wipe + try the helper pattern (the NEW save_login behavior)
$GLOBALS['__options'] = array();
sn_setting_update( 'login.slug', 'new-pattern' );
$helper_result = (array) get_option( 'sn_settings', array() );

// They should write the same shape (login -> slug)
assertEq(
    array_keys( $direct_result['login'] ),
    array_keys( $helper_result['login'] ),
    'helper writes same login subkey structure as direct pattern'
);
assertEq(
    'new-pattern',
    $helper_result['login']['slug'],
    'helper writes value at correct dot-path'
);

// Without other settings, helper should not inject empty sibling keys
// (the helper only writes the path requested)
assertEq( true, ! isset( $helper_result['identity'] ), 'helper does not inject unrelated sibling keys' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

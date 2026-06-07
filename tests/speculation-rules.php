<?php
/**
 * Standalone fixture tests for inc/speculation-rules.php (v4.10.0, T6).
 *
 * Verifies the two WP-core Speculation Rules filter callbacks:
 *   - sn_speculation_configuration()      → wp_speculation_rules_configuration
 *   - sn_speculation_href_exclude_paths() → wp_speculation_rules_href_exclude_paths
 *
 * Plus the settings-roundtrip regression (modelled on
 * tests/settings-save-preserves-subtrees.php): saving the Identity tab must
 * NOT clobber a pre-existing `perf` subtree (the whole-option-replace hazard).
 *
 * Run: php tests/speculation-rules.php
 *
 * @since plugin v4.10.0
 */

// SECURITY: CLI / WP-CLI only (mirrors sibling fixtures).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );

// ── Minimal WP + settings stubs (match real types/effects, not just shape) ──
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
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function sanitize_title( $s ) { return strtolower( trim( (string) $s ) ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
// add_filter is a no-op here — we call the callbacks directly to test behavior.
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

// Configurable login slug stub (real impl lives in inc/login-hide.php, which
// runs WP-dependent file-scope code we don't want to load standalone).
$GLOBALS['__login_slug'] = 'sn-login';
function sn_login_get_slug() { return $GLOBALS['__login_slug']; }

require __DIR__ . '/../inc/settings.php';
require __DIR__ . '/../inc/speculation-rules.php';

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
function assertContains( $needle, array $haystack, $label ) {
    global $pass, $fail;
    if ( in_array( $needle, $haystack, true ) ) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label — " . var_export( $needle, true ) . " not in " . var_export( $haystack, true ) . "\n";
    }
}
function assertNotContains( $needle, array $haystack, $label ) {
    global $pass, $fail;
    if ( ! in_array( $needle, $haystack, true ) ) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label — " . var_export( $needle, true ) . " unexpectedly in " . var_export( $haystack, true ) . "\n";
    }
}

// ── 1. Default: perf.speculative_loading deep-merges to true on a fresh install ──
sn_setting_reset_cache();
assertEq( true, sn_setting( 'perf.speculative_loading', 'SENTINEL' ), 'fresh install: perf.speculative_loading defaults to true (deep-merge)' );

// ── 2. Config filter ON: returns prerender/moderate ──
sn_setting_reset_cache();
$config_on = sn_speculation_configuration( null );
assertEq( array( 'mode' => 'prerender', 'eagerness' => 'moderate' ), $config_on, 'config ON returns prerender/moderate' );

// ── 3. Config filter OFF: returns null (disables core feature) ──
sn_setting_update( 'perf.speculative_loading', false );
sn_setting_reset_cache();
assertEq( null, sn_speculation_configuration( array( 'mode' => 'auto', 'eagerness' => 'auto' ) ), 'config OFF returns null (disables speculative loading)' );

// Re-enable for the exclude-path tests.
sn_setting_update( 'perf.speculative_loading', true );
sn_setting_reset_cache();

// ── 4. Exclude-paths is ADDITIVE: keeps a pre-existing path, adds login slug + /contact/* ──
$GLOBALS['__login_slug'] = 'sn-login';
$existing = array( '/secret-area/*' );
$result   = sn_speculation_href_exclude_paths( $existing, 'prerender' );
assertContains( '/secret-area/*', $result, 'exclude-paths keeps the pre-existing path' );
assertContains( '/sn-login/*', $result, 'exclude-paths adds the login slug path' );
assertContains( '/contact/*', $result, 'exclude-paths adds /contact/*' );

// ── 5. Does NOT re-add core-owned exclusions (core already excludes these) ──
assertNotContains( '/wp-admin/*', $result, 'exclude-paths does NOT re-add /wp-admin/*' );
assertNotContains( '/wp-login.php', $result, 'exclude-paths does NOT re-add /wp-login.php' );

// ── 6. Custom login slug is reflected ──
$GLOBALS['__login_slug'] = 'my-secret-login';
$result_custom = sn_speculation_href_exclude_paths( array(), 'prerender' );
assertContains( '/my-secret-login/*', $result_custom, 'custom login slug reflected in exclude-paths' );
assertNotContains( '/sn-login/*', $result_custom, 'default slug NOT present when a custom slug is configured' );
$GLOBALS['__login_slug'] = 'sn-login';

// ── 7. Settings roundtrip: saving Identity preserves a pre-existing perf subtree ──
sn_setting_update( 'perf.speculative_loading', false );
sn_setting_reset_cache();
assertEq( false, sn_setting( 'perf.speculative_loading', 'SENTINEL' ), 'precondition: perf.speculative_loading set to false' );

sn_settings_save( array(
    'identity_site_name'        => 'New Site Name',
    'identity_site_description' => 'New tagline',
) );
sn_setting_reset_cache();

assertEq( 'New Site Name', sn_setting( 'identity.site_name', '' ), 'Identity save persisted its own field' );
assertEq( false, sn_setting( 'perf.speculative_loading', 'SENTINEL' ), 'perf subtree survives an Identity save (v4.10.0 preservation guard)' );

echo "\n";
echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

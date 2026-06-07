<?php
/**
 * Regression test: sn_settings_save() (the Identity-tab form handler) must
 * NOT clobber settings subtrees that are configured on OTHER tabs.
 *
 * sn_settings_save() does a whole-option replace with only the Identity-form
 * keys. Any subtree written elsewhere via sn_setting_update() — login.slug
 * (Login/Security tab) and audit.retention_days (Security tab → Audit-log
 * sub-tab) — must be re-included, or it reverts to defaults on the next save.
 *
 * login.slug has been preserved since v1.9.0; audit.retention_days was added
 * in v4.2.0 but NOT added to the preservation list — so saving Identity
 * silently reset a configured retention back to the 90-day default. This test
 * locks in preservation of BOTH subtrees.
 *
 * @since plugin v4.5.2
 */

// SECURITY: CLI / WP-CLI only (mirrors sibling fixtures).
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
// Sanitizers used by sn_settings_save() — identity transforms only.
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function sanitize_title( $s ) { return strtolower( trim( (string) $s ) ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }

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

// v4.9.0: the monitoring subtree (Uptime Kuma heartbeat) must deep-merge from
// defaults on a fresh install (migration-free) and survive an Identity save.
sn_setting_reset_cache();
assertEq( false, sn_setting( 'monitoring.uptime_kuma_enabled', 'SENTINEL' ), 'fresh install: monitoring.uptime_kuma_enabled defaults to false (deep-merge)' );
assertEq( '', sn_setting( 'monitoring.uptime_kuma_push_url', 'SENTINEL' ), 'fresh install: monitoring.uptime_kuma_push_url defaults to empty string (deep-merge)' );

// 1. Configure cross-tab settings the Identity form does NOT touch:
//    audit retention (Security tab) + a custom login slug (Login tab) +
//    the monitoring heartbeat (Webhooks tab).
sn_setting_update( 'audit.retention_days', 30 );
sn_setting_update( 'login.slug', 'my-secret-login' );
sn_setting_update( 'monitoring.uptime_kuma_enabled', true );
sn_setting_update( 'monitoring.uptime_kuma_push_url', 'https://kuma.example.com/api/push/abc' );
sn_setting_reset_cache();

// Sanity: they're set before the Identity save.
assertEq( 30, (int) sn_setting( 'audit.retention_days', 90 ), 'precondition: audit.retention_days is 30' );
assertEq( 'my-secret-login', sn_setting( 'login.slug', 'sn-login' ), 'precondition: login.slug is custom' );

// 2. Save the Identity tab form (payload carries NO audit/login fields).
sn_settings_save( array(
    'identity_site_name'        => 'New Site Name',
    'identity_site_description' => 'New tagline',
) );
sn_setting_reset_cache();

// 3. Both cross-tab subtrees must survive the Identity save.
assertEq( 'New Site Name', sn_setting( 'identity.site_name', '' ), 'Identity save persisted its own field' );
assertEq( 'my-secret-login', sn_setting( 'login.slug', 'sn-login' ), 'login.slug survives an Identity save (existing v1.9.0 guard)' );
assertEq( 30, (int) sn_setting( 'audit.retention_days', 90 ), 'audit.retention_days survives an Identity save (v4.5.2 fix)' );
assertEq( true, sn_setting( 'monitoring.uptime_kuma_enabled', false ), 'monitoring.uptime_kuma_enabled survives an Identity save (v4.9.0 guard)' );
assertEq( 'https://kuma.example.com/api/push/abc', sn_setting( 'monitoring.uptime_kuma_push_url', '' ), 'monitoring.uptime_kuma_push_url survives an Identity save (v4.9.0 guard)' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

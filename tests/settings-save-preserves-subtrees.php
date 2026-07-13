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
// Real stripslashes_deep behavior (sn_settings_save() unslashes its payload
// as of v9.36.1) — a no-op for the clean literals this fixture passes.
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }

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
assertEq( false, sn_setting( 'insights.weekly_cron_enabled', 'SENTINEL' ), 'fresh install: insights.weekly_cron_enabled defaults to false (deep-merge)' );
// v6.23.0: analytics owner-exclusion roles. MUST default to an EMPTY array so a
// user who unchecks every role can actually store "exclude nobody" — a non-empty
// default would resurface via array_replace_recursive (index-keyed merge).
assertEq( array(), sn_setting( 'analytics.exclude_roles', 'SENTINEL' ), 'fresh install: analytics.exclude_roles defaults to empty array (deep-merge)' );

// D5 (v6.17.0): the availability line lives IN the identity subtree (written by
// the Identity form itself), so it defaults to empty and round-trips directly —
// no separate preserve block needed (it's never a cross-tab orphan).
assertEq( '', sn_setting( 'identity.availability', 'SENTINEL' ), 'fresh install: identity.availability defaults to empty string' );

// 1. Configure cross-tab settings the Identity form does NOT touch:
//    audit retention (Security tab) + a custom login slug (Login tab) +
//    the monitoring heartbeat (Webhooks tab).
sn_setting_update( 'audit.retention_days', 30 );
sn_setting_update( 'login.slug', 'my-secret-login' );
sn_setting_update( 'monitoring.uptime_kuma_enabled', true );
sn_setting_update( 'monitoring.uptime_kuma_push_url', 'https://kuma.example.com/api/push/abc' );
// v4.12.0: the theme subtree (Tools → Front-End render knobs) is configured via
// sn_setting_update('theme.*', …), NOT in the Identity form payload. Same
// whole-option-replace hazard as audit/monitoring/perf above.
sn_setting_update( 'theme.related_count', 9 );
sn_setting_update( 'theme.palette_enabled', false );
sn_setting_update( 'theme.ai_model', 'claude-opus-4-8' );
sn_setting_update( 'indexnow.enabled', true );
// vX: the insights subtree (Insights tab → weekly-cron opt-in) is written via
// sn_setting_update('insights.weekly_cron_enabled', …) by sn_handle_save_insights_settings(),
// NOT in the Identity form payload. Same whole-option-replace hazard.
sn_setting_update( 'insights.weekly_cron_enabled', true );
// v6.23.0: the analytics subtree (Monitoring → Analytics → "Exclude my own
// visits") is written via sn_setting_update('analytics.exclude_roles', …) by
// sn_handle_analytics_exclude_save(), NOT in the Identity form payload. Same
// whole-option-replace hazard.
sn_setting_update( 'analytics.exclude_roles', array( 'administrator' ) );
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
assertEq( 9, (int) sn_setting( 'theme.related_count', 3 ), 'theme.related_count survives an Identity save (v4.12.0 guard)' );
assertEq( false, sn_setting( 'theme.palette_enabled', true ), 'theme.palette_enabled survives an Identity save (v4.12.0 guard)' );
assertEq( 'claude-opus-4-8', sn_setting( 'theme.ai_model', 'claude-sonnet-4-6' ), 'theme.ai_model survives an Identity save (v4.12.0 guard)' );
assertEq( true, sn_setting( 'indexnow.enabled', false ), 'indexnow.enabled survives an Identity save (v5.1.0 guard)' );
assertEq( true, sn_setting( 'insights.weekly_cron_enabled', false ), 'insights.weekly_cron_enabled survives an Identity save (insights-preserve guard)' );
assertEq( array( 'administrator' ), sn_setting( 'analytics.exclude_roles', array() ), 'analytics.exclude_roles survives an Identity save (v6.23.0 guard)' );

// D5 (v6.17.0): a second Identity save carrying the availability field persists
// it directly, and the cross-tab subtrees still survive (availability didn't
// disturb the preserve blocks).
sn_settings_save( array(
	'identity_site_name'    => 'New Site Name',
	'identity_availability' => 'Available for select mixing work',
) );
sn_setting_reset_cache();
assertEq( 'Available for select mixing work', sn_setting( 'identity.availability', '' ), 'identity.availability round-trips through sn_settings_save' );
assertEq( 'my-secret-login', sn_setting( 'login.slug', 'sn-login' ), 'login.slug still survives after availability added' );
assertEq( true, sn_setting( 'indexnow.enabled', false ), 'indexnow.enabled still survives after availability added' );
assertEq( true, sn_setting( 'insights.weekly_cron_enabled', false ), 'insights.weekly_cron_enabled still survives after availability added' );
assertEq( array( 'administrator' ), sn_setting( 'analytics.exclude_roles', array() ), 'analytics.exclude_roles still survives after availability added' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

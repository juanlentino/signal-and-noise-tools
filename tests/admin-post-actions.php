<?php
/**
 * Unit tests for the extracted admin POST action handlers
 * (inc/admin-post-actions.php) + the dispatch map (inc/admin-post-handler.php).
 *
 * Before v4.5.3 these lived inside a 270-line if/elseif in
 * sn_handle_admin_post() with ZERO unit coverage. Each handler is now a
 * standalone fn( array $post ): string returning a flash code, so flash +
 * side effects are assertable directly.
 *
 * Run: php tests/admin-post-actions.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$changed = ! array_key_exists( $name, $GLOBALS['__options'] ) || $GLOBALS['__options'][ $name ] !== $value;
	$GLOBALS['__options'][ $name ] = $value;
	return $changed; // mirror WP: returns false when the value is unchanged
}
function delete_option( $name ) { unset( $GLOBALS['__options'][ $name ] ); return true; }
function get_bloginfo( $what ) { return ''; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '~[^a-z0-9\-]+~i', '-', (string) $s ), '-' ) ); }
function esc_url_raw( $s ) { return $s; }
function wp_unslash( $v ) { return $v; }
function add_action( $hook, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }

require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/admin-post-actions.php';
require_once __DIR__ . '/../inc/admin-post-handler.php';

$pass = 0; $fail = 0;
function pa_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function pa_reset_store() { $GLOBALS['__options'] = array(); sn_setting_reset_cache(); }

echo "\nTest: sn_handle_save_login()\n";
pa_reset_store();
pa_eq( 'login_empty', sn_handle_save_login( array() ), 'missing slug → login_empty' );
pa_eq( 'login_empty', sn_handle_save_login( array( 'login_slug' => '   ' ) ), 'blank slug → login_empty' );
pa_eq( 'login_saved', sn_handle_save_login( array( 'login_slug' => 'Secret Door' ) ), 'valid slug → login_saved' );
pa_eq( 'secret-door', sn_setting( 'login.slug' ), 'slug persisted + sanitized' );

echo "\nTest: sn_handle_audit_save_retention() clamps [7,365]\n";
pa_reset_store();
sn_handle_audit_save_retention( array( 'audit_retention_days' => 999 ) );
pa_eq( 365, sn_setting( 'audit.retention_days' ), '999 → 365 (max)' );
sn_handle_audit_save_retention( array( 'audit_retention_days' => 2 ) );
pa_eq( 7, sn_setting( 'audit.retention_days' ), '2 → 7 (min)' );
sn_handle_audit_save_retention( array( 'audit_retention_days' => 90 ) );
pa_eq( 90, sn_setting( 'audit.retention_days' ), '90 passes through' );
pa_eq( 'audit_retention_saved', sn_handle_audit_save_retention( array( 'audit_retention_days' => 45 ) ), 'changed → audit_retention_saved' );

echo "\nTest: sn_handle_save_identity()\n";
pa_reset_store();
pa_eq( 'identity_saved', sn_handle_save_identity( array( 'identity_site_name' => 'Acme' ) ), 'first save → identity_saved' );
pa_eq( 'identity_unchanged', sn_handle_save_identity( array( 'identity_site_name' => 'Acme' ) ), 'identical re-save → identity_unchanged' );

echo "\nTest: sn_handle_cf_save() honors constant locks\n";
define( 'SN_CLOUDFLARE_API_TOKEN', 'locked-tok' );
define( 'SN_CLOUDFLARE_ZONE_ID', 'locked-zone' );
pa_reset_store();
pa_eq( 'cf_saved', sn_handle_cf_save( array( 'sn_cf_token' => 'attempt', 'sn_cf_zone' => 'attempt' ) ), 'returns cf_saved' );
pa_eq( array(), $GLOBALS['__options'], 'no option written when both constants are defined (locked)' );

echo "\nTest: sn_handle_pl_save() branches\n";
define( 'SN_PLAUSIBLE_TOKEN_OPT', 'sn_pl_token' );
function sn_pl_admin_invalidate_caches() {}
$GLOBALS['__options'] = array( 'sn_pl_token' => 'old' );
pa_eq( 'pl_cleared', sn_handle_pl_save( array( 'sn_pl_token' => 'clear' ) ), "'clear' → pl_cleared" );
pa_eq( false, array_key_exists( 'sn_pl_token', $GLOBALS['__options'] ), 'token option deleted' );
pa_eq( 'pl_unchanged', sn_handle_pl_save( array( 'sn_pl_token' => '' ) ), 'empty → pl_unchanged' );
pa_eq( 'pl_saved', sn_handle_pl_save( array( 'sn_pl_token' => 'real-new-token' ) ), 'real token → pl_saved' );
pa_eq( 'real-new-token', get_option( 'sn_pl_token' ), 'token persisted' );

echo "\nTest: sn_handle_monitoring_save() enforces https (Fix C)\n";
pa_reset_store();
// http:// push URL → rejected, cleared, error flash.
pa_eq( 'monitoring_url_not_https', sn_handle_monitoring_save( array( 'uptime_kuma_enabled' => '1', 'uptime_kuma_push_url' => 'http://kuma.example/api/push/x' ) ), 'http url → monitoring_url_not_https' );
pa_eq( '', sn_setting( 'monitoring.uptime_kuma_push_url' ), 'rejected http url cleared (not persisted)' );
// https:// push URL → saved.
pa_reset_store();
pa_eq( 'monitoring_saved', sn_handle_monitoring_save( array( 'uptime_kuma_enabled' => '1', 'uptime_kuma_push_url' => 'https://kuma.example/api/push/x' ) ), 'https url → monitoring_saved' );
pa_eq( 'https://kuma.example/api/push/x', sn_setting( 'monitoring.uptime_kuma_push_url' ), 'https url persisted' );

echo "\nTest: sn_admin_post_handlers() map is complete + callable\n";
$map = sn_admin_post_handlers();
pa_eq( 23, count( $map ), 'map has 23 actions' );
foreach ( $map as $action => $cb ) {
	pa_eq( true, is_callable( $cb ), "handler for '$action' is callable" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

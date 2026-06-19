<?php
/**
 * Render test for snt_analytics_render_exclusion() — the Monitoring → Analytics
 * "Exclude my own visits" card (plugin v6.23.0). Asserts a checkbox per role, the
 * configured role pre-checked, the current-viewer status line (both branches),
 * the CDN logged-in-bypass note, the nonce, and the save button's action.
 *
 * @since plugin v6.23.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function wp_nonce_field( $a = -1, $b = '_wpnonce', $c = true, $d = true ) { echo '<input type="hidden" name="_wpnonce" value="x">'; return ''; }
function checked( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' checked' : '';
	if ( $echo ) { echo $r; }
	return $r;
}

// Plugin deps stubbed so the render fn is isolated from WP roles + settings.
$GLOBALS['__excluded']        = array( 'administrator' );
$GLOBALS['__viewer_excluded'] = true;
function sn_setting( $path, $default = null ) {
	return 'analytics.exclude_roles' === $path ? $GLOBALS['__excluded'] : $default;
}
function sn_beacon_excludable_roles() {
	return array( 'administrator' => 'Administrator', 'editor' => 'Editor', 'subscriber' => 'Subscriber' );
}
function sn_beacon_owner_current_user_excluded() {
	return (bool) $GLOBALS['__viewer_excluded'];
}

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; } else { $fail++; echo "FAIL: $msg\n"; }
}

ob_start();
snt_analytics_render_exclusion();
$h = ob_get_clean();

ok( strpos( $h, 'name="sn_exclude_roles[]" value="administrator"' ) !== false, 'administrator checkbox present' );
ok( strpos( $h, 'name="sn_exclude_roles[]" value="editor"' ) !== false, 'editor checkbox present' );
ok( strpos( $h, 'name="sn_exclude_roles[]" value="subscriber"' ) !== false, 'subscriber checkbox present' );
ok( preg_match( '/value="administrator"\s+checked/', $h ) === 1, 'configured role (administrator) is pre-checked' );
ok( preg_match( '/value="editor"\s+checked/', $h ) === 0, 'unconfigured role (editor) is NOT checked' );
ok( strpos( $h, 'currently excluded from analytics' ) !== false, 'status line reflects excluded viewer' );
ok( strpos( $h, 'wordpress_logged_in_' ) !== false, 'CDN logged-in-bypass note present' );
ok( strpos( $h, 'value="analytics_exclude_save"' ) !== false, 'save button posts the exclude action' );
ok( strpos( $h, 'name="_wpnonce"' ) !== false, 'nonce field present' );

// Viewer-not-excluded path flips the status line.
$GLOBALS['__viewer_excluded'] = false;
ob_start();
snt_analytics_render_exclusion();
$h2 = ob_get_clean();
ok( strpos( $h2, 'currently counted in analytics' ) !== false, 'status line reflects counted viewer' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

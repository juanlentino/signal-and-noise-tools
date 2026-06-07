<?php
/**
 * Unit tests for the shared admin flash-message registry
 * (inc/admin-flash-messages.php).
 *
 * Guards the v4.5.3 collapse of the two duplicate flash ladders into one data
 * source + resolver. Covers all three message shapes: exact-match static
 * codes, count/id-prefixed codes, and live-data codes.
 *
 * Run: php tests/admin-flash-messages.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SN_PLAUSIBLE_BATCH_KEY', 'sn_pl_batch' );

$GLOBALS['__settings']  = array( 'login.slug' => 'secret-door' );
$GLOBALS['__transient'] = false;
$GLOBALS['__pl_error']  = null;

function sn_setting( $path, $default = null ) { return $GLOBALS['__settings'][ $path ] ?? $default; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function esc_url( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function get_transient( $k ) { return $GLOBALS['__transient']; }
function sn_plausible_last_error() { return $GLOBALS['__pl_error']; }
function number_format_i18n( $n ) { return (string) $n; }

require_once __DIR__ . '/../inc/admin-flash-messages.php';

$pass = 0; $fail = 0;
function fm_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}

echo "\nTest 1: exact-match static codes\n";
fm_eq( array( 'success', 'Identity settings saved.' ), sn_admin_flash_to_notice( 'identity_saved' ), 'identity_saved' );
fm_eq( array( 'info', 'No changes to save.' ), sn_admin_flash_to_notice( 'identity_unchanged' ), 'identity_unchanged' );
fm_eq( array( 'warning', 'Cloudflare not configured — set the API token and zone ID first.' ), sn_admin_flash_to_notice( 'cf_purged_unconfigured' ), 'cf_purged_unconfigured keeps warning severity' );
fm_eq( array( 'success', 'Block migration scan complete.' ), sn_admin_flash_to_notice( 'block_migrations_scanned' ), 'block_migrations_scanned' );
fm_eq( array( 'error', 'Uptime Kuma push URL must start with <code>https://</code> — the setting was cleared. Re-enter a secure URL.' ), sn_admin_flash_to_notice( 'monitoring_url_not_https' ), 'monitoring_url_not_https → error (Fix C)' );

echo "\nTest 2: count-prefixed codes parse the trailing int\n";
fm_eq( array( 'success', '12 database override(s) cleared. Site is reading from theme files.' ), sn_admin_flash_to_notice( 'cleared_12' ), 'cleared_12' );
fm_eq( array( 'success', 'Full reset: 3 override(s) cleared + all caches purged.' ), sn_admin_flash_to_notice( 'reset_3' ), 'reset_3' );
fm_eq( array( 'success', '7 post(s) cleaned. Reading-time cache rebuilt.' ), sn_admin_flash_to_notice( 'rt_applied_7' ), 'rt_applied_7' );

echo "\nTest 3: id-prefixed codes resolve to static message\n";
fm_eq( array( 'success', 'Webhook added. Copy the signing secret below — it will not be shown again.' ), sn_admin_flash_to_notice( 'wh_added_abc123' ), 'wh_added_<id>' );
$rotated = sn_admin_flash_to_notice( 'wh_rotated_abc123' );
fm_eq( 'success', $rotated[0], 'wh_rotated_<id> severity' );
fm_eq( true, false !== strpos( $rotated[1], 'Signing secret was rotated' ), 'wh_rotated_<id> message body' );

echo "\nTest 4: live-data codes compute from state\n";
$login = sn_admin_flash_to_notice( 'login_saved' );
fm_eq( 'success', $login[0], 'login_saved severity' );
fm_eq( true, false !== strpos( $login[1], 'https://example.test/secret-door' ), 'login_saved embeds current slug URL' );

$GLOBALS['__transient'] = array( 'data' => array( 'visitors' => array( 'value' => 1234 ) ) );
$ok = sn_admin_flash_to_notice( 'pl_test_ok' );
fm_eq( 'success', $ok[0], 'pl_test_ok severity' );
fm_eq( true, false !== strpos( $ok[1], '1234 visitor(s)' ), 'pl_test_ok embeds visitor count from transient' );

$GLOBALS['__pl_error'] = array( 'code' => 503, 'message' => 'upstream down' );
$err = sn_admin_flash_to_notice( 'pl_test_err' );
fm_eq( 'error', $err[0], 'pl_test_err severity' );
fm_eq( true, false !== strpos( $err[1], 'HTTP 503' ), 'pl_test_err embeds error code' );

echo "\nTest 5: unknown code returns null (renders no notice)\n";
fm_eq( null, sn_admin_flash_to_notice( 'totally_unknown_code' ), 'unknown → null' );
fm_eq( null, sn_admin_flash_to_notice( '' ), 'empty → null' );

echo "\nTest 6: coordination guard — every exact code the dispatcher emits resolves\n";
$emitted = array(
	'identity_saved','identity_unchanged','login_empty','login_failed','pl_saved','pl_cleared',
	'pl_unchanged','pl_locked','pl_test_unconfigured','cf_saved','cf_purged_ok','cf_purged_unconfigured',
	'purged','wh_updated','wh_deleted','wh_invalid','wh_not_found','insights_scanned','insights_failed',
	'insights_dismissed','insights_snoozed','insights_done','insights_settings_saved','health_scanned',
	'pattern_adoption_scanned','block_migrations_scanned','audit_retention_saved','audit_retention_unchanged',
);
foreach ( $emitted as $code ) {
	fm_eq( true, null !== sn_admin_flash_to_notice( $code ), "resolver covers '$code'" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Standalone test: three security watches (2026-10-18). Observe, never enforce.
 *
 * - application passwords: the one standing credential that skips the masked
 *   login door, Two Factor and passkeys. Ripe on a new one or a stale one.
 * - admin 2FA coverage: reads Two Factor (passkeys arrive through its WebAuthn
 *   provider). Ripe when Two Factor is off, or an admin has no strong method.
 * - guard routes: a daily read of the Cloudflare routes. Ripe when the door's
 *   routes no longer point at the guard. Counts only, never patterns.
 *
 * Run: php tests/security-watches.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_SECURITY_WATCHES_TEST', true );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
require dirname( __DIR__ ) . '/inc/security-watches.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
$now = 1800000000; $d = DAY_IN_SECONDS;

// Application passwords.
$fresh = array( array( 'user' => 'juan', 'name' => 'n8n', 'created' => $now - 2 * $d, 'last_used' => $now - $d ) );
$w = snt_sec_app_passwords_verdict( $fresh, $now );
ok( $w['ripe'] && false !== strpos( $w['note'], 'juan' ) && false !== strpos( $w['note'], 'n8n' ), 'a password created this week is ripe and names user and app' );
$stale = array( array( 'user' => 'juan', 'name' => 'old', 'created' => $now - 400 * $d, 'last_used' => $now - 120 * $d ) );
ok( snt_sec_app_passwords_verdict( $stale, $now )['ripe'], 'one unused for 90+ days is ripe' );
$never = array( array( 'user' => 'juan', 'name' => 'never', 'created' => $now - 100 * $d, 'last_used' => null ) );
ok( snt_sec_app_passwords_verdict( $never, $now )['ripe'], 'one never used after 90 days is ripe' );
$busy = array( array( 'user' => 'juan', 'name' => 'mcp', 'created' => $now - 300 * $d, 'last_used' => $now - 3 * $d ) );
ok( ! snt_sec_app_passwords_verdict( $busy, $now )['ripe'], 'an old password in active use is quiet' );
ok( ! snt_sec_app_passwords_verdict( array(), $now )['ripe'], 'none at all is quiet' );

// Admin 2FA coverage.
ok( snt_sec_admin_2fa_verdict( null )['ripe'], 'Two Factor inactive is ripe' );
$admins = array( 'juan' => array( 'TwoFactor_Provider_WebAuthn', 'Two_Factor_Backup_Codes' ) );
ok( ! snt_sec_admin_2fa_verdict( $admins )['ripe'], 'an admin with a passkey is quiet' );
ok( ! snt_sec_admin_2fa_verdict( array( 'juan' => array( 'Two_Factor_Totp' ) ) )['ripe'], 'an authenticator app is strong too' );
$w = snt_sec_admin_2fa_verdict( array( 'juan' => array( 'TwoFactor_Provider_WebAuthn' ), 'helper' => array() ) );
ok( $w['ripe'] && false !== strpos( $w['note'], 'helper' ) && false === strpos( $w['note'], 'juan' ), 'an admin with nothing enabled is named, the covered one is not' );
ok( snt_sec_admin_2fa_verdict( array( 'juan' => array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ) ) )['ripe'], 'email and backup codes alone are not enough' );

// Guard routes.
$p = array( 'site.test/door*', 'www.site.test/door*' );
$good = array( array( 'pattern' => 'site.test/door*', 'script' => 'sn-login-guard' ), array( 'pattern' => 'www.site.test/door*', 'script' => 'sn-login-guard' ), array( 'pattern' => 'site.test/_sn/login-guard/*', 'script' => 'sn-login-guard' ) );
$r = snt_sec_guard_routes_check( $good, $p, $now );
ok( 2 === $r['covered'] && 0 === $r['missing'] && 0 === $r['stale'] && '' === $r['error'], 'both door patterns point at the guard' );
ok( false === strpos( wp_json_encode_t( $r ), 'door' ), 'the stored result holds counts, never patterns' );
$r = snt_sec_guard_routes_check( array_slice( $good, 1 ), $p, $now );
ok( 1 === $r['missing'], 'a deleted route is counted missing' );
$moved = $good; $moved[0]['script'] = 'other-worker';
ok( 1 === snt_sec_guard_routes_check( $moved, $p, $now )['missing'], 'a route pointed at another worker is missing too' );
$old = array_merge( $good, array( array( 'pattern' => 'site.test/old-door*', 'script' => 'sn-login-guard' ) ) );
ok( 1 === snt_sec_guard_routes_check( $old, $p, $now )['stale'], 'a leftover route for a retired door is counted stale' );

$GLOBALS['__opt'][ SN_SEC_GUARD_ROUTES_OPT ] = snt_sec_guard_routes_check( $good, $p, $now );
ok( ! snt_watch_ripe_guard_routes( array(), $now + 3600 )['ripe'], 'quiet when both routes are in place' );
$GLOBALS['__opt'][ SN_SEC_GUARD_ROUTES_OPT ] = snt_sec_guard_routes_check( array(), $p, $now );
$w = snt_watch_ripe_guard_routes( array(), $now + 3600 );
ok( $w['ripe'] && false === strpos( $w['note'], 'door' ), 'ripe when routes are missing, without naming them' );
$GLOBALS['__opt'][ SN_SEC_GUARD_ROUTES_OPT ] = array( 'at' => $now, 'covered' => 0, 'missing' => 0, 'stale' => 0, 'error' => 'HTTP 403' );
ok( snt_watch_ripe_guard_routes( array(), $now + 3600 )['ripe'], 'ripe when Cloudflare could not be read (a blind check is not a clean one)' );
$GLOBALS['__opt'][ SN_SEC_GUARD_ROUTES_OPT ] = snt_sec_guard_routes_check( $good, $p, $now );
ok( snt_watch_ripe_guard_routes( array(), $now + 3 * $d )['ripe'], 'ripe when the check has not run for two days' );
unset( $GLOBALS['__opt'][ SN_SEC_GUARD_ROUTES_OPT ] );
ok( ! snt_watch_ripe_guard_routes( array(), $now )['ripe'], 'not ripe before the first run' );

$root = dirname( __DIR__ );
$watches = (string) file_get_contents( "$root/inc/watches.php" );
foreach ( array( 'app_passwords', 'admin_2fa', 'guard_routes' ) as $id ) {
	ok( false !== strpos( $watches, "'$id'" ), "watch $id is registered" );
}
ok( false !== strpos( (string) file_get_contents( "$root/inc/cron-dashboard.php" ), 'SN_SEC_GUARD_ROUTES_HOOK' ), 'the daily route check is in the cron registry' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/cron-lifecycle.php" ), 'SN_SEC_GUARD_ROUTES_HOOK' ), 'and cleared on deactivation' );
ok( false !== strpos( (string) file_get_contents( "$root/signal-and-noise-tools.php" ), 'inc/security-watches.php' ), 'the module is loaded' );

function wp_json_encode_t( $v ) { return json_encode( $v ); }
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

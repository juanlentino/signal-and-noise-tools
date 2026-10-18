<?php
/**
 * Standalone test: the audit log records account and site setting changes.
 *
 * New accounts, role changes, an account's email changing, the three
 * registration/admin-email/default-role options, plugin activation,
 * deactivation and deletion, and theme switches land in the existing audit
 * blob as a bounded, retention-pruned list. No IP address, no email address,
 * no password. A grant of administrator is marked as the most important kind.
 *
 * Run: php tests/audit-log-changes.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$GLOBALS['__options'] = array();
$GLOBALS['__hooks']   = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
function sn_setting( $k, $d = null ) { return $d; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__hooks'][ $hook ] = $cb; }
function wp_next_scheduled() { return true; }
function do_action() {}
function __( $s, $d = null ) { return $s; }

// Users: alice (admin, signed in), bob (editor, old), newbie (registered this request), ghost (no role, old).
$GLOBALS['__users'] = array(
	1 => (object) array( 'ID' => 1, 'user_login' => 'alice', 'user_email' => 'alice@example.test', 'roles' => array( 'administrator' ), 'user_registered' => '2020-01-01 00:00:00' ),
	2 => (object) array( 'ID' => 2, 'user_login' => 'bob', 'user_email' => 'bob-new@example.test', 'roles' => array( 'editor' ), 'user_registered' => '2021-01-01 00:00:00' ),
	3 => (object) array( 'ID' => 3, 'user_login' => 'newbie', 'user_email' => 'n@example.test', 'roles' => array( 'administrator' ), 'user_registered' => gmdate( 'Y-m-d H:i:s' ) ),
	4 => (object) array( 'ID' => 4, 'user_login' => 'ghost', 'user_email' => 'g@example.test', 'roles' => array(), 'user_registered' => '2019-01-01 00:00:00' ),
	5 => (object) array( 'ID' => 5, 'user_login' => 'sub', 'user_email' => 's@example.test', 'roles' => array( 'subscriber' ), 'user_registered' => gmdate( 'Y-m-d H:i:s' ) ),
);
$GLOBALS['__current'] = 1;
function get_userdata( $id ) { return $GLOBALS['__users'][ (int) $id ] ?? false; }
function wp_get_current_user() { return $GLOBALS['__users'][ $GLOBALS['__current'] ] ?? (object) array( 'ID' => 0, 'user_login' => '' ); }
$_SERVER['REMOTE_ADDR']  = '203.0.113.9';
$_SERVER['REQUEST_TIME'] = time();

require dirname( __DIR__ ) . '/inc/audit-log.php';

function fire( $hook, ...$args ) { return isset( $GLOBALS['__hooks'][ $hook ] ) ? call_user_func_array( $GLOBALS['__hooks'][ $hook ], $args ) : null; }
function last_change() { $c = snt_audit_get_blob()['changes'] ?? array(); $l = end( $c ); return is_array( $l ) ? $l + array( 'kind' => '', 'subject' => '', 'detail' => '', 'high' => null, 'actor' => null ) : array( 'kind' => '', 'subject' => '', 'detail' => '', 'high' => null, 'actor' => null ); }
function change_count() { return count( snt_audit_get_blob()['changes'] ?? array() ); }

echo "\nGroup: every hook is registered on a core primitive\n";
$hooks = array( 'user_register', 'set_user_role', 'add_user_role', 'remove_user_role', 'profile_update', 'update_option_users_can_register', 'update_option_default_role', 'update_option_admin_email', 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme' );
ok( array() === array_diff( $hooks, array_keys( $GLOBALS['__hooks'] ) ), 'registered: ' . implode( ',', array_intersect( $hooks, array_keys( $GLOBALS['__hooks'] ) ) ) );

echo "\nGroup: accounts and roles\n";
fire( 'set_user_role', 3, 'administrator', array() ); // core sets the role of a new account before user_register
ok( 0 === change_count(), 'a new account\'s first role is not logged twice (user_register carries it)' );
fire( 'user_register', 3, array() );
$c = last_change();
ok( 'user_created' === $c['kind'] && 'newbie' === $c['subject'] && 'administrator' === $c['detail'] && true === $c['high'] && 'alice' === $c['actor'], 'a new administrator account is logged, marked high, with the role and who did it' );
fire( 'user_register', 5, array() );
ok( false === last_change()['high'] && 'subscriber' === last_change()['detail'], 'a new subscriber is logged, not high' );
fire( 'set_user_role', 2, 'administrator', array( 'editor' ) );
$c = last_change();
ok( 'role_set' === $c['kind'] && 'bob' === $c['subject'] && 'editor → administrator' === $c['detail'] && true === $c['high'], 'editor to administrator is a role change, marked high' );
fire( 'set_user_role', 4, 'administrator', array() );
ok( 'role_set' === last_change()['kind'] && 'ghost' === last_change()['subject'] && true === last_change()['high'], 'an old account with no role made administrator is logged and high' );
fire( 'set_user_role', 2, 'author', array( 'editor' ) );
ok( false === last_change()['high'], 'editor to author is not high' );
fire( 'add_user_role', 2, 'administrator' );
ok( 'role_added' === last_change()['kind'] && true === last_change()['high'], 'adding administrator as a second role is high' );
fire( 'remove_user_role', 2, 'administrator' );
ok( 'role_removed' === last_change()['kind'] && 'administrator' === last_change()['detail'] && false === last_change()['high'], 'removing a role is logged, not high' );

echo "\nGroup: an account's email, never the address\n";
$n = change_count();
fire( 'profile_update', 2, (object) array( 'user_email' => 'bob-new@example.test' ), array() );
ok( $n === change_count(), 'a profile save with the same email logs nothing' );
fire( 'profile_update', 2, (object) array( 'user_email' => 'bob-old@example.test' ), array() );
ok( 'email_changed' === last_change()['kind'] && 'bob' === last_change()['subject'], 'a changed account email is logged against the account' );

echo "\nGroup: site settings\n";
fire( 'update_option_users_can_register', '0', '1' );
ok( 'registration_on' === last_change()['kind'], 'turning on open registration is logged' );
fire( 'update_option_users_can_register', '1', '0' );
ok( 'registration_off' === last_change()['kind'], 'turning it off is logged' );
fire( 'update_option_default_role', 'subscriber', 'administrator' );
ok( 'default_role' === last_change()['kind'] && 'subscriber → administrator' === last_change()['detail'] && true === last_change()['high'], 'a default role of administrator is high' );
fire( 'update_option_admin_email', 'old-admin@example.test', 'new-admin@example.test' );
ok( 'admin_email' === last_change()['kind'], 'a changed site admin email is logged' );

echo "\nGroup: plugins and themes\n";
fire( 'activated_plugin', 'akismet/akismet.php', false );
ok( 'plugin_activated' === last_change()['kind'] && 'akismet/akismet.php' === last_change()['subject'], 'a plugin activation is logged with its file' );
fire( 'deactivated_plugin', 'akismet/akismet.php', false );
ok( 'plugin_deactivated' === last_change()['kind'], 'a deactivation is logged' );
$n = change_count();
fire( 'deleted_plugin', 'akismet/akismet.php', false );
ok( $n === change_count(), 'a failed deletion logs nothing' );
fire( 'deleted_plugin', 'akismet/akismet.php', true );
ok( 'plugin_deleted' === last_change()['kind'], 'a deletion is logged' );
fire( 'switch_theme', 'Signal', null, (object) array( 'name' => 'Twenty' ) );
ok( 'theme_switched' === last_change()['kind'] && 'Signal' === last_change()['subject'] && 'from Twenty' === last_change()['detail'], 'a theme switch names the new theme and the old' );
$GLOBALS['__current'] = 99;
fire( 'activated_plugin', 'x/x.php', false );
ok( '' === last_change()['actor'], 'a change with no one signed in has no actor' );

echo "\nGroup: privacy\n";
$json = json_encode( snt_audit_get_blob() );
ok( false === strpos( $json, '203.0.113.9' ), 'no IP address is stored' );
ok( false === strpos( $json, '@example.test' ), 'no email address, old or new, is stored' );

echo "\nGroup: bounded, retention-pruned, read newest first\n";
for ( $i = 0; $i < 250 && function_exists( 'snt_audit_record_change_impl' ); $i++ ) {
	snt_audit_record_change_impl( 'plugin_activated', 'p' . $i . '/p.php' );
}
ok( defined( 'SN_AUDIT_CHANGES_CAP' ) && 200 === change_count(), 'the list keeps at most 200 rows' );
ok( 'p249/p.php' === last_change()['subject'], 'the newest row survives the cap' );
$blob = snt_audit_get_blob();
$blob['changes'] = array(
	array( 'ts' => time() - 100 * DAY_IN_SECONDS, 'kind' => 'plugin_activated', 'actor' => 'a', 'subject' => 'old', 'detail' => '', 'high' => false ),
	array( 'ts' => time() - 40 * DAY_IN_SECONDS, 'kind' => 'plugin_activated', 'actor' => 'a', 'subject' => 'mid', 'detail' => '', 'high' => false ),
	array( 'ts' => time() - 60, 'kind' => 'role_set', 'actor' => 'a', 'subject' => 'new', 'detail' => 'editor → administrator', 'high' => true ),
);
update_option( SN_AUDIT_OPTION, $blob );
if ( ! function_exists( 'snt_audit_get_changes_impl' ) ) { echo "\nResult: $pass passed, " . ( $fail + 5 ) . " failed.\n"; exit( 1 ); }
$rows = snt_audit_get_changes_impl( 30 );
ok( 1 === count( $rows ) && 'new' === $rows[0]['subject'] && '' !== $rows[0]['formatted'], 'the 30-day reader returns only the window, with a formatted time' );
ok( 2 === count( snt_audit_get_changes_impl( 90 ) ) && 'new' === snt_audit_get_changes_impl( 90 )[0]['subject'], 'newest first' );
$stats = snt_audit_prune_impl();
ok( 1 === ( $stats['change_rows_dropped'] ?? null ) && 2 === change_count(), 'prune drops changes older than the retention window and says how many' );
ok( 'role_change' !== snt_audit_change_label( 'role_set' ) && '' !== snt_audit_change_label( 'role_set' ), 'every kind has a label in words' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

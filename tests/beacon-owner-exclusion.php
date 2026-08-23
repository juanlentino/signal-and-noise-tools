<?php
/**
 * Unit tests for owner/role analytics exclusion (plugin v6.23.0).
 *
 * Mirrors Plausible's "exclude user roles" toggle. Covers: the pure
 * role-intersection predicate, the sn_beacon_enabled filter callback (the
 * front-end suppression that stops the pixel for logged-in owners), the
 * current-viewer status helper, the role-list + sanitization helpers, and the
 * admin-post save handler. No WordPress runtime — every WP/plugin dependency is
 * stubbed in-memory.
 *
 * @since plugin v6.23.0
 */

// SECURITY: CLI / WP-CLI only (mirrors sibling fixtures).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// --- WP + plugin stubs -------------------------------------------------------
$GLOBALS['__filters']       = array();
$GLOBALS['__logged_in']     = false;
$GLOBALS['__current_roles'] = array();
$GLOBALS['__settings']      = array( 'analytics' => array( 'exclude_roles' => array() ) );

function add_filter( $hook, $cb ) {
	$GLOBALS['__filters'][ $hook ][] = $cb;
	return true;
}
function is_user_logged_in() {
	return (bool) $GLOBALS['__logged_in'];
}
function wp_get_current_user() {
	return (object) array( 'roles' => $GLOBALS['__current_roles'] );
}
function sanitize_key( $k ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) );
}
function wp_unslash( $v ) {
	return $v;
}
function wp_roles() {
	return (object) array(
		'roles' => array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'author'        => array( 'name' => 'Author' ),
			'subscriber'    => array( 'name' => 'Subscriber' ),
		),
	);
}
function sn_setting( $path, $default = null ) {
	$cursor = $GLOBALS['__settings'];
	foreach ( explode( '.', $path ) as $k ) {
		if ( ! is_array( $cursor ) || ! array_key_exists( $k, $cursor ) ) {
			return $default;
		}
		$cursor = $cursor[ $k ];
	}
	return $cursor;
}
function sn_setting_update( $path, $value ) {
	$segs   = explode( '.', $path );
	$cursor =& $GLOBALS['__settings'];
	$last   = count( $segs ) - 1;
	foreach ( $segs as $i => $s ) {
		if ( $i === $last ) {
			$cursor[ $s ] = $value;
		} else {
			if ( ! isset( $cursor[ $s ] ) || ! is_array( $cursor[ $s ] ) ) {
				$cursor[ $s ] = array();
			}
			$cursor =& $cursor[ $s ];
		}
	}
	unset( $cursor );
	return true;
}

require __DIR__ . '/../inc/beacon-owner-exclusion.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $label\n";
	} else {
		$fail++;
		echo "FAIL: $label\n";
	}
}

// 1) Pure predicate: does any user role fall in the exclusion set?
ok( true === sn_beacon_owner_excluded( array( 'administrator' ), array( 'administrator' ) ), 'admin role in exclude set -> excluded' );
ok( false === sn_beacon_owner_excluded( array( 'subscriber' ), array( 'administrator' ) ), 'subscriber not in exclude set -> not excluded' );
ok( true === sn_beacon_owner_excluded( array( 'editor', 'author' ), array( 'author' ) ), 'any matching role -> excluded' );
ok( false === sn_beacon_owner_excluded( array( 'administrator' ), array() ), 'empty exclude set -> not excluded' );
ok( false === sn_beacon_owner_excluded( array(), array( 'administrator' ) ), 'user with no roles -> not excluded' );

// 2) The callback is registered on the theme-provided filter.
ok( in_array( 'sn_beacon_owner_exclusion_filter', $GLOBALS['__filters']['sn_beacon_enabled'] ?? array(), true ), 'callback registered on sn_beacon_enabled filter' );

// 3) Filter behaviour across login state / roles / prior value.
$GLOBALS['__settings']['analytics']['exclude_roles'] = array( 'administrator' );

$GLOBALS['__logged_in']     = false;
$GLOBALS['__current_roles'] = array( 'administrator' );
ok( true === sn_beacon_owner_exclusion_filter( true ), 'logged-out request -> beacon stays enabled (cannot identify owner)' );

$GLOBALS['__logged_in']     = true;
$GLOBALS['__current_roles'] = array( 'administrator' );
ok( false === sn_beacon_owner_exclusion_filter( true ), 'logged-in administrator -> beacon suppressed' );

$GLOBALS['__current_roles'] = array( 'subscriber' );
ok( true === sn_beacon_owner_exclusion_filter( true ), 'logged-in subscriber -> beacon stays enabled' );

$GLOBALS['__current_roles'] = array( 'administrator' );
ok( false === sn_beacon_owner_exclusion_filter( false ), 'already-disabled beacon stays disabled (never re-enables)' );

$GLOBALS['__settings']['analytics']['exclude_roles'] = array();
ok( true === sn_beacon_owner_exclusion_filter( true ), 'empty exclude set -> beacon stays enabled even for admin' );

// 4) Current-viewer status helper (drives the settings-card status line).
$GLOBALS['__settings']['analytics']['exclude_roles'] = array( 'administrator' );
$GLOBALS['__logged_in']     = true;
$GLOBALS['__current_roles'] = array( 'administrator' );
ok( true === sn_beacon_owner_current_user_excluded(), 'status: current admin viewer is excluded' );
$GLOBALS['__current_roles'] = array( 'editor' );
ok( false === sn_beacon_owner_current_user_excluded(), 'status: current editor viewer is not excluded' );
$GLOBALS['__logged_in'] = false;
ok( false === sn_beacon_owner_current_user_excluded(), 'status: logged-out viewer is not excluded' );

// 5) Role list + sanitization.
$roles = sn_beacon_excludable_roles();
ok( isset( $roles['administrator'] ) && 'Administrator' === $roles['administrator'], 'excludable roles map slug -> display name' );
ok( array( 'administrator', 'editor' ) === sn_beacon_sanitize_exclude_roles( array( 'administrator', 'editor', 'nonsense_role' ) ), 'sanitize drops unknown roles, preserves order' );
ok( array( 'administrator' ) === sn_beacon_sanitize_exclude_roles( array( 'administrator', 'administrator' ) ), 'sanitize de-dupes' );
ok( array() === sn_beacon_sanitize_exclude_roles( 'not-an-array' ), 'sanitize handles non-array input' );

// NOTE: the admin-post save handler (sn_handle_analytics_exclude_save) lives in
// inc/admin-post-actions/analytics.php with the other analytics handlers; its behaviour
// is covered in tests/admin-post-actions.php.

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

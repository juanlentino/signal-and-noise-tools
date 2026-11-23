<?php
/**
 * Fixture for the Settings › Accounts and connections suites
 * (tests/os-leaf-settings-accounts.php, the pane and the leaves its fields
 * left, and tests/os-leaf-settings-accounts-secrets.php, the credential form
 * field for field): the real keyring, IndexNow, Zenodo form and Discography
 * modules over the harness's option store, then both twins of the pane and of
 * the three Connections leaves that kept their readings. The pane's own files
 * are required only when they exist, so before the change the suites report
 * red instead of dying.
 *
 * Every credential value in these suites is an obviously fake string.
 *
 * @package SignalNoiseTools
 */

function current_user_can( $cap ) { return $GLOBALS['__can'] ?? true; } // bound before the harness's guarded stub.
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
require_once __DIR__ . '/os-leaf-harness.php';
require_once __DIR__ . '/whole-forms.php';

$GLOBALS['__settings'] = array();
$GLOBALS['__written']  = array();
$GLOBALS['__zl']       = array( 'enabled' => true, 'rows' => array(), 'verdict' => array( 'status' => 'ok', 'detail' => 'creates a draft', 'at' => 1 ) );
function sn_setting( $path, $default = null ) { return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default; }
function sn_setting_update( $key, $value ) { $GLOBALS['__settings'][ $key ] = $value; $GLOBALS['__written'][ $key ] = $value; return true; }
function update_option( $k, $v, $a = null ) { $changed = ! array_key_exists( $k, $GLOBALS['__options'] ) || $GLOBALS['__options'][ $k ] !== $v; $GLOBALS['__options'][ $k ] = $v; $GLOBALS['__written'][ $k ] = $v; return $changed; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); $GLOBALS['__written'][ $k ] = null; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
function sn_mask_secret( $v ) { $v = (string) $v; return '' === $v ? '' : ( strlen( $v ) <= 8 ? '••••••••' : '••••' . substr( $v, -4 ) ); }
function sn_zenodo_is_enabled() { return $GLOBALS['__zl']['enabled']; }
function sn_zenodo_token_verdict() { return $GLOBALS['__zl']['verdict'] ?? null; }
function sn_zenodo_ledger() { return $GLOBALS['__zl']['rows']; }
if ( ! defined( 'SN_ZENODO_PASS_MAX' ) ) { define( 'SN_ZENODO_PASS_MAX', 5 ); }
if ( getenv( 'SNT_MUSO_LOCK' ) ) { define( 'SN_MUSO_PROFILE_ID', 'locked-profile-fake' ); }
// Search Console (2026-11, the pane's fourth box): the real credential module
// and save handlers; the sync side is stubbed, the pane never reads it.
const SNT_GSC_SYNC_HOOK = 'sn_gsc_sync_daily';
function snt_gsc_data() { return null; }
function snt_gsc_sync_last_status() { return null; }
function wp_next_scheduled( $hook ) { return false; }
function snt_gsc_list_sites( $force = false ) { return (array) ( $GLOBALS['__gsc_sites'] ?? array() ); }
function is_wp_error( $v ) { return false; }
function wp_list_pluck( $list, $field ) { return array_column( (array) $list, $field ); }

$snt_accounts_files = array(
	'inc/admin-legacy-redirect.php', 'inc/admin-tabs.php', 'inc/admin-shell.php', 'inc/admin-render-sections.php',
	'inc/keyring.php', 'inc/keyring-verify.php', 'inc/keyring-admin.php', 'inc/admin-post-actions/keyring.php',
	'inc/indexnow.php', 'inc/admin-forms/indexnow.php', 'inc/admin-post-actions/indexnow.php',
	'inc/zenodo-client.php', 'inc/provenance-doi-line.php', 'inc/admin-forms/zenodo.php', 'inc/admin-post-actions/zenodo.php',
	'inc/discography-store.php', 'inc/muso-api.php', 'inc/spotify-api.php', 'inc/music-featured.php', 'inc/admin-forms/music.php', 'inc/admin-post-actions/music.php',
	'inc/search-console-credential.php', 'inc/search-console-admin.php', 'inc/admin-post-actions/gsc.php',
	'inc/admin-flash-messages.php',
	'apps/sn-dashboard/parts/leaves/connections-indexnow.php', 'apps/sn-dashboard/parts/leaves/connections-zenodo.php', 'apps/sn-dashboard/parts/leaves/connections-music.php',
);
foreach ( $snt_accounts_files as $snt_accounts_file ) {
	require_once SNT_PATH . $snt_accounts_file;
}
foreach ( array( 'inc/admin-forms/settings-accounts.php', 'inc/admin-forms/settings-accounts-forms.php', 'apps/sn-dashboard/parts/leaves/settings-accounts-parts.php', 'apps/sn-dashboard/parts/leaves/monitoring-search-console.php', 'apps/sn-dashboard/parts/leaves/settings-accounts.php' ) as $snt_accounts_file ) {
	if ( is_file( SNT_PATH . $snt_accounts_file ) ) { // Absent before the change: the assertions go red, the suite still reports.
		require_once SNT_PATH . $snt_accounts_file;
	}
}

const ACC_PANE  = 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=accounts';
const ACC_LABEL = 'Settings › Accounts and connections';

/** The classic pane, or '' before the change. */
function acc_classic() { return function_exists( 'sn_admin_render_settings_accounts_section' ) ? snt_leaf_classic_html( 'sn_admin_render_settings_accounts_section' ) : ''; }
/** The native pane, or '' before the change. */
function acc_kit() { return snt_leaf_paint( 'settings', 'accounts' ); }
/** Every form (or os-form) on a twin that posts $action. */
function acc_forms( $html, $tag, $action ) {
	preg_match_all( '#<' . $tag . '\b.*?</' . $tag . '>#s', (string) $html, $m );
	return array_values( array_filter( $m[0], static fn( $form ) => false !== strpos( $form, 'sn_' . $action ) ) );
}
/** The one form that posts $action, '' when none or several do. */
function acc_form( $html, $tag, $action ) {
	$forms = acc_forms( $html, $tag, $action );
	return 1 === count( $forms ) ? $forms[0] : '';
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

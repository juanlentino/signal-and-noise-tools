<?php
/**
 * Settings › Accounts and connections, both twins: the native pane
 * (apps/sn-dashboard/parts/leaves/settings-accounts.php) and the classic
 * section (inc/admin-forms/settings-accounts.php). The fifth pane of the
 * Settings tab.
 *
 * Settings › Accounts and connections moved here WHOLE (the list of accounts with their
 * state is what an Accounts pane holds), so sn_admin_subtab_moves() redirects
 * it. IndexNow's switch, Zenodo's two settings forms and the Muso.AI profile
 * ID moved as FIELDS: those three leaves keep their readings and actions and
 * one line saying where the setting went, so they have no moves line.
 *
 * This suite: the registry, the redirect, the pane's boxes and whole forms,
 * where a save lands, what each handler writes on a plain save. The boxes
 * are tests/os-leaf-settings-accounts-boxes.php; the credential form, field
 * for field, is tests/os-leaf-settings-accounts-secrets.php; each leaf a
 * field left has its own suite (tests/os-leaf-connections-*.php).
 *
 * Run: php tests/os-leaf-settings-accounts.php
 */
require_once __DIR__ . '/lib/settings-accounts-fixture.php';

$GLOBALS['__settings'] = array( 'indexnow.enabled' => true );
$classic = acc_classic();
$kit     = acc_kit();

// ── 1) The registry: the fifth pane (the sixth, Analytics, has its own suite); Connections lost Credentials and kept the rest.
$by_tab = array();
foreach ( sn_admin_top_tabs() as $t ) { $by_tab[ $t['tab'] ] = $t; }
$panes = (array) ( $by_tab['settings']['sub_tabs'] ?? array() );
ok( array( 'general', 'notifications', 'security', 'ai', 'front-end', 'accounts' ) === array_slice( array_keys( $panes ), 0, 6 ) && 'Accounts and connections' === ( $panes['accounts']['label'] ?? '' ) && 'sn_admin_render_settings_accounts_section' === ( $panes['accounts']['render'] ?? '' ) && ! empty( $panes['accounts']['wide'] ), 'accounts ("Accounts and connections") is the sixth pane of Settings (the fifth until General took first place), after front-end, wide: it paints its own boxes' );
ok( array( 'cloudflare', 'cloudways', 'webhooks', 'indexnow', 'zenodo', 'music', 'cron', 'scheduled-content' ) === array_keys( $by_tab['connections']['sub_tabs'] ), 'Connections keeps eight leaves: Credentials left whole; IndexNow, Zenodo and Discography stayed' );
ok( false !== strpos( (string) $by_tab['settings']['subtitle'], 'Accounts and connections:' ) && false === strpos( (string) $by_tab['connections']['subtitle'], 'the credentials in one place' ) && false !== strpos( (string) $by_tab['connections']['subtitle'], 'are in Settings' ), 'the subtitles follow: Settings names the pane, Connections no longer holds the credentials and says where they are' );
ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/accounts'] ) && ! isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/credentials'] ) && ! is_file( SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-keyring.php' ), 'native: the painter is registered under settings/accounts, none under connections/credentials, and the old leaf file is gone' );
$here = array( 'tab' => 'settings', 'sub' => 'accounts', 'anchor' => null );
ok( null === sn_admin_canonical_destination( 'settings', 'accounts' ) && $here === sn_admin_post_redirect_target( 'settings', 'accounts' ), 'classic: settings/accounts is canonical and a save posted from it returns to it' );
ok( $here === sn_admin_canonical_destination( 'connections', 'credentials' ) && $here === sn_admin_post_redirect_target( 'connections', 'credentials' ), 'the old Credentials address redirects, GET and in-flight POST alike' );
foreach ( array( 'indexnow', 'zenodo', 'music' ) as $kept ) {
	ok( null === sn_admin_canonical_destination( 'connections', $kept ), "Connections › $kept does not redirect: the leaf stayed" );
}

// ── 2) The pane: the settings of four accounts, in whole forms, on both twins.
// 2026-11: the Search Console box brought the credential field (the property
// picker joins it once a credential parses and a test listed the properties:
// tests/os-leaf-settings-accounts-search-console.php).
$fields = array( 'key_id', 'key_value', 'indexnow_enabled', 'zenodo_env', 'zenodo_ledger_doi', 'zenodo_doi_line_enabled', 'zenodo_doi_line_label', 'zenodo_doi_line_floor', 'sn_muso_profile', 'sn_gsc_credential' );
sort( $fields );
$actions = array( 'gsc_credential_save', 'indexnow_save', 'keyring_generate', 'keyring_save', 'keyring_verify', 'music_save', 'zenodo_doi_line_save', 'zenodo_env_save' );
ok( '' !== $kit && $fields === snt_leaf_names( $kit ), 'native: exactly the ten field names: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( '' !== $classic && $fields === snt_leaf_names( $classic ), 'classic: exactly the ten field names: ' . implode( ',', snt_leaf_names( $classic ) ) );
ok( $actions === snt_leaf_actions( $kit ) && $actions === snt_leaf_actions( $classic ), 'the eight existing actions, unchanged, on both twins; no deposit, sync, ping, regenerate or connection test came along: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, '<os-disclosure' ), 'native: no wp-admin markup, and no fold: a setting on a settings pane is open' );
$guards = array( 'keyring_save' => SNT_KEYRING_SAVE_FIELDS, 'indexnow_save' => SNT_INDEXNOW_SAVE_FIELDS, 'zenodo_env_save' => SNT_ZENODO_ENV_SAVE_FIELDS, 'zenodo_doi_line_save' => SNT_ZENODO_DOI_LINE_SAVE_FIELDS );
foreach ( array( 'form' => $classic, 'os-form' => $kit ) as $tag => $html ) {
	foreach ( $guards as $action => $whole ) {
		ok( 1 === snt_whole_forms( $html, $tag, $action, $whole ), "<$tag>: one form posts $action and it carries every field the handler reads: " . implode( ', ', $whole ) );
	}
	ok( 1 === count( acc_forms( $html, $tag, 'music_save' ) ) && array( 'sn_muso_profile' ) === snt_leaf_names( acc_form( $html, $tag, 'music_save' ) ), "<$tag>: one form posts music_save and it carries the profile ID alone, never the featured release" );
	ok( false === strpos( $html, 'name="tab"' ) && false === strpos( $html, 'name="sub"' ), "<$tag>: no form carries a hidden tab or sub, so a save lands on the pane it was posted from and not on the old leaf" );
}
ok( -1 === snt_whole_forms( '<form>sn_zenodo_env_save<select name="zenodo_env"></select></form>', 'form', 'zenodo_env_save', SNT_ZENODO_ENV_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_zenodo_doi_line_save<input name="zenodo_doi_line_label"></form>', 'form', 'zenodo_doi_line_save', SNT_ZENODO_DOI_LINE_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_indexnow_save</form>', 'form', 'indexnow_save', SNT_INDEXNOW_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_keyring_save<input name="key_value"></form>', 'form', 'keyring_save', SNT_KEYRING_SAVE_FIELDS ), 'the guard can fail: an environment form without the DOI, a downloads form with one field, an IndexNow form without the switch and a credential form without its picker are each -1' );

// ── 3) Why each handler's fields share a form: what a plain save writes.
$GLOBALS['__options'][ SN_ZENODO_ENV_OPT ] = 'production'; $GLOBALS['__options'][ SN_ZENODO_LEDGER_DOI_OPT ] = '10.5281/zenodo.1234567';
sn_handle_zenodo_env_save( array( 'zenodo_ledger_doi' => '10.5281/zenodo.1234567' ) );
ok( 'sandbox' === $GLOBALS['__options'][ SN_ZENODO_ENV_OPT ], 'zenodo_env_save without the environment sets it back to sandbox' );
sn_handle_zenodo_env_save( array( 'zenodo_env' => 'production' ) );
ok( 'production' === $GLOBALS['__options'][ SN_ZENODO_ENV_OPT ] && '' === $GLOBALS['__options'][ SN_ZENODO_LEDGER_DOI_OPT ], 'and without the ledger DOI it clears the DOI: the two stay in one form' );
$GLOBALS['__options'][ SNT_PROV_DOI_OPT ] = array( 'enabled' => true, 'label' => 'Open record', 'floor' => 3 );
sn_handle_zenodo_doi_line_save( array( 'zenodo_doi_line_label' => 'Open record' ) );
ok( array( 'enabled' => false, 'label' => 'Open record', 'floor' => 0 ) === $GLOBALS['__options'][ SNT_PROV_DOI_OPT ], 'zenodo_doi_line_save writes all three every time: a form with the label alone hides the line and zeroes the floor' );
sn_handle_indexnow_save( array() );
ok( false === $GLOBALS['__settings']['indexnow.enabled'], 'indexnow_save without the switch turns IndexNow off: the one field, written on every save' );
// music_save is the other kind: a field it is not sent is left alone, which is
// why the profile ID could leave the featured release behind on Discography.
$GLOBALS['__options'][ SN_MUSO_PROFILE_OPT ] = 'profile-fake-1'; $GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ] = array( 'type' => 'album', 'id' => 'fakealbumid0000000000aa' );
$featured_before = $GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ];
ok( 'music_saved' === sn_handle_music_save( array( 'sn_muso_profile' => 'profile-fake-2' ) ) && 'profile-fake-2' === $GLOBALS['__options'][ SN_MUSO_PROFILE_OPT ] && $featured_before === $GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ], 'music_save with the profile ID alone saves it and leaves the featured release as it was' );
ok( 'music_saved' === sn_handle_music_save( array( 'sn_music_featured' => 'https://open.spotify.com/album/fakealbumid0000000000bb' ) ) && 'profile-fake-2' === $GLOBALS['__options'][ SN_MUSO_PROFILE_OPT ], 'and with the featured release alone it leaves the profile ID as it was' );
ok( 'keyring_unknown_row' === sn_handle_keyring_save( array( 'key_value' => 'fake-value-for-test-0000' ) ) && 'keyring_unchanged' === sn_handle_keyring_save( array( 'key_id' => 'cf_token' ) ) && ! isset( $GLOBALS['__options']['sn_cf_api_token'] ), 'keyring_save resets nothing by omission: no picker is refused, no value changes nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Settings › Accounts and connections: the Search Console box, field for
 * field, and where the credential may appear. The box took two forms from
 * Measurement › Search Console; this suite pins that nothing about them
 * changed in the move (the strings below were captured from the leaf on both
 * twins before it) and that the move printed nothing of the credential, and
 * nothing of its identity card, anywhere new.
 *
 * The key file here is an obviously fake one: it parses, and signs nothing.
 *
 * Run: php tests/os-leaf-settings-accounts-search-console.php
 */
require_once __DIR__ . '/lib/settings-accounts-fixture.php';

const GSC_FAKE_KEY   = 'FAKE-PRIVATE-KEY-FOR-TEST-ZQXJ';
const GSC_FAKE_EMAIL = 'fake-sa@fake-project-for-test.iam.gserviceaccount.com';
const GSC_FAKE_KEYID = 'fakekeyid-for-test-0000';
$fake = json_encode( array( 'type' => 'service_account', 'project_id' => 'fake-project-for-test', 'private_key_id' => GSC_FAKE_KEYID, 'private_key' => "-----BEGIN PRIVATE KEY-----\n" . GSC_FAKE_KEY . "\n-----END PRIVATE KEY-----\n", 'client_email' => GSC_FAKE_EMAIL ) );

// ── 1) Nothing stored: the field as the leaf had it, empty, on both twins.
$classic = acc_classic();
$kit     = acc_kit();
ok( '<form method="post" action="https://example.test/wp-admin/admin-post.php?_wpnonce=nonce-sn_gsc_credential_save" class="sn-gsc-settings"><p><label for="sn_gsc_credential"><strong>Service-account JSON</strong></label><br><textarea id="sn_gsc_credential" name="sn_gsc_credential" rows="8" class="large-text code" spellcheck="false" autocomplete="off" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }"></textarea></p><p><button type="submit" name="action" value="sn_gsc_credential_save" formaction="https://example.test/wp-admin/admin-post.php?_wpnonce=nonce-sn_gsc_credential_save" class="button button-primary">Save credential</button></p></form>' === acc_form( $classic, 'form', 'gsc_credential_save' ), 'classic: the credential form is the leaf\'s: a textarea (rows 8, spellcheck off, autocomplete off, no content), its label tied to it, the nonce on the action and on the button' );
ok( '<os-form class="snt-form" os-action="post" submit-label="Save credential" show-reset="false" columns="auto"><os-field-row label="Service-account JSON"><os-textarea name="sn_gsc_credential" value="" rows="8" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }"></os-textarea></os-field-row><input type="hidden" name="action" value="sn_gsc_credential_save"><input type="hidden" name="_wpnonce" value="nonce-sn_gsc_credential_save"></os-form>' === acc_form( $kit, 'os-form', 'gsc_credential_save' ), 'native: the credential form is byte for byte the one the leaf painted: a kit textarea, empty, its label on its row, action and nonce hidden' );
ok( false === strpos( $classic . $kit, 'sn_gsc_property' ) && false === strpos( $classic . $kit, 'Test connection' ) && false === strpos( $classic . $kit, 'sn_gsc_test' ) && false === strpos( $classic . $kit, 'sn_gsc_sync' ), 'no picker before a credential parses, and neither Test connection nor Sync now is on the pane' );
$GLOBALS['__can'] = false;
ok( false === strpos( acc_classic() . acc_kit(), 'sn_gsc_credential' ), 'an account that cannot manage options is painted no credential field' );
$GLOBALS['__can'] = true;

// ── 2) A save: the handler is as it was, and the pane shows nothing of the key.
ok( 'gsc_credential_saved' === sn_handle_gsc_credential_save( array( 'sn_gsc_credential' => $fake ) ) && $fake === snt_gsc_credential_raw() && null !== snt_gsc_credential_identity(), 'the fake key file is accepted and stored' );
ok( 'gsc_credential_unchanged' === sn_handle_gsc_credential_save( array( 'sn_gsc_credential' => '' ) ) && 'gsc_credential_unchanged' === sn_handle_gsc_credential_save( array() ) && $fake === snt_gsc_credential_raw(), 'an empty field, or none, keeps what is stored: leave empty to keep' );
ok( 'gsc_credential_not_json' === sn_handle_gsc_credential_save( array( 'sn_gsc_credential' => 'not a key file' ) ) && $fake === snt_gsc_credential_raw(), 'a paste that is not a key file is refused and the stored one kept' );
$identity = snt_gsc_credential_identity();
foreach ( array( 'classic' => acc_classic(), 'native' => acc_kit() ) as $twin => $html ) {
	$decoded = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
	ok( false === strpos( $decoded, GSC_FAKE_KEY ) && false === strpos( $decoded, 'BEGIN PRIVATE KEY' ) && false === strpos( $decoded, 'FAKE-PRIVATE' ) && false === strpos( $decoded, 'ZQXJ' ), "$twin: no private key, whole or in part, is on the pane" );
	ok( false === strpos( $decoded, GSC_FAKE_EMAIL ) && false === strpos( $decoded, 'fake-sa@' ) && false === strpos( $decoded, 'fake-project-for-test' ) && false === strpos( $decoded, GSC_FAKE_KEYID ) && false === strpos( $decoded, (string) $identity['key_fingerprint'] ) && false === strpos( $decoded, 'sha256:' ), "$twin: nothing of the identity card (client email, project, key ID, fingerprint) is on the pane: it stays on Measurement › Search Console" );
	ok( false === strpos( $decoded, (string) strlen( $fake ) . ' characters' ) && false === strpos( $decoded, (string) strlen( $fake ) . ' bytes' ) && false === stripos( $decoded, md5( $fake ) ) && false === stripos( $decoded, hash( 'sha256', $fake ) ) && false === stripos( $decoded, substr( hash( 'sha256', $fake ), 0, 12 ) ), "$twin: no length and no hash of the credential" );
	ok( 1 === preg_match( '#<(?:textarea|os-textarea)[^>]*name="sn_gsc_credential"[^>]*placeholder="Paste a fresh key file to replace; type clear to remove; leave empty to keep the current one"[^>]*>(?:</textarea>|</os-textarea>)#', $html ) && 0 === preg_match( '#name="sn_gsc_credential"[^>]*value="[^"]#', $html ), "$twin: the field is still empty, and its placeholder says how to replace, remove or keep" );
}
$line = snt_leaf_classic_html( static function () { snt_settings_accounts_moved_line( 'gsc' ); } ) . \SignalNoise\OpenStationHost\Dashboard\Leaves\accounts_moved_html( 'gsc', 'monitoring' );
ok( 2 === substr_count( $line, 'The service-account JSON and the property picker moved to' ) && false === strpos( $line, 'fake' ) && false === strpos( $line, 'value=' ) && false !== strpos( $line, 'data-snt-tab="settings" data-snt-sub="accounts"' ), 'the line Measurement › Search Console keeps names the two settings and no value, and opens the pane' );

// ── 3) The picker: gated on a parsed credential and a listed test, one form.
$picker_c = acc_classic();
$picker_k = acc_kit();
ok( 0 === count( acc_forms( $picker_c, 'form', 'gsc_property_save' ) ) && 0 === count( acc_forms( $picker_k, 'os-form', 'gsc_property_save' ) ) && false !== strpos( $picker_c, 'press Test connection on <a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=search-console">Measurement › Search Console</a>.' ) && false !== strpos( $picker_k, 'data-snt-tab="monitoring" data-snt-sub="search-console"' ), 'a credential, no test run: no picker, and a line saying which screen lists the properties, with a link' );
$GLOBALS['__gsc_sites']                      = array( array( 'siteUrl' => 'sc-domain:fake.example.test', 'permissionLevel' => 'siteFullUser' ) );
$GLOBALS['__transients']['snt_gsc_last_test'] = array( 'ok' => true, 'sites' => $GLOBALS['__gsc_sites'], 'when' => 1 );
ok( '<form method="post" action="https://example.test/wp-admin/admin-post.php?_wpnonce=nonce-sn_gsc_property_save" class="sn-gsc-settings"><p><label for="sn_gsc_property"><strong>Read search data for</strong></label><br><select id="sn_gsc_property" name="sn_gsc_property"><option value="">Choose a property</option><option value="sc-domain:fake.example.test">sc-domain:fake.example.test (siteFullUser)</option></select></p><p><button type="submit" name="action" value="sn_gsc_property_save" formaction="https://example.test/wp-admin/admin-post.php?_wpnonce=nonce-sn_gsc_property_save" class="button button-primary">Use this property</button></p></form>' === acc_form( acc_classic(), 'form', 'gsc_property_save' ), 'classic: the picker is the leaf\'s select, its label tied to it, with its own nonce; Sync now no longer shares its form' );
ok( '<os-form class="snt-form" os-action="post" submit-label="Use this property" show-reset="false" columns="auto"><os-field-row label="Read search data for"><os-select name="sn_gsc_property" value=""><os-option value="">Choose a property</os-option><os-option value="sc-domain:fake.example.test">sc-domain:fake.example.test (siteFullUser)</os-option></os-select></os-field-row><input type="hidden" name="action" value="sn_gsc_property_save"><input type="hidden" name="_wpnonce" value="nonce-sn_gsc_property_save"></os-form>' === acc_form( acc_kit(), 'os-form', 'gsc_property_save' ), 'native: the picker is byte for byte the form the leaf painted' );
ok( 'gsc_property_unchanged' === sn_handle_gsc_property_save( array() ) && 'gsc_property_unknown' === sn_handle_gsc_property_save( array( 'sn_gsc_property' => 'sc-domain:not-granted.example.test' ) ) && ! isset( $GLOBALS['__settings']['search_console.property'] ) && 'gsc_property_saved' === sn_handle_gsc_property_save( array( 'sn_gsc_property' => 'sc-domain:fake.example.test' ) ) && 'sc-domain:fake.example.test' === $GLOBALS['__settings']['search_console.property'], 'gsc_property_save resets nothing by omission: no pick changes nothing, a property the account cannot read is refused, a listed one is saved' );
foreach ( array( 'form' => acc_classic(), 'os-form' => acc_kit() ) as $tag => $html ) {
	ok( 1 === snt_whole_forms( $html, $tag, 'gsc_credential_save', array( 'sn_gsc_credential' ) ) && 1 === snt_whole_forms( $html, $tag, 'gsc_property_save', array( 'sn_gsc_property' ) ) && false === strpos( $html, 'name="tab"' ) && false === strpos( $html, 'name="sub"' ), "<$tag>: one form per action, each with its one field, and no hidden tab or sub: a save lands back on the pane" );
}

// ── 4) The notices a save answers with are fixed sentences that name screens, not values.
$said = sn_admin_flash_messages();
$all  = '';
foreach ( $said as $code => $notice ) {
	$all .= ( 0 === strpos( (string) $code, 'gsc_credential_' ) || 0 === strpos( (string) $code, 'gsc_property_' ) ) ? (string) $notice[1] : '';
}
ok( '' !== $all && false === strpos( $all, '%' ) && false === strpos( $all, 'fake' ), 'no credential or property notice has a placeholder a value could ride in' );
ok( 'Search Console credential saved. The private key is never displayed; Measurement › Search Console shows which key is stored.' === $said['gsc_credential_saved'][1] && 'Search Console property selected. Run a sync on Measurement › Search Console to pull the first window.' === $said['gsc_property_saved'][1] && 'Connection works. Pick one of the properties this service account can read, in Settings › Accounts and connections.' === $said['gsc_test_ok'][1], 'the three notices that named a box beside the form name the screen that holds it now' );
ok( 'gsc_credential_cleared' === sn_handle_gsc_credential_save( array( 'sn_gsc_credential' => 'clear' ) ) && '' === snt_gsc_credential_raw() && false === strpos( acc_classic() . acc_kit(), 'sn_gsc_property' ), 'typing clear removes the credential, and the picker goes with it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

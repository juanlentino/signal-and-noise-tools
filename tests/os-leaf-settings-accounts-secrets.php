<?php
/**
 * Settings › Accounts and connections: the credential form, field for field,
 * and where a credential's value may appear. The pane took Connections ›
 * Credentials whole; this suite pins that nothing about the form changed in
 * the move (the strings below were captured from the leaf on both twins
 * before it) and that the move printed no value anywhere new.
 *
 * Every value here is an obviously fake string.
 *
 * Run: php tests/os-leaf-settings-accounts-secrets.php
 */
require_once __DIR__ . '/lib/settings-accounts-fixture.php';

const ACC_FAKE_SAVED    = 'fake-credential-for-test-ZQXJ';
const ACC_FAKE_CONSTANT = 'fake-constant-for-test-WVKP';
$GLOBALS['__options']['sn_cf_api_token'] = ACC_FAKE_SAVED;
define( 'SN_BETTERSTACK_API_TOKEN', ACC_FAKE_CONSTANT );
$classic = acc_classic();
$kit     = acc_kit();
$save_c  = acc_form( $classic, 'form', 'keyring_save' );
$save_k  = acc_form( $kit, 'os-form', 'keyring_save' );
$gen_c   = acc_form( $classic, 'form', 'keyring_generate' );
$gen_k   = acc_form( $kit, 'os-form', 'keyring_generate' );

// ── 1) The Value field: the type, the autocomplete, never prefilled.
ok( 1 === substr_count( $classic, '<input type="text" id="key_value" name="key_value" class="sn-mono" autocomplete="off" placeholder="Paste the value">' ) && false !== strpos( $save_c, '<label class="sn-field-label" for="key_value">Value</label><input type="text" id="key_value" name="key_value" class="sn-mono" autocomplete="off" placeholder="Paste the value">' ), 'classic: the Value field is the input the leaf had: type text, autocomplete off, its label tied to it, no value attribute' );
ok( 1 === substr_count( $kit, '<os-text-field name="key_value" type="text" value="" placeholder="Paste the value">' ) && 1 === preg_match( '#<os-field-row label="Value" hint="[^"]*"><os-text-field name="key_value" type="text" value="" placeholder="Paste the value">#', $save_k ), 'native: the Value field is the kit field the leaf had: type text, empty, its label and hint on its row' );
foreach ( array( $save_c, $save_k ) as $form ) {
	$plain = html_entity_decode( strip_tags( str_replace( 'hint="', '>', $form ) ), ENT_QUOTES, 'UTF-8' );
	ok( false !== strpos( $plain, 'Paste to set. Type clear to remove. On a worker row, type site to derive it from the site secret. Rows locked in wp-config.php are not listed.' ) || false !== strpos( $plain, 'Paste to set. Type "clear" to remove. On a worker row, type "site" to derive it from the site secret. Rows locked in wp-config.php are not listed.' ), 'the hint still says the three verbs and that locked rows are not listed' );
}
ok( 'keyring_unchanged' === sn_handle_keyring_save( array( 'key_id' => 'cf_token', 'key_value' => '' ) ) && ACC_FAKE_SAVED === $GLOBALS['__options']['sn_cf_api_token'] && 'keyring_unchanged' === sn_handle_keyring_save( array( 'key_id' => 'cf_token', 'key_value' => '••••ZQXJ' ) ) && ACC_FAKE_SAVED === $GLOBALS['__options']['sn_cf_api_token'], 'an empty Value, or the obscured value pasted back, keeps what is stored: the handler is as it was' );

// ── 2) The guards: nonce, action, confirmation, the picker's locked rows.
ok( 0 === strpos( $save_c, '<form method="post" action="https://example.test/wp-admin/admin-post.php?_wpnonce=nonce-sn_keyring_save" class="sn-card">' ) && false !== strpos( $save_c, '<label class="sn-field-label" for="key_id">Credential</label><select id="key_id" name="key_id">' ) && false !== strpos( $save_c, 'name="action" value="sn_keyring_save" formaction="https://example.test/wp-admin/admin-post.php?_wpnonce=nonce-sn_keyring_save" class="button button-primary">Save</button>' ) && false !== strpos( $save_c, 'name="action" value="sn_keyring_verify" formaction="https://example.test/wp-admin/admin-post.php?_wpnonce=nonce-sn_keyring_verify" class="button">Verify all</button>' ), 'classic: the form posts with the nonce minted for keyring_save, and each button carries its own action and nonce' );
ok( 0 === strpos( $save_k, '<os-form class="snt-form" os-action="post" submit-label="Save" show-reset="false" columns="auto"><os-field-row label="Credential"><os-select name="key_id" value="" placeholder="Choose a credential">' ) && false !== strpos( $save_k, '<input type="hidden" name="action" value="sn_keyring_save"><input type="hidden" name="_wpnonce" value="nonce-sn_keyring_save"></os-form>' ) && 1 === substr_count( $kit, 'os-arg-action="sn_keyring_verify"' ) && 1 === substr_count( $kit, 'os-arg-nonce="nonce-sn_keyring_verify"' ), 'native: the form carries its action and nonce as hidden fields; Verify all is a one-click write with its own nonce' );
ok( false !== strpos( $gen_c, '<input type="hidden" name="_wpnonce" value="nonce-sn_keyring_generate">' ) && false !== strpos( $gen_c, '<button type="submit" name="action" value="sn_keyring_generate" class="button" onclick="return confirm(\'Generate a new key? Set it on the Workers next.\');">Generate</button>' ) && false !== strpos( $gen_k, 'os-confirm="Generate a new key? Set it on the Workers next."' ) && false !== strpos( $gen_k, '<input type="hidden" name="action" value="sn_keyring_generate"><input type="hidden" name="_wpnonce" value="nonce-sn_keyring_generate">' ), 'Generate still asks first, in the same words, with its own nonce, on both twins' );
ok( false === strpos( $classic, '<option value="betterstack_token"' ) && false === strpos( $kit, '<os-option value="betterstack_token"' ) && false !== strpos( $classic, 'wp-config (SN_BETTERSTACK_API_TOKEN)' ) && false === strpos( $classic, '<option value="cloudways_api_key"' ) && false === strpos( $kit, '<os-option value="cloudways_api_key"' ) && false !== strpos( $classic, '<option value="cf_token">' ) && false !== strpos( $kit, '<os-option value="cf_token">' ), 'a row a constant sets, and a wp-config-only row, are not offered in the picker on either twin; a saved row is' );
ok( $save_c === acc_form( snt_leaf_classic_html( 'sn_admin_render_credentials_section' ), 'form', 'keyring_save' ) && $save_k === acc_form( \SignalNoise\OpenStationHost\Dashboard\Leaves\accounts_credentials_html(), 'os-form', 'keyring_save' ), 'the pane paints the keyring\'s own form (the same function), not a copy of it' );

// ── 3) A value is printed nowhere new: obscured, once, in its ledger cell.
foreach ( array( 'classic' => $classic, 'native' => $kit ) as $twin => $html ) {
	$decoded = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
	ok( false === strpos( $decoded, ACC_FAKE_SAVED ) && false === strpos( $decoded, ACC_FAKE_CONSTANT ) && false === strpos( $decoded, 'fake-credential' ) && false === strpos( $decoded, 'fake-constant' ), "$twin: neither raw value, nor its leading part, is on the pane" );
	ok( 1 === substr_count( $decoded, 'ZQXJ' ) && 1 === substr_count( $decoded, '••••ZQXJ' ) && 1 === substr_count( $decoded, 'WVKP' ) && 1 === substr_count( $decoded, '••••WVKP' ), "$twin: each obscured value appears once, in its ledger cell, and nowhere else (no hint, no attribute, no pointer)" );
	ok( false === strpos( $decoded, (string) strlen( ACC_FAKE_SAVED ) . ' characters' ) && false === stripos( $decoded, md5( ACC_FAKE_SAVED ) ) && false === stripos( $decoded, hash( 'sha256', ACC_FAKE_SAVED ) ), "$twin: no length and no hash of a value" );
}
ok( '<td class="sn-mono">••••ZQXJ</td>' === ( preg_match( '#<td class="sn-mono">[^<]*ZQXJ</td>#', $classic, $cell ) ? $cell[0] : '' ), 'classic: that one place is the Value cell of the Cloudflare table' );
foreach ( array( 'indexnow', 'zenodo', 'muso' ) as $from ) {
	$line = snt_leaf_classic_html( static function () use ( $from ) { snt_settings_accounts_moved_line( $from ); } ) . \SignalNoise\OpenStationHost\Dashboard\Leaves\accounts_moved_html( $from );
	ok( false === strpos( $line, 'ZQXJ' ) && false === strpos( $line, '••••' ) && false === strpos( $line, 'value=' ) && 2 === substr_count( $line, 'moved to' ), "the line the $from leaf keeps names settings and no value" );
}

// ── 4) The notices a save answers with are fixed sentences.
$GLOBALS['__options'] = array();
$flash = sn_handle_keyring_save( array( 'key_id' => 'cf_token', 'key_value' => ACC_FAKE_SAVED ) );
$said  = sn_admin_flash_messages();
ok( 'keyring_saved' === $flash && 'Credentials saved. Press Verify all to check them.' === ( $said[ $flash ][1] ?? '' ), 'a save answers with a code, and the code\'s sentence is the one it was' );
$all = '';
foreach ( $said as $code => $notice ) {
	$all .= 0 === strpos( (string) $code, 'keyring_' ) ? (string) $notice[1] : '';
}
ok( '' !== $all && false === strpos( $all, '%' ) && false === strpos( $all, 'ZQXJ' ) && false === strpos( $all, '••••' ), 'no keyring notice has a placeholder a value could ride in' );
ok( false === strpos( acc_classic() . acc_kit(), ACC_FAKE_SAVED ) && 2 === substr_count( html_entity_decode( acc_classic() . acc_kit(), ENT_QUOTES, 'UTF-8' ), '••••ZQXJ' ), 'after the save the pane shows the new value obscured, once per twin' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Settings › Accounts and connections: the pane's layout and the two boxes
 * whose forms came from Connections › Zenodo and Connections › IndexNow, on
 * both twins. Every assertion the leaves' suites made about those forms
 * (tests/os-leaf-connections-zenodo.php, tests/os-leaf-connections-indexnow.php)
 * is made here about the boxes: the fields, the words, the bounds, the
 * defaults. The Muso.AI box is tests/os-leaf-connections-music.php, which
 * sets the constant that locks it.
 *
 * Run: php tests/os-leaf-settings-accounts-boxes.php
 */
require_once __DIR__ . '/lib/settings-accounts-fixture.php';

$GLOBALS['__settings'] = array( 'indexnow.enabled' => true );
$classic = acc_classic();
$kit     = acc_kit();
$ctext   = html_entity_decode( strip_tags( $classic ), ENT_QUOTES, 'UTF-8' );
$ktext   = html_entity_decode( $kit, ENT_QUOTES, 'UTF-8' );

// ── 1) Headed boxes by account, Credentials first.
$order = array( strpos( $classic, '<h2 class="sn-section-h">Credentials</h2><div class="sn-shell">' ), strpos( $classic, '</aside></div><div class="sn-2up"><div class="sn-fieldset"><h2 class="sn-fieldset-h">Zenodo</h2>' ), strpos( $classic, '<h2 class="sn-fieldset-h">Search engines (IndexNow)</h2>' ), strpos( $classic, '<h2 class="sn-fieldset-h">Muso.AI</h2>' ) );
$sorted = $order; sort( $sorted );
ok( 0 === $order[0] && ! in_array( false, $order, true ) && $order === $sorted, 'classic: Credentials heads the pane over the keyring\'s shell, then one row of boxes: Zenodo, Search engines (IndexNow), Muso.AI: ' . implode( ' < ', $order ) );
$order = array( strpos( $kit, '<h3 class="snt-h">Credentials</h3>' . snt_leaf_row( 2, 290, 24 ) ), strpos( $kit, snt_leaf_row( 3, 290, 18 ) . '<os-section heading="Zenodo" stack>' ), strpos( $kit, '<os-section heading="Search engines (IndexNow)" stack>' ), strpos( $kit, '<os-section heading="Muso.AI" stack>' ) );
$sorted = $order; sort( $sorted );
ok( 0 === $order[0] && ! in_array( false, $order, true ) && $order === $sorted, 'native: the same four, in the same order: ' . implode( ' < ', $order ) );
foreach ( array( 'Deposit the next batch', 'No signed documents yet.', 'Submit recent content now', 'Regenerate key', 'Key file', 'Sync now', 'Featured release', 'sn_music_featured', 'Release notes' ) as $reading ) {
	ok( false === strpos( $kit, $reading ) && false === strpos( $classic, $reading ), "no reading or action came along from a leaf that stayed: \"$reading\" is on neither twin" );
}
ok( false === strpos( $kit . $classic, "\xe2\x80\x94" ) && false === strpos( $classic, '&mdash;' ), 'no em dash on either twin' );
$GLOBALS['__can'] = false;
ok( '' === acc_classic() && false !== strpos( acc_kit(), 'cannot manage options' ) && false === strpos( acc_kit(), '<os-form' ), 'without manage_options the pane paints nothing classic and an empty state natively, as every leaf it draws from did' );
$GLOBALS['__can'] = true;

// ── 2) Zenodo: the environment form.
$env_c = acc_form( $classic, 'form', 'zenodo_env_save' );
$env_k = acc_form( $kit, 'os-form', 'zenodo_env_save' );
ok( false !== strpos( $env_c, '<input type="hidden" name="_wpnonce" value="nonce-sn_zenodo_env_save"><input type="hidden" name="action" value="sn_zenodo_env_save">' ) && false !== strpos( $env_k, '<input type="hidden" name="action" value="sn_zenodo_env_save"><input type="hidden" name="_wpnonce" value="nonce-sn_zenodo_env_save">' ), 'the environment form keeps its action and the nonce minted for it, on both twins' );
ok( false !== strpos( $env_c, '<label for="sn_zenodo_env">Environment</label><select id="sn_zenodo_env" name="zenodo_env"><option value="sandbox" selected="selected">Sandbox (sandbox.zenodo.org): test DOIs, never public</option><option value="production">Production (zenodo.org)</option></select>' ), 'classic: the environment select with its label, its id and the two environments, sandbox by default' );
ok( false !== strpos( $env_k, '<os-select' ) && false !== strpos( $env_k, 'name="zenodo_env"' ) && false !== strpos( $env_k, '<os-option value="production">' ) && false !== strpos( $env_k, 'label="Environment"' ), 'native: the environment is a kit select with the two environments' );
ok( false !== strpos( $env_c, '<label for="sn_zenodo_ledger_doi">Ledger concept DOI</label><input type="text" id="sn_zenodo_ledger_doi" name="zenodo_ledger_doi" value="" placeholder="10.5281/zenodo.NNNNNNN" autocomplete="off">' ) && 1 === preg_match( '#<os-field-row label="Ledger concept DOI"[^>]*><os-text-field name="zenodo_ledger_doi" type="text" value="" placeholder="10.5281/zenodo.NNNNNNN">#', $env_k ), 'the ledger DOI field keeps its label, id, placeholder and type on both twins' );
ok( false !== strpos( $ktext, 'Each environment reads its own token, set under Credentials on this pane.' ) && false !== strpos( $ctext, 'Each environment reads its own token, set under Credentials on this pane.' ) && false !== strpos( $kit, 'Empty until the first snapshot release' ) && false !== strpos( $classic, 'Empty until the first snapshot release' ), 'the two field hints paint in the same words on both twins; the token hint names the box on this pane' );
ok( false !== strpos( $env_c, '<button type="submit" class="button button-primary">Save environment</button>' ) && false !== strpos( $env_k, 'submit-label="Save environment"' ), 'Save environment keeps its label' );
$GLOBALS['__options'][ SN_ZENODO_ENV_OPT ] = 'production'; $GLOBALS['__options'][ SN_ZENODO_LEDGER_DOI_OPT ] = '10.5281/zenodo.1234567';
ok( false !== strpos( acc_classic(), '<option value="production" selected="selected">' ) && false !== strpos( acc_classic(), 'name="zenodo_ledger_doi" value="10.5281/zenodo.1234567"' ) && 1 === preg_match( '#<os-select name="zenodo_env" value="production"#', acc_kit() ) && false !== strpos( acc_kit(), 'name="zenodo_ledger_doi" type="text" value="10.5281/zenodo.1234567"' ), 'the stored environment and ledger DOI paint in the fields, on both twins' );

// ── 3) Zenodo: the downloads line on /provenance.
$line_c = acc_form( $classic, 'form', 'zenodo_doi_line_save' );
$line_k = acc_form( $kit, 'os-form', 'zenodo_doi_line_save' );
foreach ( SNT_ZENODO_DOI_LINE_SAVE_FIELDS as $name ) {
	ok( in_array( $name, snt_leaf_names( $line_k ), true ) && in_array( $name, snt_leaf_names( $line_c ), true ), "the downloads line field $name is on both twins" );
}
foreach ( sn_zenodo_doi_line_copy() as $key => $words ) {
	ok( false !== strpos( html_entity_decode( $line_k, ENT_QUOTES, 'UTF-8' ), $words ) && false !== strpos( html_entity_decode( strip_tags( $line_c ), ENT_QUOTES, 'UTF-8' ), $words ), "both twins say the $key words" );
}
ok( false !== strpos( $line_k, '<os-checkbox-label name="zenodo_doi_line_enabled" value="1" checked' ) && false !== strpos( $line_c, '<input type="checkbox" id="sn_zenodo_doi_line_enabled" name="zenodo_doi_line_enabled" value="1" checked="checked">' ), 'defaults: the line is on, on both twins' );
ok( false !== strpos( $line_c, '<label for="sn_zenodo_doi_line_label">Lead label (optional)</label><input type="text" id="sn_zenodo_doi_line_label" name="zenodo_doi_line_label" value="" maxlength="80" autocomplete="off">' ) && false !== strpos( $line_c, '<label for="sn_zenodo_doi_line_floor">Floor for the 30-day figure</label><input type="number" id="sn_zenodo_doi_line_floor" name="zenodo_doi_line_floor" value="0" min="0" step="1">' ), 'classic: the label and floor fields keep their labels, ids and bounds (80 characters; 0 and up, whole numbers)' );
ok( 1 === preg_match( '#<os-text-field name="zenodo_doi_line_label"[^>]*maxlength="80"#', $line_k ) && 1 === preg_match( '#name="zenodo_doi_line_floor"[^>]*min="0"#', $line_k ), 'native: the same bounds' );
ok( false !== strpos( $line_k, 'Now hidden: no recent snapshot with the site split yet.' ) && false !== strpos( $line_c, 'Now hidden: no recent snapshot with the site split yet.' ), 'with no snapshot both twins say the line is hidden and why, inside its form' );
ok( false !== strpos( $line_c, '<input type="hidden" name="_wpnonce" value="nonce-sn_zenodo_doi_line_save"><input type="hidden" name="action" value="sn_zenodo_doi_line_save">' ) && false !== strpos( $line_k, '<input type="hidden" name="_wpnonce" value="nonce-sn_zenodo_doi_line_save">' ), 'the downloads line form keeps its action and nonce' );
ok( false === strpos( $env_c . $env_k, 'zenodo_doi_line' ) && false === strpos( $line_c . $line_k, 'zenodo_env' ), 'the two Zenodo forms are siblings, neither inside the other' );

// ── 4) Search engines (IndexNow): the one switch.
$in_c = acc_form( $classic, 'form', 'indexnow_save' );
$in_k = acc_form( $kit, 'os-form', 'indexnow_save' );
ok( '<form method="post" action="https://example.test/wp-admin/admin-post.php" class="sn-fieldset"><input type="hidden" name="_wpnonce" value="nonce-sn_indexnow_save"><input type="hidden" name="action" value="sn_indexnow_save"><h2 class="sn-fieldset-h">Search engines (IndexNow)</h2><div class="sn-field"><label><input type="checkbox" name="indexnow_enabled" value="1" checked="checked"> Notify search engines when content changes</label></div><div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save IndexNow settings</button></div></form>' === $in_c, 'classic: the form IndexNow had, field for field, under the box heading and without the hidden tab and sub' );
ok( '<os-form class="snt-form" os-action="post" submit-label="Save IndexNow settings" show-reset="false" columns="auto"><os-checkbox-label name="indexnow_enabled" value="1" checked label="Notify search engines when content changes"></os-checkbox-label><input type="hidden" name="action" value="sn_indexnow_save"><input type="hidden" name="_wpnonce" value="nonce-sn_indexnow_save"></os-form>' === $in_k, 'native: the form IndexNow had, field for field, without the hidden tab and sub' );
$GLOBALS['__settings'] = array( 'indexnow.enabled' => false );
ok( false === strpos( acc_form( acc_kit(), 'os-form', 'indexnow_save' ), ' checked' ) && false === strpos( acc_form( acc_classic(), 'form', 'indexnow_save' ), 'checked=' ), 'off: the switch paints unchecked on both twins' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

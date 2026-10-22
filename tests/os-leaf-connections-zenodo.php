<?php
/**
 * Connections › Zenodo (15.11.0): the kit leaf paints the same fields and
 * actions as the classic leaf, the ledger as the house table, the rail.
 *
 * Run: php tests/os-leaf-connections-zenodo.php
 */
function current_user_can( $cap ) { return $GLOBALS['__can'] ?? true; }
// Bound before the harness's guarded stub so the last-pass transient can be driven.
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
require_once __DIR__ . '/lib/os-leaf-harness.php';
$GLOBALS['__zl'] = array( 'env' => 'sandbox', 'enabled' => true, 'rows' => array(), 'verdict' => array( 'status' => 'ok', 'detail' => 'creates a draft', 'at' => 1 ) );
function sn_zenodo_env() { return $GLOBALS['__zl']['env']; }
function sn_zenodo_is_enabled() { return $GLOBALS['__zl']['enabled']; }
function sn_zenodo_token_verdict() { return $GLOBALS['__zl']['verdict'] ?? null; }
function sn_zenodo_ledger() { return $GLOBALS['__zl']['rows']; }
if ( ! defined( 'SN_ZENODO_PASS_MAX' ) ) { define( 'SN_ZENODO_PASS_MAX', 5 ); }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b, $echo = true ) { return $a === $b ? ' selected="selected"' : ''; } }
require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/provenance-doi-line.php';
require SNT_PATH . 'inc/admin-forms/zenodo.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-zenodo.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/zenodo'] ), 'the painter is registered under connections/zenodo' );
$GLOBALS['__zl']['rows'] = array(
	array( 'id' => 7, 'title' => 'Two kinds of provenance', 'kind' => 'note', 'state' => 'minted', 'doi' => '10.5281/zenodo.42', 'env' => 'production', 'at' => '2026-09-18T01:00:00+00:00', 'error' => '' ),
	array( 'id' => 9, 'title' => 'The pen is not the notary', 'kind' => 'note', 'state' => 'ready', 'doi' => '', 'env' => '', 'at' => '', 'error' => 'publish: down' ),
	array( 'id' => 11, 'title' => 'Provenance Over Detection', 'kind' => 'page', 'state' => 'anchor-pending', 'doi' => '', 'env' => '', 'at' => '', 'error' => '' ),
);
$GLOBALS['__transients']['sn_zenodo_last_batch'] = array( 'attempted' => 2, 'published' => 1, 'failed' => 1 );
$classic = snt_leaf_classic_html( 'sn_admin_render_zenodo_section' );
$kit     = snt_leaf_paint( 'connections', 'zenodo' );
ok( '' !== $kit, 'the kit leaf paints' );
// 2026-10-12 audit: the deposit mints DOIs (permanent on production) and asked
// nothing; it asks now, in the same words on both surfaces, naming the
// environment. The field hints used `help`, which the kit never paints.
ok( 1 === preg_match( '/<os-button[^>]*os-confirm="([^"]*)"[^>]*>Deposit the next batch</', $kit, $m ) && false !== strpos( $m[1], 'Zenodo sandbox' ) && false !== strpos( $classic, 'data-snt-confirm="' . $m[1] . '"' ), 'Deposit asks first on both surfaces and names the sandbox while it is the environment' );
$GLOBALS['__zl']['env'] = 'production';
$prod = snt_leaf_paint( 'connections', 'zenodo' );
ok( false !== strpos( $prod, 'permanent public DOI' ), 'on production the question says each document gets a permanent public DOI' );
$GLOBALS['__zl']['env'] = 'sandbox';
ok( false !== strpos( $kit, 'reads its own token from Connections › Credentials' ) && false !== strpos( $kit, 'Empty until the first snapshot release' ), 'the two field hints paint, in the classic page\'s words' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'zenodo_deposit_batch', 'zenodo_doi_line_save', 'zenodo_env_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the three actions match the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
// 2026-10-21: the DOI downloads line on /provenance, a Setup fold here and a fieldset there, the same words.
foreach ( array( 'zenodo_doi_line_enabled', 'zenodo_doi_line_label', 'zenodo_doi_line_floor' ) as $__n ) {
	ok( in_array( $__n, snt_leaf_names( $kit ), true ) && in_array( $__n, snt_leaf_names( $classic ), true ), "the downloads line field $__n is on both surfaces" );
}
$__classic_text = html_entity_decode( strip_tags( $classic ), ENT_QUOTES, 'UTF-8' );
foreach ( sn_zenodo_doi_line_copy() as $__k => $__line ) {
	ok( false !== strpos( html_entity_decode( $kit, ENT_QUOTES, 'UTF-8' ), $__line ) && false !== strpos( $__classic_text, $__line ), "both surfaces say the $__k words" );
}
ok( false !== strpos( $kit, '<os-checkbox-label name="zenodo_doi_line_enabled" value="1" checked' ) && false !== strpos( $classic, 'name="zenodo_doi_line_enabled" value="1" checked="checked"' ), 'defaults: the line is on, on both surfaces' );
ok( false !== strpos( $kit, 'Now hidden: no recent snapshot with the site split yet.' ) && false !== strpos( $__classic_text, 'Now hidden: no recent snapshot with the site split yet.' ), 'with no snapshot both surfaces say the line is hidden and why' );
ok( 1 !== preg_match( '/\x{2014}|\x{2013}/u', implode( ' ', sn_zenodo_doi_line_copy() ) ), 'no em or en dash in the new words' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-select' ) && false !== strpos( $kit, 'name="zenodo_env"' ) && false !== strpos( $kit, '<os-option value="production">' ), 'the environment is a kit select with the two environments' );
ok( false !== strpos( $kit, 'os-arg-action="sn_zenodo_deposit_batch"' ) && false !== strpos( $kit, '>Deposit the next batch</os-button>' ), 'Deposit the next batch is a one-click write' );
ok( 1 === preg_match( '/<os-table[^>]*os-prop-columns=/', $kit ) && false !== strpos( $kit, '&quot;key&quot;:&quot;doi&quot;' ) && false !== strpos( $kit, '10.5281/zenodo.42' ) && false !== strpos( $kit, 'publish: down' ), 'the ledger is the house table with a DOI column, the minted DOI and the failed row\'s error' );
ok( false !== strpos( $kit, 'Anchor pending' ) && false !== strpos( $kit, 'Sandbox' ) && false !== strpos( $kit, '1 minted of 3 documents.' ), 'the rail names the environment and the minted count; states are labelled' );
ok( false !== strpos( $kit, '1 published, 1 failed, of 2 attempted' ) && false !== strpos( $classic, '1 published, 1 failed, of 2 attempted' ), 'the last pass is reported, in the same words on both surfaces' );
ok( false !== strpos( $kit, 'The papers stay with SSRN' ) && false !== strpos( $kit, 'never reach a public surface' ), 'the prose says what is deposited and what a sandbox DOI is' );
// 2026-10-12 audit: the ledger was a 58-row wall of "Minted" with no sentence,
// the DOI plain text, raw ISO times and an always-empty error column.
ok( false !== strpos( $kit, '<p class="snt-prose">1 of 3 minted; 2 not yet: 1 Ready, 1 Anchor pending.</p>' ) && false !== strpos( $classic, '1 of 3 minted; 2 not yet: 1 Ready, 1 Anchor pending.' ), 'the ledger leads with one finding from its rows, on both surfaces' );
preg_match( '/os-prop-data="([^"]*)"/', $kit, $dm );
$zdata = json_decode( html_entity_decode( $dm[1] ?? '', ENT_QUOTES, 'UTF-8' ), true );
ok( is_array( $zdata ) && 'The pen is not the notary' === $zdata[0]['title']['text'] && 'Two kinds of provenance' === $zdata[2]['title']['text'], 'the rows still waiting come first, the minted after' );
ok( false !== strpos( $kit, 'href="https://doi.org/10.5281%2Fzenodo.42"' ) && false !== strpos( $kit, 'os-arg-url="https://example.test/wp-admin/post.php?post=7&amp;action=edit"' ), 'a minted DOI opens doi.org in a new tab; the document opens its editor in a window' );
ok( 'September 18, 2026 1:00 am' === ( $zdata[2]['at']['text'] ?? $zdata[2]['at'] ) && 'not deposited' === ( $zdata[0]['at']['text'] ?? $zdata[0]['at'] ) && false === strpos( $kit, '2026-09-18T01:00:00' ) && false !== strpos( $classic, 'September 18, 2026 1:00 am' ) && false === strpos( $classic, '2026-09-18T01:00:00' ), 'Deposited reads in the site\'s format on both surfaces, never the raw ISO stamp; a row not deposited says so' );
ok( false !== strpos( $kit, '&quot;key&quot;:&quot;error&quot;' ), 'a row with an error keeps the Last error column' );
// Live check (2026-10-12): DOIs broke mid-number in the 149px column.
ok( false !== strpos( $kit, '<span class="sn-nowrap"><a ' ) && 1 === preg_match( '/\.sn-nowrap\s*\{\s*white-space:\s*nowrap;/', (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' ) ), 'a DOI reads whole: its cell does not wrap' );
// Re-measure (2026-10-12): the capped ledger scrolled inside a 440px box,
// hiding rows 9 and 10; wrapped titles centered; "6:33 pm" broke.
ok( 1 === preg_match( '/<os-table[^>]*class="snt-table-full"/', $kit ), 'the ledger shows every row it lists, no inner scroller' );
ok( false !== strpos( $kit, '<span class="snt-zenodo-title"><os-button' ) && 1 === preg_match( '/\.snt-zenodo-title os-button::part\( button \)\s*\{[^}]*justify-content:\s*flex-start;[^}]*text-align:\s*start;/', (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' ) ), 'a wrapped document title is left-aligned' );
ok( 1 === preg_match( '#<span class="sn-nowrap">September 18, 2026 1:00 am</span>#', $kit ), 'a deposit time stays on one line' );
$GLOBALS['__zl']['rows'] = array();
for ( $i = 1; $i <= 13; $i++ ) {
	$GLOBALS['__zl']['rows'][] = array( 'id' => 100 + $i, 'title' => 'Note ' . $i, 'kind' => 'note', 'state' => 13 === $i ? 'ready' : 'minted', 'doi' => 13 === $i ? '' : '10.5281/zenodo.' . $i, 'env' => 'production', 'at' => 13 === $i ? '' : '2026-09-18T01:00:00+00:00', 'error' => '' );
}
$big = snt_leaf_paint( 'connections', 'zenodo' );
snt_leaf_preview( 'connections-zenodo', $big );
preg_match( '/os-prop-data="([^"]*)"/', $big, $bm );
$bdata = json_decode( html_entity_decode( $bm[1] ?? '', ENT_QUOTES, 'UTF-8' ), true );
ok( is_array( $bdata ) && 10 === count( $bdata ) && 'Note 13' === $bdata[0]['title']['text'] && false !== strpos( $big, '+3 more; the list is capped, not complete.' ) && false !== strpos( $big, '12 of 13 minted; 1 not yet: 1 Ready.' ), 'thirteen documents: the waiting one first, ten rows, "+3 more"' );
ok( false === strpos( $big, '&quot;key&quot;:&quot;error&quot;' ), 'no row has an error: no Last error column' );
$GLOBALS['__zl']['rows'] = array_fill( 0, 3, array( 'id' => 5, 'title' => 'N', 'kind' => 'note', 'state' => 'minted', 'doi' => '10.5281/zenodo.5', 'env' => 'production', 'at' => '2026-09-18T01:00:00+00:00', 'error' => '' ) );
ok( false !== strpos( snt_leaf_paint( 'connections', 'zenodo' ), '3 of 3 minted; none waiting.' ), 'everything minted: "none waiting"' );
$GLOBALS['__zl']['rows'] = array(
	array( 'id' => 7, 'title' => 'Two kinds of provenance', 'kind' => 'note', 'state' => 'minted', 'doi' => '10.5281/zenodo.42', 'env' => 'production', 'at' => '2026-09-18T01:00:00+00:00', 'error' => '' ),
	array( 'id' => 9, 'title' => 'The pen is not the notary', 'kind' => 'note', 'state' => 'ready', 'doi' => '', 'env' => '', 'at' => '', 'error' => 'publish: down' ),
	array( 'id' => 11, 'title' => 'Provenance Over Detection', 'kind' => 'page', 'state' => 'anchor-pending', 'doi' => '', 'env' => '', 'at' => '', 'error' => '' ),
);
// The hourly pass records itself too, with its time (only the button did).
$GLOBALS['__transients']['sn_zenodo_last_batch'] = array( 'attempted' => 0, 'published' => 0, 'failed' => 0, 'at' => time() - 3600 );
ok( false !== strpos( snt_leaf_paint( 'connections', 'zenodo' ), '1 hour ago: nothing waiting' ) && false !== strpos( snt_leaf_classic_html( 'sn_admin_render_zenodo_section' ), '1 hour ago: nothing waiting' ), 'a recorded pass says how long ago and what it did, on both surfaces' );
$pass_src = (string) file_get_contents( SNT_PATH . 'inc/zenodo-records.php' );
ok( 1 === preg_match( '/function sn_zenodo_backfill_pass\(\).*?set_transient\( \'sn_zenodo_last_batch\', \$out \+ array\( \'at\' => time\(\) \)/s', $pass_src ) && false === strpos( (string) file_get_contents( SNT_PATH . 'inc/admin-post-actions/zenodo.php' ), "set_transient( 'sn_zenodo_last_batch'" ), 'the pass itself records the last pass (hourly and manual alike); the button no longer does' );
$GLOBALS['__transients']['sn_zenodo_last_batch'] = array( 'attempted' => 2, 'published' => 1, 'failed' => 1 );
// 2026-10-16 (leaf anatomy, docs/kit-leaf-anatomy.md): the rail is a Status
// card leading the leaf (one status line, a problem's notice, the counts,
// Deposit, the intro as How this works) beside one Setup card whose
// Environment fold says the environment and ledger DOI (one row, since the
// live audit of 2026-10-16), then the ledger at full width.
ok( 0 === strpos( $kit, snt_leaf_row() . '<os-section heading="Status" stack role="region" aria-label="Zenodo status"><div class="snt-status-line"><os-cluster gap="8"><os-badge tone="success" title="1 minted of 3 documents.">On</os-badge><os-chip>Sandbox</os-chip></os-cluster></div><p class="snt-hint">1 minted of 3 documents.</p>' ), 'the leaf leads with the Status card: one status line (the token\'s verdict, the environment); an ok verdict paints no notice, its sentence a hint' );
ok( false === strpos( $kit, '<os-row' ) && false === strpos( $kit, 'col="4"' ) && false === strpos( $kit, '<os-notice' ), 'no rail and no notice while the token is verified' );
ok( 1 === preg_match( '#<os-disclosure heading="How this works" hint="[^"]+"[^>]*><os-stack gap="12"><p class="snt-prose">Every signed document#', $kit ) && 1 === substr_count( $kit, 'The papers stay with SSRN' ), 'the intro is the Status card\'s How this works fold, painted once' );
$order = array( strpos( $kit, 'class="snt-status-line"' ), strpos( $kit, 'os-arg-action="sn_zenodo_deposit_batch"' ), strpos( $kit, 'heading="How this works"' ), strpos( $kit, 'heading="Setup"' ), strpos( $kit, '</os-grid>' ), strpos( $kit, 'heading="Ledger"' ) );
$sorted = $order;
sort( $sorted );
ok( ! in_array( false, $order, true ) && $order === $sorted, 'the order: status line, Deposit, How this works, Setup (one row), then the ledger: ' . implode( ' < ', $order ) );
ok( 1 === substr_count( $kit, snt_leaf_row() ) && 1 === preg_match( '#</os-section><os-section heading="Setup" description="[^"]*" stack>.*?</os-section></os-grid><os-section heading="Ledger"#s', $kit ), 'live audit 2026-10-16: the Status card and the Setup card share the first row; the ledger follows it at full width' );
ok( 1 === preg_match( '#<os-section heading="Setup" description="[^"]*" stack><os-disclosure heading="Environment" hint="Sandbox · ledger DOI not set"[^>]*data-snt-open="0"><os-stack gap="12"><os-form[^>]*submit-label="Save environment"#', $kit ), 'one Setup card: the Environment form is a fold whose summary is the environment and the ledger DOI, closed while a token is set' );
// 16.2.2: the tile reads the keyring's verdict. A stored token the keyring REFUSED is not On.
$GLOBALS['__zl']['verdict'] = array( 'status' => 'refused', 'detail' => 'Zenodo sandbox refused to create a draft (HTTP 403: Permission denied.).', 'at' => 1 );
$kit = snt_leaf_paint( 'connections', 'zenodo' );
ok( false !== strpos( $kit, '>Refused</os-badge>' ) && false !== strpos( $kit, 'tone="danger"' ) && false !== strpos( $kit, 'HTTP 403' ) && false === strpos( $kit, '>On</os-badge>' ), 'a refused token: the tile says Refused in the danger tone with the keyring\'s detail, never On (the 16.1.x tile said On beside a refused row)' );
$GLOBALS['__zl']['verdict'] = null;
$kit = snt_leaf_paint( 'connections', 'zenodo' );
ok( false !== strpos( $kit, '>Unverified</os-badge>' ) && false !== strpos( $kit, 'Verify all has not run' ), 'a token never verified: Unverified, and the body says what to run' );
$GLOBALS['__zl']['verdict'] = array( 'status' => 'ok', 'detail' => 'creates a draft', 'at' => 1 );
$GLOBALS['__zl']['enabled'] = false;
$kit = snt_leaf_paint( 'connections', 'zenodo' );
ok( false !== strpos( $kit, '<b>No token</b>' ) && false !== strpos( $kit, 'disabled' ), 'no token: the rail says so and the deposit button is disabled' );
ok( false !== strpos( $kit, '<os-badge tone="warning" title="Add the sandbox token under Credentials.">Off</os-badge><os-chip>No token</os-chip>' ) && 1 === preg_match( '#<os-disclosure heading="Environment" hint="Sandbox · ledger DOI not set"[^>]*\sopen[\s=>]#', $kit ), 'no token: the status line says Off and No token, the notice stays open, and the Environment fold opens itself' );
$GLOBALS['__zl']['rows'] = array();
$kit = snt_leaf_paint( 'connections', 'zenodo' );
ok( false !== strpos( $kit, 'No signed documents yet.' ), 'an empty ledger is honest' );
$GLOBALS['__can'] = false;
ok( false !== strpos( snt_leaf_paint( 'connections', 'zenodo' ), 'cannot manage options' ), 'the capability gate holds' );
$GLOBALS['__can'] = true;

// 2026-10-12 audit: with no pass on record, (array) false read as a pass of
// "0 published, 0 failed, of 0".
unset( $GLOBALS['__transients']['sn_zenodo_last_batch'] );
$__nopass = snt_leaf_paint( 'connections', 'zenodo' );
ok( false === strpos( $__nopass, 'Last pass' ) && false === strpos( $__nopass, '0 published, 0 failed, of 0' ), 'no pass on record paints no Last pass row' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

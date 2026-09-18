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
$GLOBALS['__zl'] = array( 'env' => 'sandbox', 'enabled' => true, 'rows' => array() );
function sn_zenodo_env() { return $GLOBALS['__zl']['env']; }
function sn_zenodo_is_enabled() { return $GLOBALS['__zl']['enabled']; }
function sn_zenodo_ledger() { return $GLOBALS['__zl']['rows']; }
if ( ! defined( 'SN_ZENODO_PASS_MAX' ) ) { define( 'SN_ZENODO_PASS_MAX', 5 ); }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b, $echo = true ) { return $a === $b ? ' selected="selected"' : ''; } }
require SNT_PATH . 'inc/admin-shell.php';
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
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'zenodo_deposit_batch', 'zenodo_env_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the two actions match the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-select' ) && false !== strpos( $kit, 'name="zenodo_env"' ) && false !== strpos( $kit, '<os-option value="production">' ), 'the environment is a kit select with the two environments' );
ok( false !== strpos( $kit, 'os-arg-action="zenodo_deposit_batch"' ) && false !== strpos( $kit, '>Deposit the next batch</os-button>' ), 'Deposit the next batch is a one-click write' );
ok( 1 === preg_match( '/<os-table[^>]*os-prop-columns=/', $kit ) && false !== strpos( $kit, '&quot;key&quot;:&quot;doi&quot;' ) && false !== strpos( $kit, '10.5281/zenodo.42' ) && false !== strpos( $kit, 'publish: down' ), 'the ledger is the house table with a DOI column, the minted DOI and the failed row\'s error' );
ok( false !== strpos( $kit, 'Anchor pending' ) && false !== strpos( $kit, 'Sandbox' ) && false !== strpos( $kit, '1 minted of 3 documents.' ), 'the rail names the environment and the minted count; states are labelled' );
ok( false !== strpos( $kit, '1 published, 1 failed, of 2' ), 'the last pass is reported' );
ok( false !== strpos( $kit, 'The papers stay with SSRN' ) && false !== strpos( $kit, 'never reach a public surface' ), 'the prose says what is deposited and what a sandbox DOI is' );
ok( false !== strpos( $kit, 'col="4" aria-label="Zenodo status"' ) && false !== strpos( $kit, '<os-row gap="16"' ), 'the rail keeps its landmark; the two-column shell survives' );
$GLOBALS['__zl']['enabled'] = false;
$kit = snt_leaf_paint( 'connections', 'zenodo' );
ok( false !== strpos( $kit, '<b>No token</b>' ) && false !== strpos( $kit, 'disabled' ), 'no token: the rail says so and the deposit button is disabled' );
$GLOBALS['__zl']['rows'] = array();
$kit = snt_leaf_paint( 'connections', 'zenodo' );
ok( false !== strpos( $kit, 'No signed documents yet.' ), 'an empty ledger is honest' );
$GLOBALS['__can'] = false;
ok( false !== strpos( snt_leaf_paint( 'connections', 'zenodo' ), 'cannot manage options' ), 'the capability gate holds' );
$GLOBALS['__can'] = true;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

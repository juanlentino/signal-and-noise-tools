<?php
/**
 * Native window leaf: Connections → Credentials (apps/sn-dashboard/parts/leaves/connections-keyring.php).
 *
 * The oracle is the classic leaf (sn_admin_render_credentials_section →
 * sn_admin_keyring_render). Both paint the one row model as a LEDGER
 * (15.2.1): three tables, one form (key_id + key_value), two actions, the
 * refusals as notices, never a raw secret, the four worker commands.
 *
 * Run: php tests/os-leaf-connections-keyring.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $path, $d = null ) { return $GLOBALS['__settings'][ $path ] ?? $d; } }
if ( ! function_exists( 'sn_mask_secret' ) ) { function sn_mask_secret( $v ) { $v = (string) $v; return '' === $v ? '' : ( strlen( $v ) <= 8 ? '••••••••' : '••••' . substr( $v, -4 ) ); } }

require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/keyring.php';
require SNT_PATH . 'inc/keyring-verify.php';
require SNT_PATH . 'inc/keyring-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-keyring.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/credentials'] ), 'the painter is registered under connections/credentials' );
function kr_table_rows( $html, $heading ) {
	$at = strpos( $html, 'heading="' . $heading . '"' );
	if ( false === $at ) { return null; }
	$region = substr( $html, $at );
	if ( ! preg_match( '/os-prop-data="([^"]*)"/', $region, $m ) ) { return null; }
	return json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
}

// ── Nothing set, never verified.
$GLOBALS['__options'] = array(); $GLOBALS['__settings'] = array();
$classic = snt_leaf_classic_html( 'sn_admin_render_credentials_section' );
$kit     = snt_leaf_paint( 'connections', 'credentials' );
ok( '' !== $kit && '' !== $classic, 'both leaves paint' );
$names = array_values( array_diff( snt_leaf_names( $kit ), array( '_wpnonce', 'sn_action' ) ) );
ok( array( 'key_id', 'key_value' ) === $names && $names === array_values( array_diff( snt_leaf_names( $classic ), array( '_wpnonce', 'sn_action' ) ) ), '15.2.1: two fields, key_id and key_value, on both leaves; never a field per row' );
ok( array( 'keyring_save', 'keyring_verify' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'two actions, Save and Verify all, on both leaves' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives' );
ok( false !== strpos( $kit, '<div class="snt-2up">' ) && false !== strpos( $kit, 'heading="Site secret and the workers"' ) && false !== strpos( $kit, 'heading="Cloudflare"' ) && false !== strpos( $kit, 'heading="Issued by others"' ) && strpos( $kit, 'heading="Issued by others"' ) < strpos( $kit, 'heading="Set a credential"' ), 'two columns: the three ledger tables left, the form right' );
$site = kr_table_rows( $kit, 'Site secret and the workers' );
ok( is_array( $site ) && 5 === count( $site ) && 'Site secret' === $site[0]['credential'] && 'Not set' === $site[0]['source'] && '—' === $site[0]['value'] && 'no probe' === $site[0]['verified'] && 'not verified' === $site[1]['verified'], 'the workers table: five rows, unset reads Not set / —; the sensor row (probe) reads not verified, the site secret (no probe) says so' );
ok( 20 === count( (array) kr_table_rows( $kit, 'Site secret and the workers' ) ) + count( (array) kr_table_rows( $kit, 'Cloudflare' ) ) + count( (array) kr_table_rows( $kit, 'Issued by others' ) ) && 'Workers AI token (embeddings)' === kr_table_rows( $kit, 'Cloudflare' )[4]['credential'], 'twenty rows across the three tables (16.5.2: the TypeSafe key left for the connector; 16.3.0: the TypeSafe key under Issued; 16.2.0: the Bing Webmaster key under Issued; 15.11.0: the two Zenodo tokens; 15.3.1: the Workers AI token under Cloudflare)' );
ok( 19 === substr_count( $kit, '<os-option value="' ) && false === strpos( $kit, '<os-option value="cloudways_api_key"' ) && false !== strpos( $kit, '<os-option value="mr_read_token">Site secret and the workers · Machine Readers read token</os-option>' ), 'the select lists the nineteen rows a value can be set on (16.5.2: the TypeSafe key left; 16.3.0: + the TypeSafe key; 16.2.0: + the Bing key), labelled by group; the wp-config-only Cloudways key is not offered' );
ok( false !== strpos( $kit, 'wrangler secret put SN_MR_READ_TOKEN' ) && 5 === substr_count( $kit, 'npx wrangler secret put' ) && false !== strpos( $kit, 'wrangler secret put SN_MR_SQL_TOKEN' ) && false !== strpos( $classic, 'wrangler secret put SN_MR_READ_TOKEN' ) && false !== strpos( $kit, 'heading="Worker secrets"' ), 'the rail lists the five worker commands (15.2.2: + the Cloudflare token\'s copy in the sensor), on both leaves' );
ok( false !== strpos( $kit, 'Never verified.' ) && false === strpos( $kit, 'Last verified' ) && false === strpos( $kit, 'tone="warning"' ), 'never verified: says so, no refusal notice' );
ok( false !== strpos( $kit, 'Type &quot;clear&quot; to remove' ) && false !== strpos( $kit, 'type &quot;site&quot; to derive' ), 'the form hint names the two verbs' );

// ── Values set: obscured, never raw; sources named; a constant locks.
$GLOBALS['__options']['sn_cf_api_token'] = 'cf-secret-value-9999';
$GLOBALS['__options']['sn_cf_zone_id']   = 'zone0123456789abcdef';
$GLOBALS['__options'][ SN_SITE_SECRET_OPT ] = 'site-secret-value-7777';
$GLOBALS['__options'][ SN_KEYRING_SITE_ROWS ] = array( 'srv_token' );
define( 'SN_BETTERSTACK_API_TOKEN', 'bs-constant-value-4242' );
$classic = snt_leaf_classic_html( 'sn_admin_render_credentials_section' );
$kit     = snt_leaf_paint( 'connections', 'credentials' );
foreach ( array( 'cf-secret-value-9999', 'site-secret-value-7777', 'bs-constant-value-4242' ) as $raw ) {
	ok( false === strpos( $kit, $raw ) && false === strpos( $classic, $raw ), "the raw value $raw is on neither leaf" );
}
$cf = kr_table_rows( $kit, 'Cloudflare' );
ok( '••••9999' === $cf[0]['value'] && 'Saved' === $cf[0]['source'] && 'zone0123456789abcdef' === $cf[1]['value'], 'a secret shows its last four, an id shows whole; the source reads Saved' );
$site = kr_table_rows( $kit, 'Site secret and the workers' );
ok( 'Site secret' === $site[2]['source'] && '••••7777' === $site[2]['value'], 'a switched row reads Site secret, showing the site secret\'s last four' );
$issued = kr_table_rows( $kit, 'Issued by others' );
ok( 'wp-config (SN_BETTERSTACK_API_TOKEN)' === $issued[0]['source'] && 18 === substr_count( $kit, '<os-option value="' ) && false === strpos( $kit, '<os-option value="betterstack_token"' ), 'a constant-locked row reads its constant and leaves the select' );
ok( false !== strpos( $classic, 'wp-config (SN_BETTERSTACK_API_TOKEN)' ) && false === strpos( $classic, '<option value="betterstack_token"' ), 'the classic leaf agrees' );

// ── Verdicts: the refused one is a notice with its sentence; the table says the word; the time paints.
$GLOBALS['__options'][ SN_KEYRING_VERDICTS_OPT ] = array(
	'mr_read_token' => array( 'status' => 'refused', 'detail' => "The sensor refused it: this value and the worker's SN_MR_READ_TOKEN differ.", 'at' => 1789500000 ),
	'cf_token'      => array( 'status' => 'ok', 'detail' => 'Cloudflare answers active (user token).', 'at' => 1789500000 ),
);
$kit     = snt_leaf_paint( 'connections', 'credentials' );
$classic = snt_leaf_classic_html( 'sn_admin_render_credentials_section' );
ok( 1 === substr_count( $kit, 'tone="warning"' ) && false !== strpos( $kit, '<b>Machine Readers read token</b>' ) && false !== strpos( $kit, 'SN_MR_READ_TOKEN differ' ) && strpos( $kit, 'SN_MR_READ_TOKEN differ' ) < strpos( $kit, 'heading="Site secret and the workers"' ), 'the one refusal is a notice above the ledger, naming the row and the side' );
ok( 'refused' === kr_table_rows( $kit, 'Site secret and the workers' )[1]['verified'] && 'ok' === kr_table_rows( $kit, 'Cloudflare' )[0]['verified'], 'the table cells carry the verdict word' );
ok( false === strpos( $kit, 'Cloudflare answers active' ), 'an ok verdict\'s sentence is not repeated on the leaf: the word is enough' );
ok( false !== strpos( $kit, 'Last verified 2026-09-15' ) && false !== strpos( $classic, 'Last verified 2026-09-15' ), 'the verification time paints on both leaves' );
ok( false !== strpos( $classic, 'notice-warning' ) && false !== strpos( $classic, 'SN_MR_READ_TOKEN differ' ) && false !== strpos( $classic, '<td>refused</td>' ), 'the classic leaf paints the same refusal and the same cell' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

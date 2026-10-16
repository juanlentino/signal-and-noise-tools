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
ok( array( 'keyring_generate', 'keyring_save', 'keyring_verify' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'three actions, Save, Verify all and Generate (Unreleased: the owner identity keys), on both leaves' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives' );
ok( false !== strpos( $kit, snt_leaf_row( 2, 290, 24 ) . '<os-stack gap="18">' ) && false !== strpos( $kit, 'heading="Site secret and the workers"' ) && false !== strpos( $kit, 'heading="Cloudflare"' ) && false !== strpos( $kit, 'heading="Issued by others"' ) && strpos( $kit, 'heading="Issued by others"' ) < strpos( $kit, 'heading="Set a credential"' ), 'two columns: the three ledger tables left, the form right' );
$site = kr_table_rows( $kit, 'Site secret and the workers' );
ok( is_array( $site ) && 7 === count( $site ) && 'Site secret' === $site[0]['credential'] && 'Not set' === $site[0]['source'] && 'not set' === $site[0]['value'] && 'no probe' === $site[0]['verified'] && 'not verified' === $site[1]['verified'], 'the workers table: seven rows (Unreleased: + the owner key and its previous twin), unset reads Not set / not set; the sensor row (probe) reads not verified, the site secret (no probe) says so' );
ok( 24 === count( (array) kr_table_rows( $kit, 'Site secret and the workers' ) ) + count( (array) kr_table_rows( $kit, 'Cloudflare' ) ) + count( (array) kr_table_rows( $kit, 'Issued by others' ) ) && 'Workers AI token (embeddings)' === kr_table_rows( $kit, 'Cloudflare' )[4]['credential'], 'twenty-four rows across the three tables (Unreleased: the owner key and its previous twin under the workers; Unreleased: the two Internet Archive keys under Issued, wp-config only so neither is a saveable option; 16.5.2: the TypeSafe key left for the connector; 16.3.0: the TypeSafe key under Issued; 16.2.0: the Bing Webmaster key under Issued; 15.11.0: the two Zenodo tokens; 15.3.1: the Workers AI token under Cloudflare)' );
ok( 22 === substr_count( $kit, '<os-option value="' ) && 2 === substr_count( $kit, '<os-option value="owner_key"' ) && false === strpos( $kit, '<os-option value="cloudways_api_key"' ) && false !== strpos( $kit, '<os-option value="mr_read_token">Site secret and the workers · Machine Readers read token</os-option>' ), 'the select lists the twenty-one rows a value can be set on, and Generate the one owner key (Unreleased; 16.5.2: the TypeSafe key left; 16.3.0: + the TypeSafe key; 16.2.0: + the Bing key), labelled by group; the wp-config-only Cloudways key is not offered' );
ok( false !== strpos( $kit, 'wrangler secret put SN_MR_READ_TOKEN' ) && 11 === substr_count( $kit, 'npx wrangler secret put' ) && false !== strpos( $kit, 'sn-rights-signals-worker &amp;&amp; npx wrangler secret put SN_OWNER_KEY' ) && false !== strpos( $kit, 'signal-and-noise-analytics-worker &amp;&amp; npx wrangler secret put SN_OWNER_KEY' ) && false !== strpos( $kit, 'wrangler secret put SN_MR_SQL_TOKEN' ) && false !== strpos( $classic, 'wrangler secret put SN_MR_READ_TOKEN' ) && false !== strpos( $kit, 'heading="Worker secrets"' ), 'the rail lists nine worker commands, plus the owner key box\'s two (owner request 2026-10-15) (Unreleased: the owner key and its previous twin, each on both Workers; 15.2.2: + the Cloudflare token\'s copy in the sensor), on both leaves' );
ok( false !== strpos( $kit, 'Never verified.' ) && false === strpos( $kit, 'Last verified' ) && false === strpos( $kit, 'tone="warning"' ), 'never verified: says so, no refusal notice' );
// 2026-10-12 audit: the native leaf said three sentences the classic did not,
// one of them a position ("the ledger on the left is the record"); the group
// sentence described the retired per-row field ("type site in a row").
foreach ( array( 'One value at a time; each group&#039;s table shows what is set.', 'Never verified.', 'each verdict names the side to fix.', 'After changing a worker row here, set the same value on the worker.' ) as $line ) {
	$plain = html_entity_decode( $line, ENT_QUOTES, 'UTF-8' );
	ok( ( false !== strpos( $kit, $line ) || false !== strpos( $kit, $plain ) ) && ( false !== strpos( $classic, $line ) || false !== strpos( $classic, $plain ) ), "both surfaces say: $plain" );
}
ok( false === strpos( $kit, 'on the left' ) && false === strpos( $kit, 'in a row to switch it' ) && false !== strpos( $kit, 'pick the row under Set a credential and type &quot;site&quot;, then run its command under Worker secrets' ) && false !== strpos( $classic, 'pick the row under Set a credential and type &quot;site&quot;, then run its command under Worker secrets' ), 'the workers group sentence names the box and the commands it uses, on both surfaces' );
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
ok( 'wp-config (SN_BETTERSTACK_API_TOKEN)' === $issued[0]['source'] && 21 === substr_count( $kit, '<os-option value="' ) && false === strpos( $kit, '<os-option value="betterstack_token"' ), 'a constant-locked row reads its constant and leaves the select' );
ok( false !== strpos( $classic, 'wp-config (SN_BETTERSTACK_API_TOKEN)' ) && false === strpos( $classic, '<option value="betterstack_token"' ), 'the classic leaf agrees' );

// ── Owner request 2026-10-15: a pasted owner key is never shown, only its last four.
require_once SNT_PATH . 'inc/admin-post-actions/keyring.php';
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; } }
$owner_paste = 'c0ffee' . str_repeat( '1', 54 ) . 'BEEF';
ok( 'keyring_saved' === sn_handle_keyring_save( array( 'key_id' => 'owner_key', 'key_value' => $owner_paste ) ), 'the owner key takes a pasted 64-hex value' );
$classic = snt_leaf_classic_html( 'sn_admin_render_credentials_section' );
$kit     = snt_leaf_paint( 'connections', 'credentials' );
ok( false === stripos( $kit, substr( $owner_paste, 0, 60 ) ) && false === stripos( $classic, substr( $owner_paste, 0, 60 ) ) && false !== strpos( $kit, '••••beef' ) && false !== strpos( $classic, '••••beef' ), 'the pasted owner key is on neither leaf; both show its last four, lowercase' );
foreach ( array( $kit, $classic ) as $html ) {
	$plain = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
	ok( false !== strpos( $plain, 'openssl rand -hex 32' ) && false !== strpos( $plain, 'cd ~/Projects/signal-and-noise-analytics-worker && npx wrangler secret put SN_OWNER_KEY' ) && false !== strpos( $plain, 'cd ~/Projects/sn-rights-signals-worker && npx wrangler secret put SN_OWNER_KEY' ) && false !== strpos( $plain, 'Measurement › Analytics' ), 'the owner key box says the flow: openssl, paste, both wrangler commands, enroll' );
}

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
ok( false !== strpos( $kit, 'Last verified September 15, 2026' ) && false !== strpos( $classic, 'Last verified September 15, 2026' ), 'the verification time paints on both leaves, in the site\'s date format (2026-10-12 audit: one readable format)' );
ok( false !== strpos( $classic, 'notice-warning' ) && false !== strpos( $classic, 'SN_MR_READ_TOKEN differ' ) && false !== strpos( $classic, '<td>refused</td>' ), 'the classic leaf paints the same refusal and the same cell' );

// 2026-10-12, live: the picker painted an empty box (value '' matches no
// option), which read as "no credentials". It says what to do instead, and
// every kit select with no matching value gets a placeholder.
$picker = snt_leaf_paint( 'connections', 'credentials' );
ok( 1 === preg_match( '/<os-select[^>]*name="key_id"[^>]*placeholder="Choose a credential"/', $picker ), 'the credential picker says "Choose a credential" until one is chosen' );
ok( false !== strpos( snt_kit_field( 'select', 'x', 'X', '', array( 'options' => array( 'a' => 'A' ) ) ), 'placeholder="Choose one"' ) && false === strpos( snt_kit_field( 'select', 'x', 'X', 'a', array( 'options' => array( 'a' => 'A' ) ) ), 'placeholder=' ), 'a kit select whose value matches no option shows a placeholder; one with a match shows its option' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

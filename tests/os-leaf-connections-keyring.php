<?php
/**
 * Native window leaf: Connections → Credentials (apps/sn-dashboard/parts/leaves/connections-keyring.php).
 *
 * The oracle is the classic leaf (sn_admin_render_credentials_section →
 * sn_admin_keyring_render). Both paint the one row model: sixteen fields
 * named key_<id>, the two actions, never a raw secret, the source, the
 * verdict in words, and for a shared secret the wrangler command.
 *
 * Run: php tests/os-leaf-connections-keyring.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $path, $d = null ) { return $GLOBALS['__settings'][ $path ] ?? $d; } }
if ( ! function_exists( 'sn_mask_secret' ) ) { function sn_mask_secret( $v ) { $v = (string) $v; return '' === $v ? '' : ( strlen( $v ) <= 8 ? '••••••••' : '••••' . substr( $v, -4 ) ); } }

require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/keyring.php';
require SNT_PATH . 'inc/keyring-verify.php';
require SNT_PATH . 'inc/keyring-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-keyring.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/credentials'] ), 'the painter is registered under connections/credentials' );

// ── Nothing set, never verified.
$GLOBALS['__options'] = array(); $GLOBALS['__settings'] = array();
$classic = snt_leaf_classic_html( 'sn_admin_render_credentials_section' );
$kit     = snt_leaf_paint( 'connections', 'credentials' );
ok( '' !== $kit && '' !== $classic, 'both leaves paint' );
$names = snt_leaf_names( $kit );
$keys = array_values( array_filter( $names, static function ( $n ) { return 0 === strpos( $n, 'key_' ); } ) );
ok( 15 === count( $keys ) && $names === snt_leaf_names( $classic ) && in_array( 'key_site_secret', $keys, true ) && in_array( 'key_mr_read_token', $keys, true ) && ! in_array( 'key_cloudways_api_key', $keys, true ), 'fifteen fields named key_<id>, the same on both leaves; the wp-config-only Cloudways key has none: ' . implode( ',', $keys ) );
ok( array( 'keyring_save', 'keyring_verify' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'two actions, Save and Verify all, on both leaves' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives' );
ok( false !== strpos( $kit, 'Not set.' ) && false !== strpos( $kit, 'not verified' ) && false !== strpos( $kit, 'no probe' ) && false === strpos( $kit, 'Last verified' ), 'unset rows say Not set; rows with a probe say not verified, rows without say no probe; no verification time' );
ok( false !== strpos( $kit, 'wrangler secret put SN_MR_READ_TOKEN' ) && false !== strpos( $kit, 'sn-rights-signals-worker' ) && false !== strpos( $classic, 'wrangler secret put SN_MR_READ_TOKEN' ), 'a shared secret prints the command that sets its other half, on both leaves' );
ok( false !== strpos( $kit, 'Not a Cloudflare token' ) && false !== strpos( $kit, 'Type &quot;site&quot; to derive it from the site secret' ), 'the sensor row says what it is not, and how to switch it' );
ok( false !== strpos( $kit, 'Site secret and the workers' ) && false !== strpos( $kit, 'heading="Credentials"' ) && strpos( $kit, 'Site secret and the workers' ) < strpos( $kit, '>Cloudflare<' ) && strpos( $kit, '>Cloudflare<' ) < strpos( $kit, 'Issued by others' ), 'three groups, in order: site, Cloudflare, issued' );

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
ok( false !== strpos( $kit, 'value="••••9999"' ) && false !== strpos( $kit, 'value="zone0123456789abcdef"' ), 'a secret shows its last four; an id shows whole' );
ok( false !== strpos( $kit, 'Saved here.' ) && false !== strpos( $kit, 'From the site secret.' ) && false !== strpos( $kit, 'Locked by SN_BETTERSTACK_API_TOKEN in wp-config.php.' ), 'sources: saved, from the site secret, locked by the constant' );
$keys = array_values( array_filter( snt_leaf_names( $kit ), static function ( $n ) { return 0 === strpos( $n, 'key_' ); } ) );
ok( 14 === count( $keys ) && ! in_array( 'key_betterstack_token', $keys, true ) && snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'a constant-locked row has no field on either leaf' );

// ── Verdicts painted in words, with the time.
$GLOBALS['__options'][ SN_KEYRING_VERDICTS_OPT ] = array(
	'mr_read_token' => array( 'status' => 'refused', 'detail' => "The sensor refused it: this value and the worker's SN_MR_READ_TOKEN differ.", 'at' => 1789500000 ),
	'cf_token'      => array( 'status' => 'ok', 'detail' => 'Cloudflare answers active (user token).', 'at' => 1789500000 ),
);
$kit     = snt_leaf_paint( 'connections', 'credentials' );
$classic = snt_leaf_classic_html( 'sn_admin_render_credentials_section' );
ok( false !== strpos( $kit, '>refused</os-badge>' ) && false !== strpos( $kit, 'SN_MR_READ_TOKEN differ' ) && false !== strpos( $kit, '>ok</os-badge>' ) && false !== strpos( $kit, 'Cloudflare answers active' ), 'each verdict paints its badge and its sentence' );
ok( false !== strpos( $kit, 'Last verified 2026-09-15' ) && false !== strpos( $classic, 'Last verified 2026-09-15' ), 'the verification time paints on both leaves' );
ok( false !== strpos( $classic, 'sn-pill--warn">refused' ) && false !== strpos( $classic, 'SN_MR_READ_TOKEN differ' ), 'the classic leaf paints the same verdict' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

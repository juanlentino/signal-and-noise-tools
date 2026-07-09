<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $k, $single = false ) {
		$v = $GLOBALS['__pv_meta'][ $id ][ $k ] ?? null;
		return $single ? ( null === $v ? '' : $v ) : ( null === $v ? array() : array( $v ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) {
		$GLOBALS['__pv_meta'][ $id ][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0, $depth = 512 ) {
		return json_encode( $d, $f, $depth ); }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $p = '' ) {
		return 'https://example.com' . $p; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) {
		return (string) $u; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $decimals = 0 ) {
		return number_format( (float) $n, (int) $decimals ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__pv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode() {
		return true; }
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $s, $allowed = array() ) {
		return (string) $s; }
}
require_once SNT_PATH . 'inc/provenance-core.php';
require_once SNT_PATH . 'inc/provenance-webhook.php';
require_once SNT_PATH . 'inc/provenance-render.php';

$pass = 0;
$fail = 0;
function rp_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function rp_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

echo "Provenance render suite\n\nTask 1: view-model\n";
rp_eq( null, sn_prov_view_data( 5 ), 'no chain -> null view-model' );

update_post_meta( 5, SN_PROV_UID_META, 'uid5' );
update_post_meta( 5, SN_PROV_CHAIN_META, array(
	array( 'version' => 1, 'content_hash' => 'a1', 'status' => 'confirmed', 'bitcoin_block' => 902417 ),
	array( 'version' => 2, 'content_hash' => 'b2', 'status' => 'pending' ),
) );
$vm = sn_prov_view_data( 5 );
rp_eq( 'pending', $vm['status'], 'status = latest commit status' );
rp_eq( 2, $vm['version'], 'version = latest' );
rp_eq( 2, count( $vm['versions'] ), 'both versions surfaced' );
rp_eq( 902417, $vm['versions'][0]['bitcoin_block'], 'block height carried' );
rp_eq( false, $vm['is_genesis_only'], 'not genesis-only' );
rp_true( false !== strpos( $vm['ledger_url'], 'uid5' ), 'ledger url keyed by note_uid' );

// Genesis-only Note.
update_post_meta( 6, SN_PROV_UID_META, 'uid6' );
update_post_meta( 6, SN_PROV_CHAIN_META, array( array( 'version' => 0, 'content_hash' => 'g0', 'status' => 'genesis', 'genesis' => true ) ) );
$vm6 = sn_prov_view_data( 6 );
rp_eq( true, $vm6['is_genesis_only'], 'genesis-only flagged' );
rp_eq( true, $vm6['genesis_caveat'], 'genesis caveat on' );

echo "\nTask 2: render helpers\n";
$chip = sn_prov_render_chip( 5 );  // version 2, pending
rp_true( false !== strpos( $chip, 'sn-prov-chip' ), 'chip has its class' );
rp_true( false !== strpos( $chip, 'Pending' ), 'chip shows pending label' );
rp_eq( '', sn_prov_render_chip( 999 ), 'no chain -> empty chip' );

$panel = sn_prov_render_panel( 5 );
rp_true( false !== strpos( $panel, 'sn-prov-panel' ), 'panel wrapper present' );
rp_true( false !== strpos( $panel, '902417' ) || false !== strpos( $panel, '902,417' ), 'panel shows block height' );
rp_true( false !== strpos( $panel, 'v2' ) && false !== strpos( $panel, 'v1' ), 'panel lists both versions' );

$panel6 = sn_prov_render_panel( 6 ); // genesis-only
rp_true( false !== strpos( $panel6, 'not independently proven' ), 'genesis caveat rendered' );

echo "\nTask 3: verify shortcode\n";
$GLOBALS['__pv_options']['sn_prov_pubkey_b64'] = 'PUBLICKEYBASE64';
$html = sn_prov_verify_shortcode( array() );
rp_true( false !== strpos( $html, 'PUBLICKEYBASE64' ), 'verify content shows the public key' );
rp_true( false !== strpos( $html, 'ots verify' ), 'verify content documents ots verify' );
rp_true( false !== strpos( $html, 'sn-normalize-v1' ), 'verify content names the normalization algo' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

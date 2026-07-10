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
if ( ! defined( 'SNT_VERSION' ) ) {
	define( 'SNT_VERSION', 'test' );
}

$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_enq']     = array(); // captured wp_enqueue_style handles

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
// Enqueue-gate stubs. Toggled via $GLOBALS so the REAL sn_prov_is_note()
// (which calls has_term) drives the gate without redeclaring it.
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $type = '' ) {
		return ! empty( $GLOBALS['__pv_singular'] ); }
}
if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID() {
		return $GLOBALS['__pv_current_id'] ?? 0; }
}
if ( ! function_exists( 'has_term' ) ) {
	function has_term( $term = '', $taxonomy = '', $post = null ) {
		return ! empty( $GLOBALS['__pv_is_note'] ); }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['__pv_enq'][] = $handle;
		return true; }
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

$chip6 = sn_prov_render_chip( 6 ); // genesis-only fixture (post 6)
rp_true( false !== strpos( $chip6, 'sn-prov-genesis' ), 'genesis-only chip carries the genesis class' );
rp_true( false !== strpos( $chip6, 'Genesis' ), 'genesis-only chip shows the Genesis label' );
rp_true( false === strpos( $chip6, '&middot;' ), 'genesis-only chip suppresses the middot separator' );
rp_true( false === strpos( $chip6, ' v0' ), 'genesis-only chip suppresses the v0 version suffix' );

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

echo "\nTask 4: front enqueue gate\n";
// Single Note view: gate is open, front CSS enqueued.
$GLOBALS['__pv_enq']        = array();
$GLOBALS['__pv_singular']   = true;
$GLOBALS['__pv_is_note']    = true;
$GLOBALS['__pv_current_id'] = 5;
sn_prov_enqueue_front();
rp_true( in_array( 'sn-provenance-front', $GLOBALS['__pv_enq'], true ), 'front CSS enqueued on a single Note' );

// Not a singular view: gate closed, nothing enqueued.
$GLOBALS['__pv_enq']      = array();
$GLOBALS['__pv_singular'] = false;
$GLOBALS['__pv_is_note']  = true;
sn_prov_enqueue_front();
rp_eq( 0, count( $GLOBALS['__pv_enq'] ), 'nothing enqueued off single views' );

// Singular but not a Note: gate closed, nothing enqueued.
$GLOBALS['__pv_enq']      = array();
$GLOBALS['__pv_singular'] = true;
$GLOBALS['__pv_is_note']  = false;
sn_prov_enqueue_front();
rp_eq( 0, count( $GLOBALS['__pv_enq'] ), 'nothing enqueued on a non-Note single' );

echo "\nTask 5: genesis Notes read verified-via-snapshot once the root confirms\n";
// Pure presenter. A founding-snapshot commit stays "Genesis" until the genesis
// ROOT itself is Bitcoin-confirmed; only then does it read verified.
$g_pending = sn_prov_present_status( 'genesis', 'pending' );
rp_eq( 'Genesis', $g_pending['label'], 'genesis + pending root → Genesis label' );
rp_eq( 'genesis', $g_pending['state'], 'genesis + pending root → genesis state class' );
$g_conf = sn_prov_present_status( 'genesis', 'confirmed' );
rp_eq( 'Verified', $g_conf['label'], 'genesis + confirmed root → Verified label' );
rp_eq( 'confirmed', $g_conf['state'], 'genesis + confirmed root → confirmed state class' );
// A real per-Note status is never affected by the genesis root.
rp_eq( 'Verified', sn_prov_present_status( 'confirmed', 'pending' )['label'], 'per-Note confirmed is independent of the root' );
rp_eq( 'Pending', sn_prov_present_status( 'pending', 'confirmed' )['label'], 'per-Note pending is independent of the root' );

// End-to-end on the genesis-only fixture (post 6) with a confirmed root.
$GLOBALS['__pv_options']['sn_prov_genesis'] = array( 'status' => 'confirmed', 'bitcoin_block' => 957359 );
$chip6c = sn_prov_render_chip( 6 );
rp_true( false !== strpos( $chip6c, 'Verified' ), 'confirmed root → genesis chip reads Verified' );
rp_true( false !== strpos( $chip6c, 'sn-prov-confirmed' ), 'confirmed root → genesis chip carries the verified state class' );
rp_true( false !== strpos( $chip6c, 'sn-prov-genesis' ), 'confirmed root → genesis chip KEEPS the genesis marker (distinct)' );
$panel6c = sn_prov_render_panel( 6 );
rp_true( false !== strpos( $panel6c, 'founding snapshot' ), 'confirmed root → panel meta reads "founding snapshot"' );
rp_true( false !== strpos( $panel6c, '957,359' ), 'confirmed root → panel shows the genesis Bitcoin block' );
rp_true( false === strpos( $panel6c, 'not independently proven' ), 'confirmed root → the stale "not proven" caveat is gone' );
rp_true( false !== strpos( $panel6c, 'Verified via the founding snapshot' ), 'confirmed root → caveat states verified-via-snapshot' );

// A still-pending root leaves the honest "Genesis / not proven" surface intact.
$GLOBALS['__pv_options']['sn_prov_genesis'] = array( 'status' => 'pending' );
$chip6p = sn_prov_render_chip( 6 );
rp_true( false !== strpos( $chip6p, 'Genesis' ), 'pending root → genesis chip still reads Genesis' );
rp_true( false !== strpos( sn_prov_render_panel( 6 ), 'not independently proven' ), 'pending root → honest caveat still shown' );
unset( $GLOBALS['__pv_options']['sn_prov_genesis'] );

echo "\nTask 6: Bitcoin block numbers link to a public explorer\n";
rp_true( false !== strpos( sn_prov_block_explorer_url( 957333 ), 'mempool.space/block/957333' ), 'explorer url defaults to mempool.space/block/<height>' );
rp_eq( '', sn_prov_block_explorer_url( 0 ), 'no explorer url for a zero block' );
$blink = sn_prov_block_link( 957333 );
rp_true( false !== strpos( $blink, '<a ' ), 'block link is an anchor' );
rp_true( false !== strpos( $blink, 'mempool.space/block/957333' ), 'block link points at the block' );
rp_true( false !== strpos( $blink, 'block 957,333' ), 'block link text is the humanized number' );
rp_true( false !== strpos( $blink, 'nofollow' ), 'block link is rel=nofollow (external)' );
rp_eq( '', sn_prov_block_link( 0 ), 'no link for a zero/absent block' );

// Confirmed genesis panel (post 6): the founding-snapshot block is a link.
$GLOBALS['__pv_options']['sn_prov_genesis'] = array( 'status' => 'confirmed', 'bitcoin_block' => 957359 );
$panel6b = sn_prov_render_panel( 6 );
rp_true( false !== strpos( $panel6b, 'mempool.space/block/957359' ), 'confirmed genesis panel links the founding-snapshot block' );
rp_true( false !== strpos( $panel6b, 'founding snapshot' ), 'confirmed genesis panel keeps the founding-snapshot label' );
rp_true( false !== strpos( $panel6b, '957,359</a>' ), 'the block number is inside the anchor' );
unset( $GLOBALS['__pv_options']['sn_prov_genesis'] );

// Per-Note confirmed block (post 5, block 902417) links too.
$panel5b = sn_prov_render_panel( 5 );
rp_true( false !== strpos( $panel5b, 'mempool.space/block/902417' ), 'per-Note confirmed block links to the explorer' );
rp_true( false !== strpos( $panel5b, '902,417</a>' ), 'per-Note block number is inside the anchor' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

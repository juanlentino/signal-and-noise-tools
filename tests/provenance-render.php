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
// v10.86.0: the viewmodel resolves the SUBJECT KIND, which needs the post
// object. Fifth stub-drift fatal of the day and the third I caused — the shape
// is always the same: a module with a standalone harness gains a WordPress call
// and dies BEFORE its first assertion, visible only through the missing summary
// line. Default post_type 'post' keeps every existing Note fixture unchanged.
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id = 0 ) {
		return (object) array( 'ID' => (int) $id, 'post_type' => $GLOBALS['__pr_post_type'] ?? 'post' );
	}
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

// sn_prov_block_meta(): plain text when it duplicates the lead link's target,
// an anchor otherwise (or plain text for a zero block).
rp_true( false === strpos( sn_prov_block_meta( 957333, 'https://mempool.space/block/957333' ), '<a ' ), 'block_meta: plain text when it matches the lead link' );
rp_true( false !== strpos( sn_prov_block_meta( 957333, 'https://mempool.space/block/957333' ), 'block 957,333' ), 'block_meta: still shows the humanized number when de-linked' );
rp_true( false !== strpos( sn_prov_block_meta( 957333, 'https://mempool.space/tx/abc' ), '<a ' ), 'block_meta: links when the lead link points elsewhere' );
rp_true( false !== strpos( sn_prov_block_meta( 957333, '' ), '<a ' ), 'block_meta: links when there is no lead link' );
rp_eq( '', sn_prov_block_meta( 0, 'https://mempool.space/block/957333' ), 'block_meta: no output for a zero/absent block' );

// Confirmed genesis panel (post 6): the founding-snapshot block reaches the
// explorer via the panel's single lead link; the chain + caveat show it as plain
// text, so the same block is never linked twice.
$GLOBALS['__pv_options']['sn_prov_genesis'] = array( 'status' => 'confirmed', 'bitcoin_block' => 957359 );
$panel6b = sn_prov_render_panel( 6 );
rp_true( false !== strpos( $panel6b, 'mempool.space/block/957359' ), 'confirmed genesis panel reaches the founding-snapshot block' );
rp_true( false !== strpos( $panel6b, 'founding snapshot' ), 'confirmed genesis panel keeps the founding-snapshot label' );
rp_eq( 1, substr_count( $panel6b, 'mempool.space/block/957359' ), 'the genesis block is linked exactly once (the lead link owns it)' );
rp_true( false !== strpos( $panel6b, 'sn-prov-onchain' ), 'that one link is the plain-language lead link' );
rp_true( false !== strpos( $panel6b, 'founding snapshot · block 957,359' ), 'the chain shows the block as plain text, not a second link' );
unset( $GLOBALS['__pv_options']['sn_prov_genesis'] );

// Per-Note confirmed block (post 5): its LATEST version (v2) is pending with no
// tx yet, so the panel has NO lead link — which means the confirmed v1 block must
// keep its own link (never orphan a reachable anchor behind an absent lead link).
$panel5b = sn_prov_render_panel( 5 );
rp_true( false === strpos( $panel5b, 'sn-prov-onchain' ), 'post 5 (latest pending, no tx) → no lead link' );
rp_true( false !== strpos( $panel5b, 'mempool.space/block/902417' ), 'the confirmed v1 block still links to the explorer' );
rp_true( false !== strpos( $panel5b, '902,417</a>' ), 'with no lead link to de-dup against, the v1 block stays a link' );

echo "\nTask 7: pending Notes link to the mempool tx with a live N/6 count\n";
$txid = str_repeat( 'ab', 32 ); // 64-hex
rp_true( false !== strpos( sn_prov_tx_explorer_url( $txid ), 'mempool.space/tx/' . $txid ), 'tx url = mempool.space/tx/<txid>' );
rp_eq( '', sn_prov_tx_explorer_url( 'nothex' ), 'invalid txid → no url' );

update_post_meta( 7, SN_PROV_UID_META, 'uid7' );
update_post_meta( 7, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'p1', 'status' => 'pending', 'bitcoin_txid' => $txid, 'confirmations' => 3 ) ) );
$chip7 = sn_prov_render_chip( 7 );
rp_true( false !== strpos( $chip7, 'mempool.space/tx/' . $txid ), 'pending chip links to the in-flight tx' );
rp_true( false !== strpos( $chip7, '3/6' ), 'pending chip shows the N/6 confirmation count' );
rp_true( false !== strpos( $chip7, 'Pending' ), 'pending chip still reads Pending' );
rp_true( false !== strpos( $chip7, '<a ' ), 'pending chip is wrapped in a link' );

// Pending with NO txid yet (not in a tx) → plain, unlinked chip.
update_post_meta( 7, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'p1', 'status' => 'pending' ) ) );
$chip7b = sn_prov_render_chip( 7 );
rp_true( false === strpos( $chip7b, '<a ' ), 'pending chip with no txid → plain, unlinked' );
rp_true( false === strpos( $chip7b, '/6' ), 'no count without confirmations' );

// A confirmed per-Note chip now links to its block too.
update_post_meta( 7, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'p1', 'status' => 'confirmed', 'bitcoin_block' => 957333 ) ) );
$chip7c = sn_prov_render_chip( 7 );
rp_true( false !== strpos( $chip7c, 'mempool.space/block/957333' ), 'confirmed chip links to its block' );
rp_true( false !== strpos( $chip7c, 'Verified' ), 'confirmed chip reads Verified' );

update_post_meta( 7, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'p1', 'status' => 'pending', 'bitcoin_txid' => $txid, 'confirmations' => 4 ) ) );
$panel7 = sn_prov_render_panel( 7 );
rp_true( false !== strpos( $panel7, 'mempool.space/tx/' . $txid ), 'panel pending row links the in-flight tx' );
rp_true( false !== strpos( $panel7, '4/6' ), 'panel pending row shows the N/6 count' );

echo "\nTask 8: a plain-language, self-announcing on-chain link (chip + panel)\n";

// --- sn_prov_primary_explorer(): the single reader-facing on-chain target ---
$txid8   = str_repeat( 'cd', 32 );
$no_root = array( 'status' => '', 'bitcoin_block' => 0 );
// Confirmed per-Note → its block.
$e = sn_prov_primary_explorer( array( 'status' => 'confirmed', 'is_genesis_only' => false, 'bitcoin_block' => 957333, 'bitcoin_txid' => '' ), $no_root );
rp_eq( 'block', $e['kind'], 'primary explorer: confirmed Note → block kind' );
rp_true( false !== strpos( $e['href'], 'mempool.space/block/957333' ), 'primary explorer: confirmed → its block url' );
// Pending, already in a tx → the tx.
$e = sn_prov_primary_explorer( array( 'status' => 'pending', 'is_genesis_only' => false, 'bitcoin_block' => 0, 'bitcoin_txid' => $txid8 ), $no_root );
rp_eq( 'tx', $e['kind'], 'primary explorer: pending Note in a tx → tx kind' );
rp_true( false !== strpos( $e['href'], 'mempool.space/tx/' . $txid8 ), 'primary explorer: pending → its tx url' );
// Pending, no tx yet → no public target.
$e = sn_prov_primary_explorer( array( 'status' => 'pending', 'is_genesis_only' => false, 'bitcoin_block' => 0, 'bitcoin_txid' => '' ), $no_root );
rp_eq( '', $e['kind'], 'primary explorer: pending with no tx → no target' );
rp_eq( '', $e['href'], 'primary explorer: no target → empty href' );
// Genesis-only leaf inherits the genesis root's block once the root confirms.
$gen_vm = array( 'status' => 'genesis', 'is_genesis_only' => true, 'bitcoin_block' => 0, 'bitcoin_txid' => '' );
$e      = sn_prov_primary_explorer( $gen_vm, array( 'status' => 'confirmed', 'bitcoin_block' => 957359 ) );
rp_eq( 'block', $e['kind'], 'primary explorer: genesis + confirmed root → block kind' );
rp_true( false !== strpos( $e['href'], 'mempool.space/block/957359' ), 'primary explorer: genesis → the root block url' );
$e = sn_prov_primary_explorer( $gen_vm, array( 'status' => 'pending', 'bitcoin_block' => 0 ) );
rp_eq( '', $e['kind'], 'primary explorer: genesis + pending root → no target yet' );

// --- CTA copy is plain-language and kind-aware ---
rp_true( false !== stripos( sn_prov_explorer_cta( 'block' ), 'public Bitcoin ledger' ), 'confirmed CTA names the public Bitcoin ledger' );
rp_true( false !== stripos( sn_prov_explorer_cta( 'tx' ), 'confirm' ), 'pending CTA says "watch it confirm"' );

// --- The byline chip announces itself as a link when it is one ---
update_post_meta( 8, SN_PROV_UID_META, 'uid8' );
update_post_meta( 8, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'c1', 'status' => 'confirmed', 'bitcoin_block' => 957333 ) ) );
$chip8 = sn_prov_render_chip( 8 );
rp_true( false !== strpos( $chip8, 'title="' ) && false !== stripos( $chip8, 'public Bitcoin ledger' ), 'confirmed chip carries a plain-language title' );
rp_true( false !== strpos( $chip8, 'aria-label="' ), 'confirmed chip carries an aria-label for screen readers' );
rp_true( false !== strpos( $chip8, 'sn-prov-chip-ext' ), 'linked chip shows the opens-the-ledger affordance' );
rp_true( false !== strpos( $chip8, 'mempool.space/block/957333' ), 'confirmed chip still links its block (unchanged)' );
rp_true( false !== strpos( $chip8, 'Verified' ), 'confirmed chip still reads Verified (unchanged)' );

update_post_meta( 8, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'c1', 'status' => 'pending', 'bitcoin_txid' => $txid8, 'confirmations' => 2 ) ) );
$chip8p = sn_prov_render_chip( 8 );
rp_true( false !== stripos( $chip8p, 'confirm' ), 'pending chip title says watch it confirm' );
rp_true( false !== strpos( $chip8p, '2/6' ), 'pending chip keeps the N/6 count (unchanged)' );

// An unlinked chip (pending, not yet in a tx) stays plain — no affordance, no label.
update_post_meta( 8, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'c1', 'status' => 'pending' ) ) );
$chip8u = sn_prov_render_chip( 8 );
rp_true( false === strpos( $chip8u, '<a ' ), 'unlinked chip is not an anchor' );
rp_true( false === strpos( $chip8u, 'sn-prov-chip-ext' ), 'unlinked chip has no affordance' );
rp_true( false === strpos( $chip8u, 'title="' ), 'unlinked chip has no title' );

// --- The panel leads with one plainly-worded link to the same on-chain target ---
update_post_meta( 8, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'c1', 'status' => 'confirmed', 'bitcoin_block' => 957333 ) ) );
$panel8 = sn_prov_render_panel( 8 );
rp_true( false !== strpos( $panel8, 'sn-prov-onchain' ), 'confirmed panel renders the lead ledger link' );
rp_true( false !== stripos( $panel8, 'See it on the public Bitcoin ledger' ), 'lead link uses plain language' );
rp_true( false !== strpos( $panel8, 'mempool.space/block/957333' ), 'lead link points at the block' );
rp_true( false !== stripos( $panel8, '(mempool.space)' ), 'lead link names the host in plain sight' );
rp_true( strpos( $panel8, 'sn-prov-onchain' ) < strpos( $panel8, 'sn-prov-chain' ), 'lead link precedes the version chain' );
// De-dup: the same block is linked exactly ONCE (the lead link); the chain row
// below shows it as plain text, not a second link to the same place.
rp_eq( 1, substr_count( $panel8, 'mempool.space/block/957333' ), 'confirmed panel links the block exactly once (no duplicate)' );
rp_true( false === strpos( $panel8, '957,333</a>' ), 'the chain row shows the block as plain text, not a duplicate link' );

update_post_meta( 8, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'c1', 'status' => 'pending', 'bitcoin_txid' => $txid8, 'confirmations' => 2 ) ) );
$panel8p = sn_prov_render_panel( 8 );
rp_true( false !== strpos( $panel8p, 'sn-prov-onchain' ), 'pending panel renders the lead ledger link' );
rp_true( false !== stripos( $panel8p, 'Watch it confirm on the public Bitcoin ledger' ), 'pending lead link says watch it confirm' );
rp_true( false !== strpos( $panel8p, 'mempool.space/tx/' . $txid8 ), 'pending lead link points at the tx' );
rp_eq( 1, substr_count( $panel8p, 'mempool.space/tx/' . $txid8 ), 'pending panel links the tx exactly once (no duplicate)' );

// No public target yet → no lead link, but the panel still renders.
update_post_meta( 8, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'c1', 'status' => 'pending' ) ) );
$panel8u = sn_prov_render_panel( 8 );
rp_true( false === strpos( $panel8u, 'sn-prov-onchain' ), 'no lead link when there is no public target yet' );
rp_true( false !== strpos( $panel8u, 'sn-prov-panel' ), 'panel still renders without a lead link' );

// --- "Verify it yourself" must point somewhere the verifier actually serves ---
//
// LIVE DEFECT (2026-08-08, fixed v10.66.1): the byline panel of EVERY Note linked
// "Verify it yourself" to home_url('/provenance/verify'), which returns 404 --
// confirmed against production, redirects followed. The invitation to check the
// proof, on the surface whose entire job is trustworthiness, went nowhere.
//
// Two things had already noticed and neither closed the loop: the theme fixed the
// same URL in its agents manifest in v10.49.0, and the site's OWN 404 log carries
// "/provenance/verify -> /verify" as a genuine broken link in its fixture. The
// signal existed; nothing connected it back to the emitter.
//
// Asserted as a RELATIONSHIP against sn_prov_verify_is_request() -- the router's
// own authority on what /verify means -- so this link and the route can only move
// together. A literal would merely re-encode today's string.
require_once __DIR__ . '/../inc/provenance-verify.php';
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
// Assert on the RENDERED panel -- the anchor a reader actually clicks -- not the
// viewmodel field, so a future renderer that stops using verify_url cannot pass.
$panel_v = sn_prov_render_panel( 5 );
rp_true( false !== strpos( $panel_v, 'Verify it yourself' ), 'the panel renders the "Verify it yourself" invitation' );
preg_match( '#<a href="([^"]*)">Verify it yourself</a>#', $panel_v, $vm_m );
$vurl  = $vm_m[1] ?? '';
rp_true( '' !== $vurl, 'the invitation carries an href' );
$vpath = (string) wp_parse_url( html_entity_decode( $vurl ), PHP_URL_PATH );
rp_true( sn_prov_verify_is_request( $vpath ), '"Verify it yourself" resolves to the LIVE /verify docket, never the 404 /provenance/verify Page' );
rp_true( false === strpos( $vpath, '/provenance/verify' ), 'the reader-facing verify link is never the unrelated /provenance/verify path' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

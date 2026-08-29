<?php
/**
 * Standalone tests for the Notes provenance verifier (/verify) — RED phase.
 *
 * inc/provenance-verify.php does NOT exist yet. Every assertion that depends
 * on it is guarded via vv_require_fn() so a missing module reports clean
 * FAILs instead of a fatal "Call to undefined function", and the summary
 * line always emits. The chip-integration assertions (Task 5) exercise the
 * REAL, already-existing sn_prov_render_chip() from inc/provenance-render.php
 * — those fail today too, because that function hasn't grown the Verify
 * link yet (GREEN work, not this file).
 *
 * Mirrors the idiom in tests/provenance-did.php (pure route-matcher +
 * send() tested directly, real header()/status_header() buffered) and the
 * stub set in tests/provenance-render.php (chip dependencies).
 *
 * Design contract this fixture pins for inc/provenance-verify.php (per
 * docs/superpowers/specs/2026-07-21-notes-verifier-design.md):
 *   - sn_prov_verify_is_request( $uri )              : bool   (pure)
 *   - sn_prov_verify_sanitize_uid( $raw )             : string (pure; '' = invalid)
 *   - sn_prov_verify_sanitize_version( $raw )         : int    (pure; 0 = unset/invalid)
 *   - sn_prov_verify_send()                           : void   (reads $_GET, status_header + header + echo)
 *   - sn_prov_verify_maybe_serve()                     : void   (template_redirect pri 0, guarded by SN_PROV_VERIFY_TEST)
 *
 * @package SignalNoiseTools
 * @since 9.73.0
 */

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
define( 'SN_PROV_VERIFY_TEST', true );

ob_start(); // buffer so send()'s real header() doesn't warn after PASS lines.

$GLOBALS['__vv_meta']      = array();
$GLOBALS['__vv_options']   = array();
$GLOBALS['__vv_status']    = 0;
$GLOBALS['__vv_singular']  = false;
$GLOBALS['__vv_is_note']   = false;
$GLOBALS['__vv_current_id'] = 0;

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $p = '' ) {
		return 'https://example.com' . $p; }
}
if ( ! function_exists( 'rest_url' ) ) {
	// v9.81.0: the credential base derives via rest_url() so a customized rest
	// prefix survives; the stub mirrors the default /wp-json/ prefix.
	function rest_url( $p = '' ) {
		return 'https://example.com/wp-json/' . ltrim( (string) $p, '/' ); }
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
		return htmlspecialchars( (string) $u, ENT_QUOTES ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $decimals = 0 ) {
		return number_format( (float) $n, (int) $decimals ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__vv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0, $depth = 512 ) {
		return json_encode( $d, $f, $depth ); }
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $s, $allowed = array() ) {
		return (string) $s; }
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $type = '' ) {
		return ! empty( $GLOBALS['__vv_singular'] ); }
}
if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID() {
		return $GLOBALS['__vv_current_id'] ?? 0; }
}
if ( ! function_exists( 'has_term' ) ) {
	function has_term( $term = '', $taxonomy = '', $post = null ) {
		return ! empty( $GLOBALS['__vv_is_note'] ); }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style() {
		return true; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script() {
		return true; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $k, $single = false ) {
		$v = $GLOBALS['__vv_meta'][ $id ][ $k ] ?? null;
		return $single ? ( null === $v ? '' : $v ) : ( null === $v ? array() : array( $v ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) {
		$GLOBALS['__vv_meta'][ $id ][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) {
		return is_string( $v ) ? stripslashes( $v ) : $v; }
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( $c ) {
		$GLOBALS['__vv_status'] = (int) $c; }
}

require_once SNT_PATH . 'inc/provenance-core.php';
require_once SNT_PATH . 'inc/provenance-webhook.php';
require_once SNT_PATH . 'inc/provenance-render.php';

// The module under test — does NOT exist yet (RED). include_once (not
// require_once) so a missing file doesn't fatal the whole run: every
// dependent assertion below is guarded by vv_require_fn() instead, so the
// summary line still always emits.
$__vv_module = SNT_PATH . 'inc/provenance-verify.php';
$__vv_module_exists = file_exists( $__vv_module );
if ( $__vv_module_exists ) {
	include_once $__vv_module;
}

$pass = 0;
$fail = 0;
function vv_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function vv_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}
/** Fail cleanly (no fatal) when the RED module hasn't defined $name yet. */
function vv_require_fn( $name ) {
	global $pass, $fail;
	if ( function_exists( $name ) ) {
		return true;
	}
	++$fail;
	echo "  FAIL: {$name}() is defined (inc/provenance-verify.php not implemented yet)\n";
	return false;
}

echo "Provenance verify suite\n\n";

vv_true( $__vv_module_exists, 'inc/provenance-verify.php exists' );

echo "\nTask 1: route recognition (pure path fn)\n";
if ( vv_require_fn( 'sn_prov_verify_is_request' ) ) {
	vv_true( sn_prov_verify_is_request( '/verify' ) === true, 'matches /verify' );
	vv_true( sn_prov_verify_is_request( '/verify?note=abc' ) === true, 'matches /verify with a query string' );
	vv_true( sn_prov_verify_is_request( '/verify?note=abc&v=2' ) === true, 'matches /verify with a multi-param query string' );
	vv_true( sn_prov_verify_is_request( '/verify/' ) === true, 'matches /verify with a trailing slash' );
}

echo "\nTask 2: non-verify passthrough\n";
if ( vv_require_fn( 'sn_prov_verify_is_request' ) ) {
	vv_true( sn_prov_verify_is_request( '/' ) === false, 'root path does not match' );
	vv_true( sn_prov_verify_is_request( '/provenance/verify' ) === false, 'distinct from the existing /provenance/verify Page' );
	vv_true( sn_prov_verify_is_request( '/verify-something' ) === false, 'rejects a path merely prefixed with "verify"' );
	vv_true( sn_prov_verify_is_request( '/verifyy' ) === false, 'rejects a near-miss path' );
	vv_true( sn_prov_verify_is_request( '/wp-json/signal-noise/v1/credential/abc' ) === false, 'rejects an unrelated route' );
	vv_true( sn_prov_verify_is_request( '/.well-known/did.json' ) === false, 'rejects the DID route' );
}

echo "\nTask 3: uid sanitization (UUID shape enforced)\n";
if ( vv_require_fn( 'sn_prov_verify_sanitize_uid' ) ) {
	$valid_uid = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
	vv_eq( $valid_uid, sn_prov_verify_sanitize_uid( $valid_uid ), 'a well-formed UUID passes through' );
	vv_eq( $valid_uid, sn_prov_verify_sanitize_uid( strtoupper( $valid_uid ) ), 'an uppercase UUID is lowercased' );
	vv_eq( '', sn_prov_verify_sanitize_uid( '' ), 'empty string -> blank' );
	vv_eq( '', sn_prov_verify_sanitize_uid( 'not-a-uuid' ), 'non-UUID text -> blank' );
	vv_eq( '', sn_prov_verify_sanitize_uid( '3fa85f64-5717-4562-b3fc-2c963f66afa' ), 'one hex digit short -> blank' );
	vv_eq( '', sn_prov_verify_sanitize_uid( '3fa85f64-5717-4562-b3fc-2c963f66afa66' ), 'one hex digit long -> blank' );
	vv_eq( '', sn_prov_verify_sanitize_uid( '3fa85f6457174562b3fc2c963f66afa6' ), 'missing dashes -> blank' );
	vv_eq( '', sn_prov_verify_sanitize_uid( '3fa85f64-5717-4562-b3fc-2c963f66afaZ' ), 'a non-hex character -> blank' );
	vv_eq( '', sn_prov_verify_sanitize_uid( "3fa85f64-5717-4562-b3fc-2c963f66afa6' OR '1'='1" ), 'SQL-injection-shaped payload -> blank' );
	vv_eq( '', sn_prov_verify_sanitize_uid( '<script>alert(1)</script>' ), 'markup-shaped payload -> blank' );
}

echo "\nTask 4: version param int-sanitized\n";
if ( vv_require_fn( 'sn_prov_verify_sanitize_version' ) ) {
	vv_eq( 3, sn_prov_verify_sanitize_version( '3' ), 'a plain digit string sanitizes to its int' );
	vv_eq( 7, sn_prov_verify_sanitize_version( '007' ), 'leading zeros sanitize to the int value' );
	vv_eq( 2, sn_prov_verify_sanitize_version( '2.9' ), 'a decimal string truncates to an int' );
	vv_eq( 0, sn_prov_verify_sanitize_version( '' ), 'empty string -> 0 (unset)' );
	vv_eq( 0, sn_prov_verify_sanitize_version( 'abc' ), 'non-numeric text -> 0' );
	vv_eq( 0, sn_prov_verify_sanitize_version( '-5' ), 'a negative number -> 0, never a negative int' );
	vv_eq( 0, sn_prov_verify_sanitize_version( '0' ), 'literal zero -> 0' );
	vv_true( is_int( sn_prov_verify_sanitize_version( '3' ) ), 'return type is always int' );
}

echo "\nTask 5: page render contract (200, noindex, .sn-verify root, config data attributes)\n";
if ( vv_require_fn( 'sn_prov_verify_send' ) ) {
	// Prefilled from a valid ?note=&v= — every config attribute must be present
	// and correct per the spec's Config embedding section.
	$_GET             = array();
	$_GET['note']      = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
	$_GET['v']         = '3';
	$GLOBALS['__vv_status'] = 0;
	ob_start();
	sn_prov_verify_send();
	$html = ob_get_clean();

	vv_eq( 200, $GLOBALS['__vv_status'], 'send() emits a 200 status' );
	vv_true(
		(bool) preg_match( '/<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex[^"\']*["\']/i', $html ),
		'page carries a noindex robots meta tag'
	);
	vv_true( false !== strpos( $html, 'class="sn-verify"' ), 'page root element carries the .sn-verify class' );

	$expected_credential_base = esc_attr( 'https://example.com/wp-json/signal-noise/v1/credential/' );
	$expected_did_url         = esc_attr( 'https://example.com/.well-known/did.json' );
	$expected_keys_url        = esc_attr( 'https://example.com/.well-known/provenance-keys.json' );
	$expected_ledger_base     = esc_attr( 'https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/' );
	$expected_mempool_base    = esc_attr( 'https://mempool.space/api/' );

	vv_true( false !== strpos( $html, 'data-credential-base="' . $expected_credential_base . '"' ), 'data-credential-base is the credential endpoint base' );
	vv_true( false !== strpos( $html, 'data-did-url="' . $expected_did_url . '"' ), 'data-did-url points at /.well-known/did.json' );
	vv_true( false !== strpos( $html, 'data-keys-url="' . $expected_keys_url . '"' ), 'data-keys-url points at /.well-known/provenance-keys.json' );
	vv_true( false !== strpos( $html, 'data-ledger-base="' . $expected_ledger_base . '"' ), 'data-ledger-base is the raw.githubusercontent.com ledger base' );
	vv_true( false !== strpos( $html, 'data-mempool-base="' . $expected_mempool_base . '"' ), 'data-mempool-base is the mempool.space API base' );
	vv_true( false !== strpos( $html, 'data-note="' . esc_attr( $_GET['note'] ) . '"' ), 'data-note is prefilled from the sanitized ?note=' );
	vv_true( false !== strpos( $html, 'data-version="3"' ), 'data-version is prefilled from the sanitized ?v=' );

	// A bad uid must never reach the markup verbatim — sanitized to blank.
	$_GET             = array();
	$_GET['note']      = "'; DROP TABLE wp_posts; --";
	$_GET['v']         = 'not-a-number';
	$GLOBALS['__vv_status'] = 0;
	ob_start();
	sn_prov_verify_send();
	$html_bad = ob_get_clean();
	vv_eq( 200, $GLOBALS['__vv_status'], 'send() still emits 200 even with a malformed ?note=/?v= (page always renders)' );
	vv_true( false !== strpos( $html_bad, 'data-note=""' ), 'a bad uid shape sanitizes to a blank data-note (rejected, not passed through)' );
	vv_true( false !== strpos( $html_bad, 'data-version="0"' ), 'a non-numeric ?v= sanitizes to data-version="0"' );
	vv_true( false === strpos( $html_bad, 'DROP TABLE' ), 'the malicious payload never reaches the rendered markup' );

	// No query params at all: still a valid, blank-prefilled page.
	$_GET             = array();
	$GLOBALS['__vv_status'] = 0;
	ob_start();
	sn_prov_verify_send();
	$html_empty = ob_get_clean();
	vv_eq( 200, $GLOBALS['__vv_status'], 'send() with no query params still emits 200' );
	vv_true( false !== strpos( $html_empty, 'data-note=""' ), 'no ?note= -> blank data-note' );
	vv_true( false !== strpos( $html_empty, 'data-version="0"' ), 'no ?v= -> data-version="0"' );

	// v9.79.2: the pure decision core is a separate script and a hard
	// dependency of the page script — the shell must emit BOTH tags, core
	// FIRST (both defer: deferred scripts execute in document order, the
	// load-order guarantee this enqueue-free route uses in place of WP's
	// dependency graph), both cache-busted with the same ?ver= param.
	$core_tag_pos = strpos( $html_empty, 'assets/js/prov-verify-core.js?ver=' );
	$page_tag_pos = strpos( $html_empty, 'assets/js/prov-verify.js?ver=' );
	vv_true( false !== $core_tag_pos, 'shell emits the prov-verify-core.js script tag with the ?ver= cache-buster' );
	vv_true( false !== $page_tag_pos, 'shell emits the prov-verify.js script tag with the ?ver= cache-buster' );
	// The retraction panel ships HIDDEN and empty. Two properties, because the
	// alarm without the explanation leaves a reader worse off than before they
	// asked, and a panel that renders by default would accuse every Note.
	vv_true( false !== strpos( $html_empty, 'data-role="retraction"' ), 'shell emits the retraction panel container' );
	vv_true(
		preg_match( '/<section class="sn-verify-retraction"[^>]*\shidden/', $html_empty ) === 1,
		'the retraction panel is HIDDEN by default (it must never accuse a Note the JS has not judged)'
	);
	vv_true( false !== strpos( $html_empty, 'data-role="retraction-rows"' ), 'shell emits the rows container the JS fills' );
	// Without its own rule, data-level="retracted" inherits the PASS band and a
	// withdrawn record looks exactly like a verified one — the failure being
	// styled against is visual, so it has to be pinned here rather than noticed.
	$vv_css = file_exists( SNT_PATH . 'assets/css/prov-verify.css' ) ? (string) file_get_contents( SNT_PATH . 'assets/css/prov-verify.css' ) : '';
	vv_true(
		'' !== $vv_css && false !== strpos( $vv_css, '[data-level="retracted"]' ),
		'the retracted verdict band has its OWN styling (it must not inherit the pass band)'
	);
	vv_true( '' !== $vv_css && false !== strpos( $vv_css, '.sn-verify-retraction{' ), 'the retraction panel is styled' );
	// The record is not deleted, and the panel has to SAY so — that is the whole
	// difference between a retraction and an erasure.
	vv_true(
		false !== strpos( $html_empty, 'has <strong>not</strong> been deleted' ),
		'the panel states that the retracted record was kept, not removed'
	);
	vv_true(
		false !== $core_tag_pos && false !== $page_tag_pos && $core_tag_pos < $page_tag_pos,
		'the core script tag precedes the page script tag (dependency order)'
	);
	vv_true(
		(bool) preg_match( '/<script src="[^"]*prov-verify-core\.js[^"]*" defer>/', $html_empty )
			&& (bool) preg_match( '/<script src="[^"]*prov-verify\.js[^"]*" defer>/', $html_empty ),
		'both script tags are defer (document-order execution keeps the dependency guarantee)'
	);
}

echo "\nTask 6: chip Verify-link (sn_prov_render_chip, real module — GREEN not yet done)\n";
// Non-genesis Note: version IS shown on the chip ('&middot; vN'), so its
// Verify link must carry &v=<version>.
update_post_meta( 5, SN_PROV_UID_META, 'uid5' );
update_post_meta(
	5,
	SN_PROV_CHAIN_META,
	array(
		array(
			'version'       => 2,
			'content_hash'  => 'b2',
			'status'        => 'pending',
			'bitcoin_txid'  => str_repeat( 'ab', 32 ),
			'confirmations' => 3,
		),
	)
);
$chip5 = sn_prov_render_chip( 5 );
$expected_verify_link_v = esc_url( 'https://example.com/verify?note=' . rawurlencode( 'uid5' ) . '&v=2' );
vv_true(
	false !== strpos( $chip5, 'href="' . $expected_verify_link_v . '"' ),
	'chip carries an esc_url\'d Verify link to /verify?note=<uid>&v=<version> when a version is shown'
);

// Genesis-only Note: the chip suppresses the version suffix entirely, so the
// Verify link must NOT carry a &v= param either.
update_post_meta( 6, SN_PROV_UID_META, 'uid6' );
update_post_meta(
	6,
	SN_PROV_CHAIN_META,
	array(
		array(
			'version'      => 0,
			'content_hash' => 'g0',
			'status'       => 'genesis',
			'genesis'      => true,
		),
	)
);
$GLOBALS['__vv_options']['sn_prov_genesis'] = array(
	'status'        => 'confirmed',
	'bitcoin_block' => 957359,
);
$chip6 = sn_prov_render_chip( 6 );
$expected_verify_link_g = esc_url( 'https://example.com/verify?note=' . rawurlencode( 'uid6' ) );
vv_true(
	false !== strpos( $chip6, 'href="' . $expected_verify_link_g . '"' ),
	'genesis-only chip carries a Verify link with no version param (none is shown)'
);
vv_true(
	false === strpos( $chip6, rawurlencode( 'uid6' ) . '&v=' ) && false === strpos( $chip6, rawurlencode( 'uid6' ) . '&#038;v=' ) && false === strpos( $chip6, rawurlencode( 'uid6' ) . '&amp;v=' ),
	'genesis-only chip Verify link has no trailing version param'
);

// A plain, unlinked chip (pending, no txid, no explorer target yet) stays
// PLAIN: the shipped contract (tests/provenance-render.php) is zero anchors
// on unlinked chips, and it wins over the Verify affordance — the link
// appears once the chip itself is linkable.
update_post_meta(
	7,
	SN_PROV_UID_META,
	'uid7'
);
update_post_meta(
	7,
	SN_PROV_CHAIN_META,
	array(
		array(
			'version'      => 1,
			'content_hash' => 'p1',
			'status'       => 'pending',
		),
	)
);
$chip7 = sn_prov_render_chip( 7 );
vv_true( false === strpos( $chip7, 'sn-prov-chip-link' ), 'sanity: this fixture chip has no on-chain explorer link' );
vv_true(
	false === strpos( $chip7, '<a ' ),
	'an unlinked chip stays plain — no Verify link, no anchors at all (shipped plain-chip contract)'
);

echo "\nTask 7: JS contract pins (assets/js/prov-verify.js + assets/js/prov-verify-core.js)\n";
// v9.79.2 layout: the page script keeps DOM/fetch/WebCrypto orchestration;
// every pure decision moved VERBATIM into prov-verify-core.js (executable
// under Node — tests/js/prov-verify-core.test.mjs — relayed into this sweep
// by tests/provenance-verify-core.php). Pins that follow moved logic now
// grep the CORE file; environmental pins stay on the page file.
$js_path     = SNT_PATH . 'assets/js/prov-verify.js';
$js_exists   = file_exists( $js_path );
$core_path   = SNT_PATH . 'assets/js/prov-verify-core.js';
$core_exists = file_exists( $core_path );
vv_true( $js_exists, 'assets/js/prov-verify.js exists' );
vv_true( $core_exists, 'assets/js/prov-verify-core.js exists' );
$js   = $js_exists ? (string) file_get_contents( $js_path ) : '';
$core = $core_exists ? (string) file_get_contents( $core_path ) : '';

vv_true( '' !== $js && false !== strpos( $js, 'Ed25519' ), "JS references the 'Ed25519' algorithm literal" );
vv_true( '' !== $js && false !== strpos( $js, 'SHA-256' ), "JS references the 'SHA-256' digest literal" );
vv_true(
	'' !== $core && false !== strpos( $core, 'UNREACHABLE' ) && false !== strpos( $core, 'FAIL' ),
	'core has an UNREACHABLE state distinct from, and alongside, FAIL'
);
vv_true( '' !== $core && false !== strpos( $core, 'sha256:' ), "core strips the 'sha256:' content-hash prefix" );
// v9.73.2: the anchor check must treat the NORMAL no-txid shape (OTS
// block-anchored, no aggregation tx extracted — 20+ of 25 live ledger records)
// as a NOTE with a ledger hash cross-attest, NEVER a FAIL; FAIL is reserved
// for a PRESENT field that contradicts.
vv_true( '' !== $core && false !== strpos( $core, 'Block-anchored via OpenTimestamps at block' ), 'core renders the no-txid confirmed anchor as a block-anchored NOTE, not FAIL' );
vv_true( '' !== $core && false !== strpos( $core, 'A PRESENT field that disagrees is a contradiction; an ABSENT field' ), 'core pins the contradiction-only-FAIL principle for the ledger tie-check' );
vv_true( '' !== $core && preg_match( '/anchorTxid = String\( anchor\.txid \|\| .. \)\.toLowerCase\(\)/', $core ) === 1, 'core case-normalizes the anchor txid before comparison' );
// v9.74.1: the live-match check reads the twin's REAL schema field. The theme's
// .json twin carries content_text/content_html — no bare `content`; reading one
// made live-match report "edited since signing" on EVERY Note, always.
vv_true( '' !== $core && false !== strpos( $core, 'content_text' ), 'core live-match reads the twin schema field content_text (a bare `content` field does not exist)' );
// v9.74.1: when the ledger carries the aggregation txid the credential's chain
// data predates, the triangle completes: mempool must confirm the LEDGER's tx
// at the credential's claimed block before the anchor may PASS.
vv_true( '' !== $core && false !== strpos( $core, 'The ledger record supplies the aggregation transaction' ), 'core completes the anchor triangle via a ledger-supplied txid when hash-attested' );
// The orchestrator must hand the CREDENTIAL'S OWN key id to the agreement
// gate. Without it the gate resolves the ACTIVE key, so the first rotation
// checks every historical Note against today's key and reports correctly
// signed work as unverifiable. The core's behaviour is pinned executably in
// tests/js/prov-verify-core.test.mjs Group 5c; this pins that the page file
// actually CALLS it that way — a correct core reached with three arguments
// is the same bug.
vv_true(
	'' !== $js
		&& preg_match( '/deriveKeyAgreement\(\s*didDoc,\s*siteKeys,\s*ledgerKeys\s*,\s*\S/', $js ) === 1
		&& false !== strpos( $js, 'cred.proof.pubkey_id' ),
	'JS passes the credential proof.pubkey_id into deriveKeyAgreement (not the active-key default)'
);
// RETRACTION WIRING. Three properties, each of which the core cannot enforce
// on its own:
//   1. the verdict band must be told about the retraction, or a withdrawn
//      record still paints "Authentic";
//   2. a retraction must be VERIFIED before it is honoured — an unverified one
//      is a denial-of-service on our own corpus, since anyone able to serve a
//      file at the retraction path could silence any record;
//   3. it must be cleared between lookups, or a stale retraction withdraws an
//      innocent Note.
vv_true(
	'' !== $js && preg_match( '/deriveOverallVerdict\(\s*states\s*,\s*activeRetraction\s*\)/', $js ) === 1,
	'JS passes the active retraction into the verdict (a withdrawn record cannot paint Authentic)'
);
vv_true(
	'' !== $js && false !== strpos( $js, 'signed_payload_b64' )
		&& preg_match( '/deriveKeyAgreement\([^)]*pubkey_id/', $js ) === 1,
	'JS verifies the retraction signature under the key it NAMES before honouring it'
);
vv_true(
	'' !== $js && preg_match( '/resetChecks\(\);.*?activeRetraction = null;/s', $js ) === 1,
	'JS clears the retraction between lookups (no carry-over onto the next record)'
);
// A retraction that does not verify must become UNKNOWN, never silence. Waving
// it through converts an attacker-supplied file into a clean bill of health;
// honouring it lets anyone silence any record. Both directions are wrong, so
// the orchestrator routes every branch through the pure classifier.
// COUNTED, not merely present: a presence check passes while one branch quietly
// returns null again, which is the exact regression this pins against. Six is
// the count checkRetraction reaches today; losing one means a branch stopped
// classifying. Set AT the real count, not below it — a threshold with slack is
// a threshold that tolerates exactly the regression it was written for.
vv_true(
	'' !== $js && substr_count( $js, 'Core.retractionOutcome(' ) >= 6,
	'every retraction branch classifies through retractionOutcome (no silent discard in any one of them)'
);
vv_true(
	'' !== $js && preg_match( '/catch\(\s*function\s*\(\)\s*\{\s*return UNKNOWN;/', $js ) === 1,
	'a failed retraction lookup returns UNKNOWN, never a clean result'
);
vv_true( '' !== $js && false !== strpos( $js, 'location.origin' ), 'JS guards paste mode against a foreign origin' );
vv_true(
	'' !== $js && false === strpos( $js, 'https://' ),
	'JS hardcodes zero https:// URLs (every endpoint comes from data attributes)'
);
vv_true(
	'' !== $core && false === strpos( $core, 'https://' ),
	'core hardcodes zero https:// URLs (bases always arrive as arguments)'
);
// The split contract itself: core defines the single namespaced global plus
// the CommonJS guard (Node require()s the same classic script); the page
// consumes the global and never redefines the moved logic.
vv_true( '' !== $core && false !== strpos( $core, 'window.SNProvVerifyCore' ), 'core assigns the window.SNProvVerifyCore global' );
vv_true( '' !== $core && false !== strpos( $core, "typeof module !== 'undefined' && module.exports" ), 'core carries the CommonJS export guard for Node' );
vv_true( '' !== $js && false !== strpos( $js, 'window.SNProvVerifyCore' ), 'page script consumes the SNProvVerifyCore global' );
vv_true( '' !== $js && false === strpos( $js, 'function roughNormalize' ), 'page script no longer defines the moved normalization (single source in the core)' );

echo "\nTask 8: v9.81.0 consistency batch (rest_url base; visible core-missing state; live-match origin pin; version-compare diff)\n";
// (1c) The credential base must derive via rest_url(), never a hand-built
// /wp-json/ prefix (which dies silently under a customized rest prefix).
$verify_src = (string) file_get_contents( SNT_PATH . 'inc/provenance-verify.php' );
vv_true( false !== strpos( $verify_src, 'rest_url(' ), 'credential base derives via rest_url() (a customized rest prefix survives)' );
vv_true( false === strpos( $verify_src, "'/wp-json/" ), 'no hand-built /wp-json/ prefix remains in the module' );

// (2) A missing SNProvVerifyCore global paints a VISIBLE could-not-load state
// (status line + settled checks), never a bare silent return that leaves four
// perpetual pending stamps.
vv_true( '' !== $js && false !== strpos( $js, 'Could not load the verifier' ), 'shell paints a visible could-not-load status line when the core global is missing' );
vv_true( '' !== $js && false !== strpos( $js, 'The verifier script did not load' ), 'the four checks settle with a named detail instead of blinking pending forever' );

// (1b) checkLiveMatch pins the twin fetch to this origin — the same pin
// resolvePasted() enforces (the credential's live URL is untrusted data).
// Pinned on the INTENT-carrying fragment, not the whole sentence: this is a
// security assertion standing behind a copy string, and the v10.49.1 em-dash
// sweep rewrote the clause in front of it. The tail is what states the
// guarantee, so it is the part worth being brittle about.
vv_true( '' !== $js && false !== strpos( $js, 'rather than fetching a foreign origin' ),
	'live-match refuses a foreign-origin twin URL instead of fetching it' );

// (1a) Core key decodes are wrapped: a corrupt published key is a DISTINCT
// key-corrupt verdict, not an uncaught atob throw.
vv_true( '' !== $core && false !== strpos( $core, 'Key corrupt' ), 'core derives a distinct key-corrupt verdict for undecodable ledger/site keys' );

// (3) Version compare: the pure word-diff lives in the core; the UI is its own
// classic-IIFE asset, escaped by construction (createElement/textContent, no
// innerHTML with payload text); the shell emits its script tag after the page's.
vv_true( '' !== $core && false !== strpos( $core, 'diffWords' ), 'core exports the pure word-level diffWords()' );
$diff_path   = SNT_PATH . 'assets/js/prov-verify-diff.js';
$diff_exists = file_exists( $diff_path );
vv_true( $diff_exists, 'assets/js/prov-verify-diff.js exists (its own small UI asset)' );
$diff = $diff_exists ? (string) file_get_contents( $diff_path ) : '';
vv_true( '' !== $diff && false !== strpos( $diff, 'createElement' ) && false !== strpos( $diff, 'textContent' ), 'diff UI builds DOM via createElement/textContent' );
vv_true( '' !== $diff && false === strpos( $diff, 'innerHTML' ), 'diff UI never touches innerHTML (payload text is untrusted)' );
vv_true( '' !== $diff && false === strpos( $diff, 'https://' ), 'diff UI hardcodes zero https:// URLs (the credential base comes from the data attribute)' );
if ( function_exists( 'sn_prov_verify_send' ) ) {
	$_GET = array();
	ob_start();
	sn_prov_verify_send();
	$html_diff = ob_get_clean();
	$page_pos  = strpos( $html_diff, 'assets/js/prov-verify.js?ver=' );
	$diff_pos  = strpos( $html_diff, 'assets/js/prov-verify-diff.js?ver=' );
	vv_true( false !== $diff_pos, 'shell emits the prov-verify-diff.js script tag with the ?ver= cache-buster' );
	vv_true( false !== $page_pos && false !== $diff_pos && $page_pos < $diff_pos, 'the diff script tag follows the page script tag' );
	vv_true( false !== strpos( $html_diff, 'data-role="compare"' ), 'shell renders the compare section' );
}

$report = ob_get_clean();
echo $report;
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

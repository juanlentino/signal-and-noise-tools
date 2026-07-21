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

echo "\nTask 7: JS contract pins (assets/js/prov-verify.js)\n";
$js_path   = SNT_PATH . 'assets/js/prov-verify.js';
$js_exists = file_exists( $js_path );
vv_true( $js_exists, 'assets/js/prov-verify.js exists' );
$js = $js_exists ? (string) file_get_contents( $js_path ) : '';

vv_true( '' !== $js && false !== strpos( $js, 'Ed25519' ), "JS references the 'Ed25519' algorithm literal" );
vv_true( '' !== $js && false !== strpos( $js, 'SHA-256' ), "JS references the 'SHA-256' digest literal" );
vv_true(
	'' !== $js && false !== strpos( $js, 'UNREACHABLE' ) && false !== strpos( $js, 'FAIL' ),
	'JS has an UNREACHABLE state distinct from, and alongside, FAIL'
);
vv_true( '' !== $js && false !== strpos( $js, 'sha256:' ), "JS strips the 'sha256:' content-hash prefix" );
vv_true( '' !== $js && false !== strpos( $js, 'location.origin' ), 'JS guards paste mode against a foreign origin' );
vv_true(
	'' !== $js && false === strpos( $js, 'https://' ),
	'JS hardcodes zero https:// URLs (every endpoint comes from data attributes)'
);

$report = ob_get_clean();
echo $report;
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

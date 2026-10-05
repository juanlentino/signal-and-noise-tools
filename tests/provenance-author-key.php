<?php
/**
 * The author's countersigning key, published beside the publisher's.
 *
 * The ledger (signal-and-noise-provenance, verify-key-pins.mjs) accepts the
 * author key only when the site's key document carries it with role "author"
 * and the same fields as its key history. Pinned here: nothing changes until
 * the option is set; a malformed or publisher-equal key publishes nothing; the
 * entry comes after the publisher; did.json lists the key as material and
 * never as an assertion method.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_PROV_DID_TEST', true );

ob_start();

$GLOBALS['__options'] = array();
$GLOBALS['__pub']     = base64_encode( str_repeat( "\x01", 32 ) );

if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'status_header' ) ) { function status_header( $c ) { $GLOBALS['__status'] = (int) $c; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'sn_prov_pubkey_b64' ) ) { function sn_prov_pubkey_b64() { return $GLOBALS['__pub']; } }

if ( ! function_exists( 'sn_prov_config' ) ) {
	// Mirrors inc/provenance-webhook.php: constant first, then option.
	function sn_prov_config( $const, $option ) {
		if ( defined( $const ) ) { return (string) constant( $const ); }
		return (string) get_option( $option, '' );
	}
}
// Mirrors inc/provenance-webhook.php's PUBLIC-value resolver: a USABLE option
// first, the wp-config constant as the floor. Called UNGUARDED from
// inc/provenance-did.php on purpose — a missing resolver must fatal here rather
// than silently degrade to the old constant-first order.
if ( ! function_exists( 'sn_prov_public_config' ) ) {
	function sn_prov_public_config( $const, $option, $is_usable = null ) {
		$value = trim( (string) get_option( $option, '' ) );
		if ( '' !== $value && ( null === $is_usable || call_user_func( $is_usable, $value ) ) ) {
			return $value;
		}
		return defined( $const ) ? (string) constant( $const ) : '';
	}
}

require __DIR__ . '/../inc/provenance-did.php';
require __DIR__ . '/../inc/provenance-author-key.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$author_raw = str_repeat( "\x07", 32 );
$author     = array( 'id' => 'sn-author-ed25519-2026-10', 'public_key_base64' => base64_encode( $author_raw ), 'introduced_at' => '2026-10-05' );

// Unconfigured: the documents are exactly what they were.
$before_keys = sn_prov_key_document();
$before_did  = sn_prov_did_document();
ok( null === sn_prov_author_key() && null === sn_prov_author_key_txt(), 'no option, no author key' );
ok( 1 === count( $before_keys['keys'] ) && 1 === count( $before_did['verificationMethod'] ), 'an unconfigured site serves one key in each document' );

// Configured.
$GLOBALS['__options']['sn_prov_author_key'] = $author;
$doc  = sn_prov_key_document();
$last = end( $doc['keys'] );
ok( 'active' === $doc['keys'][0]['status'] && ! isset( $doc['keys'][0]['role'] ), 'the publisher key stays first' );
ok( 'author' === $last['role'] && 'sn-author-ed25519-2026-10' === $last['id'], 'the author key follows, with its role' );
ok( hash( 'sha256', $author_raw ) === $last['sha256_fingerprint'] && '2026-10-05' === $last['valid_from'] && null === $last['valid_until'] && 'active' === $last['status'], 'its fields match what the ledger compares' );
$did = sn_prov_did_document();
ok( in_array( sn_prov_did_id() . '#sn-author-ed25519-2026-10', array_column( $did['verificationMethod'], 'id' ), true ), 'did.json lists the author key as key material' );
ok( array( sn_prov_did_id() . '#prov-key-1' ) === $did['assertionMethod'], 'and never as an assertion method' );
ok( sprintf( 'v=sn-prov1; id=sn-author-ed25519-2026-10; alg=Ed25519; key=%s; sha256=%s', $author['public_key_base64'], hash( 'sha256', $author_raw ) ) === sn_prov_author_key_txt(), 'the DNS value is in the publisher record\'s format' );

// Refused shapes publish nothing.
foreach ( array(
	'bad id'          => array( 'id' => 'sn-ed25519-2026-10' ) + $author,
	'short key'       => array( 'public_key_base64' => base64_encode( 'x' ) ) + $author,
	'bad date'        => array( 'introduced_at' => 'October' ) + $author,
	'the publisher'   => array( 'public_key_base64' => $GLOBALS['__pub'] ) + $author,
	'not an array'    => 'sn-author-ed25519-2026-10',
) as $label => $value ) {
	$GLOBALS['__options']['sn_prov_author_key'] = $value;
	ok( null === sn_prov_author_key() && 1 === count( sn_prov_key_document()['keys'] ), "$label: refused, nothing published" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

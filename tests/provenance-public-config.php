<?php
/**
 * The PUBLIC-value resolver: option first, wp-config constant as the floor.
 *
 * sn_prov_config() puts the constant first, and for SECRETS that is right —
 * wp-config.php is harder to tamper with than the database. But the signing
 * key's PUBLIC half, its id and its introduction date are not secrets: they are
 * published at /.well-known/ for anyone to read. Constant-first made them
 * unwritable by the plugin, so a rotation could not take effect without a human
 * editing wp-config.php — and until v13.41.0 the panel told them to delete the
 * line, which resolves the key to '' and 404s the site's published identity.
 *
 * Inverting the precedence for public values removes the edit entirely. The
 * constant stays as a disaster-recovery floor that serves whenever the option
 * is absent or unusable.
 *
 * @since 13.42.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_PROV_WEBHOOK_TEST', true );

$GLOBALS['__options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $n ] : $d; }
}
if ( ! function_exists( 'update_option' ) ) { function update_option( $n, $v ) { $GLOBALS['__options'][ $n ] = $v; return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }

require __DIR__ . '/../inc/provenance-webhook.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "provenance public-value config — option first, constant as the floor\n";

$KEY_A = base64_encode( str_repeat( "\x01", 32 ) );
$KEY_B = base64_encode( str_repeat( "\x02", 32 ) );

echo "\nGroup: the validator decides whether an option may supersede\n";
ok( true === sn_prov_is_ed25519_public_key( $KEY_A ), 'a 32-byte base64 key validates' );
ok( false === sn_prov_is_ed25519_public_key( base64_encode( 'short' ) ), 'a short value does not' );
ok( false === sn_prov_is_ed25519_public_key( 'not base64 !!' ), 'nor does something that is not base64' );
ok( false === sn_prov_is_ed25519_public_key( '' ), 'nor an empty string' );

echo "\nGroup: with NO constant defined\n";
$GLOBALS['__options'] = array();
ok( '' === sn_prov_pubkey_b64(), 'nothing configured anywhere resolves to empty' );
$GLOBALS['__options'] = array( 'sn_prov_pubkey_b64' => $KEY_A );
ok( $KEY_A === sn_prov_pubkey_b64(), 'the option alone is used' );

echo "\nGroup: WITH a constant defined — the option supersedes it\n";
define( 'SN_PROV_PUBKEY_B64', $KEY_B );
ok( $KEY_A === sn_prov_pubkey_b64(),
	'a VALID option now wins over the constant — this is what lets a rotation take effect without editing wp-config.php' );

$GLOBALS['__options'] = array();
ok( $KEY_B === sn_prov_pubkey_b64(),
	'with no option the constant still serves — it is the disaster-recovery floor, so the key can never vanish' );

// The whole safety of inverting: an option may only WIN if it is usable. A
// blank or corrupt row must never take the site's published key to nothing,
// which is exactly the outage v13.41.0 was written to prevent.
$GLOBALS['__options'] = array( 'sn_prov_pubkey_b64' => '' );
ok( $KEY_B === sn_prov_pubkey_b64(), 'a BLANK option does not win — the constant still serves' );
$GLOBALS['__options'] = array( 'sn_prov_pubkey_b64' => '   ' );
ok( $KEY_B === sn_prov_pubkey_b64(), 'nor does whitespace' );
$GLOBALS['__options'] = array( 'sn_prov_pubkey_b64' => 'garbage-not-a-key' );
ok( $KEY_B === sn_prov_pubkey_b64(), 'nor does a value that is not a 32-byte Ed25519 key' );
$GLOBALS['__options'] = array( 'sn_prov_pubkey_b64' => base64_encode( str_repeat( "\x03", 31 ) ) );
ok( $KEY_B === sn_prov_pubkey_b64(), 'nor a 31-byte one — length is checked, not just decodability' );

echo "\nGroup: SECRETS keep constant-first — this inversion is for PUBLIC values only\n";
define( 'SN_PROV_HMAC_SECRET', 'from-wp-config' );
$GLOBALS['__options'] = array( 'sn_prov_hmac_secret' => 'from-database' );
ok( 'from-wp-config' === sn_prov_hmac_secret(),
	'the HMAC secret still takes the CONSTANT first: wp-config.php is harder to tamper with than the database, and that argument holds for secrets even though it does not for published values' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

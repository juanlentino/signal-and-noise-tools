<?php
/**
 * `signal-noise/keyring-status` (15.2.0): sources and verdicts, never a value.
 * Run: php tests/abilities-keyring-status.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__opt'] = array(); $GLOBALS['__settings'] = array();
function __( $s, $d = null ) { return $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function sn_setting( $path, $d = null ) { return $GLOBALS['__settings'][ $path ] ?? $d; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/keyring-verify.php';
require dirname( __DIR__ ) . '/inc/abilities-keyring-status.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$out = snt_ability_keyring_status();
ok( 'never_verified' === $out['state'] && null === $out['verified_at'] && count( sn_keyring() ) === count( $out['rows'] ), 'never verified: one row per credential, no time' );
$GLOBALS['__opt']['sn_cf_api_token'] = 'cf-secret-value';
$GLOBALS['__opt'][ SN_KEYRING_VERDICTS_OPT ] = array( 'cf_token' => array( 'status' => 'ok', 'detail' => 'Cloudflare answers active (user token).', 'at' => 1789500000 ), 'mr_read_token' => array( 'status' => 'refused', 'detail' => "differ", 'at' => 1789500000 ) );
$out = snt_ability_keyring_status();
$by  = array_column( $out['rows'], null, 'id' );
ok( 'verified' === $out['state'] && 1789500000 === $out['verified_at'], 'verified: the state and the latest time' );
ok( 'option' === $by['cf_token']['source'] && true === $by['cf_token']['set'] && 'ok' === $by['cf_token']['verdict'] && false === $by['cf_token']['derives'], 'a saved row: source option, set, its verdict, does not derive' );
ok( '' === $by['mr_read_token']['source'] && false === $by['mr_read_token']['set'] && 'refused' === $by['mr_read_token']['verdict'] && true === $by['mr_read_token']['derives'], 'an unset handshake row: no source, not set, its verdict, can derive' );
ok( null === $by['cf_zone']['verdict'] && null === $by['cf_zone']['detail'], 'a row never verified carries null, not a string' );
ok( false === strpos( json_encode( $out ), 'cf-secret-value' ), 'the payload never carries a value' );
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { $cb(); }
$a = $GLOBALS['__abilities']['signal-noise/keyring-status'] ?? null;
ok( is_array( $a ) && true === $a['meta']['annotations']['readonly'] && 'snt_ability_keyring_status' === $a['execute_callback'] && false !== strpos( $a['description'], 'NEVER a value' ), 'registered read-only, its description says never a value' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

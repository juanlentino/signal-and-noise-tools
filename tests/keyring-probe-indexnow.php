<?php
/**
 * The IndexNow key's probe: the key file fetched at its public URL, through
 * the edge, must answer this key and nothing else. No verdict ever carries
 * the key or the body. Run: php tests/keyring-probe-indexnow.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__opt'] = array(); $GLOBALS['__http'] = null; $GLOBALS['__calls'] = array();
function __( $s, $d = null ) { return $s; }
function apply_filters( $t, $v ) { return $v; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__calls'][] = array( $url, $args ); return $GLOBALS['__http']; }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/keyring-verify.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$row  = sn_keyring()['indexnow_key'];
$key  = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
$seen = array();
$run  = static function ( $http ) use ( $row, &$seen ) { $GLOBALS['__http'] = $http; $GLOBALS['__calls'] = array(); $v = sn_keyring_probe( 'indexnow_key', $row ); $seen[] = $v['detail']; return $v; };
echo "keyring: the IndexNow key probe\n";

ok( 'indexnow' === ( $row['probe'] ?? '' ), 'the row names a probe, so Verify all no longer reads it as "no probe"' );
$v = $run( array( 'response' => array( 'code' => 200 ), 'body' => 'x' ) );
ok( 'unset' === $v['status'] && array() === $GLOBALS['__calls'], 'no key stored: unset, and no request is made' );

$GLOBALS['__opt']['sn_indexnow_key'] = $key;
$v = $run( array( 'response' => array( 'code' => 200 ), 'body' => $key . "\n" ) );
$c = $GLOBALS['__calls'][0] ?? array( '', array() );
ok( 'ok' === $v['status'], 'the key file answers the key: ok (a trailing newline is not a mismatch)' );
ok( "https://example.test/$key.txt" === $c[0] && 6 === $c[1]['timeout'] && 0 === $c[1]['redirection'] && true === $c[1]['sslverify'], 'one GET of the public key URL, with the probes\' timeout, no redirects, TLS verified' );
ok( 'refused' === $run( array( 'response' => array( 'code' => 200 ), 'body' => 'f00ba4' . $key ) )['status'], 'a body that is not exactly the key is refused' );
$v = $run( array( 'response' => array( 'code' => 404 ), 'body' => 'Not found' ) );
ok( 'refused' === $v['status'] && false !== strpos( $v['detail'], 'HTTP 404' ), 'a 404 is refused and names the status (IndexNow off serves no key file)' );
ok( 'error' === $run( array( 'response' => array( 'code' => 503 ), 'body' => '' ) )['status'], 'a 5xx is an error, not a refusal' );
$v = $run( new WP_Error( 'http', 'cURL error 28: timed out' ) );
ok( 'error' === $v['status'] && false !== strpos( $v['detail'], 'timed out' ), 'a transport failure is an error with its message' );
ok( array() === array_filter( $seen, static fn( $d ) => false !== strpos( $d, $key ) || false !== strpos( $d, 'f00ba4' ) ), 'no verdict carries the key or the body' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

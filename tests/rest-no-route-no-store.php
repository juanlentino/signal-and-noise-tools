<?php
/**
 * Standalone test: a rest_no_route answer is never stored by a shared cache (2026-10-17).
 * Run: php tests/rest-no-route-no-store.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
$GLOBALS['__filters'] = array();
function add_filter( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $h ][] = $c; }
function __( $t, $d = 'default' ) { return $t; }
class WP_Error { public function __construct( $c = '', $m = '', $d = array() ) {} }
class Fake_Response {
	public $headers = array();
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/rest-no-route.php';

$hooked = in_array( 'sn_rest_no_route_no_store', $GLOBALS['__filters']['rest_post_dispatch'] ?? array(), true );
ok( $hooked, 'the no-store filter rides rest_post_dispatch' );
$r = new Fake_Response( array( 'code' => 'rest_no_route', 'message' => 'x', 'data' => array( 'status' => 404 ) ) );
if ( $hooked ) { sn_rest_no_route_no_store( $r ); }
ok( 'no-store' === ( $r->headers['Cache-Control'] ?? '' ) && 'no-store' === ( $r->headers['Cloudflare-CDN-Cache-Control'] ?? '' ), 'a rest_no_route 404 (ours or core\'s) is never stored by Cloudflare or a browser' );
$ok = new Fake_Response( array( 'revoked' => array() ) );
if ( $hooked ) { sn_rest_no_route_no_store( $ok ); }
ok( array() === $ok->headers, 'any other response keeps its own caching headers' );
$err = new Fake_Response( array( 'code' => 'rest_forbidden' ) );
if ( $hooked ) { sn_rest_no_route_no_store( $err ); }
ok( array() === $err->headers, 'other errors are left alone' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );

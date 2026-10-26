<?php
/**
 * The write door's bound credential reaches the write door only (2026-10-26
 * MCP doors audit, N5 and N0): /signal-noise/v1/mcp-rw and the run routes of
 * write-door tools. Off-door abilities (clear-template-overrides, merge-tags)
 * and core routes (/wp/v2/*) are refused. Other credentials and cookie sessions
 * are untouched.
 *
 * Run: php tests/mcp-rw-credential-scope.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

class WP_Error {
	public $code; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->data = $d; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function apply_filters( $t, $v ) { return $v; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $t ][ $c ] = $p; return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function rest_get_authenticated_app_password() { return $GLOBALS['__app_pw'] ?? null; }
function sn_mcp_rw_audit_record( $slug, $args, $outcome, $err = null ) { $GLOBALS['__audit'][] = array( $slug, $outcome ); return array(); }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';
require __DIR__ . '/../inc/mcp/mcp-rw-credential-scope.php';

class SC_Req { private $r; public function __construct( $r ) { $this->r = $r; } public function get_route() { return $this->r; } }
$BOUND = '11111111-2222-4333-8444-555555555555';
$OTHER = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$GLOBALS['__options'] = array( 'sn_mcp_rw_app_password_uuid' => $BOUND );
$run   = static function ( $slug ) { return '/wp-abilities/v1/abilities/' . $slug . '/run'; };
$scope = static function ( $route ) { return sn_mcp_rw_credential_scope( null, null, new SC_Req( $route ) ); };
$door  = sn_mcp_rw_allowlist();

ok( 9 === ( $GLOBALS['__filters']['rest_pre_dispatch']['sn_mcp_rw_credential_scope'] ?? null ), 'hooked on rest_pre_dispatch at 9, before the door guards at 10' );

echo "\nThe bound credential\n";
$GLOBALS['__app_pw'] = $BOUND;
$GLOBALS['__audit']  = array();
ok( null === $scope( '/signal-noise/v1/mcp-rw' ) && null === $scope( '/Signal-Noise/V1/MCP-RW/' ), 'reaches the door, in any letter case, with or without a trailing slash' );
ok( null === $scope( $run( $door[0] ) ), "reaches a write-door tool's run route ($door[0])" );
foreach ( array( 'signal-noise/clear-template-overrides', 'signal-noise/merge-tags', 'signal-noise/run-cron-event', 'signal-noise/get-post-content' ) as $off ) {
	$r = $scope( $run( $off ) );
	ok( is_wp_error( $r ) && 'sn_mcp_rw_credential_out_of_scope' === $r->get_error_code() && 403 === $r->data['status'], "is refused an off-door ability ($off)" );
}
foreach ( array( '/wp/v2/posts', '/wp/v2/settings', '/wp/v2/users', '/signal-noise/v1/mcp', '/' ) as $core ) {
	ok( is_wp_error( $scope( $core ) ), "is refused $core" );
}
ok( is_wp_error( $scope( '/wp-abilities/v1/abilities/' . $door[0] . '/run/extra' ) ), 'a route that merely contains a door tool is still refused' );
ok( in_array( array( 'route:/wp/v2/posts', 'denied' ), $GLOBALS['__audit'], true ), 'each refusal is a denied row in the rw audit log' );
$missed = array_filter( $door, static function ( $s ) use ( $scope, $run ) { return null !== $scope( $run( $s ) ); } );
ok( count( $door ) > 5 && array() === $missed, 'every write-door tool is reachable (' . count( $door ) . ' checked)' );

echo "\nEveryone else is untouched\n";
$GLOBALS['__app_pw'] = $OTHER;
ok( null === $scope( '/wp/v2/posts' ), 'another Application Password is not this guard\'s business' );
$GLOBALS['__app_pw'] = null;
ok( null === $scope( '/wp/v2/posts' ), 'a cookie session (no Application Password) is untouched' );
$GLOBALS['__options'] = array();
$GLOBALS['__app_pw']  = $BOUND;
ok( null === $scope( '/wp/v2/posts' ), 'with no credential bound, nothing is scoped' );
$GLOBALS['__options'] = array( 'sn_mcp_rw_app_password_uuid' => $BOUND );
$prior = new WP_Error( 'earlier', 'x' );
ok( $prior === sn_mcp_rw_credential_scope( $prior, null, new SC_Req( '/wp/v2/posts' ) ), 'an earlier answer is never overridden' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );

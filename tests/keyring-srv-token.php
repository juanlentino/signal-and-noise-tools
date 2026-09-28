<?php
/**
 * The analytics server token resolves through the keyring on every reader:
 * with no SN_SRV_TOKEN constant and a value saved in Connections › Credentials,
 * the refresh route, the RSS tracker and the settings pill all use the saved
 * value; unset, the route's 503 names the keyring row, not wp-config.
 * Run: php tests/keyring-srv-token.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_BEACON_TOKEN', 'beacon' ); // keeps the beacon pill green so the asserts read the server-token pill only
$GLOBALS['__opt'] = array(); $GLOBALS['__f'] = array();
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function add_action() { return true; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__f'][ $t ][] = $c; return true; }
function apply_filters( $t, $v ) { foreach ( $GLOBALS['__f'][ $t ] ?? array() as $c ) { $v = $c( $v ); } return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function sn_setting( $p, $d = null ) { return $d; }
class WP_Error { public $c; public $m; public $d; function __construct( $c = '', $m = '', $d = array() ) { $this->c = $c; $this->m = $m; $this->d = $d; } }
class Req { function get_header( $h ) { return $GLOBALS['__hdr'] ?? ''; } }
function sn_worker_version_get( $f = false ) { return array( 'ok' => false, 'data' => array() ); }
function sn_analytics_config() { return array( 'account' => 'a' ); }
function sn_cf_get_zone() { return 'z'; }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/analytics-refresh-rest.php';
require dirname( __DIR__ ) . '/inc/rss-feed-tracker.php';
require dirname( __DIR__ ) . '/inc/analytics-render-settings.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function pills() { ob_start(); snt_analytics_render_pipeline_status(); $h = ob_get_clean(); if ( getenv( "DUMP" ) ) { echo $h, "\n"; } return $h; }
echo "keyring-srv-token -- every reader resolves through the keyring\n";

ok( ! defined( 'SN_SRV_TOKEN' ), 'precondition: no SN_SRV_TOKEN constant' );
$e = sn_analytics_refresh_permission( new Req() );
ok( $e instanceof WP_Error && 503 === $e->d['status'] && false !== strpos( $e->m, 'set it in Connections › Credentials (Analytics server token)' ) && false === strpos( $e->m, 'SN_SRV_TOKEN' ), 'unset: 503 names the keyring row, not the constant' );
$h = pills();
ok( false !== strpos( $h, 'Server token missing' ) && false !== strpos( $h, 'Connections › Credentials' ) && false === strpos( $h, 'wp-config' ), 'unset: the pill points at the keyring row' );

$GLOBALS['__opt']['sn_srv_token'] = 'saved-srv-42';
ok( 'saved-srv-42' === sn_analytics_refresh_secret(), 'refresh route: constant absent, saved value used' );
ok( 'saved-srv-42' === sn_rss_tracker_server_token(), 'RSS tracker: constant absent, saved value used (sn_server_token filter)' );
$GLOBALS['__hdr'] = 'saved-srv-42';
ok( true === sn_analytics_refresh_permission( new Req() ), 'the route accepts the saved value' );
$GLOBALS['__hdr'] = 'old-value';
$e = sn_analytics_refresh_permission( new Req() );
ok( $e instanceof WP_Error && 403 === $e->d['status'], 'a different value is 403' );
$h = pills();
ok( false !== strpos( $h, 'Server token set' ) && false === strpos( $h, 'missing' ) && false === strpos( $h, 'saved-srv-42' ), 'the pill says set from the saved value, and never echoes it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

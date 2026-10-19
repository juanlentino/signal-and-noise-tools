<?php
/**
 * Worker health in the daily Cloudflare monitor: one GraphQL read of
 * workersInvocationsAdaptive per day, per script. A refused read names the
 * scope and is never zero; nothing is judged without a rule.
 * Run: php tests/cloudflare-monitor-workers.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_CF_API_BASE', 'https://api.cloudflare.com/client/v4' );
$GLOBALS['__opt'] = array(); $GLOBALS['__calls'] = array(); $GLOBALS['__http'] = array();
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function add_action( $h, $cb, $p = 10, $a = 1 ) {}
function wp_admin_notice( $m, $a = array() ) { echo "<div class=\"notice\">$m</div>"; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function sn_cf_get_token() { return 'tok'; }
function sn_cf_get_account_id() { return $GLOBALS['__acct'] ?? ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['__calls'][] = json_decode( (string) $args['body'], true ); return $GLOBALS['__http']; }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
require dirname( __DIR__ ) . '/inc/cloudflare-monitor.php';
require dirname( __DIR__ ) . '/inc/abilities-cloudflare-status.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$j   = static fn( $code, array $body ) => array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
$row = static fn( $script, $status, $req, $err, $sub, $p50, $p99 ) => array( 'sum' => array( 'requests' => $req, 'errors' => $err, 'subrequests' => $sub ), 'quantiles' => array( 'cpuTimeP50' => $p50, 'cpuTimeP99' => $p99 ), 'dimensions' => array( 'scriptName' => $script, 'status' => $status ) );
$ok  = $j( 200, array( 'data' => array( 'viewer' => array( 'accounts' => array( array( 'workersInvocationsAdaptive' => array(
	$row( 'sn-analytics', 'success', 900, 0, 50, 1200, 4800 ),
	$row( 'sn-analytics', 'scriptThrewException', 100, 100, 0, 3000, 9000 ),
	$row( 'sn-login-guard', 'success', 40, 0, 40, 500, 700 ),
) ) ) ) ) ) );
$now = strtotime( '2026-10-19 06:00:00 UTC' );
echo "Worker health\n";

$w = sn_cf_monitor_workers_read( $now );
ok( false === $w['available'] && false === $w['needs_permission'] && false !== strpos( $w['error'], 'account ID' ) && array() === $GLOBALS['__calls'], 'no account ID: unavailable with the reason, and no request' );

$GLOBALS['__acct'] = 'acct1'; $GLOBALS['__http'] = $ok;
$w = sn_cf_monitor_workers_read( $now );
$q = $GLOBALS['__calls'][0] ?? array();
ok( false !== strpos( (string) ( $q['query'] ?? '' ), 'workersInvocationsAdaptive' ) && 'acct1' === ( $q['variables']['account'] ?? '' ), 'one GraphQL read of workersInvocationsAdaptive for the account' );
ok( '2026-10-19T06:00:00Z' === ( $q['variables']['until'] ?? '' ) && '2026-10-18T06:01:00Z' === ( $q['variables']['since'] ?? '' ), 'the last day, both bounds explicit, a minute under a day' );
ok( true === $w['available'] && array( 'sn-analytics', 'sn-login-guard' ) === array_column( $w['scripts'], 'script' ), 'available, one entry per script, busiest first' );
$a = $w['scripts'][0];
ok( 1000 === $a['requests'] && 100 === $a['errors'] && 50 === $a['subrequests'] && 10.0 === $a['error_share'], 'counts sum across statuses; the error share is errors over requests, in percent' );
ok( array( 'success' => 900, 'scriptThrewException' => 100 ) === $a['status'], 'requests by status are kept' );
ok( 1.2 === $a['cpu_p50_ms'] && 4.8 === $a['cpu_p99_ms'], 'CPU quantiles (microseconds) read as milliseconds, from the status with the most requests: quantiles do not add' );
ok( ! array_key_exists( 'flag', $a ) && ! array_key_exists( 'verdict', $a ), 'shown, not judged: no configured cpu_ms is known here, so there is no rule' );

$GLOBALS['__http'] = $j( 200, array( 'data' => null, 'errors' => array( array( 'message' => 'not authorized for that account', 'extensions' => array( 'code' => 9109 ) ) ) ) );
$w = sn_cf_monitor_workers_read( $now );
ok( false === $w['available'] && true === $w['needs_permission'] && 'Account › Account Analytics › Read' === $w['scope'] && array() === $w['scripts'], 'a refused read says needs_permission and names the scope, never zero' );
ok( false !== strpos( sn_cf_monitor_permission_hint( 'workers' ), 'Account › Account Analytics › Read' ), 'and the permission sentence names it too' );
$GLOBALS['__http'] = new WP_Error( 'x', 'timed out' );
$w = sn_cf_monitor_workers_read( $now );
ok( false === $w['available'] && false === $w['needs_permission'] && 'timed out' === $w['error'], 'a transport failure is an error, not a permission gap' );

echo "\nWhere it shows\n";
$GLOBALS['__opt'][ SN_CF_MONITOR_OPT ] = array( 'fetched_at' => 1, 'configured' => true, 'token' => array(), 'zone' => array(), 'firewall' => array(), 'workers' => array( 'available' => true ) );
ok( array( 'available' => true ) === snt_ability_cloudflare_status()['workers'], 'cloudflare-status carries the stored workers reading' );
unset( $GLOBALS['__opt'][ SN_CF_MONITOR_OPT ]['workers'] );
ok( null === snt_ability_cloudflare_status()['workers'], 'a record stored before reads null, not an empty reading' );
$GLOBALS['__http'] = $ok;
ob_start(); sn_cf_workers_card_render( array( 'workers' => sn_cf_monitor_workers_read( $now ) ) ); $html = ob_get_clean();
ok( false !== strpos( $html, '<th scope="row">sn-analytics</th><td>1,000</td><td>10.0%</td><td>50</td><td>1.2 ms</td><td>4.8 ms</td>' ), 'the Edge card shows a row per worker: requests, error share, subrequests, CPU p50 and p99' );
ob_start(); sn_cf_workers_card_render( array( 'workers' => array( 'available' => false, 'needs_permission' => true, 'scope' => 'Account › Account Analytics › Read', 'scripts' => array() ) ) ); $html = ob_get_clean();
ok( false !== strpos( $html, 'needs Account › Account Analytics › Read' ) && false === strpos( $html, '<td>0</td>' ), 'a refused read shows the scope it needs, not a table of zeros' );
ob_start(); sn_cf_workers_card_render( array() ); ok( '' === ob_get_clean(), 'a record from before prints nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

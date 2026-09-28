<?php
/**
 * The keyring's probes (15.2.0): each verdict names the side that broke,
 * through stubbed transports. Run: php tests/keyring-verify.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_MR_DEFAULT_ENDPOINT', 'https://juanlentino.com/_sn/rights-signals/machine-readers' );
define( 'SN_SPOTIFY_TOKEN_KEY', 'sn_spotify_token' );
$GLOBALS['__opt'] = array(); $GLOBALS['__filters'] = array(); $GLOBALS['__settings'] = array(); $GLOBALS['__http'] = array(); $GLOBALS['__calls'] = array();
function __( $s, $d = null ) { return $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function delete_transient( $k ) { $GLOBALS['__deleted'][] = $k; return true; }
function sn_setting( $path, $d = null ) { return $GLOBALS['__settings'][ $path ] ?? $d; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__calls'][] = array( $url, $args ); $k = strtok( $url, '?' ); return $GLOBALS['__http'][ $k ] ?? array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function sn_cf_monitor_verify( $z, $token = null ) { $GLOBALS['__cf_token'] = $token; return null === $token ? $GLOBALS['__cf'] : ( $GLOBALS['__cf_override'] ?? $GLOBALS['__cf'] ); }
function sn_uptime_status_api_get( $r ) { return $GLOBALS['__bs']; }
function sn_worker_version_get( $force = false ) { $GLOBALS['__wv_force'] = $force; return $GLOBALS['__wv']; }
function sn_spotify_token() { return $GLOBALS['__spotify'] ?? ''; }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/keyring-verify.php';
require dirname( __DIR__ ) . '/inc/admin-post-actions/keyring.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$rows = sn_keyring();
echo "keyring-verify -- each verdict names the side (15.2.0)\n";

// ── Unset rows are never probed.
$v = sn_keyring_probe( 'mr_read_token', $rows['mr_read_token'] );
ok( 'refused' === $v['status'] && false !== strpos( $v['detail'], 'plugin side' ) && array() === $GLOBALS['__calls'], 'an unset worker row is refused on the plugin side, and no request is made' );
$v = sn_keyring_probe( 'github_token', $rows['github_token'] );
ok( 'unset' === $v['status'] && array() === $GLOBALS['__calls'], 'an unset issued row is "unset" and no request is made' );

// ── The sensor: 200 / 401 / 502 / 503 / other, each its own sentence.
$GLOBALS['__settings']['machine_readers.read_token'] = 'mr-1';
foreach ( array( 200 => array( 'ok', 'accepts this token' ), 401 => array( 'refused', "worker's SN_MR_READ_TOKEN differ" ), 502 => array( 'error', 'SN_MR_SQL_TOKEN needs Account › Account Analytics › Read' ), 503 => array( 'error', 'not configured' ), 500 => array( 'error', 'HTTP 500' ) ) as $code => $exp ) {
	$GLOBALS['__http'][ SN_MR_DEFAULT_ENDPOINT ] = array( 'response' => array( 'code' => $code ), 'body' => '{}' );
	$GLOBALS['__calls'] = array();
	$v = sn_keyring_probe( 'mr_read_token', $rows['mr_read_token'] );
	ok( $exp[0] === $v['status'] && false !== strpos( $v['detail'], $exp[1] ) && 'Bearer mr-1' === $GLOBALS['__calls'][0][1]['headers']['Authorization'] && 0 === (int) $GLOBALS['__calls'][0][1]['redirection'], "sensor $code → {$exp[0]}: '{$exp[1]}', sent with the row's bearer, no redirects" );
}
$titles = array();
foreach ( array( 200, 401, 502, 503 ) as $code ) { $GLOBALS['__http'][ SN_MR_DEFAULT_ENDPOINT ] = array( 'response' => array( 'code' => $code ), 'body' => '' ); $titles[] = sn_keyring_probe( 'mr_read_token', $rows['mr_read_token'] )['detail']; }
ok( 4 === count( array_unique( $titles ) ), 'four sensor answers, four different sentences' );
$GLOBALS['__http'][ SN_MR_DEFAULT_ENDPOINT ] = new WP_Error( 'x', 'cURL error 28' );
ok( 'error' === sn_keyring_probe( 'mr_read_token', $rows['mr_read_token'] )['status'], 'a transport failure is an error, not a refusal' );

// ── Cloudflare: through the monitor's verify.
$GLOBALS['__opt']['sn_cf_api_token'] = 'cf-1';
$GLOBALS['__cf'] = array( 'verified' => true, 'status' => 'active', 'kind' => 'user', 'error' => '' );
$v = sn_keyring_probe( 'cf_token', $rows['cf_token'] );
ok( 'ok' === $v['status'] && false !== strpos( $v['detail'], 'active' ) && false !== strpos( $v['detail'], 'user' ), 'Cloudflare ok names status and kind' );
$GLOBALS['__cf'] = array( 'verified' => false, 'status' => 'invalid', 'kind' => '', 'error' => 'Invalid request headers' );
ok( 'refused' === sn_keyring_probe( 'cf_token', $rows['cf_token'] )['status'], 'an invalid Cloudflare token is refused' );
$GLOBALS['__cf'] = array( 'verified' => false, 'status' => 'unreachable', 'kind' => '', 'error' => 'timeout' );
ok( 'error' === sn_keyring_probe( 'cf_token', $rows['cf_token'] )['status'], 'an unreachable Cloudflare is an error' );

// ── 15.2.2: the analytics override verifies with ITS value, not the central token (15.3.1: probe kind cloudflare_token).
$GLOBALS['__opt']['sn_cf_analytics_token'] = 'override-dead';
$GLOBALS['__cf_override'] = array( 'verified' => false, 'status' => 'invalid', 'kind' => '', 'error' => 'Invalid access token' );
$v = sn_keyring_probe( 'cf_analytics_override', $rows['cf_analytics_override'] );
ok( 'refused' === $v['status'] && 'override-dead' === $GLOBALS['__cf_token'], 'a dead override reads refused, verified with the override\'s own bytes' );
unset( $GLOBALS['__opt']['sn_cf_analytics_token'] );
ok( 'unset' === sn_keyring_probe( 'cf_analytics_override', $rows['cf_analytics_override'] )['status'], 'no override, nothing to verify' );

// ── Better Stack, Spotify, GitHub.
$GLOBALS['__opt']['sn_betterstack_api_token'] = 'bs-1';
$GLOBALS['__bs'] = array( 'data' => array( 1, 2, 3 ) );
ok( 'ok' === sn_keyring_probe( 'betterstack_token', $rows['betterstack_token'] )['status'] && false !== strpos( sn_keyring_probe( 'betterstack_token', $rows['betterstack_token'] )['detail'], '3 monitor' ), 'Better Stack ok counts monitors' );
$GLOBALS['__bs'] = new WP_Error( 'x', 'Better Stack returned HTTP 401 (v2/monitors).' );
ok( 'refused' === sn_keyring_probe( 'betterstack_token', $rows['betterstack_token'] )['status'], 'Better Stack 401 is refused' );
$GLOBALS['__opt']['sn_spotify_client_secret'] = 'sp-1';
$GLOBALS['__spotify'] = 'access'; $GLOBALS['__deleted'] = array();
ok( 'ok' === sn_keyring_probe( 'spotify_client_secret', $rows['spotify_client_secret'] )['status'] && in_array( SN_SPOTIFY_TOKEN_KEY, $GLOBALS['__deleted'], true ), 'Spotify ok, after dropping the cached token so a rotated secret cannot pass on cache' );
$GLOBALS['__spotify'] = '';
ok( 'refused' === sn_keyring_probe( 'spotify_client_secret', $rows['spotify_client_secret'] )['status'], 'no Spotify token is refused' );
$GLOBALS['__opt']['sn_spend_gh_token'] = 'gh-1';
$GLOBALS['__http']['https://api.github.com/user'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"login":"juanlentino"}' );
ok( 'ok' === sn_keyring_probe( 'github_token', $rows['github_token'] )['status'] && false !== strpos( sn_keyring_probe( 'github_token', $rows['github_token'] )['detail'], 'juanlentino' ), 'GitHub ok names the login' );
$GLOBALS['__http']['https://api.github.com/user'] = array( 'response' => array( 'code' => 401 ), 'body' => '' );
ok( 'refused' === sn_keyring_probe( 'github_token', $rows['github_token'] )['status'], 'GitHub 401 is refused' );

// ── The analytics server token: read off the worker's public /_sn/version.
$srv = $rows['srv_token'];
$v = sn_keyring_probe( 'srv_token', $srv );
ok( 'refused' === $v['status'] && false !== strpos( $v['detail'], 'plugin side' ), 'srv unset: REFUSED, "not set here (plugin side)" (19.6.2: unset never reads green)' );
$GLOBALS['__opt']['sn_srv_token'] = 'srv-1';
$wv = function ( $http, $status, $ago = 60, $ok = true ) { return array( 'ok' => $ok, 'data' => array( 'cron' => array( 'at' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $ago ), 'refresh_status' => $status, 'refresh_http' => $http ) ) ); };
$GLOBALS['__wv'] = $wv( 403, 'error' );
$v = sn_keyring_probe( 'srv_token', $srv );
ok( 'refused' === $v['status'] && false !== strpos( $v['detail'], 'worker holds a different value' ) && false !== strpos( $v['detail'], 'signal-and-noise-analytics-worker && npx wrangler secret put SN_SRV_TOKEN' ) && true === $GLOBALS['__wv_force'], 'srv 403 → refused, names the worker side and its command, fresh read' );
ok( false === strpos( $v['detail'], 'srv-1' ), 'the secret never appears in the verdict' );
$GLOBALS['__wv'] = $wv( 503, 'error' );
$v = sn_keyring_probe( 'srv_token', $srv );
ok( 'refused' === $v['status'] && false !== strpos( $v['detail'], 'plugin side' ), 'srv 503 → plugin side' );
$GLOBALS['__wv'] = $wv( 200, 'ok' );
ok( 'ok' === sn_keyring_probe( 'srv_token', $srv )['status'], 'srv 2xx → ok' );
$GLOBALS['__wv'] = $wv( 200, 'ok', 3600 );
$v = sn_keyring_probe( 'srv_token', $srv );
ok( 'error' === $v['status'] && false !== strpos( $v['detail'], 'Unknown' ), 'srv reading older than 30 minutes → unknown, never ok' );
$GLOBALS['__wv'] = $wv( 403, 'error', 3600 );
ok( 'error' === sn_keyring_probe( 'srv_token', $srv )['status'], 'a stale 403 is unknown, not refused' );
$GLOBALS['__wv'] = array( 'ok' => false, 'data' => array(), 'error' => 'network' );
ok( 'error' === sn_keyring_probe( 'srv_token', $srv )['status'], 'worker unreachable → unknown' );
$GLOBALS['__wv'] = array( 'ok' => true, 'data' => array() );
ok( 'error' === sn_keyring_probe( 'srv_token', $srv )['status'], 'no cron block → unknown' );
$GLOBALS['__wv'] = $wv( 403, 'error' );
unset( $GLOBALS['__opt']['sn_srv_token'] );

// ── No probe is never a pass.
$GLOBALS['__opt']['sn_cf_zone_id'] = 'z';
ok( 'none' === sn_keyring_probe( 'cf_zone', $rows['cf_zone'] )['status'], 'a row without a probe says so' );

// ── Verify all stores every verdict, never autoloaded.
$GLOBALS['__http'][ SN_MR_DEFAULT_ENDPOINT ] = array( 'response' => array( 'code' => 401 ), 'body' => '' );
$all = sn_keyring_verify_all();
ok( count( $rows ) === count( $all ) && 'refused' === $all['mr_read_token']['status'] && 'unset' === $all['bridge_token']['status'] && $all === sn_keyring_verdicts() && false === $GLOBALS['__autoload'][ SN_KEYRING_VERDICTS_OPT ] && $all['mr_read_token']['at'] >= time() - 5, 'Verify all: one verdict per row, stored once, read back unchanged, stamped' );

// ── 19.6.2: the banner. An unset probed worker row with every other probe
//    green was "Every credential with a probe was accepted" (2026-09-28).
$GLOBALS['__opt'] = array( 'sn_cf_api_token' => 'cf-1' );
$GLOBALS['__cf'] = array( 'verified' => true, 'status' => 'active', 'kind' => 'account' );
$GLOBALS['__bs'] = array( 'data' => array() ); $GLOBALS['__spotify'] = 'tok';
$GLOBALS['__http'][ SN_MR_DEFAULT_ENDPOINT ] = array( 'response' => array( 'code' => 200 ), 'body' => '' );
$code = sn_handle_keyring_verify( array() );
$all  = sn_keyring_verdicts();
ok( 'refused' === $all['srv_token']['status'] && 'keyring_verified_with_refusals' === $code, 'Verify all with srv_token unset is a refusal banner, not all-green' );
ok( 'unset' === $all['zenodo_sandbox_token']['status'], 'an unset row with no worker half stays unset (not a failure)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

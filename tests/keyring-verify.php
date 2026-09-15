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
function sn_spotify_token() { return $GLOBALS['__spotify'] ?? ''; }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/keyring-verify.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$rows = sn_keyring();
echo "keyring-verify -- each verdict names the side (15.2.0)\n";

// ── Unset rows are never probed.
$v = sn_keyring_probe( 'mr_read_token', $rows['mr_read_token'] );
ok( 'unset' === $v['status'] && array() === $GLOBALS['__calls'], 'an unset row is "unset" and no request is made' );

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

// ── 15.2.2: the analytics override verifies with ITS value, not the central token.
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

// ── No probe is never a pass.
$GLOBALS['__opt']['sn_cf_zone_id'] = 'z';
ok( 'none' === sn_keyring_probe( 'cf_zone', $rows['cf_zone'] )['status'], 'a row without a probe says so' );

// ── Verify all stores every verdict, never autoloaded.
$GLOBALS['__http'][ SN_MR_DEFAULT_ENDPOINT ] = array( 'response' => array( 'code' => 401 ), 'body' => '' );
$all = sn_keyring_verify_all();
ok( count( $rows ) === count( $all ) && 'refused' === $all['mr_read_token']['status'] && 'unset' === $all['bridge_token']['status'] && $all === sn_keyring_verdicts() && false === $GLOBALS['__autoload'][ SN_KEYRING_VERDICTS_OPT ] && $all['mr_read_token']['at'] >= time() - 5, 'Verify all: one verdict per row, stored once, read back unchanged, stamped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

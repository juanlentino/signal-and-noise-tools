<?php
/**
 * ONE Cloudflare credential set (14.10.0): resolution, the analytics
 * override, the one-time migration, the callers. Run: php tests/cloudflare-credentials.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_CF_TOKEN_OPT', 'sn_cf_api_token' );
define( 'SN_CF_ZONE_OPT', 'sn_cf_zone_id' );
define( 'SN_CF_ANALYTICS_TOKEN_OPT', 'sn_cf_analytics_token' );
define( 'SN_CF_ACCOUNT_ID_OPT', 'sn_cf_account_id' );
$GLOBALS['__opt'] = array(); $GLOBALS['__actions'] = array(); $GLOBALS['__writes'] = array();
function __( $s, $d = null ) { return $s; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ] = array( $cb, $p ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__writes'][] = $k; return true; }
function sn_cf_get_token() { return (string) get_option( SN_CF_TOKEN_OPT, '' ); }
function sn_cf_get_zone() { return (string) get_option( SN_CF_ZONE_OPT, '' ); }

require dirname( __DIR__ ) . '/inc/cloudflare-credentials.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "cloudflare-credentials -- one credential set (14.10.0)\n";

// ── Resolution
ok( '' === sn_cf_analytics_token() && '' === sn_cf_get_account_id() && '' === sn_cf_analytics_override_source(), 'nothing set: empty token, empty account, no override' );
$GLOBALS['__opt'][ SN_CF_TOKEN_OPT ] = 'central-tok';
ok( 'central-tok' === sn_cf_analytics_token() && '' === sn_cf_analytics_override_source(), 'the central token serves analytics when no override is set' );
$GLOBALS['__opt'][ SN_CF_ANALYTICS_TOKEN_OPT ] = 'legacy-tok';
ok( 'legacy-tok' === sn_cf_analytics_token() && 'option' === sn_cf_analytics_override_source(), 'a saved analytics token overrides the central one, and says so (option)' );
$GLOBALS['__opt'][ SN_CF_ACCOUNT_ID_OPT ] = 'acct-1';
$c = sn_cf_credentials();
ok( true === $c['token_set'] && 'option' === $c['token_source'] && 'acct-1' === $c['account_id'] && 'option' === $c['analytics_override'], 'the credential set reports token, account and the override with their sources' );

// ── Grants
$grants = sn_cf_required_grants();
$names  = array_map( static function ( $g ) { return $g['scope'] . ' › ' . $g['grant']; }, $grants );
ok( in_array( 'Zone › Cache Purge › Purge', $names, true ) && in_array( 'Zone › Analytics › Read', $names, true ) && in_array( 'Account › Account Analytics › Read', $names, true ), 'the grant list names purge, zone analytics and account analytics' );
foreach ( $grants as $g ) {
	ok( in_array( $g['status'], array( 'documented', 'measured', 'candidate' ), true ), "{$g['grant']}: its status says how sure we are ({$g['status']})" );
}
ok( 6 === count( $grants ) && array() === array_filter( $grants, static function ( $g ) { return 'candidate' === $g['status']; } ), '15.4.0: six grants, no candidates: three measured, three the endpoints\' API reference names for the posture reads (the firewall still needs none: the plan decides its dataset)' );
ok( array( 'Zone Settings › Read', 'Zone WAF › Read', 'DNS › Read' ) === array_column( array_slice( $grants, 3 ), 'grant' ) && array( 'measured', 'measured', 'measured' ) === array_column( array_slice( $grants, 3 ), 'status' ), '15.4.1: the three posture scopes, named as the endpoint docs name them, all measured (2026-09-16)' );

// ── Migration: once, only into an empty central token, never over a constant
$GLOBALS['__opt'] = array( SN_CF_ANALYTICS_TOKEN_OPT => 'legacy-tok' ); $GLOBALS['__writes'] = array();
ok( 'copied' === sn_cf_credentials_migrate() && 'legacy-tok' === get_option( SN_CF_TOKEN_OPT ), 'an empty central token takes the analytics token on the first run' );
ok( 'legacy-tok' === get_option( SN_CF_ANALYTICS_TOKEN_OPT ), 'the analytics option is left in place (the override still works until dropped by hand)' );
ok( 'done_before' === sn_cf_credentials_migrate() && 2 === count( $GLOBALS['__writes'] ), 'a second run does nothing: the flag makes it a single write ever' );
$GLOBALS['__opt'] = array( SN_CF_TOKEN_OPT => 'central-tok', SN_CF_ANALYTICS_TOKEN_OPT => 'legacy-tok' ); $GLOBALS['__writes'] = array();
ok( 'nothing' === sn_cf_credentials_migrate() && 'central-tok' === get_option( SN_CF_TOKEN_OPT ), 'a set central token is never overwritten' );
$GLOBALS['__opt'] = array();
ok( 'nothing' === sn_cf_credentials_migrate() && false === get_option( SN_CF_TOKEN_OPT ), 'nothing to copy: nothing written but the flag' );
ok( isset( $GLOBALS['__actions']['init'] ) && 'sn_cf_credentials_migrate' === $GLOBALS['__actions']['init'][0] && 5 === $GLOBALS['__actions']['init'][1], 'the migration runs on init at priority 5, before the readers' );

// ── The callers resolve through the module (source pins, comments stripped)
$strip = static function ( $f ) { $s = (string) file_get_contents( dirname( __DIR__ ) . '/' . $f ); $s = preg_replace( '~/\*.*?\*/~s', '', $s ); return preg_replace( '~^\s*//.*$~m', '', (string) $s ); };
$api = $strip( 'inc/analytics-api.php' );
ok( false !== strpos( $api, '$token      = sn_cf_analytics_token();' ) && false !== strpos( $api, '$account_id = sn_cf_get_account_id();' ), 'sn_analytics_config() resolves token and account through the module' );
$edge = $strip( 'inc/edge-analytics.php' );
ok( false !== strpos( $edge, '? sn_cf_analytics_token()' ), 'sn_edge_config() resolves the token through the module' );
$handler = $strip( 'inc/admin-post-actions/cloudflare.php' );
ok( false === strpos( $handler, 'function sn_handle_cf_save' ) && false === strpos( $handler, 'function sn_handle_analytics_use_central_token' ), '15.2.1: cf_save and analytics_use_central_token are gone; the keyring saves the account id and clears the override' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

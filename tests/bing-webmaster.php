<?php
/**
 * Bing Webmaster Tools (16.2.0): the date shape, the site match, the pure
 * shape, the sync's store-and-keep, the probe's four verdicts, the ability.
 * Run: php tests/bing-webmaster.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_MR_DEFAULT_ENDPOINT', 'https://juanlentino.com/_sn/rights-signals/machine-readers' );
define( 'SN_SPOTIFY_TOKEN_KEY', 'sn_spotify_token' );
$GLOBALS['__b'] = array( 'opt' => array(), 'http' => array(), 'calls' => array(), 'cred' => array(), 'actions' => array(), 'abilities' => array(), 'scheduled' => array(), 'settings' => array() );
function __( $s, $d = null ) { return $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $t, $v ) { return $v; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__b']['actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__b']['opt'] ) ? $GLOBALS['__b']['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__b']['opt'][ $k ] = $v; return true; }
function delete_transient( $k ) { return true; }
function sn_setting( $path, $d = null ) { return $GLOBALS['__b']['settings'][ $path ] ?? $d; }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__b']['calls'][] = $url; $k = strtok( $url, '?' ); return $GLOBALS['__b']['http'][ $k ] ?? array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_next_scheduled( $h ) { return $GLOBALS['__b']['scheduled'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['__b']['scheduled'][ $h ] = $r; return true; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__b']['abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }
function sn_cf_monitor_verify( $z, $t = null ) { return null; }
function sn_uptime_status_api_get( $r ) { return null; }
function sn_spotify_token() { return ''; }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/keyring-verify.php';
require dirname( __DIR__ ) . '/inc/bing-webmaster.php';
require dirname( __DIR__ ) . '/inc/abilities-bing.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$B = 'https://ssl.bing.com/webmaster/api.svc/json/';
$d = static function ( $rows ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'd' => $rows ) ) ); };

echo "Group A: pure pieces\n";
ok( '2011-09-16' === sn_bing_parse_date( '/Date(1316156400000-0700)/' ) && '2011-09-16' === sn_bing_parse_date( '/Date(1316156400000)/' ), 'Bing\'s /Date(ms-offset)/ reads as the UTC day' );
ok( '' === sn_bing_parse_date( '2011-09-16' ) && '' === sn_bing_parse_date( '' ) && '' === sn_bing_parse_date( null ), 'anything else is empty, never today' );
$sites = array( array( 'Url' => 'http://example.com', 'IsVerified' => false ), array( 'Url' => 'https://www.juanlentino.com/', 'IsVerified' => true ) );
ok( array( 'listed' => true, 'verified' => true ) === sn_bing_site_state( $sites, 'https://juanlentino.com/' ), 'the site matches by host, ignoring scheme, www and the slash' );
ok( array( 'listed' => true, 'verified' => false ) === sn_bing_site_state( array( array( 'Url' => 'https://juanlentino.com', 'IsVerified' => false ) ), 'https://juanlentino.com/' ), 'listed but unverified is its own state' );
ok( array( 'listed' => false, 'verified' => false ) === sn_bing_site_state( $sites, 'https://other.test/' ) && array( 'listed' => false, 'verified' => false ) === sn_bing_site_state( 'junk', 'https://juanlentino.com/' ), 'absent, or a non-array, is not listed' );

$ms = static function ( $ymd ) { return '/Date(' . ( strtotime( $ymd . ' 00:00:00 UTC' ) * 1000 ) . ')/'; };
$traffic = array();
for ( $i = 0; $i < 40; $i++ ) { $traffic[] = array( 'Date' => $ms( gmdate( 'Y-m-d', strtotime( '2026-09-16 UTC' ) - $i * 86400 ) ), 'Clicks' => 1, 'Impressions' => 10 ); }
$traffic[] = array( 'Date' => 'garbage', 'Clicks' => 999, 'Impressions' => 999 );
$queries = array(
	array( 'Query' => 'music provenance', 'Clicks' => 3, 'Impressions' => 120, 'AvgImpressionPosition' => 7.44, 'AvgClickPosition' => 5 ),
	array( 'Query' => 'c2pa music', 'Clicks' => 0, 'Impressions' => 300, 'AvgImpressionPosition' => 12 ),
	array( 'Query' => '   ', 'Clicks' => 9, 'Impressions' => 900 ),
	'junk',
);
$s = sn_bing_shape( $traffic, $queries, '2026-09-16' );
ok( array( 'start' => '2026-08-20', 'end' => '2026-09-16', 'days' => 28 ) === $s['window'], 'the window is 28 days ending on the day given' );
ok( array( 'clicks' => 28, 'impressions' => 280, 'days' => 28 ) === $s['totals'], 'totals sum only the days inside the window; the garbage row and the 12 older days are out' );
ok( 'c2pa music' === $s['queries'][0]['key'] && 300 === $s['queries'][0]['impressions'] && 0.0 === $s['queries'][0]['ctr'] && 12.0 === $s['queries'][0]['position'], 'queries rank by impressions; a zero-click row has CTR 0.0, not a division' );
ok( 2 === count( $s['queries'] ) && 0.025 === $s['queries'][1]['ctr'] && 7.4 === $s['queries'][1]['position'], 'a blank query and a non-array are dropped; CTR is clicks over impressions; position is the impression position, one decimal' );
$empty = sn_bing_shape( array(), array(), '2026-09-16' );
ok( 0 === $empty['totals']['days'] && array() === $empty['queries'], 'no rows: zero days counted, no queries, no exception' );

echo "\nGroup B: the request\n";
ok( 'no-key' === sn_bing_request( 'GetUserSites' )['error'], 'no key: no request, the error says so' );
$GLOBALS['__b']['opt']['sn_bing_webmaster_key'] = 'k-secret';
$GLOBALS['__b']['http'][ $B . 'GetUserSites' ] = $d( $sites );
$r = sn_bing_request( 'GetUserSites' );
ok( true === $r['ok'] && 2 === count( $r['body'] ) && false !== strpos( end( $GLOBALS['__b']['calls'] ), 'apikey=k-secret' ), 'a documented GET with apikey in the query; the body is the d array' );
$GLOBALS['__b']['http'][ $B . 'GetQueryStats' ] = array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'Message' => 'The apikey k-secret is invalid' ) ) );
$r = sn_bing_request( 'GetQueryStats', array( 'siteUrl' => 'https://juanlentino.com/' ) );
ok( false === $r['ok'] && 400 === $r['code'] && 'The apikey [key] is invalid' === $r['error'], 'a fault carries Bing\'s Message with the key redacted' );
$GLOBALS['__b']['http'][ $B . 'GetCrawlStats' ] = array( 'response' => array( 'code' => 200 ), 'body' => 'not json' );
ok( false === sn_bing_request( 'GetCrawlStats' )['ok'] && 'http-200' === sn_bing_request( 'GetCrawlStats' )['error'], 'a 200 without a d envelope is not ok' );

echo "\nGroup C: the sync stores, and keeps the last good record on failure\n";
$GLOBALS['__b']['http'][ $B . 'GetRankAndTrafficStats' ] = $d( $traffic );
$GLOBALS['__b']['http'][ $B . 'GetQueryStats' ] = $d( $queries );
ok( true === sn_bing_sync()['ok'], 'both reads answer: the sync is ok' );
$stored = sn_bing_data();
ok( is_array( $stored ) && 'https://juanlentino.com/' === $stored['site'] && '2026-09-16' === $stored['window']['end'] && 28 === $stored['totals']['clicks'] && '' === $stored['last_error'], 'the record carries the site, a window ending on the newest day Bing reported, the totals, no error' );
$GLOBALS['__b']['http'][ $B . 'GetQueryStats' ] = array( 'response' => array( 'code' => 500 ), 'body' => '' );
$r = sn_bing_sync();
$kept = sn_bing_data();
ok( false === $r['ok'] && 'GetQueryStats: http-500' === $r['error'] && 28 === $kept['totals']['clicks'] && false !== strpos( $kept['last_error'], 'GetQueryStats: http-500' ), 'a failed read keeps the last good figures and writes the failure beside them' );
$GLOBALS['__b']['opt']['sn_bing_webmaster_key'] = '';
ok( 'no-key' === sn_bing_sync()['error'] && ! sn_bing_is_ready(), 'no key: not ready, no sync' );
foreach ( $GLOBALS['__b']['actions']['init'] as $cb ) { $cb(); }
ok( ! isset( $GLOBALS['__b']['scheduled'][ SN_BING_SYNC_HOOK ] ), 'no key: nothing is scheduled' );
$GLOBALS['__b']['opt']['sn_bing_webmaster_key'] = 'k-secret';
foreach ( $GLOBALS['__b']['actions']['init'] as $cb ) { $cb(); }
ok( 'daily' === ( $GLOBALS['__b']['scheduled'][ SN_BING_SYNC_HOOK ] ?? '' ), 'a key: the daily sync is scheduled once' );

echo "\nGroup D: the probe names the side\n";
$rows = sn_keyring();
ok( isset( $rows['bing_webmaster_key'] ) && 'bing' === $rows['bing_webmaster_key']['probe'] && 'issued' === $rows['bing_webmaster_key']['group'] && 'secret' === $rows['bing_webmaster_key']['kind'], 'the keyring row: issued, secret, probed as bing' );
$GLOBALS['__b']['http'][ $B . 'GetUserSites' ] = $d( $sites );
ok( 'ok' === sn_keyring_probe( 'bing_webmaster_key', $rows['bing_webmaster_key'] )['status'], 'listed and verified: ok' );
$GLOBALS['__b']['http'][ $B . 'GetUserSites' ] = $d( array( array( 'Url' => 'https://juanlentino.com/', 'IsVerified' => false ) ) );
$v = sn_keyring_probe( 'bing_webmaster_key', $rows['bing_webmaster_key'] );
ok( 'refused' === $v['status'] && false !== strpos( $v['detail'], 'not verified' ), 'listed, unverified: refused, and the detail says verify' );
$GLOBALS['__b']['http'][ $B . 'GetUserSites' ] = $d( array( array( 'Url' => 'http://example.com', 'IsVerified' => true ) ) );
$v = sn_keyring_probe( 'bing_webmaster_key', $rows['bing_webmaster_key'] );
ok( 'refused' === $v['status'] && false !== strpos( $v['detail'], 'not among its sites' ), 'a key for another site: refused, says add the site' );
$GLOBALS['__b']['http'][ $B . 'GetUserSites' ] = array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'Message' => 'The apikey is invalid' ) ) );
ok( 'refused' === sn_keyring_probe( 'bing_webmaster_key', $rows['bing_webmaster_key'] )['status'], 'Bing\'s "apikey is invalid" (a 400, not a 401) reads as refused' );
$GLOBALS['__b']['http'][ $B . 'GetUserSites' ] = array( 'response' => array( 'code' => 503 ), 'body' => '' );
ok( 'error' === sn_keyring_probe( 'bing_webmaster_key', $rows['bing_webmaster_key'] )['status'], 'a 503 is Bing\'s side: error, not refused' );

echo "\nGroup E: the ability\n";
foreach ( $GLOBALS['__b']['actions']['wp_abilities_api_init'] as $cb ) { $cb(); }
$ab = $GLOBALS['__b']['abilities']['signal-noise/bing-search-performance'] ?? null;
ok( is_array( $ab ) && array( 'object', 'null' ) === $ab['input_schema']['type'] && true === $ab['meta']['annotations']['readonly'] && 'diagnostics' === $ab['category'], 'registers readonly, diagnostics, with the [object,null] input union' );
$out = snt_ability_bing_search_performance();
ok( 'bing' === $out['source'] && true === $out['synced'] && 28 === $out['totals']['clicks'] && 2 === count( $out['queries'] ), 'the ability reads the stored record and names its source' );
$GLOBALS['__b']['opt'] = array( 'sn_bing_webmaster_key' => 'k-secret' );
$out = snt_ability_bing_search_performance();
ok( false === $out['synced'] && null === $out['totals'] && true === $out['ready'] && false !== strpos( $out['note'], 'nothing has synced' ), 'nothing stored: synced false, totals NULL (not zero), the note says the sync runs on its own' );
$GLOBALS['__b']['opt']['sn_bing_webmaster_key'] = '';
ok( false === snt_ability_bing_search_performance()['ready'], 'no key: ready false' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

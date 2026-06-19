<?php
/**
 * Tests for inc/edge-analytics.php — the Cloudflare GraphQL zone-analytics client:
 * config gating, the wp_remote_post query transport (200-with-errors handling),
 * query-builder shape, and sampling correction. Stubbed transport, no network.
 * Run: php tests/edge-analytics.php
 * @since plugin v6.26.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
// Option keys the edge config reuses (defined by analytics-api.php / cloudflare-purge.php at runtime).
define( 'SN_CF_ANALYTICS_TOKEN_OPT', 'sn_cf_analytics_token' );
define( 'SN_CF_ZONE_OPT', 'sn_cf_zone_id' );

// WP HTTP + option seams.
$GLOBALS['__opt']  = array();
$GLOBALS['__http'] = array( 'code' => 200, 'body' => '{}', 'wp_error' => false, 'last_args' => null );
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $v ) { return ( $v instanceof WP_Error ) || ( is_array( $v ) && ! empty( $v['__is_wp_error'] ) ); }
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http']['last_url']  = $url;
	$GLOBALS['__http']['last_args'] = $args;
	if ( $GLOBALS['__http']['wp_error'] ) { return array( '__is_wp_error' => true ); }
	return array( 'response' => array( 'code' => $GLOBALS['__http']['code'] ), 'body' => $GLOBALS['__http']['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require_once __DIR__ . '/../inc/edge-analytics.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Edge analytics — GraphQL client\n\n";

echo "Group: config gating (dormant until zone + token present)\n";
$GLOBALS['__opt'] = array();
ok( null === sn_edge_config(), 'config: null when neither token nor zone set' );
$GLOBALS['__opt'][ SN_CF_ANALYTICS_TOKEN_OPT ] = 'tok123';
ok( null === sn_edge_config(), 'config: null with token but no zone' );
$GLOBALS['__opt'][ SN_CF_ZONE_OPT ] = 'zone456';
$cfg = sn_edge_config();
ok( is_array( $cfg ) && $cfg['token'] === 'tok123' && $cfg['zone'] === 'zone456', 'config: returns token + zone when both set' );

echo "\nGroup: sn_edge_query transport\n";
// Happy path: returns the single zone's dataset object.
$GLOBALS['__http']['wp_error'] = false;
$GLOBALS['__http']['code']     = 200;
$GLOBALS['__http']['body']     = json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array(
	array( 'httpRequests1dGroups' => array( array( 'dimensions' => array( 'date' => '2026-06-18' ), 'sum' => array( 'requests' => 1000 ) ) ) ),
) ) ) ) );
$zone = sn_edge_query( 'query($zone:string!){x}', array() );
ok( is_array( $zone ) && isset( $zone['httpRequests1dGroups'] ), 'query: returns zones[0] dataset object on success' );
ok( isset( $GLOBALS['__http']['last_args']['headers']['Authorization'] ) && strpos( $GLOBALS['__http']['last_args']['headers']['Authorization'], 'Bearer tok123' ) === 0, 'query: sends Bearer token' );
$sent = json_decode( $GLOBALS['__http']['last_args']['body'], true );
ok( isset( $sent['variables']['zone'] ) && $sent['variables']['zone'] === 'zone456', 'query: auto-injects the configured zoneTag into variables' );

echo "\nGroup: sn_edge_query failure modes → null (never fatal)\n";
$GLOBALS['__http']['wp_error'] = true;
ok( null === sn_edge_query( 'q', array() ), 'query: WP_Error transport → null' );
$GLOBALS['__http']['wp_error'] = false;
$GLOBALS['__http']['code']     = 403;
$GLOBALS['__http']['body']     = '{}';
ok( null === sn_edge_query( 'q', array() ), 'query: non-200 → null' );
$GLOBALS['__http']['code']     = 200;
$GLOBALS['__http']['body']     = json_encode( array( 'data' => null, 'errors' => array( array( 'message' => 'filter is required' ) ) ) );
ok( null === sn_edge_query( 'q', array() ), 'query: HTTP 200 WITH errors[] → null (GraphQL soft-fail)' );
$GLOBALS['__http']['body']     = json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array() ) ) ) );
ok( null === sn_edge_query( 'q', array() ), 'query: empty zones (bad zoneTag / no access) → null' );
$GLOBALS['__http']['body']     = 'not json';
ok( null === sn_edge_query( 'q', array() ), 'query: unparseable body → null' );
// Dormant: no config → no HTTP call at all.
$GLOBALS['__opt'] = array();
$GLOBALS['__http']['last_url'] = null;
ok( null === sn_edge_query( 'q', array() ) && null === $GLOBALS['__http']['last_url'], 'query: unconfigured → null with NO network call' );
$GLOBALS['__opt'][ SN_CF_ANALYTICS_TOKEN_OPT ] = 'tok123';
$GLOBALS['__opt'][ SN_CF_ZONE_OPT ]            = 'zone456';

echo "\nGroup: query builders — datasets + fields + filter-arg conventions\n";
$daily = sn_edge_daily_query();
ok( strpos( $daily, 'httpRequests1dGroups' ) !== false, 'daily: uses the EXACT (non-sampled) httpRequests1dGroups rollup' );
ok( strpos( $daily, 'date_geq' ) !== false && strpos( $daily, 'date_leq' ) !== false, 'daily: date-type filter args (date_geq/date_leq)' );
ok( strpos( $daily, 'sampleInterval' ) === false, 'daily: NO sampleInterval (1dGroups is pre-aggregated/exact)' );
foreach ( array( 'requests', 'cachedRequests', 'bytes', 'cachedBytes', 'threats', 'responseStatusMap', 'countryMap' ) as $f ) {
	ok( strpos( $daily, $f ) !== false, "daily: requests field '$f'" );
}
ok( strpos( $daily, 'limit:' ) !== false, 'daily: includes a limit (always required)' );

$fw = sn_edge_firewall_query();
ok( strpos( $fw, 'firewallEventsAdaptiveGroups' ) !== false, 'firewall: uses firewallEventsAdaptiveGroups' );
ok( strpos( $fw, 'datetime_geq' ) !== false, 'firewall: datetime-type filter (adaptive)' );
ok( strpos( $fw, 'sampleInterval' ) !== false, 'firewall: REQUESTS sampleInterval (adaptive → must sampling-correct)' );

$colo = sn_edge_colo_query();
ok( strpos( $colo, 'httpRequestsAdaptiveGroups' ) !== false, 'colo: uses httpRequestsAdaptiveGroups' );
ok( strpos( $colo, 'coloCode' ) !== false, 'colo: groups by coloCode' );
ok( strpos( $colo, 'sampleInterval' ) !== false, 'colo: sampleInterval (adaptive)' );

echo "\nGroup: sampling correction (adaptive count × sampleInterval)\n";
ok( sn_edge_corrected( array( 'count' => 12, 'avg' => array( 'sampleInterval' => 10 ) ) ) === 120, 'corrected: 12 × 10 = 120' );
ok( sn_edge_corrected( array( 'count' => 5 ) ) === 5, 'corrected: missing sampleInterval defaults to ×1' );
ok( sn_edge_corrected( array( 'count' => 7, 'avg' => array( 'sampleInterval' => 0 ) ) ) === 7, 'corrected: sampleInterval < 1 floored to ×1 (no zeroing)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

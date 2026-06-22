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

// WP HTTP + option + transient seams.
$GLOBALS['__opt']       = array();
$GLOBALS['__transient'] = array();
$GLOBALS['__http']      = array( 'code' => 200, 'body' => '{}', 'wp_error' => false, 'last_args' => null );
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transient'] ) ? $GLOBALS['__transient'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transient'][ $k ] = $v; return true; }
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

echo "\nGroup: attack-surface query (doors + probes)\n";
$atk = sn_edge_attack_query();
ok( strpos( $atk, 'doors:httpRequestsAdaptiveGroups' ) !== false, 'attack: doors alias on httpRequestsAdaptiveGroups' );
ok( strpos( $atk, 'probes:httpRequestsAdaptiveGroups' ) !== false, 'attack: probes alias on httpRequestsAdaptiveGroups' );
ok( strpos( $atk, 'clientRequestPath_in:["/wp-login.php","/xmlrpc.php"]' ) !== false, 'attack: doors filter the named login paths' );
ok( strpos( $atk, 'clientRequestPath_notin:["/wp-login.php","/xmlrpc.php"]' ) !== false, 'attack: probes exclude the named doors' );
ok( strpos( $atk, 'edgeResponseStatus_geq:400' ) !== false && strpos( $atk, 'edgeResponseStatus_leq:499' ) !== false, 'attack: probes are the 4xx scan surface' );
foreach ( array( 'clientRequestPath', 'clientCountryName', 'clientASNDescription', 'clientAsn', 'edgeResponseStatus', 'clientRequestHTTPMethodName' ) as $d ) {
	ok( strpos( $atk, $d ) !== false, "attack: door dimension '$d'" );
}
ok( strpos( $atk, 'sampleInterval' ) !== false, 'attack: adaptive → sampleInterval present' );
ok( strpos( $atk, '$from:Time!' ) !== false, 'attack: trailing-window Time variable' );

echo "\nGroup: sampling correction (adaptive count × sampleInterval)\n";
ok( sn_edge_corrected( array( 'count' => 12, 'avg' => array( 'sampleInterval' => 10 ) ) ) === 120, 'corrected: 12 × 10 = 120' );
ok( sn_edge_corrected( array( 'count' => 5 ) ) === 5, 'corrected: missing sampleInterval defaults to ×1' );
ok( sn_edge_corrected( array( 'count' => 7, 'avg' => array( 'sampleInterval' => 0 ) ) ) === 7, 'corrected: sampleInterval < 1 floored to ×1 (no zeroing)' );

echo "\nGroup: settings-node retention probe query builder\n";
$settings = sn_edge_settings_query();
ok( strpos( $settings, 'settings{' ) !== false, 'settings: queries the zone settings node' );
ok( strpos( $settings, 'httpRequestsAdaptiveGroups' ) !== false, 'settings: probes the httpRequestsAdaptiveGroups dataset' );
ok( strpos( $settings, 'notOlderThan' ) !== false, 'settings: reads notOlderThan (the real retention window)' );
ok( strpos( $settings, 'firewallEventsAdaptiveGroups' ) === false, 'settings: probes ONE node only (a single bad field would error the whole query)' );

echo "\nGroup: sn_edge_adaptive_retention — discovers real notOlderThan, SWR-cached\n";
$GLOBALS['__opt'] = array( SN_CF_ANALYTICS_TOKEN_OPT => 'tok123', SN_CF_ZONE_OPT => 'zone456' );
$GLOBALS['__transient'] = array();
$GLOBALS['__http']['wp_error'] = false;
$GLOBALS['__http']['code']     = 200;
// notOlderThan: 2678400s = 31 days — NOT the assumed 24h, proving the probe corrects the guess.
$GLOBALS['__http']['body'] = json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array(
	array( 'settings' => array( 'httpRequestsAdaptiveGroups' => array( 'enabled' => true, 'notOlderThan' => 2678400, 'maxDuration' => 259200 ) ) ),
) ) ) ) );
ok( sn_edge_adaptive_retention() === 2678400, 'retention: returns the discovered notOlderThan in seconds (31d, not 24h)' );
ok( (int) get_option( SN_EDGE_RETENTION_LASTGOOD ) === 2678400, 'retention: persists last-known-good to an option (survives transient eviction)' );
ok( is_array( get_transient( SN_EDGE_RETENTION_TRANSIENT ) ), 'retention: SWR-caches the probe result in a transient' );
// Warm cache: a changed body is NOT re-fetched (served from the transient).
$GLOBALS['__http']['body'] = json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array(
	array( 'settings' => array( 'httpRequestsAdaptiveGroups' => array( 'notOlderThan' => 999 ) ) ),
) ) ) ) );
ok( sn_edge_adaptive_retention() === 2678400, 'retention: warm transient served WITHOUT re-probing' );
ok( sn_edge_adaptive_retention( true ) === 999, 'retention: $force=true bypasses the cache and re-probes live' );

echo "\nGroup: retention probe — failure falls back to last-known-good (never blanks)\n";
$GLOBALS['__transient'] = array();
$GLOBALS['__http']['body'] = json_encode( array( 'data' => null, 'errors' => array( array( 'message' => 'unknown field' ) ) ) );
ok( sn_edge_adaptive_retention( true ) === 999, 'retention: probe error → returns the last-known-good option (999 from above)' );
ok( sn_edge_adaptive_retention() === 999, 'retention: warm read after a failed probe agrees with the cold read (no null flicker)' );

echo "\nGroup: retention probe — dormant when unconfigured (no network, no cache write)\n";
$GLOBALS['__opt'] = array();
$GLOBALS['__transient'] = array();
$GLOBALS['__http']['last_url'] = null;
ok( null === sn_edge_adaptive_retention() && null === $GLOBALS['__http']['last_url'], 'retention: unconfigured → null with NO network call' );
ok( empty( $GLOBALS['__transient'] ), 'retention: unconfigured → no transient written (truly dormant)' );

echo "\nGroup: sn_edge_adaptive_retention_days — discovered retention as whole days\n";
$GLOBALS['__opt'] = array( SN_CF_ANALYTICS_TOKEN_OPT => 'tok123', SN_CF_ZONE_OPT => 'zone456' );
$GLOBALS['__transient'] = array();
$GLOBALS['__http']['body'] = json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array(
	array( 'settings' => array( 'httpRequestsAdaptiveGroups' => array( 'notOlderThan' => 2678400 ) ) ),
) ) ) ) );
ok( sn_edge_adaptive_retention_days() === 31, 'retention_days: 2678400s → 31 days' );
$GLOBALS['__opt'] = array();
$GLOBALS['__transient'] = array();
ok( sn_edge_adaptive_retention_days() === 0, 'retention_days: dormant/unknown → 0 (not a negative or fatal)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

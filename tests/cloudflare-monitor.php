<?php
/**
 * The Cloudflare monitor (14.9.0): three parsers over fixtures, the API row,
 * and the refresh through stubbed HTTP. Run: php tests/cloudflare-monitor.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_CF_API_BASE', 'https://api.cloudflare.com/client/v4' );
$GLOBALS['__opt'] = array(); $GLOBALS['__http'] = array(); $GLOBALS['__calls'] = array(); $GLOBALS['__actions'] = array();
function __( $s, $d = null ) { return $s; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ] = $cb; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function sn_cf_is_configured() { return ! empty( $GLOBALS['__configured'] ); }
function sn_cf_get_token() { return 'tok'; }
function sn_cf_get_zone() { return 'zone123'; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__calls'][] = array( 'GET', $url, $args ); return $GLOBALS['__http'][ $url ] ?? array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['__calls'][] = array( 'POST', $url, $args ); $body = json_decode( (string) ( $args['body'] ?? '' ), true ); $key = $url . '#' . ( false !== strpos( (string) ( $body['query'] ?? '' ), 'firewallEvents' ) ? 'fw' : 'zone' ); return $GLOBALS['__http'][ $key ] ?? array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['__scheduled'] = array( $t, $r, $h ); return true; }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
function human_time_diff( $from, $to ) { $d = abs( $to - $from ); return $d < 3600 ? intdiv( $d, 60 ) . ' mins' : intdiv( $d, 3600 ) . ' hours'; }

require dirname( __DIR__ ) . '/inc/cloudflare-monitor.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$j = static function ( $code, array $body ) { return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) ); };
echo "cloudflare-monitor -- what the API will tell us (14.9.0)\n";

// ── Token
$t = sn_cf_monitor_token_from( array( 'http' => 200, 'body' => array( 'success' => true, 'result' => array( 'id' => 'abc', 'status' => 'active', 'expires_on' => '2027-01-01T00:00:00Z', 'not_before' => '2026-07-01T00:00:00Z' ) ), 'error' => '' ) );
ok( true === $t['verified'] && 'active' === $t['status'] && '2027-01-01T00:00:00Z' === $t['expires_on'], 'a verify answer: verified, status active, expiry carried' );
$t = sn_cf_monitor_token_from( array( 'http' => 200, 'body' => array( 'success' => true, 'result' => array( 'id' => 'abc', 'status' => 'active' ) ), 'error' => '' ) );
ok( '' === $t['expires_on'], 'a token with no expires_on reads as never expiring, not as an empty date' );
$t = sn_cf_monitor_token_from( array( 'http' => 400, 'body' => array( 'success' => false, 'errors' => array( array( 'code' => 6003, 'message' => 'Invalid request headers' ) ) ), 'error' => '' ) );
ok( false === $t['verified'] && 'invalid' === $t['status'] && 'Invalid request headers' === $t['error'], 'a refused verify: not verified, status invalid, the API\'s own message' );
$t = sn_cf_monitor_token_from( array( 'http' => 0, 'body' => array(), 'error' => 'cURL error 28' ) );
ok( 'unreachable' === $t['status'] && 'cURL error 28' === $t['error'], 'a network failure is unreachable, never invalid' );

// ── Zone
$zone_ok = array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'httpRequests1dGroups' => array(
	array( 'dimensions' => array( 'date' => '2026-09-14' ), 'sum' => array( 'requests' => 1000, 'cachedRequests' => 800, 'bytes' => 5000000, 'cachedBytes' => 4000000, 'threats' => 3, 'responseStatusMap' => array( array( 'edgeResponseStatus' => 200, 'requests' => 950 ), array( 'edgeResponseStatus' => 404, 'requests' => 40 ), array( 'edgeResponseStatus' => 503, 'requests' => 10 ) ) ) ),
	array( 'dimensions' => array( 'date' => '2026-09-13' ), 'sum' => array( 'requests' => 500, 'cachedRequests' => 100, 'bytes' => 1000000, 'cachedBytes' => 200000, 'threats' => 0, 'responseStatusMap' => array() ) ),
) ) ) ) ), 'errors' => null );
$z = sn_cf_monitor_zone_from( array( 'http' => 200, 'body' => $zone_ok, 'error' => '' ) );
ok( true === $z['available'] && false === $z['needs_permission'], 'a zone answer is available' );
ok( 1500 === $z['totals']['requests'] && 900 === $z['totals']['cached'] && 60.0 === $z['totals']['cache_share'] && 3 === $z['totals']['threats'], 'totals sum the days: 1500 requests, 900 cached, 60.0% from cache, 3 threats' );
ok( 40 === $z['totals']['status_4xx'] && 10 === $z['totals']['status_5xx'], '4xx and 5xx are counted from the status map, by class' );
ok( '2026-09-13' === $z['days'][0]['date'] && '2026-09-14' === $z['days'][1]['date'], 'days are sorted ascending whatever order the API returned' );
$refused = array( 'data' => null, 'errors' => array( array( 'message' => 'unauthorized to access requested resource', 'extensions' => array( 'code' => 'authz', 'timestamp' => '' ) ) ) );
$z = sn_cf_monitor_zone_from( array( 'http' => 200, 'body' => $refused, 'error' => '' ) );
ok( false === $z['available'] && true === $z['needs_permission'] && array() === $z['totals'], 'a token without Zone Analytics Read: needs_permission, NO totals (a gap is never a zero)' );
$z = sn_cf_monitor_zone_from( array( 'http' => 200, 'body' => array( 'data' => null, 'errors' => array( array( 'code' => 9109, 'message' => 'x' ) ) ), 'error' => '' ) );
ok( true === $z['needs_permission'], 'code 9109 is the same refusal' );
$z = sn_cf_monitor_zone_from( array( 'http' => 200, 'body' => array( 'data' => null, 'errors' => array( array( 'message' => 'zone not found' ) ) ), 'error' => '' ) );
ok( false === $z['available'] && false === $z['needs_permission'] && 'zone not found' === $z['error'], 'another GraphQL error is an error with the API\'s message, not a permission gap' );

// ── Firewall
$fw_ok = array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'firewallEventsAdaptiveGroups' => array(
	array( 'count' => 40, 'dimensions' => array( 'action' => 'block', 'source' => 'waf', 'ruleId' => 'rule-a' ) ),
	array( 'count' => 5, 'dimensions' => array( 'action' => 'managed_challenge', 'source' => 'bic', 'ruleId' => 'rule-b' ) ),
	array( 'count' => 2, 'dimensions' => array( 'action' => 'block', 'source' => 'waf', 'ruleId' => 'rule-a' ) ),
) ) ) ) ) );
$f = sn_cf_monitor_firewall_from( array( 'http' => 200, 'body' => $fw_ok, 'error' => '' ) );
ok( true === $f['available'] && 47 === $f['events'] && array( 'block' => 42, 'managed_challenge' => 5 ) === $f['by_action'], 'firewall: 47 events, by action summed across rules' );
ok( 'rule-a' === $f['top_rules'][0]['rule'] && 42 === $f['top_rules'][0]['count'] && 'waf' === $f['top_rules'][0]['source'], 'top rules merge the same source:rule and sort by count' );
$f = sn_cf_monitor_firewall_from( array( 'http' => 200, 'body' => $refused, 'error' => '' ) );
ok( true === $f['needs_permission'] && 0 === $f['events'] && array() === $f['by_action'], 'firewall without permission: a gap, zero events reported as absent, not as quiet' );

// ── The API row
$now = 1789500000;
$row = sn_cf_monitor_api_row( null, array(), $now );
ok( false !== strpos( $row['value'], 'monitor has not run yet' ) && 'unknown' === $row['dot'], 'no record: the row says the monitor has not run (never "not seen")' );
$rec = array( 'fetched_at' => $now - 100, 'configured' => true, 'token' => array( 'verified' => true, 'status' => 'active', 'expires_on' => '', 'not_before' => '', 'error' => '' ), 'zone' => null, 'firewall' => null );
$row = sn_cf_monitor_api_row( $rec, array( 'time' => $now - 7200, 'kind' => 'urls', 'count' => 3 ), $now );
ok( 'token active · last call 2 hours ago · no rate-limit headers (Cloudflare publishes none)' === $row['value'] && '' === $row['dot'], 'a live token, the last call, and the truth about headers, on one line' );
$rec['token']['expires_on'] = gmdate( 'Y-m-d\TH:i:s\Z', $now + 5 * DAY_IN_SECONDS );
$row = sn_cf_monitor_api_row( $rec, array(), $now );
ok( false !== strpos( $row['value'], '(soon)' ) && 'warn' === $row['dot'], 'a token expiring within 14 days warns' );
$rec['token'] = array( 'verified' => true, 'status' => 'expired', 'expires_on' => '2026-09-01T00:00:00Z', 'not_before' => '', 'error' => '' );
$row = sn_cf_monitor_api_row( $rec, array(), $now );
ok( false !== strpos( $row['value'], 'token expired' ) && 'err' === $row['dot'], 'an expired token is red' );
$row = sn_cf_monitor_api_row( array( 'fetched_at' => $now, 'configured' => false, 'token' => null, 'zone' => null, 'firewall' => null ), array(), $now );
ok( false !== strpos( $row['value'], 'not configured' ), 'unconfigured says so' );

// ── Refresh: three requests, the record stored, nothing purged
$GLOBALS['__configured'] = true;
$GLOBALS['__http'] = array(
	SN_CF_API_BASE . '/user/tokens/verify' => $j( 200, array( 'success' => true, 'result' => array( 'status' => 'active' ) ) ),
	SN_CF_API_BASE . '/graphql#zone'       => $j( 200, $zone_ok ),
	SN_CF_API_BASE . '/graphql#fw'         => $j( 200, $refused ),
);
$r = sn_cf_monitor_refresh();
ok( 3 === count( $GLOBALS['__calls'] ) && 'GET' === $GLOBALS['__calls'][0][0] && 'POST' === $GLOBALS['__calls'][1][0], 'a refresh makes exactly three requests: verify, zone, firewall' );
ok( true === $r['configured'] && 'active' === $r['token']['status'] && 1500 === $r['zone']['totals']['requests'] && true === $r['firewall']['needs_permission'], 'the record carries all three readings, each in its own truth' );
ok( $GLOBALS['__opt'][ SN_CF_MONITOR_OPT ] === $r && $r === sn_cf_monitor_read(), 'stored in one option; the reader returns it unchanged' );
foreach ( $GLOBALS['__calls'] as $c ) {
	ok( 0 === (int) $c[2]['redirection'] && false === strpos( $c[1], 'purge' ), 'every request refuses redirects (a Bearer on a fixed host) and none is a purge: ' . $c[1] );
}
ok( isset( $GLOBALS['__actions'][ SN_CF_MONITOR_HOOK ] ) && 'sn_cf_monitor_refresh' === $GLOBALS['__actions'][ SN_CF_MONITOR_HOOK ], 'the daily hook runs the refresh' );
sn_cf_monitor_schedule();
ok( 'daily' === $GLOBALS['__scheduled'][1] && SN_CF_MONITOR_HOOK === $GLOBALS['__scheduled'][2], 'scheduled daily under sn_cf_monitor_daily' );
$GLOBALS['__configured'] = false; $GLOBALS['__calls'] = array();
$r = sn_cf_monitor_refresh();
ok( false === $r['configured'] && array() === $GLOBALS['__calls'], 'unconfigured: no request leaves the box, the record says so' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

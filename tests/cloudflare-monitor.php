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
function sn_cf_get_account_id() { return $GLOBALS['__acct'] ?? ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__calls'][] = array( 'GET', $url, $args ); return $GLOBALS['__http'][ $url ] ?? array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['__calls'][] = array( 'POST', $url, $args ); $body = json_decode( (string) ( $args['body'] ?? '' ), true ); $q = (string) ( $body['query'] ?? '' ); $key = $url . '#' . ( false !== strpos( $q, 'accounts(filter' ) ? 'fwacct' : ( false !== strpos( $q, 'firewallEvents' ) ? 'fw' : 'zone' ) ); return $GLOBALS['__http'][ $key ] ?? array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
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
ok( array( 503 => 10 ) === $z['totals']['status_5xx_codes'], '14.9.1: the 5xx CODES are kept, so a Cloudflare 52x (could not reach the origin) is never blended with an origin 503 (Varnish)' );
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
// 14.9.1: the live refusal for the firewall dataset, with a token that reads
// zone analytics fine. A different sentence, and a different grant.
$live_refusal = array( 'data' => null, 'errors' => array( array( 'message' => "zone '319c5233e47cb32fdb6de197eff034cc' does not have access to the path. Refer to this page for more details about access controls: https://developers.cloudflare.com/analytics/graphql-api/errors/", 'path' => array( 'viewer', 'zones', '0', 'firewallEventsAdaptiveGroups' ), 'extensions' => array( 'code' => 'authz', 'timestamp' => '2026-09-15T13:59:00Z' ) ) ) );
$f = sn_cf_monitor_firewall_from( array( 'http' => 200, 'body' => $live_refusal, 'error' => '' ) );
ok( true === $f['needs_permission'] && false === $f['available'], '"does not have access to the path" is a permission gap, not an error (the live refusal, 2026-09-15)' );
// 14.9.2: two grants later the refusal read the same, and Cloudflare documents
// none. The hint says exactly that, keeps the API's sentence, and the monitor
// probes the account path rather than naming a fourth guess.
ok( false !== strpos( sn_cf_monitor_permission_hint( 'firewall' ), 'documents no grant' ) && false !== strpos( sn_cf_monitor_permission_hint( 'firewall' ), 'Logs Read' ) && false !== strpos( sn_cf_monitor_permission_hint( 'zone' ), 'Zone › Analytics › Read' ), 'the firewall hint says the grant is undocumented and which three were tried; the zone hint still names Analytics Read' );
ok( false !== strpos( $f['error'], 'does not have access to the path' ), 'a refused firewall read keeps the API\'s own sentence' );

// ── The API row: FIGURE-SIZED (14.9.1). The first cut put the whole sentence
// in the value; the kit list's value never shrank and the label "Cloudflare
// API" was squeezed to nothing on the live Dashboard. The sentence rides the
// title attribute now; the value stays the length of "4,790 / 5,000".
$now = 1789500000;
$row = sn_cf_monitor_api_row( null, array(), $now );
ok( 'not run yet' === $row['value'] && 'unknown' === $row['dot'] && false !== strpos( $row['title'], 'publishes no rate-limit headers' ), 'no record: the value says not run yet; the headers sentence rides the title' );
$rec = array( 'fetched_at' => $now - 100, 'configured' => true, 'token' => array( 'verified' => true, 'status' => 'active', 'expires_on' => '', 'not_before' => '', 'error' => '' ), 'zone' => null, 'firewall' => null );
$row = sn_cf_monitor_api_row( $rec, array( 'time' => $now - 7200, 'kind' => 'urls', 'count' => 3 ), $now );
ok( 'token active' === $row['value'] && '' === $row['dot'] && false !== strpos( $row['title'], 'Last call 2 hours ago' ), 'a live token: two words in the value, the last call in the title' );
foreach ( array( $row['value'], 'expires 2026-09-20', 'token expired', 'not configured' ) as $v ) {
	ok( strlen( $v ) <= 20, "a row value stays figure-sized: '$v' (" . strlen( $v ) . ' chars, the GitHub row is 13)' );
}
$rec['token']['expires_on'] = gmdate( 'Y-m-d\TH:i:s\Z', $now + 5 * DAY_IN_SECONDS );
$row = sn_cf_monitor_api_row( $rec, array(), $now );
ok( 0 === strpos( $row['value'], 'expires ' ) && 'warn' === $row['dot'], 'a token expiring within 14 days: the value is the date, amber' );
$rec['token'] = array( 'verified' => true, 'status' => 'expired', 'expires_on' => '2026-09-01T00:00:00Z', 'not_before' => '', 'error' => '' );
$row = sn_cf_monitor_api_row( $rec, array(), $now );
ok( 'token expired' === $row['value'] && 'err' === $row['dot'], 'an expired token is red' );
$row = sn_cf_monitor_api_row( array( 'fetched_at' => $now, 'configured' => false, 'token' => null, 'zone' => null, 'firewall' => null ), array(), $now );
ok( 'not configured' === $row['value'], 'unconfigured says so' );

// ── Verify: a User token answers /user, an Account token answers /accounts/{id}
$GLOBALS['__http'] = array( SN_CF_API_BASE . '/user/tokens/verify' => $j( 200, array( 'success' => true, 'result' => array( 'status' => 'active' ) ) ) );
$GLOBALS['__acct'] = 'acct9'; $GLOBALS['__calls'] = array();
$t = sn_cf_monitor_verify( 'zone123' );
ok( 'user' === $t['kind'] && 'active' === $t['status'] && 1 === count( $GLOBALS['__calls'] ), 'a User token verifies on /user and is marked user; the account route is never asked' );
$GLOBALS['__http'] = array(
	SN_CF_API_BASE . '/user/tokens/verify'           => $j( 400, array( 'success' => false, 'errors' => array( array( 'code' => 6003, 'message' => 'Invalid request headers' ) ) ) ),
	SN_CF_API_BASE . '/accounts/acct9/tokens/verify' => $j( 200, array( 'success' => true, 'result' => array( 'status' => 'active', 'expires_on' => '2027-01-01T00:00:00Z' ) ) ),
);
$GLOBALS['__calls'] = array();
$t = sn_cf_monitor_verify( 'zone123' );
ok( 'account' === $t['kind'] && 'active' === $t['status'] && '2027-01-01T00:00:00Z' === $t['expires_on'] && 2 === count( $GLOBALS['__calls'] ), 'an Account token refused on /user verifies on /accounts/{id} and is marked account' );
$GLOBALS['__acct'] = '';
$t = sn_cf_monitor_verify( 'zone123' );
ok( '' === $t['kind'] && 'invalid' === $t['status'], 'without an account id the account route cannot be asked: the user refusal stands, kind unknown' );
$GLOBALS['__acct'] = 'acct9';
$GLOBALS['__http'][ SN_CF_API_BASE . '/accounts/acct9/tokens/verify' ] = $j( 400, array( 'success' => false, 'errors' => array( array( 'message' => 'not a token of this account' ) ) ) );
$t = sn_cf_monitor_verify( 'zone123' );
ok( '' === $t['kind'] && 'invalid' === $t['status'] && false !== strpos( $t['error'], 'Invalid request headers' ) && false !== strpos( $t['error'], 'not a token of this account' ), 'both routes refusing: invalid, with both sentences kept' );
$GLOBALS['__acct'] = '';

// ── Refresh: three requests, the record stored, nothing purged
$GLOBALS['__configured'] = true; $GLOBALS['__calls'] = array();
$GLOBALS['__http'] = array(
	SN_CF_API_BASE . '/user/tokens/verify' => $j( 200, array( 'success' => true, 'result' => array( 'status' => 'active' ) ) ),
	SN_CF_API_BASE . '/graphql#zone'       => $j( 200, $zone_ok ),
	SN_CF_API_BASE . '/graphql#fw'         => $j( 200, $refused ),
);
$r = sn_cf_monitor_refresh();
// 14.9.2: on a firewall refusal the refresh probes the ACCOUNT path too: one
// GET for the account id, one more GraphQL. Five requests, still no purge.
ok( 4 === count( $GLOBALS['__calls'] ) && 'GET' === $GLOBALS['__calls'][0][0] && 'POST' === $GLOBALS['__calls'][1][0] && false !== strpos( $GLOBALS['__calls'][3][1], '/zones/zone123' ), 'a refresh with a refused firewall read asks for the zone record (four requests); with no account id in it the fifth is never made' );
ok( 'refused' === $r['firewall']['probe']['zone_path'] && 'no_account_id' === $r['firewall']['probe']['account_path'], 'the probe records the zone path refused and, with no account id in the zone record, that the account path was not tried' );
ok( true === $r['configured'] && 'active' === $r['token']['status'] && 1500 === $r['zone']['totals']['requests'] && true === $r['firewall']['needs_permission'], 'the record carries all three readings, each in its own truth' );
// The account path answers: the firewall reading is taken from it and says so.
$GLOBALS['__calls'] = array();
$GLOBALS['__http'][ SN_CF_API_BASE . '/zones/zone123' ] = $j( 200, array( 'success' => true, 'result' => array( 'id' => 'zone123', 'account' => array( 'id' => 'acct9' ) ) ) );
$fw_acct_ok = array( 'data' => array( 'viewer' => array( 'accounts' => array( array( 'firewallEventsAdaptiveGroups' => array( array( 'count' => 9, 'dimensions' => array( 'action' => 'block', 'source' => 'waf', 'ruleId' => 'r1' ) ) ) ) ) ) ) );
$GLOBALS['__http'][ SN_CF_API_BASE . '/graphql#fw' ] = $j( 200, $refused );
$GLOBALS['__http'][ SN_CF_API_BASE . '/graphql#fwacct' ] = $j( 200, $fw_acct_ok );
$r = sn_cf_monitor_refresh();
ok( true === $r['firewall']['available'] && 9 === $r['firewall']['events'] && 'account' === $r['firewall']['path'] && 'answered' === $r['firewall']['probe']['account_path'], 'when the account path answers, the firewall reading comes from it and records the path' );
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

// ── The kit list's CSS keeps the label alive under a long value (14.9.1).
$css = preg_replace( '~/\*.*?\*/~s', '', (string) file_get_contents( dirname( __DIR__ ) . '/assets/os-app.css' ) );
preg_match( '/\.snt-list__label \{([^}]*)\}/s', (string) $css, $lab );
preg_match( '/\.snt-list__value \{([^}]*)\}/s', (string) $css, $val );
ok( isset( $lab[1] ) && preg_match( '/min-width:\s*6em/', $lab[1] ), 'the label keeps a 6em floor: a row always shows its name' );
ok( isset( $val[1] ) && preg_match( '/flex:\s*0 1 auto/', $val[1] ) && preg_match( '/max-width:\s*60%/', $val[1] ) && preg_match( '/text-overflow:\s*ellipsis/', $val[1] ), 'the value may shrink (flex 0 1 auto, 60% cap, ellipsis): a long reading truncates instead of erasing the label' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/openstation-kit-data.php' ), "'title' => '' !== (string) ( \$row['title'] ?? '' )" ), 'a list row may carry a title, where the sentence a figure cannot hold goes' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

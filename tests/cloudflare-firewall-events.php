<?php
/**
 * The Cloudflare firewall event log (15.1.0): the parser over fixtures, the
 * weighted tops, the abilities witness with its negative controls, and the
 * refresh through stubbed HTTP. Run: php tests/cloudflare-firewall-events.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_CF_API_BASE', 'https://api.cloudflare.com/client/v4' );
$GLOBALS['__opt'] = array(); $GLOBALS['__http'] = array(); $GLOBALS['__calls'] = array(); $GLOBALS['__actions'] = array();
function __( $s, $d = null ) { return $s; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = array( $cb, $p ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function sn_cf_is_configured() { return ! empty( $GLOBALS['__configured'] ); }
function sn_cf_get_token() { return 'tok'; }
function sn_cf_get_zone() { return 'zone123'; }
function sn_cf_get_account_id() { return ''; }
function is_wp_error( $x ) { return false; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__calls'][] = array( 'GET', $url, $args ); return array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['__calls'][] = array( 'POST', $url, $args ); return $GLOBALS['__http'][ $url ] ?? array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( $t, $r, $h ) { return true; }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
function human_time_diff( $from, $to ) { return '1 hour'; }

require dirname( __DIR__ ) . '/inc/cloudflare-monitor.php';
require dirname( __DIR__ ) . '/inc/cloudflare-firewall-events.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$j   = static function ( $code, array $body ) { return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) ); };
$row = static function ( array $over = array() ) { return $over + array( 'datetime' => '2026-09-15T12:00:00Z', 'action' => 'block', 'source' => 'firewallCustom', 'ruleId' => 'r1', 'description' => 'Block Basic-auth on abilities API', 'clientIP' => '203.0.113.9', 'clientAsn' => '64500', 'clientCountryName' => 'US', 'clientRequestPath' => '/wp-json/wp-abilities/v1/abilities', 'clientRequestQuery' => '', 'userAgent' => 'curl/8', 'rayName' => 'ray1', 'sampleInterval' => 1 ); };
echo "cloudflare-firewall-events -- what Cloudflare stopped before WordPress ran (15.1.0)\n";

// ── Query
$q = sn_cf_firewall_events_query();
ok( false !== strpos( $q, 'firewallEventsAdaptive(limit: ' . SN_CF_FW_EVENTS_LIMIT ) && false !== strpos( $q, 'orderBy: [datetime_DESC]' ) && false !== strpos( $q, 'sampleInterval' ) && false !== strpos( $q, 'clientRequestPath' ) && false === strpos( $q, 'Groups' ), 'the query asks the raw dataset, one page, newest first, with sampleInterval and the path; never the grouped one' );

// ── Parser
$body = array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'firewallEventsAdaptive' => array( $row(), $row( array( 'sampleInterval' => 5, 'userAgent' => str_repeat( 'x', 400 ) ) ), $row( array( 'sampleInterval' => 0 ) ) ) ) ) ) ) );
$r = sn_cf_firewall_events_from( array( 'http' => 200, 'body' => $body, 'error' => '' ) );
ok( true === $r['available'] && 3 === count( $r['rows'] ) && array( 1, 5, 1 ) === array_column( $r['rows'], 'weight' ) && 160 === strlen( $r['rows'][1]['userAgent'] ) && false === $r['truncated'], 'three rows: weight is the sampleInterval, never below 1; the UA is cut at 160; not truncated' );
$refusal = array( 'data' => null, 'errors' => array( array( 'message' => "zone 'zone123' does not have access to the path", 'extensions' => array( 'code' => 'authz' ) ) ) );
$r = sn_cf_firewall_events_from( array( 'http' => 200, 'body' => $refusal, 'error' => '' ) );
ok( false === $r['available'] && true === $r['needs_permission'] && array() === $r['rows'] && false !== strpos( $r['error'], 'does not have access' ), 'a refusal is a gap with the API\'s sentence, never zero rows' );
$r = sn_cf_firewall_events_from( array( 'http' => 0, 'body' => array(), 'error' => 'cURL error 28' ) );
ok( false === $r['available'] && false === $r['needs_permission'] && 'cURL error 28' === $r['error'], 'a network failure is a gap, not a permission verdict' );
$page = array_fill( 0, SN_CF_FW_EVENTS_LIMIT, $row() );
$r = sn_cf_firewall_events_from( array( 'http' => 200, 'body' => array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'firewallEventsAdaptive' => $page ) ) ) ) ), 'error' => '' ) );
ok( true === $r['truncated'] && SN_CF_FW_EVENTS_LIMIT === count( $r['rows'] ), 'a full page is marked truncated: the counts are a floor' );

// ── Tops, weighted
$rows = array( $row( array( 'clientRequestPath' => '/a', 'sampleInterval' => 3 ) ), $row( array( 'clientRequestPath' => '/b' ) ), $row( array( 'clientRequestPath' => '/b' ) ), $row( array( 'clientRequestPath' => '/c', 'clientCountryName' => '' ) ) );
$parsed = sn_cf_firewall_events_from( array( 'http' => 200, 'body' => array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'firewallEventsAdaptive' => $rows ) ) ) ) ), 'error' => '' ) );
ok( array( '/a' => 3, '/b' => 2, '/c' => 1 ) === sn_cf_firewall_events_top( $parsed['rows'], 'clientRequestPath' ), 'top paths weigh the sampleInterval: /a once at 3 outranks /b twice at 1' );
ok( array( 'US' => 5, '(none)' => 1 ) === sn_cf_firewall_events_top( $parsed['rows'], 'clientCountryName' ) && array( '/a' => 3 ) === sn_cf_firewall_events_top( $parsed['rows'], 'clientRequestPath', 1 ), 'an empty value is named (none); n caps the list' );

// ── The abilities witness, with its negative controls
$hit_path  = $row();
$hit_query = $row( array( 'clientRequestPath' => '/', 'clientRequestQuery' => 'rest_route=/wp-abilities/v1/abilities', 'sampleInterval' => 2 ) );
$managed   = $row( array( 'source' => 'firewallManaged', 'description' => 'SQLi' ) );
$other     = $row( array( 'clientRequestPath' => '/wp-json/wp/v2/posts' ) );
$logged    = $row( array( 'action' => 'log' ) );
$bot       = $row( array( 'source' => 'botFight', 'description' => 'Bot Fight Mode' ) );
$ratelimit = $row( array( 'source' => 'rateLimit', 'description' => 'abilities rate limit' ) ); // the word, the path, a block: but not a custom rule
$hits = sn_cf_firewall_events_abilities_blocks( array( $managed, $hit_path, $other, $logged, $bot, $hit_query ) );
ok( 2 === count( $hits ) && '/wp-json/wp-abilities/v1/abilities' === $hits[0]['clientRequestPath'] && 'rest_route=/wp-abilities/v1/abilities' === $hits[1]['clientRequestQuery'], 'only the custom rule blocking an abilities request counts, on either spelling' );
ok( array() === sn_cf_firewall_events_abilities_blocks( array( $managed, $other, $logged, $bot, $ratelimit ) ), 'negative control: a managed-rule block on the path, the rule on another path, a log action, a bot block, and a rate-limit rule carrying the word are not this rule firing' );
ok( array() === sn_cf_firewall_events_abilities_blocks( array() ), 'an empty log proves nothing: no hits, the caller falls through' );

// ── Refresh through stubbed HTTP
$GLOBALS['__configured'] = false;
$rec = sn_cf_firewall_events_refresh();
ok( false === $rec['configured'] && array() === $rec['rows'] && array() === $GLOBALS['__calls'] && false === $GLOBALS['__autoload'][ SN_CF_FW_EVENTS_OPT ], 'unconfigured: nothing asked, an honest record stored, never autoloaded' );
$GLOBALS['__configured'] = true; $GLOBALS['__calls'] = array();
$GLOBALS['__http'][ SN_CF_API_BASE . '/graphql' ] = $j( 200, $body );
$rec = sn_cf_firewall_events_refresh();
$sent = json_decode( (string) $GLOBALS['__calls'][0][2]['body'], true );
ok( 1 === count( $GLOBALS['__calls'] ) && 'POST' === $GLOBALS['__calls'][0][0] && 'zone123' === $sent['variables']['zone'] && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $sent['variables']['since'] ) && abs( strtotime( $sent['variables']['since'] ) - ( time() - DAY_IN_SECONDS ) ) < 5, 'one GraphQL POST for the zone, since 24 hours ago' );
ok( true === $rec['available'] && 3 === count( $rec['rows'] ) && $rec === sn_cf_firewall_events_read() && $rec['fetched_at'] >= time() - 5, 'the reading is stored and read back unchanged' );
ok( in_array( array( 'sn_cf_firewall_events_refresh', 20 ), $GLOBALS['__actions'][ SN_CF_MONITOR_HOOK ] ?? array(), true ), 'rides the monitor\'s daily hook, after the monitor' );
foreach ( $GLOBALS['__calls'] as $c ) {
	ok( false === strpos( $c[1], 'purge' ), 'never a purge: ' . $c[1] );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Standalone test: Cloudflare BLOCKING purge accept-confirmation (v8.7.0).
 *
 * The fast auto-purge path stays non-blocking (sn_cf_purge_everything); the
 * verified-purge path (the manual "Purge All Caches" button) routes CF through
 * sn_cf_purge_everything_verified() — a blocking request that reads CF's real
 * {success:true} body so the theme's per-leg report carries an actual
 * accept-confirmation instead of a fire-and-forget guess.
 *
 * Run: php tests/cloudflare-purge.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// --- WP stubs -------------------------------------------------------------
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }

// Configurable HTTP response for the CF purge endpoint (default: success).
$GLOBALS['__http']    = array();
$GLOBALS['__cf_reply'] = array( 'body' => json_encode( array( 'success' => true, 'errors' => array() ) ), 'response' => array( 'code' => 200 ) );
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['__cf_reply'];
}
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }

$GLOBALS['__opts'] = array();
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/cloudflare-purge.php';

// --- Scenario 1: not configured → no HTTP, not accepted -------------------
echo "Group: not configured\n";
$GLOBALS['__http'] = array();
$r = sn_cf_purge_everything_verified();
ok( is_array( $r ) && false === $r['accepted'] && false === $r['cf_success'], 'unconfigured returns accepted=false, cf_success=false' );
ok( empty( $GLOBALS['__http'] ), 'no HTTP call when unconfigured' );

// --- Scenario 2: configured (via options) — blocking, confirmed ----------
echo "\nGroup: configured — blocking accept-confirmation\n";
$GLOBALS['__opts']['sn_cf_api_token'] = 'CFTOKEN';
$GLOBALS['__opts']['sn_cf_zone_id']   = 'ZONE123';
$GLOBALS['__http'] = array();
$r = sn_cf_purge_everything_verified();
ok( true === $r['accepted'], 'HTTP 200 → accepted=true' );
ok( 200 === $r['http'], 'http code surfaced' );
ok( true === $r['cf_success'], '{success:true} → cf_success=true' );
ok( count( $GLOBALS['__http'] ) === 1, 'exactly one CF request' );

$req = $GLOBALS['__http'][0];
ok( strpos( $req['url'], '/zones/ZONE123/purge_cache' ) !== false, 'request targets the zone purge_cache endpoint' );
ok( true === ( $req['args']['blocking'] ?? null ), 'the verified request is BLOCKING' );
ok( ( $req['args']['headers']['Authorization'] ?? '' ) === 'Bearer CFTOKEN', 'Bearer token sent' );
// v8.7.1 (CMA audit INFO-1): a Bearer credential is attached, so forbid following any
// redirect from the API host that would re-send it (matches sn_uptime_status_api_get).
ok( 0 === ( $req['args']['redirection'] ?? -1 ), 'the verified CF request disables redirects' );
$body = json_decode( (string) ( $req['args']['body'] ?? '' ), true );
ok( is_array( $body ) && ! empty( $body['purge_everything'] ), 'body requests purge_everything' );

$stored = $GLOBALS['__opts']['sn_cf_last_purge'] ?? array();
ok( ! empty( $stored['verified'] ) && true === ( $stored['cf_success'] ?? null ), 'last-purge option records the verified confirmation' );

// --- Scenario 3: CF says {success:false} → cf_success=false --------------
echo "\nGroup: CF failure body\n";
$GLOBALS['__cf_reply'] = array( 'body' => json_encode( array( 'success' => false, 'errors' => array( array( 'message' => 'bad token' ) ) ) ), 'response' => array( 'code' => 200 ) );
$GLOBALS['__http'] = array();
$r = sn_cf_purge_everything_verified();
ok( true === $r['accepted'], 'a 200 is still accepted at the HTTP layer' );
ok( false === $r['cf_success'], '{success:false} → cf_success=false' );

// --- Scenario 4: non-200 → accepted=false --------------------------------
echo "\nGroup: transport / non-200\n";
$GLOBALS['__cf_reply'] = array( 'body' => '{}', 'response' => array( 'code' => 403 ) );
$GLOBALS['__http'] = array();
$r = sn_cf_purge_everything_verified();
ok( false === $r['accepted'] && false === $r['cf_success'], 'a 403 is neither accepted nor a success' );
ok( 403 === $r['http'], 'the non-200 code surfaces in the report' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

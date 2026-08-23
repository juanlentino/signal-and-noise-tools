<?php
/**
 * Standalone tests for inc/agent-ard.php — the ARD capability manifest.
 *
 * Pins the spec's HARD rules (specVersion / host / entries; the URN shape; and
 * "exactly one of url or data") plus the two local rules: the host identifier
 * is the site's real did:web, and the rw MCP door never appears.
 *
 * @package SignalNoiseTools
 * @since 12.15.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
define( 'SN_AGENT_DISCOVERY_TEST', true );
define( 'SN_MCP_TEST', true );

$GLOBALS['__status'] = 0;
if ( ! function_exists( 'status_header' ) ) { function status_header( $c ) { $GLOBALS['__status'] = (int) $c; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = 'name' ) { return 'Juan Lentino'; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '9.9.9' ); }

require_once __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require_once __DIR__ . '/../inc/mcp/mcp-endpoint.php';
require_once __DIR__ . '/../inc/agent-discovery.php';
require_once __DIR__ . '/../inc/agent-ard.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
ob_start();
echo "agent ARD — /.well-known/ai-catalog.json (v12.15.0)\n\n";

// ---- route ----------------------------------------------------------------
ok( sn_agent_ard_is_request( '/.well-known/ai-catalog.json' ) === true, 'matches the ai-catalog path' );
ok( sn_agent_ard_is_request( '/.well-known/ai-catalog.json?x=1' ) === true, 'permits a query string' );
ok( sn_agent_ard_is_request( '/ai-catalog.json' ) === false, 'rejects it outside .well-known' );
ok( sn_agent_ard_is_request( '/.well-known/api-catalog' ) === false, 'does not collide with the RFC 9727 catalog' );

// ---- spec-required root shape ---------------------------------------------
$m = sn_agent_ard_manifest();
ok( ( $m['specVersion'] ?? '' ) !== '', 'root carries a non-empty specVersion' );
ok( isset( $m['host'] ) && is_array( $m['host'] ), 'root carries a host object' );
ok( isset( $m['entries'] ) && is_array( $m['entries'] ) && count( $m['entries'] ) > 0, 'root carries a non-empty entries array' );
ok( ( $m['host']['displayName'] ?? '' ) !== '', 'host has a displayName' );
ok( ( $m['host']['identifier'] ?? '' ) === 'did:web:juanlentino.com', "host identifier is the site's REAL did:web" );

// ---- per-entry spec rules --------------------------------------------------
$urn_ok = true; $one_of = true; $qcount = true; $typed = true; $named = true; $https = true;
foreach ( $m['entries'] as $e ) {
	if ( ! preg_match( '#^urn:air:juanlentino\.com:[a-z0-9-]+:[a-z0-9-]+$#', (string) ( $e['identifier'] ?? '' ) ) ) { $urn_ok = false; }
	$has_url = array_key_exists( 'url', $e ); $has_data = array_key_exists( 'data', $e );
	if ( $has_url === $has_data ) { $one_of = false; } // exactly one, never both/neither
	$q = $e['representativeQueries'] ?? array();
	if ( ! is_array( $q ) || count( $q ) < 2 || count( $q ) > 5 ) { $qcount = false; }
	if ( ( $e['type'] ?? '' ) === '' ) { $typed = false; }
	if ( ( $e['displayName'] ?? '' ) === '' ) { $named = false; }
	if ( strpos( (string) ( $e['url'] ?? '' ), 'https://' ) !== 0 ) { $https = false; }
}
ok( $urn_ok, 'every identifier is a well-formed urn:air:<fqdn>:<ns>:<name>' );
ok( $one_of, 'every entry has EXACTLY ONE of url / data (spec hard rule)' );
ok( $qcount, 'every entry carries 2–5 representativeQueries' );
ok( $typed, 'every entry declares an IANA media type' );
ok( $named, 'every entry has a displayName' );
ok( $https, 'every entry url is absolute https' );

$ids = array_column( $m['entries'], 'identifier' );
ok( count( $ids ) === count( array_unique( $ids ) ), 'identifiers are unique' );

// ---- entries point at things that exist ------------------------------------
$urls = array_column( $m['entries'], 'url' );
ok( in_array( 'https://juanlentino.com/.well-known/mcp/server-card.json', $urls, true ), 'names the MCP server card' );
ok( in_array( 'https://juanlentino.com/.well-known/api-catalog', $urls, true ), 'names the API catalog' );
ok( in_array( 'https://juanlentino.com/llms.txt', $urls, true ), 'names llms.txt' );
ok( in_array( 'https://juanlentino.com/tdm-policy/', $urls, true ), 'names the TDM policy' );

// ---- the unattended-surface rule holds here too -----------------------------
$json = wp_json_encode( $m, JSON_UNESCAPED_SLASHES );
ok( strpos( $json, 'mcp-rw' ) === false, 'never names the rw MCP door' );
ok( strpos( $json, '(Write)' ) === false, 'never names the rw door identity' );

// ---- agents.json advertisement --------------------------------------------
$adv = sn_agent_advertise_ard_surface( array() );
ok( count( $adv ) === 1, 'ARD advertises exactly itself' );
ok( ( $adv[0]['type'] ?? '' ) === 'ard', 'advertised as type=ard' );
ok( ( $adv[0]['url'] ?? '' ) === 'https://juanlentino.com/.well-known/ai-catalog.json', 'advertised URL matches the served path' );
ok( count( sn_agent_advertise_ard_surface( array( array( 'type' => 'x' ) ) ) ) === 2, 'appends rather than replacing' );

// ---- content type + send ----------------------------------------------------
// ARD is plain application/json; the RFC 9727 catalog is a linkset. Different
// documents, different types — a test so they are never "harmonised".
ok( SN_AGENT_ARD_TYPE === 'application/json; charset=utf-8', 'ARD is typed application/json' );
ok( SN_AGENT_ARD_TYPE !== SN_AGENT_CATALOG_TYPE, 'ARD and the API catalog do NOT share a content type' );

$GLOBALS['__status'] = 0; ob_start(); sn_agent_ard_send(); $out = ob_get_clean();
ok( $GLOBALS['__status'] === 200, 'send() sets an explicit 200' );
$decoded = json_decode( $out, true );
ok( is_array( $decoded ) && isset( $decoded['entries'] ), 'send() emits a valid ARD document' );

$report = ob_get_clean(); echo $report;
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

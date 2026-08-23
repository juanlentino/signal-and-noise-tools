<?php
/**
 * Standalone tests for inc/agent-discovery.php — the standard-named agent
 * discovery documents (MCP Server Card SEP-1649, RFC 9727 API catalog).
 *
 * DRIVES THE REAL PRODUCERS. The capability-parity assertion below calls the
 * actual sn_mcp_handle_request() initialize path and compares its capabilities
 * against the card's, rather than comparing the card to a fixture that restates
 * what the card says. A fixture on both sides of that comparison would stay
 * green through the exact drift it is supposed to catch.
 *
 * Only WordPress seams are stubbed (home_url/rest_url/get_bloginfo/
 * status_header/add_action/…). Everything sn_* is the shipped code.
 *
 * @package SignalNoiseTools
 * @since 12.14.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// Suppress both modules' hook registration; we call the handlers directly.
define( 'SN_AGENT_DISCOVERY_TEST', true );
define( 'SN_MCP_TEST', true );

$GLOBALS['__status'] = 0;

if ( ! function_exists( 'status_header' ) ) {
	function status_header( $c ) { $GLOBALS['__status'] = (int) $c; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $k = 'name' ) { return 'Juan Lentino'; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) { return $v; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
}
if ( ! defined( 'SNT_VERSION' ) ) {
	// Deliberately NOT the shipping version. If the card ever hardcoded the real
	// version instead of reading this constant, a stub set to the real version
	// would still pass — the assertion would be vacuous exactly when it matters.
	define( 'SNT_VERSION', '9.9.9' );
}

require_once __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require_once __DIR__ . '/../inc/mcp/mcp-endpoint.php';
require_once __DIR__ . '/../inc/mcp/mcp-server.php';
require_once __DIR__ . '/../inc/agent-discovery.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// Outer buffer opened BEFORE any output: while output is buffered PHP reports
// headers_sent() === false, so the real header() calls inside send() are legal
// and emit no warnings into the assertions below. Same idiom as
// tests/provenance-did.php — copying its tail without this head is what makes
// send() look like it emits malformed JSON.
ob_start();
echo "agent discovery — MCP server card + API catalog (v12.14.0)\n\n";

// ---- route matchers -------------------------------------------------------
ok( sn_agent_card_is_request( '/.well-known/mcp/server-card.json' ) === true, 'matches the server-card path' );
ok( sn_agent_card_is_request( '/.well-known/mcp/server-card.json?x=1' ) === true, 'server card permits a query string' );
ok( sn_agent_card_is_request( '/.well-known/mcp/server-card.json/' ) === true, 'server card tolerates a trailing slash' );
ok( sn_agent_card_is_request( '/server-card.json' ) === false, 'rejects the card outside .well-known' );
ok( sn_agent_card_is_request( '/.well-known/mcp/server-card.jsonx' ) === false, 'rejects a near-miss card path' );
ok( sn_agent_catalog_is_request( '/.well-known/api-catalog' ) === true, 'matches the api-catalog path' );
ok( sn_agent_catalog_is_request( '/.well-known/api-catalog?x=1' ) === true, 'api-catalog permits a query string' );
ok( sn_agent_catalog_is_request( '/api-catalog' ) === false, 'rejects the catalog outside .well-known' );
ok( sn_agent_card_is_request( '/.well-known/api-catalog' ) === false, 'the two routes do not collide' );

// ---- server card ----------------------------------------------------------
$card = sn_agent_mcp_server_card();
ok( ( $card['serverInfo']['name'] ?? '' ) !== '', 'card carries a serverInfo name' );
ok( ( $card['serverInfo']['version'] ?? '' ) === SNT_VERSION, 'card version tracks SNT_VERSION (not a hardcoded literal)' );
ok( ( $card['protocolVersion'] ?? '' ) === SN_MCP_PROTOCOL_VERSION, 'card states the pinned MCP protocol revision' );
ok( ( $card['transport']['type'] ?? '' ) === 'streamable-http', 'card declares the streamable-http transport' );
ok( ( $card['transport']['endpoint'] ?? '' ) === 'https://juanlentino.com/wp-json/signal-noise/v1/mcp', 'card points at the REAL read-door URL built from rest_url()' );
ok( ( $card['authentication']['required'] ?? null ) === true, 'card states authentication is required' );
ok( ( $card['authentication']['type'] ?? '' ) === 'application-password', 'card names the application-password scheme' );

// ---- capability parity: the WHOLE point of sn_mcp_capabilities_map() -------
$init = sn_mcp_handle_request(
	array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array() ),
	SN_MCP_DOOR_READ
);
$handshake = $init['result']['capabilities'] ?? null;
ok( is_array( $handshake ), 'initialize really returned a capabilities map (producer drove)' );
ok( $card['capabilities'] === $handshake, 'card capabilities are IDENTICAL to what initialize returns' );
ok( ( $init['result']['serverInfo'] ?? null ) === $card['serverInfo'], 'card serverInfo matches the read door handshake' );

// ---- the read-door-only policy (D5) ---------------------------------------
// Same flags the module serializes with — default encoding escapes / as \/,
// so a substring search for a path would never match the shipped bytes.
$card_json = wp_json_encode( $card, JSON_UNESCAPED_SLASHES );
ok( strpos( $card_json, 'mcp-rw' ) === false, 'card never names the rw door path' );
ok( strpos( $card_json, '(Write)' ) === false, 'card never names the rw door identity' );
ok( strpos( $card_json, '/wp-json/signal-noise/v1/mcp' ) !== false, 'card does name the read door' );

// ---- API catalog ----------------------------------------------------------
$cat = sn_agent_api_catalog();
ok( isset( $cat['linkset'] ) && is_array( $cat['linkset'] ), 'catalog has a linkset array (RFC 9264 serialization)' );
ok( count( $cat['linkset'] ) === 3, 'catalog advertises exactly the three APIs that answer' );
$all_anchored = true; $all_desc = true; $all_absolute = true;
foreach ( $cat['linkset'] as $entry ) {
	if ( empty( $entry['anchor'] ) ) { $all_anchored = false; }
	if ( empty( $entry['service-desc'][0]['href'] ) ) { $all_desc = false; }
	foreach ( array( $entry['anchor'] ?? '', $entry['service-desc'][0]['href'] ?? '' ) as $u ) {
		if ( strpos( (string) $u, 'https://' ) !== 0 ) { $all_absolute = false; }
	}
}
ok( $all_anchored, 'every catalog entry carries an anchor' );
ok( $all_desc, 'every catalog entry carries a service-desc href' );
ok( $all_absolute, 'every catalog URL is absolute https (an off-site agent follows it directly)' );
$anchors = array_column( $cat['linkset'], 'anchor' );
ok( in_array( 'https://juanlentino.com/wp-json/', $anchors, true ), 'catalog anchors the WP REST index' );
ok( in_array( 'https://juanlentino.com/wp-json/wp-abilities/v1/abilities', $anchors, true ), 'catalog anchors the Abilities API' );
ok( in_array( 'https://juanlentino.com/wp-json/signal-noise/v1/mcp', $anchors, true ), 'catalog anchors the MCP read door' );
$mcp_entry = null;
foreach ( $cat['linkset'] as $e ) { if ( ( $e['anchor'] ?? '' ) === 'https://juanlentino.com/wp-json/signal-noise/v1/mcp' ) { $mcp_entry = $e; } }
ok( ( $mcp_entry['service-desc'][0]['href'] ?? '' ) === 'https://juanlentino.com/.well-known/mcp/server-card.json', "the MCP entry's service-desc is the server card" );
ok( strpos( wp_json_encode( $cat, JSON_UNESCAPED_SLASHES ), 'mcp-rw' ) === false, 'catalog never names the rw door either' );

// ---- agents.json advertisement (v12.16.0) ---------------------------------
// The site's own richer index did not know about the standard documents next
// to it. The theme's filter seam is how the plugin fixes that with no theme edit.
$surfaces = sn_agent_advertise_discovery_surfaces( array() );
ok( count( $surfaces ) === 2, 'advertises exactly the two documents this file owns' );
$types = array_column( $surfaces, 'type' );
ok( in_array( 'mcp-server-card', $types, true ), 'advertises the MCP server card' );
ok( in_array( 'api-catalog', $types, true ), 'advertises the API catalog' );
$adv_urls = array_column( $surfaces, 'url' );
ok( in_array( 'https://juanlentino.com/.well-known/mcp/server-card.json', $adv_urls, true ), 'card URL matches the served path' );
ok( in_array( 'https://juanlentino.com/.well-known/api-catalog', $adv_urls, true ), 'catalog URL matches the served path' );
ok( count( sn_agent_advertise_discovery_surfaces( array( array( 'type' => 'existing' ) ) ) ) === 3, 'APPENDS to the existing surface list, never replaces it' );
foreach ( $surfaces as $sfc ) {
	ok( ! empty( $sfc['format'] ) && ! empty( $sfc['title'] ) && ! empty( $sfc['description'] ), 'advertised entry ' . $sfc['type'] . ' carries format/title/description' );
}

// ---- content types + send paths -------------------------------------------
ok( SN_AGENT_CATALOG_TYPE === 'application/linkset+json; charset=utf-8', 'catalog is typed as a linkset (RFC 9727 §3), not plain json' );
ok( SN_AGENT_CARD_TYPE === 'application/json; charset=utf-8', 'server card is typed application/json' );

$GLOBALS['__status'] = 0; ob_start(); sn_agent_card_send(); $out = ob_get_clean();
ok( $GLOBALS['__status'] === 200, 'card send() sets an explicit 200 (postless route would 404)' );
ok( is_array( json_decode( $out, true ) ), 'card send() emits valid JSON' );
$GLOBALS['__status'] = 0; ob_start(); sn_agent_catalog_send(); $out2 = ob_get_clean();
ok( $GLOBALS['__status'] === 200, 'catalog send() sets an explicit 200' );
$decoded = json_decode( $out2, true );
ok( is_array( $decoded ) && isset( $decoded['linkset'] ), 'catalog send() emits a valid linkset document' );

$report = ob_get_clean(); echo $report;
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

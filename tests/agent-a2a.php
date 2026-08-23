<?php
/**
 * The A2A Agent Card (/.well-known/agent-card.json).
 *
 * The load-bearing assertion here is the HONESTY one: this card must never
 * claim an A2A JSON-RPC binding, because the site does not implement one. A
 * conformant-looking card over an endpoint that rejects `message/send` is a
 * trap. If someone later "fixes" preferredTransport to JSONRPC to satisfy a
 * scanner, this suite fails and says why.
 *
 * Skills are driven through the REAL producer with only the WP seams stubbed —
 * a fixture that returned a hand-built skills array would assert nothing about
 * the derivation, which is the part that can rot.
 *
 * Run: php tests/agent-a2a.php
 *
 * @since 12.20.0
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

define( 'SN_AGENT_DISCOVERY_TEST', true );
define( 'SN_MCP_TEST', true );
define( 'SN_MCP_DOOR_READ', 'read' );
define( 'SN_MCP_DOOR_RW', 'rw' );
define( 'SNT_VERSION', '12.20.0' );

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $k = 'name' ) { return 'Juan Lentino'; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return $s; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $s ) { return $s; }
}
if ( ! function_exists( 'sn_mcp_namespace' ) ) {
	function sn_mcp_namespace() { return 'signal-noise/v1'; }
}

// --- the seams the derivation reads -------------------------------------
class SN_Test_Ability {
	private $n, $l, $d;
	public function __construct( $n, $l, $d ) { $this->n = $n; $this->l = $l; $this->d = $d; }
	public function get_name() { return $this->n; }
	public function get_label() { return $this->l; }
	public function get_description() { return $this->d; }
}
$GLOBALS['__abilities'] = array(
	new SN_Test_Ability( 'signal-noise/get-analytics-summary', 'Analytics summary', 'Rolled-up traffic for a window.' ),
	new SN_Test_Ability( 'signal-noise/get-health-scan', 'Health scan', 'Cached content-health findings.' ),
	new SN_Test_Ability( 'signal-noise/sn-apply', 'Apply', 'Write door only — must not appear.' ),
	new SN_Test_Ability( 'signal-noise/no-description', 'No description', '' ),
	new SN_Test_Ability( 'other-plugin/thing', 'Foreign', 'Not ours.' ),
);
$GLOBALS['__read_allow'] = array(
	'signal-noise/get-analytics-summary',
	'signal-noise/get-health-scan',
	'signal-noise/no-description',
);
if ( ! function_exists( 'wp_get_abilities' ) ) {
	function wp_get_abilities() { return $GLOBALS['__abilities']; }
}
if ( ! function_exists( 'sn_mcp_is_allowed' ) ) {
	function sn_mcp_is_allowed( $slug, $door = SN_MCP_DOOR_READ ) {
		return SN_MCP_DOOR_READ === $door && in_array( (string) $slug, $GLOBALS['__read_allow'], true );
	}
}

require_once __DIR__ . '/../inc/agent-discovery.php';
require_once __DIR__ . '/../inc/agent-a2a.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

// 1. Address.
ok( SN_AGENT_A2A_PATH === '/.well-known/agent-card.json', 'path is the standard A2A address' );
ok( sn_agent_a2a_is_request( '/.well-known/agent-card.json' ), 'matches its own path' );
ok( sn_agent_a2a_is_request( '/.well-known/agent-card.json?x=1' ), 'matches with a query string' );
ok( ! sn_agent_a2a_is_request( '/.well-known/agent-card.json/extra' ), 'does NOT match a longer path' );
ok( ! sn_agent_a2a_is_request( '/.well-known/agents.json' ), 'does NOT match the theme-owned agents.json' );

$card = sn_agent_a2a_card();

// 2. A2A REQUIRED fields.
foreach ( array( 'id', 'name', 'description', 'url', 'version' ) as $k ) {
	ok( isset( $card[ $k ] ) && '' !== $card[ $k ], "required field '$k' present and non-empty" );
}

// 3. THE HONESTY PIN. The site speaks MCP. If this card ever claims an A2A
//    JSON-RPC/gRPC/HTTP+JSON binding, it is advertising an interface that does
//    not answer — the failure inc/agent-discovery.php exists to prevent.
ok( 'MCP' === $card['preferredTransport'], 'preferredTransport declares MCP' );
foreach ( array( 'JSONRPC', 'GRPC', 'HTTP+JSON' ) as $claimed ) {
	ok( $card['preferredTransport'] !== $claimed,
		"does NOT claim the A2A '$claimed' binding (the site does not implement it)" );
}
ok( $card['url'] === sn_agent_mcp_endpoint_url(), 'url is the real MCP endpoint, shared with the server card' );

// 3b. supportedInterfaces — required by the conformance scanner, and the field
//     whose absence failed the card on first ship. It must name the same
//     endpoint the server card does, and declare the same honest transport.
ok( isset( $card['supportedInterfaces'] ) && is_array( $card['supportedInterfaces'] ),
	'supportedInterfaces present and an array' );
ok( count( $card['supportedInterfaces'] ) > 0, 'supportedInterfaces is NOT empty' );
$iface = $card['supportedInterfaces'][0];
ok( ( $iface['url'] ?? '' ) === sn_agent_mcp_endpoint_url(),
	'supportedInterfaces[0].url is the same MCP endpoint as the server card' );
ok( 'MCP' === ( $iface['transport'] ?? '' ), 'supportedInterfaces[0].transport declares MCP' );
foreach ( array( 'JSONRPC', 'GRPC', 'HTTP+JSON' ) as $claimed ) {
	ok( ( $iface['transport'] ?? '' ) !== $claimed,
		"supportedInterfaces does NOT claim the A2A '$claimed' binding either" );
}

// 4. Capabilities are declared FALSE because none are implemented. A client
//    reading streaming:true would open a stream and hang.
foreach ( array( 'streaming', 'pushNotifications', 'stateTransitionHistory' ) as $c ) {
	ok( array_key_exists( $c, $card['capabilities'] ) && false === $card['capabilities'][ $c ],
		"capability '$c' declared false, not omitted" );
}

// 5. Authentication is stated, not implied.
ok( true === $card['authentication']['required'], 'authentication.required is true' );
ok( false !== strpos( $card['authentication']['description'], '401' ),
	'authentication says what an anonymous request receives' );

// 6. SKILLS ARE DERIVED — driven through the real producer.
$ids = array_column( $card['skills'], 'id' );
ok( in_array( 'signal-noise/get-analytics-summary', $ids, true ), 'read-door ability appears as a skill' );
ok( in_array( 'signal-noise/get-health-scan', $ids, true ), 'second read-door ability appears' );
ok( ! in_array( 'signal-noise/sn-apply', $ids, true ), 'rw-only ability NEVER appears on the card' );
ok( ! in_array( 'other-plugin/thing', $ids, true ), 'foreign-namespace ability excluded' );
ok( ! in_array( 'signal-noise/no-description', $ids, true ),
	'ability with no description is SKIPPED, not padded with an invented one' );
ok( $ids === array_values( array_unique( $ids ) ), 'no duplicate skill ids' );
$sorted = $ids; sort( $sorted );
ok( $ids === $sorted, 'skills are ordered deterministically' );
foreach ( $card['skills'] as $s ) {
	ok( isset( $s['id'], $s['name'], $s['description'] ) && '' !== $s['description'],
		"skill '{$s['id']}' carries the three A2A-required fields" );
}

// 7. Derivation is LIVE: shrink the allowlist and the card must shrink with it.
$GLOBALS['__read_allow'] = array( 'signal-noise/get-health-scan' );
$again = sn_agent_a2a_card();
ok( array_column( $again['skills'], 'id' ) === array( 'signal-noise/get-health-scan' ),
	'card tracks the allowlist — narrowing the door narrows the card' );
$GLOBALS['__read_allow'] = array(
	'signal-noise/get-analytics-summary',
	'signal-noise/get-health-scan',
	'signal-noise/no-description',
);

// 8. It advertises itself on agents.json.
$surfaces = sn_agent_a2a_advertise_surface( array() );
ok( 1 === count( $surfaces ), 'appends exactly one surface' );
ok( 'a2a-agent-card' === $surfaces[0]['type'], 'surface type names the document' );
ok( $surfaces[0]['url'] === 'https://juanlentino.com' . SN_AGENT_A2A_PATH, 'surface url is absolute and correct' );
ok( false !== strpos( $surfaces[0]['description'], 'MCP' ),
	'surface description repeats the transport caveat rather than hiding it' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Tests: the remote MCP payload-shape contract (versioned-contract phase 2).
 *
 * Phase 1 (worker repo v0.5.0) pinned the door's ENVELOPE. This suite pins
 * the other half at its source: the 8 remote twins' output_schemas ARE the
 * payload contract (parity-pinned byte-identical to the admin registrations
 * by tests/abilities-remote-set.php), so a renamed or re-typed field in any
 * of them must fail CI unless SN_REMOTE_CONTRACT_VERSION moves with it —
 * and a version bump without a shape change must fail too. Both directions
 * ride one equality against the (version → hash) map plus a distinctness pin.
 *
 * Run: php tests/remote-contract-shapes.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

// ── Minimal WP surface (the ai-abilities-contract pattern) ──────────────────
function __( $s, $d = null ) { return (string) $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $h, $v ) { return $v; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function current_user_can( $c ) { return false; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }

// The guard DOUBLE (the real inc/mcp/mcp-remote-guard.php fatals on
// redeclare in this harness — see tests/abilities-remote-set.php's header;
// its verbatim slug pin is tested there and in tests/mcp-remote-guard.php).
// This list is the SAME 8 the guard returns; if the guard widens, the parity
// suites red first and bring you here.
function sn_mcp_remote_slugs() {
	return array(
		'signal-noise/remote-get-analytics-summary',
		'signal-noise/remote-get-analytics-events',
		'signal-noise/remote-get-insights',
		'signal-noise/remote-get-narration',
		'signal-noise/remote-uptime-status',
		'signal-noise/remote-get-health-scan',
		'signal-noise/remote-get-rss-stats',
		'signal-noise/remote-get-deploy-status',
	);
}
function sn_remote_analytics_allows( $slug ) { return true; }

// Execute callbacks the registrations name; bodies irrelevant here (this
// suite reads schemas, it never executes) — declared so nothing fatals if a
// registration is ever eagerly resolved.
require_once __DIR__ . '/../inc/mcp/mcp-remote-contract.php';
require_once __DIR__ . '/../inc/abilities-remote-analytics.php';
require_once __DIR__ . '/../inc/abilities-remote-set.php';
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

echo "remote MCP payload-shape contract (phase 2)\n\n";

echo "Group: the 8 twins register with an output_schema\n";
$slugs   = sn_mcp_remote_slugs();
$schemas = array();
foreach ( $slugs as $slug ) {
	$args = $GLOBALS['__abilities'][ $slug ] ?? null;
	ok( is_array( $args ), "$slug is registered" );
	$schema = is_array( $args ) ? ( $args['output_schema'] ?? null ) : null;
	ok( is_array( $schema ) && array() !== $schema, "$slug declares a non-empty output_schema" );
	if ( is_array( $schema ) ) {
		$schemas[ $slug ] = $schema;
	}
}

echo "\nGroup: null-capability declarations (the August incident class)\n";
// These three answer null by design (no scan/digest yet). The worker omits
// structuredContent for exactly this; the DECLARED type must keep saying so.
foreach ( array(
	'signal-noise/remote-get-insights',
	'signal-noise/remote-get-narration',
	'signal-noise/remote-get-health-scan',
) as $slug ) {
	$type = $schemas[ $slug ]['type'] ?? null;
	ok( is_array( $type ) && in_array( 'null', $type, true ), "$slug output type still includes 'null'" );
}

echo "\nGroup: the (version, hash) pair moves together\n";
$computed = sn_remote_contract_shape_hash( $schemas );
$declared = SN_REMOTE_CONTRACT_VERSION_HASHES[ SN_REMOTE_CONTRACT_VERSION ] ?? '(version missing from map)';
ok( array_key_exists( SN_REMOTE_CONTRACT_VERSION, SN_REMOTE_CONTRACT_VERSION_HASHES ),
	'SN_REMOTE_CONTRACT_VERSION has an entry in the hash map' );
ok( $computed === $declared,
	"shape hash matches the declared pin for version '" . SN_REMOTE_CONTRACT_VERSION . "'"
	. ( $computed === $declared ? '' : " — computed $computed, declared $declared" ) );
ok( count( SN_REMOTE_CONTRACT_VERSION_HASHES ) === count( array_unique( SN_REMOTE_CONTRACT_VERSION_HASHES ) ),
	'every contract version maps to a DISTINCT hash (a bump without a shape change is refused)' );
ok( 1 === preg_match( '/^[0-9a-f]{64}$/', $computed ), 'computed hash is 64-char lowercase hex' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

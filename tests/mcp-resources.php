<?php
/**
 * Standalone tests for the MCP resources module: the resources/list catalog,
 * resources/read per-uri dispatch, the live-generated abilities catalog, the
 * CHANGELOG.md slicer, and the ability-passthrough degrade path (unregistered
 * / permission-denied / WP_Error, none of them a JSON-RPC error). Sub-project
 * B, v9.50.0 (lane PROTO).
 *
 * @since plugin v9.50.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $message; public function __construct( $c = '', $m = '' ) { $this->message = $m; }
		public function get_error_message() { return $this->message; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }

// A lightweight WP_Ability stand-in (same shape as tests/mcp-tools.php's), plus
// a registry wp_get_ability()/wp_get_abilities() pair.
class SN_Test_Ability {
	private $n, $label, $desc, $meta, $perm, $result;
	public function __construct( $n, $args ) {
		$this->n = $n; $this->label = $args['label'] ?? ''; $this->desc = $args['description'] ?? '';
		$this->meta = $args['meta'] ?? array(); $this->perm = $args['perm'] ?? true; $this->result = $args['result'] ?? null;
	}
	public function get_name() { return $this->n; }
	public function get_label() { return $this->label; }
	public function get_description() { return $this->desc; }
	public function get_meta() { return $this->meta; }
	public function check_permissions( $i = null ) { return $this->perm; }
	public function execute( $i = null ) { return $this->result; }
}
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }
if ( ! function_exists( 'wp_get_abilities' ) ) { function wp_get_abilities() { return array_values( $GLOBALS['__abilities'] ); } }

require __DIR__ . '/../inc/mcp/mcp-resources.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP resources — plugin v9.50.0\n\n";

// --- resources/list: exactly the 4 descriptors, the sn:// uris R2 names ---
$list = sn_mcp_resources_list();
ok( isset( $list['resources'] ) && is_array( $list['resources'] ), 'resources_list returns a resources array' );
ok( count( $list['resources'] ) === 4, 'exactly 4 resources are advertised' );
$uris = array_column( $list['resources'], 'uri' );
foreach ( array( 'sn://abilities-catalog', 'sn://changelog-latest', 'sn://design-tokens', 'sn://llms-txt' ) as $expected_uri ) {
	ok( in_array( $expected_uri, $uris, true ), "resources/list advertises $expected_uri" );
}
foreach ( $list['resources'] as $r ) {
	ok( ! empty( $r['name'] ) && ! empty( $r['description'] ) && ! empty( $r['mimeType'] ), "resource '{$r['uri']}' has name+description+mimeType" );
}

// --- resources/read: unknown uri -> null (caller maps to -32602, R4) ---
ok( null === sn_mcp_resource_read( 'sn://does-not-exist' ), 'resources/read on an unknown uri returns null' );

// --- sn://abilities-catalog: generated LIVE from the registry, not the stale docs file ---
$GLOBALS['__abilities'] = array(
	'signal-noise/get-health-scan' => new SN_Test_Ability( 'signal-noise/get-health-scan', array(
		'label' => 'Get health scan', 'description' => 'Cached scan summary.', 'meta' => array( 'category' => 'diagnostics' ),
	) ),
	'signal-and-noise/get-design-tokens' => new SN_Test_Ability( 'signal-and-noise/get-design-tokens', array(
		'label' => 'Get design tokens', 'description' => 'Theme tokens.', 'meta' => array( 'category' => 'diagnostics' ),
	) ),
);
$catalog_result = sn_mcp_resource_read( 'sn://abilities-catalog' );
ok( isset( $catalog_result['contents'][0]['uri'] ) && 'sn://abilities-catalog' === $catalog_result['contents'][0]['uri'], 'abilities-catalog content carries its own uri' );
ok( ( $catalog_result['contents'][0]['mimeType'] ?? '' ) === 'application/json', 'abilities-catalog mimeType is application/json' );
$decoded = json_decode( $catalog_result['contents'][0]['text'], true );
ok( is_array( $decoded ) && isset( $decoded['abilities'] ) && count( $decoded['abilities'] ) === 2, 'abilities-catalog JSON has one entry per registered ability (LIVE, not hardcoded)' );
ok( ( $decoded['abilities'][0]['slug'] ?? '' ) === 'signal-noise/get-health-scan', 'abilities-catalog entry carries the slug' );
ok( ( $decoded['abilities'][0]['label'] ?? '' ) === 'Get health scan', 'abilities-catalog entry carries the label' );
ok( ( $decoded['abilities'][0]['description'] ?? '' ) === 'Cached scan summary.', 'abilities-catalog entry carries the description' );
ok( ( $decoded['abilities'][0]['category'] ?? '' ) === 'diagnostics', 'abilities-catalog entry carries the category' );

// --- sn://abilities-catalog: empty registry -> empty (never fatal) ---
$GLOBALS['__abilities'] = array();
$empty_catalog = sn_mcp_resource_read( 'sn://abilities-catalog' );
$empty_decoded = json_decode( $empty_catalog['contents'][0]['text'], true );
ok( is_array( $empty_decoded['abilities'] ) && 0 === count( $empty_decoded['abilities'] ), 'abilities-catalog degrades to an empty list when the registry is empty' );

// --- sn://changelog-latest: the injectable-path slicer, RED-then-GREEN on a fixture ---
$scratch = sys_get_temp_dir() . '/sn-mcp-resources-test-' . uniqid( '', true ) . '.md';
file_put_contents( $scratch, "# Changelog\n\nAll notable changes.\n\n## [3.0.0] - Third\n\nThird body.\n\n## [2.0.0] - Second\n\nSecond body.\n\n## [1.0.0] - First\n\nFirst body.\n" );
$sliced = sn_mcp_changelog_latest_text( 2, $scratch );
ok( false !== strpos( $sliced, '## [3.0.0] - Third' ), 'changelog slicer includes the newest entry' );
ok( false !== strpos( $sliced, '## [2.0.0] - Second' ), 'changelog slicer includes the 2nd-newest entry (limit=2)' );
ok( false === strpos( $sliced, '## [1.0.0] - First' ), 'changelog slicer excludes entries past the limit' );
unlink( $scratch );

$missing = sn_mcp_changelog_latest_text( 5, sys_get_temp_dir() . '/sn-mcp-resources-does-not-exist-' . uniqid( '', true ) . '.md' );
ok( false !== stripos( $missing, 'unavailable' ), 'changelog slicer degrades to a plain-text notice when the file is missing (never a fatal)' );

$read_result = sn_mcp_resource_read( 'sn://changelog-latest' );
ok( ( $read_result['contents'][0]['mimeType'] ?? '' ) === 'text/markdown', 'changelog-latest mimeType is text/markdown' );
ok( false !== strpos( $read_result['contents'][0]['text'], '## [' ), 'changelog-latest (real file) renders at least one version heading' );

// --- sn://design-tokens: JSON passthrough of the theme ability's result ---
$GLOBALS['__abilities']['signal-and-noise/get-design-tokens'] = new SN_Test_Ability( 'signal-and-noise/get-design-tokens', array(
	'result' => array( 'colors' => array( 'accent' => '#123456' ) ),
) );
$tokens_result = sn_mcp_resource_read( 'sn://design-tokens' );
ok( ( $tokens_result['contents'][0]['mimeType'] ?? '' ) === 'application/json', 'design-tokens mimeType is application/json' );
$tokens_decoded = json_decode( $tokens_result['contents'][0]['text'], true );
ok( ( $tokens_decoded['colors']['accent'] ?? '' ) === '#123456', 'design-tokens is a byte-faithful JSON passthrough of the ability result' );

// --- sn://design-tokens: degrade path — ability not registered ---
unset( $GLOBALS['__abilities']['signal-and-noise/get-design-tokens'] );
$tokens_missing = sn_mcp_resource_read( 'sn://design-tokens' );
ok( false !== stripos( $tokens_missing['contents'][0]['text'], 'unavailable' ), 'design-tokens degrades to an error-text content when the ability is unregistered' );
ok( ( $tokens_missing['contents'][0]['mimeType'] ?? '' ) === 'text/plain', 'the degrade content itself is text/plain, not a false application/json claim' );

// --- sn://design-tokens: degrade path — permission denied ---
$GLOBALS['__abilities']['signal-and-noise/get-design-tokens'] = new SN_Test_Ability( 'signal-and-noise/get-design-tokens', array( 'perm' => false, 'result' => array( 'x' => 1 ) ) );
$tokens_denied = sn_mcp_resource_read( 'sn://design-tokens' );
ok( false !== stripos( $tokens_denied['contents'][0]['text'], 'permission' ), 'design-tokens degrades to an error-text content on permission denial' );

// --- sn://design-tokens: degrade path — execute() WP_Error ---
$GLOBALS['__abilities']['signal-and-noise/get-design-tokens'] = new SN_Test_Ability( 'signal-and-noise/get-design-tokens', array( 'result' => new WP_Error( 'boom', 'tokens unavailable' ) ) );
$tokens_err = sn_mcp_resource_read( 'sn://design-tokens' );
ok( false !== strpos( $tokens_err['contents'][0]['text'], 'tokens unavailable' ), 'design-tokens degrades to an error-text content carrying the WP_Error message' );

// --- sn://llms-txt: extracts the rendered manifest text (not the whole JSON envelope) ---
$GLOBALS['__abilities']['signal-and-noise/get-llms-txt'] = new SN_Test_Ability( 'signal-and-noise/get-llms-txt', array(
	'result' => array( 'variant' => 'index', 'content' => "# llms.txt\n\nSite index.", 'bytes' => 42 ),
) );
$llms_result = sn_mcp_resource_read( 'sn://llms-txt' );
ok( ( $llms_result['contents'][0]['mimeType'] ?? '' ) === 'text/plain', 'llms-txt mimeType is text/plain' );
ok( $llms_result['contents'][0]['text'] === "# llms.txt\n\nSite index.", 'llms-txt renders the manifest content field verbatim, not the whole {variant,content,bytes} envelope' );

// --- sn://llms-txt: same degrade contract as design-tokens ---
unset( $GLOBALS['__abilities']['signal-and-noise/get-llms-txt'] );
$llms_missing = sn_mcp_resource_read( 'sn://llms-txt' );
ok( false !== stripos( $llms_missing['contents'][0]['text'], 'unavailable' ), 'llms-txt degrades to an error-text content when the ability is unregistered (same contract as design-tokens)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Standalone fixture tests for the Anthropic AI provider (v3.8.0).
 *
 * Covers:
 *   - HTTP layer (anthropic-wire.php) — request shape, response decode, error paths
 *   - Provider callbacks (anthropic-provider.php) — make_turn_input, agentic_call, structured_request
 *   - Tool format translation (anthropic-tools.php) — OpenAI→Anthropic, tool_use extraction
 *
 * Mocks the HTTP layer via global stub. Does NOT make real API calls.
 *
 * @since plugin v3.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__test_http_calls'] = array();
$GLOBALS['__test_http_responses'] = array();

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args ) {
		$GLOBALS['__test_http_calls'][] = array( 'url' => $url, 'args' => $args );
		$next = array_shift( $GLOBALS['__test_http_responses'] );
		if ( $next === null ) {
			// Default: empty 200 with valid JSON body
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":"msg_test","content":[{"type":"text","text":"mock"}],"stop_reason":"end_turn"}',
			);
		}
		return $next;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) ? ( $r['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v, $opts = 0, $depth = 512 ) { return json_encode( $v, $opts, $depth ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = '' ) { return $s; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return $url; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}

if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '3.8.0' ); }

require_once __DIR__ . '/../inc/ai-copilot/anthropic-wire.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ap_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ap_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Anthropic provider suite — plugin v3.8.0\n";

// ─── Wire layer tests (Task 2) ───────────────────────────────────────────
echo "\nTest W1: wire layer happy path\n";
$GLOBALS['__test_http_calls'] = array();
$GLOBALS['__test_http_responses'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"id":"msg_01","content":[{"type":"text","text":"hello"}],"stop_reason":"end_turn"}',
	),
);
$result = snt_anthropic_messages_call( 'sk-test-key', array( 'model' => 'claude-sonnet-4-6', 'max_tokens' => 100, 'messages' => array() ) );
ap_true( is_array( $result ), 'wire returns array on 200' );
ap_eq( 'msg_01', $result['id'] ?? null, 'wire decodes response id' );
ap_eq( 'end_turn', $result['stop_reason'] ?? null, 'wire decodes stop_reason' );

echo "\nTest W2: request headers + URL\n";
$last = end( $GLOBALS['__test_http_calls'] );
ap_eq( 'https://api.anthropic.com/v1/messages', $last['url'], 'POSTs to /v1/messages' );
ap_eq( 'sk-test-key', $last['args']['headers']['x-api-key'] ?? null, 'sets x-api-key header' );
ap_eq( '2023-06-01', $last['args']['headers']['anthropic-version'] ?? null, 'sets anthropic-version header' );
ap_eq( 'application/json', $last['args']['headers']['content-type'] ?? null, 'sets content-type header' );

echo "\nTest W3: error path — non-200 with Anthropic error body\n";
$GLOBALS['__test_http_responses'] = array(
	array(
		'response' => array( 'code' => 401 ),
		'body'     => '{"type":"error","error":{"type":"authentication_error","message":"Invalid API key"}}',
	),
);
$result = snt_anthropic_messages_call( 'bad-key', array( 'model' => 'claude-sonnet-4-6' ) );
ap_true( is_wp_error( $result ), 'wire returns WP_Error on 401' );
ap_eq( 'anthropic_http_401', $result->get_error_code(), 'error code is anthropic_http_401' );
ap_true( strpos( $result->get_error_message(), 'Invalid API key' ) !== false, 'error message includes Anthropic message' );

echo "\nTest W4: error path — wp_remote_post returns WP_Error\n";
$GLOBALS['__test_http_responses'] = array( new WP_Error( 'http_request_failed', 'Network down' ) );
$result = snt_anthropic_messages_call( 'sk-test', array() );
ap_true( is_wp_error( $result ), 'wire returns WP_Error on transport failure' );
ap_eq( 'anthropic_transport_error', $result->get_error_code(), 'transport error code is set' );

echo "\nTest W5: empty API key\n";
$result = snt_anthropic_messages_call( '', array() );
ap_true( is_wp_error( $result ), 'wire rejects empty API key' );
ap_eq( 'anthropic_no_key', $result->get_error_code(), 'no-key error code' );

// ─── Tool translation tests (Task 3) ────────────────────────────────────
require_once __DIR__ . '/../inc/ai-copilot/anthropic-tools.php';

echo "\nTest T1: OpenAI → Anthropic tool translation\n";
$openai_tools = array(
	array(
		'type'        => 'function',
		'name'        => 'sn_get_theme_version',
		'description' => 'Return theme version info.',
		'parameters'  => array( 'type' => 'object', 'properties' => (object) array() ),
	),
	array(
		'type'        => 'function',
		'name'        => 'sn_validate',
		'description' => 'Validate brand alignment.',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'content' => array( 'type' => 'string' ),
			),
			'required'   => array( 'content' ),
		),
	),
);
$translated = snt_anthropic_translate_tools( $openai_tools );
ap_eq( 2, count( $translated ), 'translates 2 tools' );
ap_eq( 'sn_get_theme_version', $translated[0]['name'], 'preserves name' );
ap_eq( 'Return theme version info.', $translated[0]['description'], 'preserves description' );
ap_true( isset( $translated[0]['input_schema'] ), 'renames parameters to input_schema' );
ap_true( ! isset( $translated[0]['type'] ), 'drops type:function' );
ap_true( ! isset( $translated[0]['parameters'] ), 'drops parameters key' );
ap_eq( array( 'content' ), $translated[1]['input_schema']['required'] ?? null, 'preserves required array' );

echo "\nTest T2: tool_use block extraction\n";
$content = array(
	array( 'type' => 'text', 'text' => 'I will call the tool.' ),
	array(
		'type'  => 'tool_use',
		'id'    => 'toolu_01ABC',
		'name'  => 'sn_get_theme_version',
		'input' => (object) array(),
	),
	array(
		'type'  => 'tool_use',
		'id'    => 'toolu_02DEF',
		'name'  => 'sn_validate',
		'input' => array( 'content' => 'hello' ),
	),
);
$tool_uses = snt_anthropic_extract_tool_uses( $content );
ap_eq( 2, count( $tool_uses ), 'extracts 2 tool_use blocks' );
ap_eq( 'sn_get_theme_version', $tool_uses[0]['name'], 'first tool name' );
ap_eq( 'toolu_01ABC', $tool_uses[0]['call_id'], 'first call_id' );
ap_true( is_string( $tool_uses[0]['arguments'] ), 'arguments is a string (per registry contract)' );
ap_eq( '{"content":"hello"}', $tool_uses[1]['arguments'], 'JSON-encodes input back to string for registry contract' );

echo "\nTest T3: text-block extraction\n";
$content = array(
	array( 'type' => 'text', 'text' => 'Hello, ' ),
	array( 'type' => 'text', 'text' => 'world!' ),
	array( 'type' => 'tool_use', 'id' => 'x', 'name' => 'y', 'input' => (object) array() ),
);
$text = snt_anthropic_extract_text_blocks( $content );
ap_eq( 'Hello, world!', $text, 'concatenates text blocks, skips tool_use' );

echo "\nTest T4: synthetic structured tool builder\n";
$schema = array(
	'type'       => 'object',
	'properties' => array( 'answer' => array( 'type' => 'string' ) ),
	'required'   => array( 'answer' ),
);
$tool = snt_anthropic_synthetic_structured_tool( 'final_answer', $schema );
ap_eq( 'final_answer', $tool['name'], 'synthetic tool name' );
ap_true( ! empty( $tool['description'] ), 'synthetic tool has description' );
ap_eq( $schema, $tool['input_schema'], 'synthetic tool input_schema matches input' );

echo "\nTest T5: schema-tool-use extraction\n";
$content = array(
	array(
		'type'  => 'tool_use',
		'id'    => 'toolu_final',
		'name'  => 'final_answer',
		'input' => array( 'answer' => 'forty-two' ),
	),
);
$schema_input = snt_anthropic_extract_schema_tool_use( $content, 'final_answer' );
ap_eq( array( 'answer' => 'forty-two' ), $schema_input, 'extracts synthetic-tool input as array' );

$schema_input_other = snt_anthropic_extract_schema_tool_use( $content, 'other_name' );
ap_eq( null, $schema_input_other, 'returns null when schema tool name not found' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

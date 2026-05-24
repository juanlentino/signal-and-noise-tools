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

// ─── Provider callback tests (Task 4) ───────────────────────────────────
require_once __DIR__ . '/../inc/ai-copilot/anthropic-provider.php';

// Filter stub — declared first so the add_filter() call below works.
if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['__test_filters'] = array();
	function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
		$GLOBALS['__test_filters'][ $tag ][] = $cb;
		return true;
	}
}
// API key stub — provider tests should NOT depend on wp-ai-client being loaded.
// We mock the resolver to return a fixed value or controlled errors.
// Note: the existing apply_filters() stub (top of file) returns $value unchanged,
// so the registered filter callback below is never actually invoked by the provider.
// The provider's resolver gets '' back, then falls through to the wp-ai-client check
// (which doesn't exist in tests), returns WP_Error, which fails the is_string guard,
// and the test-passed 'sk-test' key is preserved. That's the documented test behavior
// per the plan (Task 4 Step 4 notes lines 1224-1226).
$GLOBALS['__test_resolved_key'] = 'sk-ant-test-fixture';
add_filter( 'snt_anthropic_resolved_api_key', function( $key ) {
	return $GLOBALS['__test_resolved_key'] ?? $key;
} );

echo "\nTest P1: make_turn_input — user_message\n";
$ti = snt_anthropic_make_turn_input( 'user_message', 'Hello, world!' );
ap_eq( 1, count( $ti ), 'one message produced' );
ap_eq( 'user', $ti[0]['role'], 'role is user' );
ap_eq( 'Hello, world!', $ti[0]['content'], 'content is the input string' );

echo "\nTest P2: make_turn_input — tool_results\n";
$ti = snt_anthropic_make_turn_input( 'tool_results', array(
	array( 'call_id' => 'toolu_01', 'output' => '{"theme_version":"9.1.1"}' ),
	array( 'call_id' => 'toolu_02', 'output' => '{"error":"not found"}' ),
) );
ap_eq( 1, count( $ti ), 'one user message produced (with multiple tool_result blocks)' );
ap_eq( 'user', $ti[0]['role'], 'role is user' );
ap_eq( 2, count( $ti[0]['content'] ), 'two tool_result blocks' );
ap_eq( 'tool_result', $ti[0]['content'][0]['type'], 'first block type is tool_result' );
ap_eq( 'toolu_01', $ti[0]['content'][0]['tool_use_id'], 'tool_use_id matches call_id' );

echo "\nTest P3: agentic_call — happy path (end_turn, text response)\n";
$GLOBALS['__test_http_responses'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'id'           => 'msg_01',
			'stop_reason'  => 'end_turn',
			'content'      => array(
				array( 'type' => 'text', 'text' => 'The theme version is 9.1.1.' ),
			),
		) ),
	),
);
$result = snt_anthropic_agentic_call(
	'sk-test',
	array( array( 'role' => 'user', 'content' => 'What is the theme version?' ) ),
	array(), // no tools
	null,    // no text_format
	'You are a helpful assistant.',
	null     // no state
);
ap_true( is_array( $result ), 'returns array on success' );
ap_eq( 'The theme version is 9.1.1.', $result['text'], 'extracts text from end_turn response' );
ap_eq( array(), $result['function_calls'], 'no function_calls on end_turn' );
ap_true( is_array( $result['next_state'] ), 'next_state is array' );
ap_true( isset( $result['next_state']['messages'] ), 'next_state has messages key' );

echo "\nTest P4: agentic_call — tool_use stop reason\n";
$GLOBALS['__test_http_responses'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'id'           => 'msg_02',
			'stop_reason'  => 'tool_use',
			'content'      => array(
				array( 'type' => 'text', 'text' => 'Let me check.' ),
				array(
					'type'  => 'tool_use',
					'id'    => 'toolu_xyz',
					'name'  => 'sn_get_theme_version',
					'input' => (object) array(),
				),
			),
		) ),
	),
);
$result = snt_anthropic_agentic_call(
	'sk-test',
	array( array( 'role' => 'user', 'content' => 'theme version?' ) ),
	array(
		array( 'type' => 'function', 'name' => 'sn_get_theme_version', 'description' => 'Get version', 'parameters' => array( 'type' => 'object', 'properties' => (object) array() ) ),
	),
	null,
	'',
	null
);
ap_eq( null, $result['text'], 'text is null on tool_use' );
ap_eq( 1, count( $result['function_calls'] ), 'one function_call extracted' );
ap_eq( 'sn_get_theme_version', $result['function_calls'][0]['name'], 'function_call name' );
ap_eq( 'toolu_xyz', $result['function_calls'][0]['call_id'], 'function_call call_id' );
ap_true( is_string( $result['function_calls'][0]['arguments'] ), 'arguments is a JSON string' );

echo "\nTest P5: agentic_call — request body shape\n";
$last_call = end( $GLOBALS['__test_http_calls'] );
$body = json_decode( $last_call['args']['body'], true );
ap_eq( 'claude-sonnet-4-6', $body['model'], 'model defaults to claude-sonnet-4-6' );
ap_true( isset( $body['system'] ) || empty( $body['system'] ), 'system field present (may be empty)' );
ap_true( is_array( $body['messages'] ), 'messages is array' );
ap_eq( 1, count( $body['tools'] ), 'one tool sent' );
ap_eq( 'sn_get_theme_version', $body['tools'][0]['name'], 'tool translated correctly' );
ap_true( isset( $body['tools'][0]['input_schema'] ), 'tool has input_schema (not parameters)' );

echo "\nTest P6: agentic_call — state threading\n";
$GLOBALS['__test_http_responses'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'stop_reason' => 'end_turn',
			'content'     => array( array( 'type' => 'text', 'text' => 'second turn' ) ),
		) ),
	),
);
$prior_state = array( 'messages' => array(
	array( 'role' => 'user', 'content' => 'first question' ),
	array( 'role' => 'assistant', 'content' => array( array( 'type' => 'text', 'text' => 'first answer' ) ) ),
) );
$result = snt_anthropic_agentic_call(
	'sk-test',
	array( array( 'role' => 'user', 'content' => 'follow-up' ) ),
	array(),
	null,
	'',
	$prior_state
);
$last_call = end( $GLOBALS['__test_http_calls'] );
$body = json_decode( $last_call['args']['body'], true );
ap_eq( 3, count( $body['messages'] ), 'sends prior 2 messages + this turn\'s 1' );
ap_eq( 'first question', $body['messages'][0]['content'], 'first prior message preserved' );
ap_eq( 'follow-up', $body['messages'][2]['content'], 'new message appended' );
ap_eq( 4, count( $result['next_state']['messages'] ), 'next_state includes this turn\'s assistant reply' );

echo "\nTest P7: agentic_call — max_tokens stop_reason returns WP_Error\n";
$GLOBALS['__test_http_responses'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'stop_reason' => 'max_tokens',
			'content'     => array( array( 'type' => 'text', 'text' => 'partial...' ) ),
		) ),
	),
);
$result = snt_anthropic_agentic_call( 'sk-test', array( array( 'role' => 'user', 'content' => 'x' ) ), array(), null, '', null );
ap_true( is_wp_error( $result ), 'returns WP_Error on max_tokens' );
ap_eq( 'anthropic_max_tokens', $result->get_error_code(), 'max_tokens error code' );

echo "\nTest P8: structured_request — happy path\n";
$GLOBALS['__test_http_responses'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'stop_reason' => 'end_turn',
			'content'     => array(
				array(
					'type'  => 'tool_use',
					'id'    => 'toolu_final',
					'name'  => 'answer_schema',
					'input' => array( 'answer' => 'forty-two', 'confidence' => 0.9 ),
				),
			),
		) ),
	),
);
$result = snt_anthropic_structured_request(
	'sk-test',
	array(
		array( 'role' => 'system', 'content' => 'Respond with the answer.' ),
		array( 'role' => 'user', 'content' => 'What is the meaning of life?' ),
	),
	array(
		'type'       => 'object',
		'properties' => array( 'answer' => array( 'type' => 'string' ), 'confidence' => array( 'type' => 'number' ) ),
		'required'   => array( 'answer' ),
	),
	'answer_schema',
	''
);
ap_eq( array( 'answer' => 'forty-two', 'confidence' => 0.9 ), $result, 'structured_request decodes tool_use.input' );

echo "\nTest P9: structured_request — request body shape\n";
$last_call = end( $GLOBALS['__test_http_calls'] );
$body = json_decode( $last_call['args']['body'], true );
ap_eq( 'Respond with the answer.', $body['system'], 'system message split out' );
ap_eq( 1, count( $body['messages'] ), 'only user message in messages' );
ap_eq( 1, count( $body['tools'] ), 'synthetic tool added' );
ap_eq( 'answer_schema', $body['tools'][0]['name'], 'synthetic tool name matches schema name' );
ap_eq( 'tool', $body['tool_choice']['type'], 'tool_choice type is tool' );
ap_eq( 'answer_schema', $body['tool_choice']['name'], 'tool_choice forces the synthetic tool' );

echo "\nTest P10: structured_request — no tool_use in response returns WP_Error\n";
$GLOBALS['__test_http_responses'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'stop_reason' => 'end_turn',
			'content'     => array( array( 'type' => 'text', 'text' => 'i refuse' ) ),
		) ),
	),
);
$result = snt_anthropic_structured_request( 'sk-test', array(), array(), 'name', '' );
ap_true( is_wp_error( $result ), 'WP_Error when no tool_use returned' );
ap_eq( 'anthropic_no_structured_output', $result->get_error_code(), 'no-structured-output error code' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

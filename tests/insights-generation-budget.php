<?php
/**
 * Standalone test: the Insights generation budget, handed to the AI Client.
 *
 * WHY THE BUDGET EXISTS. On claude-sonnet-5 with no explicit effort config,
 * thinking is CEILING-BOUNDED: it eats whatever max_tokens allows and leaves
 * the answer nothing. Raising the ceiling alone was falsified twice (v10.53.0
 * on the agent path, v13.20.5 on this one). The fix is an explicit effort,
 * which makes thinking DEMAND-bounded, and a ceiling above that demand.
 *
 * Through 2026.11.20 the scan rewrote the JSON body of the Anthropic request
 * on http_request_args. It now passes the same values through the AI Client:
 * thinking and output_config as ModelConfig custom options (the Anthropic
 * provider copies custom options into the request parameters), the ceiling
 * through using_max_tokens, the timeout through core's
 * wp_ai_client_default_request_timeout filter.
 *
 * WHAT THIS PINS: what snt_ai_generate_with_constraints() hands the client
 * for an Insights scan (model list, system instruction, ceiling, thinking,
 * effort, timeout), that every other feature gets none of it, and that the
 * monthly budget cap and the spend record are where they were.
 *
 * Standalone, no PHPUnit. Run: php tests/insights-generation-budget.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

function gb( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; } else { $fail++; echo "  FAIL  $label\n"; }
}

define( 'ABSPATH', '/' );
define( 'SN_AI_DEFAULT_MODEL', 'claude-sonnet-5-5' );
define( 'SN_AI_FALLBACK_MODEL', 'claude-sonnet-5' );
define( 'SN_INSIGHTS_MAX_TOKENS', 4096 );
define( 'SN_INSIGHTS_REC_MAX', 3 );

// The SDK's ModelConfig, as far as this call uses it.
eval( 'namespace WordPress\AiClient\Providers\Models\DTO; class ModelConfig { public $custom = array(); public function setCustomOptions( array $o ): void { $this->custom = $o; } public function getCustomOptions(): array { return $this->custom; } }' );

// ─── a filter registry: the timeout raise is added and removed for real ──
$GLOBALS['__filters']  = array();
$GLOBALS['__override'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; }
function remove_filter( $tag, $cb, $prio = 10 ) {
	$GLOBALS['__filters'][ $tag ] = array_values( array_filter( $GLOBALS['__filters'][ $tag ] ?? array(), static fn( $c ) => $c !== $cb ) );
	return true;
}
function apply_filters( $tag, $value, ...$rest ) {
	if ( array_key_exists( $tag, $GLOBALS['__override'] ) ) { return $GLOBALS['__override'][ $tag ]; }
	foreach ( $GLOBALS['__filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value, ...$rest ); }
	return $value;
}
function __( $s, $d = null ) { return $s; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

// ─── the plugin seams around the call: budget cap and spend record ───
$GLOBALS['__budget']   = 0.0;
$GLOBALS['__spent']    = 0.0;
$GLOBALS['__recorded'] = array();
function snt_ai_is_available() { return true; }
function sn_setting( $k, $d = null ) { return 'theme.ai_monthly_budget' === $k ? $GLOBALS['__budget'] : $d; }
$GLOBALS['__spend_checks'] = 0;
function snt_ai_spend_this_month() {
	$GLOBALS['__spend_checks']++;
	// A list is a sequence: what each successive budget check reads.
	return is_array( $GLOBALS['__spent'] ) ? (float) array_shift( $GLOBALS['__spent'] ) : $GLOBALS['__spent'];
}
function snt_ai_record_usage( $feature, $model, $result ) { $GLOBALS['__recorded'][] = array( $feature, $model, $result ); }

// ─── a recording prompt builder: what the wrapper hands the client ───
// The model text, and an optional refusal of any request that asks for JSON output.
$GLOBALS['__text']   = '[]';
$GLOBALS['__refuse'] = null;
class GbResult { public function toText() { return $GLOBALS['__text']; } }
class GbBuilder {
	public $calls = array();
	public $timeout;
	public function __construct( $prompt ) {
		$this->calls[] = array( 'prompt', array( $prompt ) );
		// Core's builder reads this filter when it is constructed.
		$this->timeout = apply_filters( 'wp_ai_client_default_request_timeout', 30.0 );
	}
	public function __call( $name, $args ) {
		$this->calls[] = array( $name, $args );
		if ( 'generate_text_result' !== $name ) { return $this; }
		return ( $GLOBALS['__refuse'] && ( null !== $this->arg( 'as_json_response' ) || ! empty( $GLOBALS['__refuse_all'] ) ) ) ? $GLOBALS['__refuse'] : new GbResult();
	}
	public function arg( $name ) {
		foreach ( $this->calls as $c ) { if ( $name === $c[0] ) { return $c[1]; } }
		return null;
	}
}
$GLOBALS['__builders'] = array();
function wp_ai_client_prompt( $prompt = null ) { return $GLOBALS['__builders'][] = new GbBuilder( $prompt ); }

require_once __DIR__ . '/../inc/ai-bootstrap/generate.php';
require_once __DIR__ . '/../inc/insights-generation-budget.php';
require_once __DIR__ . '/../inc/insights-ai-parts.php';

/** Run one Insights call and return the builder it used. */
function gb_scan() {
	$GLOBALS['__builders']     = array();
	$GLOBALS['__recorded']     = array();
	$GLOBALS['__spend_checks'] = 0;
	$GLOBALS['__structured']   = null;
	$out                       = snt_insights_call_ai( array( 'site' => 'x' ), $GLOBALS['__structured'] );
	return array( $out, $GLOBALS['__builders'][0] ?? null );
}
/** The request the Anthropic provider builds from a builder: its params, as prepareGenerateTextParams() assembles them. */
function gb_wire( GbBuilder $b ) {
	$config = $b->arg( 'using_model_config' );
	$params = array(
		'model'      => $b->arg( 'using_model_preference' )[0],
		'system'     => $b->arg( 'using_system_instruction' )[0],
		'max_tokens' => $b->arg( 'using_max_tokens' )[0],
	);
	// The provider turns a JSON response with a schema into output_format.
	$json = $b->arg( 'as_json_response' );
	if ( $json && ! empty( $json[0] ) ) {
		$params['output_format'] = array( 'type' => 'json_schema', 'schema' => $json[0] );
	}
	foreach ( $config ? $config[0]->getCustomOptions() : array() as $k => $v ) {
		if ( isset( $params[ $k ] ) ) { throw new Exception( "custom option $k collides" ); }
		$params[ $k ] = $v;
	}
	return $params;
}

// ─── 1. THE INSIGHTS REQUEST: same model, thinking, effort, ceiling, timeout ──
list( $out, $b ) = gb_scan();
$wire            = gb_wire( $b );
gb( 'the scan returns the model text', '[]' === $out );
gb( 'model list: the pinned model, then the fallback', array( 'claude-sonnet-5-5', 'claude-sonnet-5' ) === $b->arg( 'using_model_preference' ) );
gb( 'system instruction is the Insights one', snt_insights_system_instruction() === $wire['system'] );
gb( 'the prompt is the delimited signals blob', 0 === strpos( $b->arg( 'prompt' )[0], '<<<SN_UNTRUSTED_DATA' ) );
gb( 'thinking is adaptive', array( 'type' => 'adaptive' ) === ( $wire['thinking'] ?? null ) );
gb( 'effort is low', array( 'effort' => 'low' ) === ( $wire['output_config'] ?? null ) );
gb( 'does NOT use thinking.type=enabled (Claude 5 rejects it with a 400)', 'enabled' !== ( $wire['thinking']['type'] ?? '' ) );
gb( 'the ceiling is the wire ceiling, 8192', 8192 === $wire['max_tokens'] && 8192 === SN_INSIGHTS_WIRE_MAX_TOKENS );
gb( 'the ceiling exceeds the helper clamp of 4096', SN_INSIGHTS_WIRE_MAX_TOKENS > 4096 );
gb( 'the transport timeout is 180s when the builder is created', 180.0 === (float) $b->timeout && 180 === SN_INSIGHTS_WIRE_TIMEOUT );
gb( 'the timeout raise does not outlive the call', array() === ( $GLOBALS['__filters']['wp_ai_client_default_request_timeout'] ?? array() ) );
gb( 'thinking and effort ride a ModelConfig, as custom options', $b->arg( 'using_model_config' )[0] instanceof \WordPress\AiClient\Providers\Models\DTO\ModelConfig );
gb( 'nothing is hooked on http_request_args', empty( $GLOBALS['__filters']['http_request_args'] ) && ! function_exists( 'snt_insights_budget_shape' ) && ! function_exists( 'snt_insights_budget_arm' ) );
gb( 'spend is recorded once, for insights, against the pinned model', 1 === count( $GLOBALS['__recorded'] ) && 'insights' === $GLOBALS['__recorded'][0][0] && 'claude-sonnet-5-5' === $GLOBALS['__recorded'][0][1] && $GLOBALS['__recorded'][0][2] instanceof GbResult );

// A longer timeout already in force is not lowered.
$longer = static fn() => 600.0;
add_filter( 'wp_ai_client_default_request_timeout', $longer );
list( , $b ) = gb_scan();
gb( 'raise-only: a longer timeout already set is kept', 600.0 === (float) $b->timeout );
remove_filter( 'wp_ai_client_default_request_timeout', $longer );

// ─── 2. THE THREE FILTERS STILL STEER IT ─────────────────────────────
$GLOBALS['__override'] = array( 'snt_insights_anthropic_effort' => 'high', 'snt_insights_anthropic_max_tokens' => 12000, 'snt_insights_anthropic_timeout' => 240 );
list( , $b )           = gb_scan();
$wire                  = gb_wire( $b );
gb( 'effort filter: high reaches the request', array( 'effort' => 'high' ) === $wire['output_config'] );
gb( 'ceiling filter: 12000 reaches the request', 12000 === $wire['max_tokens'] );
gb( 'timeout filter: 240 reaches the transport', 240.0 === (float) $b->timeout );

// Raise-only: a ceiling filtered below the caller's own budget does not lower it.
$GLOBALS['__override'] = array( 'snt_insights_anthropic_max_tokens' => 1000 );
list( , $b )           = gb_scan();
gb( 'raise-only: a ceiling below the scan budget leaves 4096', 4096 === gb_wire( $b )['max_tokens'] );

// ─── 3. DISABLEABLE, AND THE CEILING IS CONDITIONAL ON THE EFFORT ────
// Raising the ceiling of an UNBOUNDED request is the v13.20.5 failure with a
// bigger number: thinking takes the extra room. No effort, no raise.
foreach ( array( '', 'max', 'banana' ) as $off ) {
	$GLOBALS['__override'] = array( 'snt_insights_anthropic_effort' => $off );
	list( , $b )           = gb_scan();
	$wire                  = gb_wire( $b );
	gb( "effort '$off': no thinking, no output_config, no ModelConfig", ! isset( $wire['thinking'] ) && ! isset( $wire['output_config'] ) && null === $b->arg( 'using_model_config' ) );
	gb( "effort '$off': the ceiling stays at the scan budget", 4096 === $wire['max_tokens'] );
	gb( "effort '$off': the timeout stays at the client default", 30.0 === (float) $b->timeout );
}
$GLOBALS['__override'] = array();

// ─── 4. MODEL FAMILY: adaptive thinking is Claude 5 only ─────────────
gb( 'claude-sonnet-5 is Claude 5', snt_insights_budget_model_is_claude5( 'claude-sonnet-5' ) );
gb( 'claude-sonnet-5-5 is Claude 5', snt_insights_budget_model_is_claude5( 'claude-sonnet-5-5' ) );
gb( 'claude-fable-5 is Claude 5', snt_insights_budget_model_is_claude5( 'claude-fable-5' ) );
gb( 'claude-3-5-sonnet-20241022 is not', ! snt_insights_budget_model_is_claude5( 'claude-3-5-sonnet-20241022' ) );
gb( 'claude-sonnet-4-6 is not', ! snt_insights_budget_model_is_claude5( 'claude-sonnet-4-6' ) );
gb( 'gemini-2.5-flash is not', ! snt_insights_budget_model_is_claude5( 'gemini-2.5-flash' ) );

// The owner's model choice arrives through snt_ai_model_preference. Any id in
// the list that is not Claude 5 could be the one served, and adaptive thinking
// would 400 there, so the whole request goes out plain.
foreach ( array( 'claude-sonnet-4-6', 'gemini-2.5-flash' ) as $other ) {
	$GLOBALS['__override'] = array( 'snt_ai_model_preference' => $other );
	list( , $b )           = gb_scan();
	$wire                  = gb_wire( $b );
	gb( "$other preferred: no thinking, no effort", ! isset( $wire['thinking'] ) && ! isset( $wire['output_config'] ) && null === $b->arg( 'using_model_config' ) );
	gb( "$other preferred: ceiling 4096, timeout 30", 4096 === $wire['max_tokens'] && 30.0 === (float) $b->timeout );
}
$GLOBALS['__override'] = array();
gb( 'options: an empty model list gets nothing', array() === snt_insights_budget_options( array() ) );
gb( 'options: a Claude 5 list gets thinking, effort, ceiling and timeout', array( 'custom_options', 'max_tokens', 'timeout' ) === array_keys( snt_insights_budget_options( array( 'claude-sonnet-5' ) ) ) );

// ─── 5. NO OTHER FEATURE SEES ANY OF IT ──────────────────────────────
$GLOBALS['__builders'] = array();
snt_ai_generate_with_constraints( 'p', 's', 300, 'meta-description' );
$b = $GLOBALS['__builders'][0];
gb( 'another feature: no ModelConfig', null === $b->arg( 'using_model_config' ) );
gb( 'another feature: its own ceiling', 300 === $b->arg( 'using_max_tokens' )[0] );
gb( 'another feature: the client default timeout', 30.0 === (float) $b->timeout );
gb( 'another feature: no JSON response format', null === $b->arg( 'as_json_response' ) );
$GLOBALS['__builders'] = array();
snt_ai_generate_with_constraints( 'p', 's', 99999, 'generic' );
gb( 'another feature: the 4096 clamp still holds', 4096 === $GLOBALS['__builders'][0]->arg( 'using_max_tokens' )[0] );

// ─── 6. THE MONTHLY BUDGET CAP STILL STOPS THE CALL FIRST ────────────
$GLOBALS['__budget'] = 5.0;
$GLOBALS['__spent']  = 5.0;
list( $out, $b )     = gb_scan();
gb( 'over budget: the scan gets snt_ai_over_budget', $out instanceof WP_Error && 'snt_ai_over_budget' === $out->get_error_code() );
gb( 'over budget: the client is never built', null === $b );
gb( 'over budget: nothing is recorded', array() === $GLOBALS['__recorded'] );
gb( 'over budget: no timeout raise is left behind', array() === ( $GLOBALS['__filters']['wp_ai_client_default_request_timeout'] ?? array() ) );
$GLOBALS['__spent'] = 4.99;
list( $out, $b )    = gb_scan();
gb( 'under budget: the scan runs, shaped as before', '[]' === $out && 8192 === gb_wire( $b )['max_tokens'] );
$GLOBALS['__budget'] = 0.0;

// ─── 7. STRUCTURED JSON OUTPUT, AND ONE PLAIN RETRY WHEN IT IS REFUSED ──
// The scan asks the AI Client for JSON against a schema. If that request is
// refused (a 4xx from the provider, or the client declining the options), it
// is sent once more without the schema, and today's tolerant parser reads it.
$GLOBALS['__log'] = tempnam( sys_get_temp_dir(), 'gb' );
ini_set( 'error_log', $GLOBALS['__log'] );
function gb_log() { return (string) file_get_contents( $GLOBALS['__log'] ); }
$schema = function_exists( 'snt_insights_response_schema' ) ? snt_insights_response_schema() : null;
$q      = '{"id":"q_credit","question":"How do readers weigh a credit list that no label vouches for?","adjacent_note":"the credits note","why_uncovered":"it covers detection, not the reader","wall_check":"an open question, no answer asserted","target":null}';

// 7a. The structured request, accepted.
$GLOBALS['__text'] = '{"questions":[' . $q . ']}';
list( $out, $b )   = gb_scan();
$wire              = gb_wire( $b );
gb( 'structured: the scan asks for JSON against the Insights schema', is_array( $schema ) && array( $schema ) === $b->arg( 'as_json_response' ) );
gb( 'structured: the schema root is an object holding the questions', is_array( $schema ) && 'object' === $schema['type'] && array( 'questions' ) === $schema['required'] && false === $schema['additionalProperties'] );
gb( 'structured: the caller is told the response is structured', true === $GLOBALS['__structured'] );
gb( 'structured: one request, recorded once', 1 === count( $GLOBALS['__builders'] ) && 1 === count( $GLOBALS['__recorded'] ) );
gb( 'structured: output_format rides beside thinking and effort, no collision', isset( $wire['output_format'] ) && array( 'type' => 'adaptive' ) === $wire['thinking'] && array( 'effort' => 'low' ) === $wire['output_config'] && 8192 === $wire['max_tokens'] );
$recs = snt_insights_parse_response( $out, $GLOBALS['__structured'] );
gb( 'structured: the questions parse straight from the object', is_array( $recs ) && 1 === count( $recs ) && 'q_credit' === $recs[0]['id'] );
gb( 'structured: no salvage, a fenced reply is an error', is_wp_error( snt_insights_parse_response( "```json\n{\"questions\":[" . $q . "]}\n```", true ) ) );
gb( 'structured: no salvage, a prose-wrapped array is an error', is_wp_error( snt_insights_parse_response( 'Here: [' . $q . ']', true ) ) );
gb( 'structured: an object without questions is an error, never an empty scan', is_wp_error( snt_insights_parse_response( '{}', true ) ) );
gb( 'fallback: the prose-wrapped array is still recovered as today', 1 === count( (array) snt_insights_parse_response( 'Here: [' . $q . ']' ) ) );
gb( 'structured: nothing logged', '' === gb_log() );

// 7b. Refused with a 400: one plain retry, same budget shape, today's result.
$GLOBALS['__refuse'] = new WP_Error( 'prompt_client_error', 'Bad Request (400) - output_format: Extra inputs are not permitted', array( 'status' => 400 ) );
$GLOBALS['__text']   = '[' . $q . ']';
$GLOBALS['__budget'] = 5.0;
$GLOBALS['__spent']  = 1.0;
list( $out, $b )     = gb_scan();
$second              = $GLOBALS['__builders'][1] ?? null;
gb( 'refused: exactly one retry', 2 === count( $GLOBALS['__builders'] ) );
gb( 'refused: the first request asked for JSON, the retry does not', null !== $b->arg( 'as_json_response' ) && $second && null === $second->arg( 'as_json_response' ) );
gb( 'refused: the retry keeps thinking, effort, ceiling and timeout', $second && gb_wire( $second ) === array_diff_key( gb_wire( $b ), array( 'output_format' => 1 ) ) && 180.0 === (float) $second->timeout );
gb( 'refused: the caller gets the plain text, flagged unstructured', '[' . $q . ']' === $out && false === $GLOBALS['__structured'] );
gb( 'refused: the budget is checked for both attempts', 2 === $GLOBALS['__spend_checks'] );
gb( 'refused: spend recorded once, for the request that ran', 1 === count( $GLOBALS['__recorded'] ) && 'insights' === $GLOBALS['__recorded'][0][0] );
$recs = snt_insights_parse_response( $out, $GLOBALS['__structured'] );
gb( 'refused: the scan still yields today\'s result', is_array( $recs ) && 1 === count( $recs ) && 'q_credit' === $recs[0]['id'] );
gb( 'refused: one line in the log says structured output was refused', 1 === substr_count( gb_log(), 'structured' ) && false !== strpos( gb_log(), 'prompt_client_error' ) && false !== strpos( gb_log(), 'insights' ) );

// 7c. The cap is reached between the attempts: the retry is stopped by it.
$GLOBALS['__spent'] = array( 4.0, 5.0 );
list( $out, $b )    = gb_scan();
gb( 'cap between attempts: the retry gets snt_ai_over_budget', $out instanceof WP_Error && 'snt_ai_over_budget' === $out->get_error_code() );
gb( 'cap between attempts: only the refused request was built, nothing recorded', 1 === count( $GLOBALS['__builders'] ) && array() === $GLOBALS['__recorded'] );
$GLOBALS['__budget'] = 0.0;
$GLOBALS['__spent']  = 0.0;

// 7d. Non-JSON on the fallback path degrades as today.
$GLOBALS['__text'] = 'I could not find a question worth asking this week.';
list( $out, $b )   = gb_scan();
$err               = snt_insights_parse_response( $out, $GLOBALS['__structured'] );
gb( 'fallback non-JSON: the same invalid-JSON error as today', is_wp_error( $err ) && 'snt_insights_invalid_json' === $err->get_error_code() );

// 7e. Only a refusal retries. A truncated answer was generated and billed.
foreach ( array( array( 'prompt_token_limit_reached', 400 ), array( 'prompt_network_error', 503 ), array( 'prompt_upstream_server_error', 529 ) ) as $e ) {
	$GLOBALS['__refuse'] = new WP_Error( $e[0], 'x', array( 'status' => $e[1] ) );
	list( $out, $b )     = gb_scan();
	gb( "{$e[0]}: no retry, the error comes back", 1 === count( $GLOBALS['__builders'] ) && $out instanceof WP_Error && $e[0] === $out->get_error_code() );
}
$GLOBALS['__refuse'] = new WP_Error( 'prompt_invalid_argument', 'No models found that support text_generation for this prompt.', array( 'status' => 400 ) );
list( $out, $b )     = gb_scan();
gb( 'client-side refusal: one plain retry', 2 === count( $GLOBALS['__builders'] ) && is_string( $out ) && false === $GLOBALS['__structured'] );

// 7f. Once: a retry that is refused too comes back as an error, not a third try.
$GLOBALS['__refuse']     = new WP_Error( 'prompt_client_error', 'Too Many Requests (429)', array( 'status' => 429 ) );
$GLOBALS['__refuse_all'] = true;
list( $out, $b )         = gb_scan();
gb( 'once: two requests at most', 2 === count( $GLOBALS['__builders'] ) && $out instanceof WP_Error && 'prompt_client_error' === $out->get_error_code() );
$GLOBALS['__refuse']     = null;
$GLOBALS['__refuse_all'] = false;
$GLOBALS['__text']       = '[]';
@unlink( $GLOBALS['__log'] );

echo "\ninsights-generation-budget: $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

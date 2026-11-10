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
function snt_ai_spend_this_month() { return $GLOBALS['__spent']; }
function snt_ai_record_usage( $feature, $model, $result ) { $GLOBALS['__recorded'][] = array( $feature, $model, $result ); }

// ─── a recording prompt builder: what the wrapper hands the client ───
class GbResult { public function toText() { return '[]'; } }
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
		return 'generate_text_result' === $name ? new GbResult() : $this;
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
	$GLOBALS['__builders'] = array();
	$GLOBALS['__recorded'] = array();
	$out                   = snt_insights_call_ai( array( 'site' => 'x' ) );
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

echo "\ninsights-generation-budget: $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

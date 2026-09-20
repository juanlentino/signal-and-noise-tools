<?php
/**
 * Standalone fixture tests for inc/openstation-agent-output-budget.php: the
 * agent-run generation budget on OpenStation's openstation_ai_model_config
 * seam (#1613). Adaptive thinking plus an effort level (demand-bounded
 * thinking) and a raised ceiling, on the agents runner only, both keys
 * leaving together or neither; the body rewrite it replaced is gone.
 *
 * Run: php tests/openstation-agent-output-budget.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/* WP stubs. apply_filters honours priority and accepted_args. */
$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();
function add_filter( $hook, $cb, $p = 10, $a = 1 ) {
	$GLOBALS['__filters'][ $hook ][] = array( 'cb' => $cb, 'p' => $p, 'a' => $a );
}
function add_action( $hook, $cb, $p = 10, $a = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = $cb;
}
function has_filter( $hook, $cb = false ) {
	if ( empty( $GLOBALS['__filters'][ $hook ] ) ) { return false; }
	if ( false === $cb ) { return true; }
	foreach ( $GLOBALS['__filters'][ $hook ] as $entry ) {
		if ( $entry['cb'] === $cb ) { return $entry['p']; }
	}
	return false;
}
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['__filters'][ $hook ] ) ) { return $value; }
	$entries = $GLOBALS['__filters'][ $hook ];
	usort( $entries, function ( $x, $y ) { return $x['p'] <=> $y['p']; } );
	foreach ( $entries as $entry ) {
		$value = call_user_func_array( $entry['cb'], array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry['a'] - 1 ) ) ) );
	}
	return $value;
}
function fire( $hook ) {
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) { $cb(); }
}
function wp_json_encode( $data ) { return json_encode( $data ); }

// The loader's first OpenStation module (signal-and-noise-tools.php); every
// openstation-*.php may call into it, so the harness loads it the same way.
require_once __DIR__ . '/../inc/openstation-compat.php';
require_once __DIR__ . '/../inc/openstation-agent-output-budget.php';

echo "openstation-agent-output-budget: the agent budget on openstation_ai_model_config (#1613)\n\n";

$runner = array( 'source' => 'agents/runner', 'user_id' => 7, 'request_id' => 'r1', 'has_tools' => true, 'has_schema' => true );

/* 1. Registration. The seam is Experimental; the guard reads on
 *    plugins_loaded for openstation_ai_apply_model_config(), the function
 *    in includes/ai-copilot/client.php that applies the filter. First fire
 *    with the function absent: the guard must FAIL. */

ok( ! function_exists( 'snt_agent_budget_shape' ), 'the http_request_args body rewrite is gone (snt_agent_budget_shape is not defined)' );
ok( ! function_exists( 'snt_agent_budget_arm' ), 'the runner pre-filter arm is gone (snt_agent_budget_arm is not defined)' );
ok( false === has_filter( 'http_request_args' ), 'nothing of ours sits on http_request_args' );

fire( 'plugins_loaded' );
ok( false === has_filter( 'openstation_ai_model_config' ), 'without OpenStation the seam is not hooked: the guard can fail' );

// Declared conditionally so it is not hoisted above the first fire.
if ( ! function_exists( 'openstation_ai_apply_model_config' ) ) {
	function openstation_ai_apply_model_config( $builder, array $context ) { return $builder; }
}
fire( 'plugins_loaded' );
ok( 10 === has_filter( 'openstation_ai_model_config', 'snt_agent_model_config' ), 'with the client present the seam is hooked at priority 10 after plugins_loaded' );
ok( 2 === $GLOBALS['__filters']['openstation_ai_model_config'][0]['a'], 'registered with accepted_args 2: the source lives in $context' );
ok( false === has_filter( 'http_request_args' ), 'and still nothing on http_request_args once plugins_loaded has fired with the client present (a re-arm inside the closure would land here)' );

/* 2. The shaping on the agents runner. */

$out = apply_filters( 'openstation_ai_model_config', array(), $runner );
ok( 8192 === ( $out['max_tokens'] ?? null ), 'agents/runner: max_tokens 8192 (headroom for the answer plus markup-bearing tool calls)' );
ok( 'adaptive' === ( $out['custom_options']['thinking']['type'] ?? null ), 'agents/runner: custom_options.thinking.type adaptive (the Claude 5 shape)' );
ok( 'low' === ( $out['custom_options']['output_config']['effort'] ?? null ), 'agents/runner: custom_options.output_config.effort low (the live-verified default)' );
ok( array( 'thinking', 'output_config' ) === array_keys( (array) ( $out['custom_options'] ?? array() ) ), 'exactly the two provider-native keys travel, nothing the connector could refuse' );
ok( ! isset( $out['model'] ), 'no model pin while SN_AI_DEFAULT_MODEL is undefined (the client picks)' );
ok( ! isset( $out['temperature'] ), 'temperature is left to the client' );

/* 3. Every other source comes back untouched. */

foreach ( array( 'ai-copilot/search', 'ai-copilot/followup', 'ai-copilot/comment-analysis', 'widgets/drafts-suggestions', 'mio/window', '' ) as $source ) {
	$out = apply_filters( 'openstation_ai_model_config', array(), array( 'source' => $source ) );
	ok( array() === $out, "source '$source': the config comes back empty" );
}
$foreign = array( 'temperature' => 0.2, 'custom_options' => array( 'metadata' => array( 'x' => 1 ) ) );
ok( $foreign === apply_filters( 'openstation_ai_model_config', $foreign, array( 'source' => 'ai-copilot/search' ) ), "another plugin's config on another source is byte-identical" );
ok( array() === apply_filters( 'openstation_ai_model_config', array(), array() ), 'a context with no source is not the runner' );
ok( function_exists( 'snt_agent_model_config' ) && array() === snt_agent_model_config( 'nope', array( 'source' => 'ai-copilot/search' ) ), 'a non-array config is normalised to an empty array, never echoed' );
ok( function_exists( 'snt_agent_model_config' ) && 8192 === ( snt_agent_model_config( null, $runner )['max_tokens'] ?? null ), 'a null config on the runner is shaped from empty' );

/* 3b. Deference. The seam starts empty, but WP convention is that priority
 *     10 builds on priority 5, and OpenStation already stores a per-agent
 *     model override the runner could one day feed in. */

$prior = array(
	'model'          => 'claude-opus-5',
	'max_tokens'     => 16384,
	'custom_options' => array(
		'metadata'      => array( 'user_id' => 'u7' ),
		'thinking'      => array( 'type' => 'adaptive' ),
		'output_config' => array( 'effort' => 'high' ),
	),
);
add_filter( 'openstation_ai_model_config', function () use ( $prior ) { return $prior; }, 5, 2 );
ok( function_exists( 'snt_agent_model_config' ) && $prior === apply_filters( 'openstation_ai_model_config', array(), $runner ), 'a priority-5 config already carrying thinking and output_config (metadata, 16384, effort high, a model) comes back byte-identical: the pin defers' );
$GLOBALS['__filters']['openstation_ai_model_config'] = array_values( array_filter( $GLOBALS['__filters']['openstation_ai_model_config'], function ( $e ) { return 5 !== $e['p']; } ) );

$partial = array( 'model' => 'claude-opus-5', 'max_tokens' => 16384, 'custom_options' => array( 'metadata' => array( 'user_id' => 'u7' ) ) );
$out     = function_exists( 'snt_agent_model_config' ) ? snt_agent_model_config( $partial, $runner ) : array();
ok( 16384 === ( $out['max_tokens'] ?? null ), 'a ceiling an earlier callback raised above 8192 stays: the pin only ever raises' );
ok( array( 'user_id' => 'u7' ) === ( $out['custom_options']['metadata'] ?? null ), 'a sibling custom_options key (metadata) survives: the pin adds, never replaces' );
ok( 'low' === ( $out['custom_options']['output_config']['effort'] ?? null ) && 'adaptive' === ( $out['custom_options']['thinking']['type'] ?? null ), 'and the two thinking keys still land beside it' );
ok( 'claude-opus-5' === ( $out['model'] ?? null ), 'a model an earlier callback chose stays (SN_AI_DEFAULT_MODEL is still undefined here; see 4)' );

/* 4. The model pin. SN_AI_DEFAULT_MODEL loads later than this module in
 *    production (inc/ai-bootstrap.php), so the read is at filter time. */

define( 'SN_AI_DEFAULT_MODEL', 'claude-sonnet-5' );
$out = apply_filters( 'openstation_ai_model_config', array(), $runner );
ok( 'claude-sonnet-5' === ( $out['model'] ?? null ), 'with SN_AI_DEFAULT_MODEL defined the runner turn prefers it: the thinking keys are a Claude 5 shape and the seam carries no resolved model to gate on' );
ok( function_exists( 'snt_agent_model_config' ) && 'claude-opus-5' === ( snt_agent_model_config( array( 'model' => 'claude-opus-5' ), $runner )['model'] ?? null ), 'and with the constant defined a model an earlier callback chose still wins: the constant fills a gap, never overrides' );

/* 5. Filters: effort level, effort disable, the ceiling. */

add_filter( 'snt_agent_anthropic_effort', function () { return 'medium'; } );
$out = apply_filters( 'openstation_ai_model_config', array(), $runner );
ok( 'medium' === ( $out['custom_options']['output_config']['effort'] ?? null ), 'snt_agent_anthropic_effort filter honoured' );
$GLOBALS['__filters']['snt_agent_anthropic_effort'] = array();

add_filter( 'snt_agent_anthropic_effort', function () { return 'turbo'; } );
$out = apply_filters( 'openstation_ai_model_config', array(), $runner );
ok( array() === $out, 'a value outside low/medium/high disables the shaping entirely: no keys, no ceiling raise, no model pin (unbounded thinking would eat the headroom)' );
$GLOBALS['__filters']['snt_agent_anthropic_effort'] = array();

add_filter( 'snt_agent_anthropic_max_tokens', function () { return 16384; } );
$out = apply_filters( 'openstation_ai_model_config', array(), $runner );
ok( 16384 === ( $out['max_tokens'] ?? null ), 'snt_agent_anthropic_max_tokens filter honoured' );
$GLOBALS['__filters']['snt_agent_anthropic_max_tokens'] = array();

add_filter( 'snt_agent_anthropic_max_tokens', function () { return 1024; } );
$out = apply_filters( 'openstation_ai_model_config', array(), $runner );
ok( 4096 === ( $out['max_tokens'] ?? null ), "a filtered ceiling at or below the client's 4096 pin leaves the pin: this seam only ever raises" );
ok( 'low' === ( $out['custom_options']['output_config']['effort'] ?? null ), 'and the effort still lands, so the request is still shaped' );
$GLOBALS['__filters']['snt_agent_anthropic_max_tokens'] = array();

/* 6. The module never reads the wire. */

$src = (string) file_get_contents( __DIR__ . '/../inc/openstation-agent-output-budget.php' );
ok( false === strpos( $src, "'http_request_args'" ), "no 'http_request_args' hook literal anywhere in the module (the history paragraph names it in backticks)" );
ok( false === strpos( $src, 'json_decode' ) && false === strpos( $src, 'wp_json_encode' ), 'no JSON decode or re-encode: the client builds the body' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

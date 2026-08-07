<?php
/**
 * Standalone fixture tests for inc/openstation-agent-output-budget.php —
 * the WordPress/openstation#517 seam, corrected: raising max_tokens alone
 * was FALSIFIED live (thinking is ceiling-bounded and consumes any raise);
 * the working configuration injects Claude 5's adaptive thinking + an
 * effort level (demand-bounded thinking) and gives the pinned ceiling
 * text headroom. Armed only during agent runs, Claude-5-only, deferential
 * to any existing config, byte-identical pass-through otherwise.
 *
 * Run: php tests/openstation-agent-output-budget.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── WP stubs ─────────────────────────────────────────────────────────
// apply_filters honours priority ([[test-stub-drift-invents-shapes]] /
// the v9.53.2 harness lesson: a stub replaying registration order cannot
// express "runs last").
$GLOBALS['__filters'] = array();
function add_filter( $hook, $cb, $p = 10, $a = 1 ) {
	$GLOBALS['__filters'][ $hook ][] = array( 'cb' => $cb, 'p' => $p, 'a' => $a );
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
function wp_json_encode( $data ) { return json_encode( $data ); }

require_once __DIR__ . '/../inc/openstation-compat.php';
require_once __DIR__ . '/../inc/openstation-agent-output-budget.php';

echo "openstation-agent-output-budget — #517 seam (adaptive+effort)\n\n";

$anthropic = 'https://api.anthropic.com/v1/messages';
function snt_test_body( $over = array() ) {
	return json_encode( array_merge( array(
		'model'      => 'claude-sonnet-5',
		'max_tokens' => 4096,
		'messages'   => array( array( 'role' => 'user', 'content' => 'hi' ) ),
	), $over ) );
}

/* ════════════════════════════════════════════════════════════════════════
 * 1. Registration — the arm callback rides the runner pre-filter seam
 *    under BOTH hook families; the shaper exists only after an agent run
 *    arms it (the Copilot and SN's own AI calls never see it).
 * ════════════════════════════════════════════════════════════════════════ */

ok( false !== has_filter( 'desktop_mode_agent_runner_generate', 'snt_agent_budget_arm' ), 'arm registered on desktop_mode_agent_runner_generate' );
ok( false !== has_filter( 'openstation_agent_runner_generate', 'snt_agent_budget_arm' ), 'arm registered on openstation_agent_runner_generate (rename-ready)' );
ok( false === has_filter( 'http_request_args', 'snt_agent_budget_shape' ), 'shaper NOT hooked before any agent run' );

/* ════════════════════════════════════════════════════════════════════════
 * 2. Arming — pass-through contract + idempotence.
 * ════════════════════════════════════════════════════════════════════════ */

ok( null === snt_agent_budget_arm( null ), 'arm returns null unchanged (runner proceeds to the AI Client)' );
$sentinel = array( 'text' => 'short-circuit', 'function_calls' => array() );
ok( $sentinel === snt_agent_budget_arm( $sentinel ), 'arm returns a non-null pre-filter result byte-identical' );
ok( PHP_INT_MAX === has_filter( 'http_request_args', 'snt_agent_budget_shape' ), 'arming hooks the shaper at PHP_INT_MAX (runs last, sees the final body)' );

snt_agent_budget_arm( null );
$count = 0;
foreach ( $GLOBALS['__filters']['http_request_args'] as $entry ) {
	if ( 'snt_agent_budget_shape' === $entry['cb'] ) { $count++; }
}
ok( 1 === $count, 'arming twice does not double-hook the shaper' );

/* ════════════════════════════════════════════════════════════════════════
 * 3. Model-family gate.
 * ════════════════════════════════════════════════════════════════════════ */

ok( snt_agent_budget_model_is_claude5( 'claude-sonnet-5' ), 'claude-sonnet-5 is Claude 5 family' );
ok( snt_agent_budget_model_is_claude5( 'claude-fable-5' ), 'claude-fable-5 is Claude 5 family' );
ok( snt_agent_budget_model_is_claude5( 'claude-opus-5.1' ), 'a point release stays in family' );
ok( ! snt_agent_budget_model_is_claude5( 'claude-haiku-4-5-20251001' ), 'claude-haiku-4-5 is NOT (4.5 family, enabled-shape thinking API)' );
ok( ! snt_agent_budget_model_is_claude5( 'claude-opus-4-1' ), 'claude-opus-4-1 is NOT' );
ok( ! snt_agent_budget_model_is_claude5( 'gpt-5' ), 'non-Claude model is NOT' );

/* ════════════════════════════════════════════════════════════════════════
 * 4. Byte-identical pass-throughs — every non-matching shape.
 * ════════════════════════════════════════════════════════════════════════ */

$args = array( 'body' => snt_test_body(), 'timeout' => 30 );
ok( $args === snt_agent_budget_shape( $args, 'https://api.openai.com/v1/chat/completions' ), 'non-Anthropic URL untouched' );
ok( $args === snt_agent_budget_shape( $args, 'https://api.anthropic.com/v1/models' ), 'Anthropic non-messages endpoint untouched' );

$a = array( 'body' => snt_test_body( array( 'model' => 'claude-haiku-4-5-20251001' ) ) );
ok( $a === snt_agent_budget_shape( $a, $anthropic ), 'non-Claude-5 model untouched — the effort keys would 400 on the older API shape' );

$a = array( 'body' => '{not json' );
ok( $a === snt_agent_budget_shape( $a, $anthropic ), 'malformed JSON body passes through untouched' );

$a = array( 'timeout' => 30 );
ok( $a === snt_agent_budget_shape( $a, $anthropic ), 'args with no body key pass through untouched' );

$a = array( 'body' => array( 'model' => 'claude-sonnet-5' ) );
ok( $a === snt_agent_budget_shape( $a, $anthropic ), 'a non-string (already-array) body passes through untouched' );

$a = array( 'body' => snt_test_body( array( 'thinking' => array( 'type' => 'adaptive' ), 'output_config' => array( 'effort' => 'high' ), 'max_tokens' => 8192 ) ) );
ok( $a === snt_agent_budget_shape( $a, $anthropic ), 'a request that already carries thinking + output_config + a non-pinned ceiling is FULLY untouched — the seam self-neutralizes when upstream ships its own config' );

/* ════════════════════════════════════════════════════════════════════════
 * 5. The shaping — injection + headroom on the matching request.
 * ════════════════════════════════════════════════════════════════════════ */

$args   = array( 'body' => snt_test_body(), 'timeout' => 30 );
$shaped = snt_agent_budget_shape( $args, $anthropic );
$body   = json_decode( $shaped['body'], true );
ok( array( 'type' => 'adaptive' ) === ( $body['thinking'] ?? null ), 'thinking: adaptive injected' );
ok( array( 'effort' => 'low' ) === ( $body['output_config'] ?? null ), 'output_config: effort low injected (the live-verified default)' );
ok( 8192 === ( $body['max_tokens'] ?? null ), 'pinned 4096 raised to 8192 (headroom for answer + markup-bearing tool calls)' );
ok( 30 === $shaped['timeout'], 'sibling args keys preserved' );
$before = json_decode( $args['body'], true );
unset( $before['max_tokens'], $body['max_tokens'], $body['thinking'], $body['output_config'] );
ok( $before === $body, 'every other body field byte-equal after the shaping' );

/* ════════════════════════════════════════════════════════════════════════
 * 6. Deference — partial existing config suppresses injection but not the
 *    headroom, and vice versa.
 * ════════════════════════════════════════════════════════════════════════ */

$a      = array( 'body' => snt_test_body( array( 'thinking' => array( 'type' => 'adaptive' ) ) ) );
$shaped = snt_agent_budget_shape( $a, $anthropic );
$body   = json_decode( $shaped['body'], true );
ok( ! isset( $body['output_config'] ), 'existing thinking config suppresses the effort injection entirely' );
ok( 8192 === ( $body['max_tokens'] ?? null ), '…but the pinned ceiling still gets its headroom' );

$a      = array( 'body' => snt_test_body( array( 'max_tokens' => 6144 ) ) );
$shaped = snt_agent_budget_shape( $a, $anthropic );
$body   = json_decode( $shaped['body'], true );
ok( 6144 === ( $body['max_tokens'] ?? null ), 'a non-pinned ceiling (6144) is never rewritten — someone already decided' );
ok( array( 'effort' => 'low' ) === ( $body['output_config'] ?? null ), '…but the effort injection still applies' );

/* ════════════════════════════════════════════════════════════════════════
 * 7. Filters — effort level, effort disable, ceiling raise-only.
 * ════════════════════════════════════════════════════════════════════════ */

add_filter( 'snt_agent_anthropic_effort', function () { return 'medium'; } );
$body = json_decode( snt_agent_budget_shape( array( 'body' => snt_test_body() ), $anthropic )['body'], true );
ok( array( 'effort' => 'medium' ) === ( $body['output_config'] ?? null ), 'snt_agent_anthropic_effort filter honoured' );

$GLOBALS['__filters']['snt_agent_anthropic_effort'] = array();
add_filter( 'snt_agent_anthropic_effort', function () { return 'turbo'; } );
$body = json_decode( snt_agent_budget_shape( array( 'body' => snt_test_body() ), $anthropic )['body'], true );
ok( ! isset( $body['thinking'] ) && ! isset( $body['output_config'] ), 'a non-whitelisted effort value disables the injection' );
ok( 8192 === ( $body['max_tokens'] ?? null ), '…while the ceiling headroom still applies' );
$GLOBALS['__filters']['snt_agent_anthropic_effort'] = array();

add_filter( 'snt_agent_anthropic_max_tokens', function () { return 16384; } );
$body = json_decode( snt_agent_budget_shape( array( 'body' => snt_test_body() ), $anthropic )['body'], true );
ok( 16384 === ( $body['max_tokens'] ?? null ), 'snt_agent_anthropic_max_tokens filter honoured' );
$GLOBALS['__filters']['snt_agent_anthropic_max_tokens'] = array();

add_filter( 'snt_agent_anthropic_max_tokens', function () { return 1024; } );
$body = json_decode( snt_agent_budget_shape( array( 'body' => snt_test_body() ), $anthropic )['body'], true );
ok( 4096 === ( $body['max_tokens'] ?? null ), 'a filtered ceiling at or below the pin leaves the ceiling untouched — this seam only ever raises' );
ok( array( 'effort' => 'low' ) === ( $body['output_config'] ?? null ), '…and the injection still lands, so the request is still shaped' );
$GLOBALS['__filters']['snt_agent_anthropic_max_tokens'] = array();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

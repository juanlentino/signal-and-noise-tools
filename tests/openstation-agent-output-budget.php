<?php
/**
 * Standalone fixture tests for inc/openstation-agent-output-budget.php —
 * the WordPress/openstation#517 workaround: the Core AI Client pins
 * max_tokens 4096 on Anthropic /v1/messages, agent runs spend the whole
 * budget inside a thinking block on hard tasks, and the run "succeeds"
 * with an empty answer. The workaround raises the cap ONLY during agent
 * runs, ONLY for Anthropic /v1/messages, ONLY when the request still
 * carries the pinned 4096 default.
 *
 * Run: php tests/openstation-agent-output-budget.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── WP stubs ─────────────────────────────────────────────────────────
// add_filter records (hook, cb, priority); apply_filters dispatches in
// priority order, registration order within a priority — the stub MUST
// honour priority ([[test-stub-drift-invents-shapes]] / the v9.53.2
// harness lesson: a stub that replays registration order cannot express
// "runs last").
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

echo "openstation-agent-output-budget — #517 workaround\n\n";

/* ════════════════════════════════════════════════════════════════════════
 * 1. Registration — the arm callback rides the runner pre-filter seam
 *    under BOTH hook families, and http_request_args is NOT hooked yet
 *    (the raiser must exist only during an agent run, never for the
 *    Copilot or SN's own AI calls).
 * ════════════════════════════════════════════════════════════════════════ */

ok( false !== has_filter( 'desktop_mode_agent_runner_generate', 'snt_agent_budget_arm' ), 'arm registered on desktop_mode_agent_runner_generate' );
ok( false !== has_filter( 'openstation_agent_runner_generate', 'snt_agent_budget_arm' ), 'arm registered on openstation_agent_runner_generate (rename-ready)' );
ok( false === has_filter( 'http_request_args', 'snt_agent_budget_raise' ), 'raiser NOT hooked before any agent run' );

/* ════════════════════════════════════════════════════════════════════════
 * 2. Arming — pass-through contract + idempotence.
 * ════════════════════════════════════════════════════════════════════════ */

ok( null === snt_agent_budget_arm( null ), 'arm returns null unchanged (runner proceeds to the AI Client)' );
$sentinel = array( 'text' => 'short-circuit', 'function_calls' => array() );
ok( $sentinel === snt_agent_budget_arm( $sentinel ), 'arm returns a non-null pre-filter result byte-identical' );

$hooked = has_filter( 'http_request_args', 'snt_agent_budget_raise' );
ok( PHP_INT_MAX === $hooked, 'arming hooks the raiser on http_request_args at PHP_INT_MAX (runs last, sees the final body)' );

snt_agent_budget_arm( null );
$count = 0;
foreach ( $GLOBALS['__filters']['http_request_args'] as $entry ) {
	if ( 'snt_agent_budget_raise' === $entry['cb'] ) { $count++; }
}
ok( 1 === $count, 'arming twice does not double-hook the raiser' );

/* ════════════════════════════════════════════════════════════════════════
 * 3. The raiser — scope gates. Every non-matching request must come back
 *    BYTE-IDENTICAL (===), not merely equivalent: re-encoding an
 *    untouched body would still perturb the transport.
 * ════════════════════════════════════════════════════════════════════════ */

$anthropic = 'https://api.anthropic.com/v1/messages';
function snt_test_body( $over = array() ) {
	return json_encode( array_merge( array(
		'model'      => 'claude-sonnet-5',
		'max_tokens' => 4096,
		'messages'   => array( array( 'role' => 'user', 'content' => 'hi' ) ),
	), $over ) );
}

$args = array( 'body' => snt_test_body(), 'timeout' => 30 );
ok( $args === snt_agent_budget_raise( $args, 'https://api.openai.com/v1/chat/completions' ), 'non-Anthropic URL untouched' );
ok( $args === snt_agent_budget_raise( $args, 'https://api.anthropic.com/v1/models' ), 'Anthropic non-messages endpoint untouched' );

$a = array( 'body' => snt_test_body( array( 'max_tokens' => 8192 ) ) );
ok( $a === snt_agent_budget_raise( $a, $anthropic ), 'a non-default max_tokens (8192) is untouched — the workaround self-neutralizes when upstream changes the default' );

$b = json_decode( snt_test_body(), true );
unset( $b['max_tokens'] );
$a = array( 'body' => json_encode( $b ) );
ok( $a === snt_agent_budget_raise( $a, $anthropic ), 'a body with no max_tokens is untouched' );

$a = array( 'body' => json_encode( array( 'max_tokens' => '4096', 'model' => 'claude-sonnet-5' ) ) );
ok( $a === snt_agent_budget_raise( $a, $anthropic ), 'a string "4096" is untouched — the pinned default is an int; only the exact pinned shape is rewritten' );

$a = array( 'body' => '{not json' );
ok( $a === snt_agent_budget_raise( $a, $anthropic ), 'malformed JSON body passes through untouched, no error' );

$a = array( 'timeout' => 30 );
ok( $a === snt_agent_budget_raise( $a, $anthropic ), 'args with no body key pass through untouched' );

$a = array( 'body' => array( 'max_tokens' => 4096 ) );
ok( $a === snt_agent_budget_raise( $a, $anthropic ), 'a non-string (already-array) body passes through untouched — the transport contract is a JSON string' );

/* ════════════════════════════════════════════════════════════════════════
 * 4. The raise — the matching request, and only max_tokens changes.
 * ════════════════════════════════════════════════════════════════════════ */

$args   = array( 'body' => snt_test_body(), 'timeout' => 30 );
$raised = snt_agent_budget_raise( $args, $anthropic );
$body   = json_decode( $raised['body'], true );
ok( 16384 === $body['max_tokens'], 'pinned 4096 raised to 16384' );
ok( 30 === $raised['timeout'], 'sibling args keys preserved' );

$before = json_decode( $args['body'], true );
unset( $before['max_tokens'], $body['max_tokens'] );
ok( $before === $body, 'every other body field byte-equal after the raise' );

/* ════════════════════════════════════════════════════════════════════════
 * 5. The value is filterable, and can never LOWER the cap.
 * ════════════════════════════════════════════════════════════════════════ */

add_filter( 'snt_agent_anthropic_max_tokens', function () { return 32768; } );
$raised = snt_agent_budget_raise( array( 'body' => snt_test_body() ), $anthropic );
ok( 32768 === json_decode( $raised['body'], true )['max_tokens'], 'snt_agent_anthropic_max_tokens filter honoured' );

$GLOBALS['__filters']['snt_agent_anthropic_max_tokens'] = array();
add_filter( 'snt_agent_anthropic_max_tokens', function () { return 1024; } );
$args = array( 'body' => snt_test_body() );
ok( $args === snt_agent_budget_raise( $args, $anthropic ), 'a filtered value at or below the pinned 4096 leaves the request untouched — this seam only ever raises' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

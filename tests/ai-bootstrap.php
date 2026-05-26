<?php
/**
 * Standalone fixture tests for inc/ai-bootstrap.php.
 *
 * Locks in the v3.7.1 method_exists() guard removal and the v3.7.2
 * Sonnet model pinning so future regressions surface as test failures.
 *
 * Key dispatch insight: wp-ai-client's Prompt_Builder routes snake_case
 * methods (using_model_preference, using_system_instruction, etc.) via
 * PHP's __call magic. method_exists() returns false for __call-routed
 * methods; is_callable() returns true. v3.7.1 fixed an erroneous
 * method_exists guard that always returned false, unconditionally
 * disabling every SN AI feature since v2.5.0.
 *
 * Mock builder below mirrors that dispatch pattern (__call) so the
 * tests reproduce the real bug surface.
 *
 * @since plugin v3.7.3
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }

// Filter map stub: tests register callbacks for specific filter tags
// via add_filter; apply_filters dispatches through them. Mirrors WP's
// behavior closely enough for the snt_ai_model_preference override test.
$GLOBALS['__test_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_filters'][ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args ); // drop $tag
		if ( ! isset( $GLOBALS['__test_filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__test_filters'][ $tag ] as $cb ) {
			$value   = call_user_func_array( $cb, $args );
			$args[0] = $value; // chain through subsequent filters
		}
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $c = '', $m = '', $data = array() ) {
		$this->code    = $c;
		$this->message = $m;
		$this->data    = $data;
	}
	public function get_error_code()    { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data()    { return $this->data; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}

// ─── AI client mock ──────────────────────────────────────────────────
//
// Control flags + recorded state. Tests reset these between blocks so
// each scenario starts from a known fixture.
//
// __test_ai_builder_throws_on_construct — make TestAiBuilder::__construct() throw
// __test_ai_builder_throws_on_method    — method name (string) that throws when called via __call
// __test_ai_builder_supports_text       — bool returned by is_supported_for_text_generation()
// __test_ai_builder_generate_returns    — string|WP_Error returned by generate_text()
// __test_ai_builder_returns_non_object  — when true, wp_ai_client_prompt() returns null instead of a builder
// __test_ai_builder_recorded_calls      — array of [name => method, args => array] recorded by __call
$GLOBALS['__test_ai_builder_throws_on_construct'] = false;
$GLOBALS['__test_ai_builder_throws_on_method']   = null;
$GLOBALS['__test_ai_builder_supports_text']      = true;
$GLOBALS['__test_ai_builder_generate_returns']   = 'mock response';
$GLOBALS['__test_ai_builder_returns_non_object'] = false;
$GLOBALS['__test_ai_builder_recorded_calls']     = array();

/**
 * Mock builder that mirrors wp-ai-client's Prompt_Builder dispatch.
 *
 * Critical property: snake_case API methods (using_model_preference,
 * using_system_instruction, using_max_tokens, generate_text) are routed
 * via __call — NOT declared as real methods. This means:
 *   method_exists($builder, 'using_model_preference') === false
 *   is_callable( array($builder, 'using_model_preference') ) === true
 * Same asymmetry as the real wp-ai-client.
 *
 * is_supported_for_text_generation is also routed via __call (returns bool).
 */
class TestAiBuilder {
	public function __construct() {
		if ( ! empty( $GLOBALS['__test_ai_builder_throws_on_construct'] ) ) {
			throw new \RuntimeException( 'fixture: construct throws' );
		}
	}

	public function __call( $name, $args ) {
		// Optional per-method throw — lets tests assert Throwable handling at
		// any point in the fluent chain (e.g. generate_text fails).
		if ( $name === ( $GLOBALS['__test_ai_builder_throws_on_method'] ?? null ) ) {
			throw new \RuntimeException( 'fixture: ' . $name . ' throws' );
		}

		$GLOBALS['__test_ai_builder_recorded_calls'][] = array(
			'name' => $name,
			'args' => $args,
		);

		if ( 'is_supported_for_text_generation' === $name ) {
			return (bool) ( $GLOBALS['__test_ai_builder_supports_text'] ?? false );
		}
		if ( 'generate_text' === $name ) {
			return $GLOBALS['__test_ai_builder_generate_returns'] ?? '';
		}
		// Fluent chain — return self so using_*() calls compose.
		return $this;
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * Test stub for wp_ai_client_prompt(). Returns either a fresh
	 * TestAiBuilder or null depending on the non_object flag.
	 *
	 * NOTE on simulating "wp_ai_client_prompt does not exist":
	 * PHP cannot undefine a function at runtime, so we cannot
	 * directly exercise the early-return at ai-bootstrap.php:73
	 * (function_exists check). The closest equivalent failure mode
	 * is "builder construction throws", which the catch block in
	 * snt_ai_can_text_generate handles identically. Test 1 covers
	 * that path; Test 2 covers the parallel non-object branch.
	 */
	function wp_ai_client_prompt( $prompt = null ) {
		if ( ! empty( $GLOBALS['__test_ai_builder_returns_non_object'] ) ) {
			return null;
		}
		return new TestAiBuilder();
	}
}

require_once __DIR__ . '/../inc/ai-bootstrap.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function hc_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n";
	}
}
function hc_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

/**
 * Reset all fixture state to a known baseline. Each test block calls
 * this first to avoid leakage between scenarios.
 */
function fixture_reset() {
	$GLOBALS['__test_ai_builder_throws_on_construct'] = false;
	$GLOBALS['__test_ai_builder_throws_on_method']   = null;
	$GLOBALS['__test_ai_builder_supports_text']      = true;
	$GLOBALS['__test_ai_builder_generate_returns']   = 'mock response';
	$GLOBALS['__test_ai_builder_returns_non_object'] = false;
	$GLOBALS['__test_ai_builder_recorded_calls']     = array();
	$GLOBALS['__test_filters']                       = array();
}

/**
 * Helper: did the recorded-calls array include a call to $method with
 * exactly $args (positional)? Used to assert the builder chain
 * recorded the right model preference / max-tokens value.
 */
function fixture_recorded_call_matches( $method, array $expected_args ) {
	foreach ( $GLOBALS['__test_ai_builder_recorded_calls'] as $call ) {
		if ( $call['name'] === $method && $call['args'] === $expected_args ) {
			return true;
		}
	}
	return false;
}

/**
 * Helper: what was the index of the first recorded call to $method?
 * Returns -1 if never called. Used to assert ordering — e.g.
 * using_model_preference must precede generate_text.
 */
function fixture_first_call_index( $method ) {
	foreach ( $GLOBALS['__test_ai_builder_recorded_calls'] as $i => $call ) {
		if ( $call['name'] === $method ) {
			return $i;
		}
	}
	return -1;
}

// ─── Sanity: mock has the right shape ────────────────────────────────
//
// Documents the v3.7.1 bug's root cause directly in the test: the same
// asymmetry between method_exists() and is_callable() that the production
// fix had to account for. If a future maintainer "fixes" the mock to
// declare real methods, these assertions will start mismatching the
// production wp-ai-client and the tests will lose their value as a
// regression guard — so we lock the invariant down here.
echo "\nSanity: mock builder mirrors wp-ai-client __call dispatch\n";
$probe = new TestAiBuilder();
hc_eq( false, method_exists( $probe, 'using_model_preference' ),
	'method_exists() returns false for __call-routed method (matches real wp-ai-client behavior — the v3.7.1 trap)' );
hc_eq( true, is_callable( array( $probe, 'using_model_preference' ) ),
	'is_callable() returns true for __call-routed method (the check that should have been used in production)' );
hc_eq( false, method_exists( $probe, 'is_supported_for_text_generation' ),
	'method_exists() returns false for is_supported_for_text_generation (this is exactly what the v3.7.1 bug hinged on)' );
hc_eq( true, is_callable( array( $probe, 'is_supported_for_text_generation' ) ),
	'is_callable() returns true for is_supported_for_text_generation' );

// ─── Test 1: wp_ai_client_prompt unavailable ─────────────────────────
//
// PHP cannot undefine a function at runtime, so we simulate "client
// unavailable" the only way that's safe in a single test process: by
// forcing the construct path to fail before the support check runs.
// In production, function_exists('wp_ai_client_prompt') === false
// hits the early return at ai-bootstrap.php:73. Here we cover the
// equivalent failure mode (any failure prior to support check returns
// false) via construct-throws — which exercises the same outward
// contract: "if you can't talk to the AI client, return false."
echo "\nTest 1: snt_ai_can_text_generate — client construction fails\n";
fixture_reset();
$GLOBALS['__test_ai_builder_throws_on_construct'] = true;
hc_eq( false, snt_ai_can_text_generate(),
	'returns false when wp_ai_client_prompt builder construction throws (covers function-missing path)' );
hc_eq( false, snt_ai_is_available(),
	'alias returns identical false' );

// ─── Test 2: builder returned is not an object ───────────────────────
echo "\nTest 2: snt_ai_can_text_generate — non-object return\n";
fixture_reset();
$GLOBALS['__test_ai_builder_returns_non_object'] = true;
hc_eq( false, snt_ai_can_text_generate(),
	'returns false when wp_ai_client_prompt returns null (not an object)' );
hc_eq( false, snt_ai_is_available(),
	'alias mirrors: non-object → false' );

// ─── Test 3: is_supported_for_text_generation returns true (v3.7.1 lock-in) ─
//
// THIS IS THE TEST THAT WOULD HAVE CAUGHT v3.7.1. With the buggy
// method_exists() guard in place, this scenario returned false even
// though the builder was perfectly capable. The fix removed the guard;
// now the function actually invokes the method via __call dispatch
// and gets the correct bool back.
echo "\nTest 3: snt_ai_can_text_generate — supported=true (v3.7.1 regression guard)\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text'] = true;
hc_eq( true, snt_ai_can_text_generate(),
	'returns true when builder supports text generation (the v3.7.1 lock-in)' );
hc_eq( true, snt_ai_is_available(),
	'alias returns identical true' );
// Confirm we actually invoked the method (not just shortcut-returned).
hc_true( fixture_recorded_call_matches( 'is_supported_for_text_generation', array() ),
	'is_supported_for_text_generation was actually called via __call dispatch' );

// ─── Test 4: is_supported_for_text_generation returns false ──────────
echo "\nTest 4: snt_ai_can_text_generate — supported=false\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text'] = false;
hc_eq( false, snt_ai_can_text_generate(),
	'returns false when builder explicitly reports no text support' );
hc_eq( false, snt_ai_is_available(),
	'alias returns identical false' );

// ─── Test 5: builder constructor throws Exception ────────────────────
echo "\nTest 5: snt_ai_can_text_generate — constructor throws Exception\n";
fixture_reset();
$GLOBALS['__test_ai_builder_throws_on_construct'] = true;
hc_eq( false, snt_ai_can_text_generate(),
	'constructor throwing → caught, returns false (no fatal)' );

// ─── Test 6: is_supported_for_text_generation throws Throwable ───────
echo "\nTest 6: snt_ai_can_text_generate — support check throws\n";
fixture_reset();
$GLOBALS['__test_ai_builder_throws_on_method'] = 'is_supported_for_text_generation';
hc_eq( false, snt_ai_can_text_generate(),
	'support check throwing Throwable → caught, returns false' );

// ─── Test 7: gate returns false → WP_Error 503 ───────────────────────
echo "\nTest 7: snt_ai_generate_with_constraints — gate returns false\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text'] = false; // forces gate false
$result = snt_ai_generate_with_constraints( 'p', 's' );
hc_true( is_wp_error( $result ), 'returns WP_Error when gate false' );
hc_eq( 'snt_ai_unavailable', $result->get_error_code(),
	'error code is snt_ai_unavailable' );
$data = $result->get_error_data();
hc_eq( 503, isset( $data['status'] ) ? $data['status'] : null,
	'error status is 503' );

// ─── Test 8: happy path — builder chain returns text ─────────────────
echo "\nTest 8: snt_ai_generate_with_constraints — happy path\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = '   trimmed output   ';
$result = snt_ai_generate_with_constraints( 'my prompt', 'be brief' );
hc_eq( 'trimmed output', $result,
	'returns trimmed string from generate_text' );

// ─── Test 9: builder returns WP_Error → passthrough ──────────────────
echo "\nTest 9: snt_ai_generate_with_constraints — builder returns WP_Error\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$inner_err = new WP_Error( 'provider_oops', 'rate limit', array( 'status' => 429 ) );
$GLOBALS['__test_ai_builder_generate_returns'] = $inner_err;
$result = snt_ai_generate_with_constraints( 'p', 's' );
hc_true( is_wp_error( $result ), 'WP_Error from builder is returned as WP_Error' );
hc_eq( 'provider_oops', $result->get_error_code(),
	'inner WP_Error code is preserved (not re-wrapped)' );

// ─── Test 10: empty string → WP_Error 502 ────────────────────────────
echo "\nTest 10: snt_ai_generate_with_constraints — empty response\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = '   '; // trims to empty
$result = snt_ai_generate_with_constraints( 'p', 's' );
hc_true( is_wp_error( $result ), 'whitespace-only response → WP_Error' );
hc_eq( 'snt_ai_empty_response', $result->get_error_code(),
	'error code is snt_ai_empty_response' );
$data = $result->get_error_data();
hc_eq( 502, isset( $data['status'] ) ? $data['status'] : null,
	'empty response error status is 502' );

// ─── Test 11: builder throws inside chain → WP_Error 500 ─────────────
echo "\nTest 11: snt_ai_generate_with_constraints — builder throws in chain\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']  = true;
$GLOBALS['__test_ai_builder_throws_on_method'] = 'generate_text';
$result = snt_ai_generate_with_constraints( 'p', 's' );
hc_true( is_wp_error( $result ), 'Throwable in chain → WP_Error' );
hc_eq( 'snt_ai_runtime_error', $result->get_error_code(),
	'error code is snt_ai_runtime_error' );
$data = $result->get_error_data();
hc_eq( 500, isset( $data['status'] ) ? $data['status'] : null,
	'runtime error status is 500' );
hc_true( false !== strpos( $result->get_error_message(), 'fixture: generate_text throws' ),
	'error message embeds the underlying Throwable message' );

// ─── Test 12: Sonnet model preference IS applied (v3.7.2 lock-in) ────
//
// CRITICAL — protects against future regression where someone removes
// the model pin. Verifies that:
//   (a) the builder chain recorded a call to using_model_preference
//       with exactly 'claude-sonnet-4-6' as the argument
//   (b) that call happened BEFORE generate_text
// If either invariant breaks (someone removes the pin OR moves it
// after generate_text such that it no-ops), this test fails.
echo "\nTest 12: snt_ai_generate_with_constraints — Sonnet pin applied (v3.7.2 lock-in)\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'ok';
snt_ai_generate_with_constraints( 'p', 's' );
hc_true( fixture_recorded_call_matches( 'using_model_preference', array( 'claude-sonnet-4-6' ) ),
	'builder chain recorded using_model_preference(claude-sonnet-4-6)' );
$pref_idx = fixture_first_call_index( 'using_model_preference' );
$gen_idx  = fixture_first_call_index( 'generate_text' );
hc_true( $pref_idx >= 0, 'using_model_preference was called' );
hc_true( $gen_idx >= 0, 'generate_text was called' );
hc_true( $pref_idx < $gen_idx,
	'using_model_preference precedes generate_text (so the pin actually takes effect)' );

// ─── Test 13: snt_ai_model_preference filter override ────────────────
echo "\nTest 13: snt_ai_generate_with_constraints — model filter override\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'ok';
add_filter( 'snt_ai_model_preference', function () {
	return 'claude-haiku-4-5';
} );
snt_ai_generate_with_constraints( 'p', 's' );
hc_true( fixture_recorded_call_matches( 'using_model_preference', array( 'claude-haiku-4-5' ) ),
	'filter override propagates to builder (using_model_preference(claude-haiku-4-5))' );
hc_eq( false, fixture_recorded_call_matches( 'using_model_preference', array( 'claude-sonnet-4-6' ) ),
	'default Sonnet pin NOT recorded once filter overrides' );

// ─── Test 14: max_tokens clamping ────────────────────────────────────
echo "\nTest 14: snt_ai_generate_with_constraints — max_tokens clamped to [1, 4096]\n";

// Upper bound
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'ok';
snt_ai_generate_with_constraints( 'p', 's', 99999 );
hc_true( fixture_recorded_call_matches( 'using_max_tokens', array( 4096 ) ),
	'max_tokens=99999 → clamped to 4096' );

// Lower bound (negative)
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'ok';
snt_ai_generate_with_constraints( 'p', 's', -5 );
hc_true( fixture_recorded_call_matches( 'using_max_tokens', array( 1 ) ),
	'max_tokens=-5 → clamped to 1' );

// Lower bound (zero)
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'ok';
snt_ai_generate_with_constraints( 'p', 's', 0 );
hc_true( fixture_recorded_call_matches( 'using_max_tokens', array( 1 ) ),
	'max_tokens=0 → clamped to 1' );

// In-range value passes through unchanged
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'ok';
snt_ai_generate_with_constraints( 'p', 's', 256 );
hc_true( fixture_recorded_call_matches( 'using_max_tokens', array( 256 ) ),
	'max_tokens=256 (in range) → passes through unchanged' );

// ─── Test 15: system instruction is forwarded to builder ─────────────
echo "\nTest 15: snt_ai_generate_with_constraints — system instruction forwarded\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'ok';
snt_ai_generate_with_constraints( 'user prompt', 'be brief and factual', 128 );
hc_true( fixture_recorded_call_matches( 'using_system_instruction', array( 'be brief and factual' ) ),
	'using_system_instruction recorded with caller-provided string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

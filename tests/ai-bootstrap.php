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

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// In-memory options store backing get_option/update_option for the v6.29.0
// usage-log assertions. Reset per block via fixture_reset().
$GLOBALS['__test_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['__test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__test_options'][ $name ] = $value;
		return true;
	}
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
// v6.39.2: count builder constructions so the memoization tests can assert
// that snt_ai_can_text_generate() rebuilds the wp_ai_client_prompt('check')
// builder (and re-runs the support check / error_log) at most ONCE per request.
$GLOBALS['__test_ai_builder_construct_count']     = 0;
// v6.29.0: TokenUsage the mock result reports via getTokenUsage().
$GLOBALS['__test_ai_result_usage'] = array(
	'prompt'     => 120,
	'completion' => 40,
	'total'      => 160,
);
// v6.39.2: the served model id the result reports via getModelMetadata()->getId().
// Defaults to the requested pin; tests set it to a DIFFERENT id to simulate the
// provider substituting a model (the case cost-attribution needs to survive).
$GLOBALS['__test_ai_served_model'] = 'claude-sonnet-4-6';

/**
 * Mock TokenUsage DTO — mirrors wp-ai-client's TokenUsage accessors
 * (getPromptTokens / getCompletionTokens / getTotalTokens) that
 * snt_ai_record_usage() reads. These are real PascalCase camel methods on
 * the live DTO (NOT __call-routed), so they are declared as real methods.
 */
class TestTokenUsage {
	private $p;
	private $c;
	private $t;
	public function __construct( $p, $c, $t ) {
		$this->p = (int) $p;
		$this->c = (int) $c;
		$this->t = (int) $t;
	}
	public function getPromptTokens()     { return $this->p; }
	public function getCompletionTokens() { return $this->c; }
	public function getTotalTokens()      { return $this->t; }
}

/**
 * Mock ModelMetadata DTO — mirrors php-ai-client's
 * Providers\Models\DTO\ModelMetadata::getId(): string (verified against
 * WordPress/php-ai-client trunk), the served-model accessor that
 * snt_ai_record_usage() reads for cost attribution under provider
 * model substitution.
 */
class TestModelMetadata {
	private $id;
	public function __construct( $id ) { $this->id = (string) $id; }
	public function getId() { return $this->id; }
}

/**
 * Mock GenerativeAiResult — what generate_text_result() returns. Carries the
 * body (toText), usage (getTokenUsage), and served-model (getModelMetadata),
 * the three accessors the wrapper reads.
 */
class TestAiResult {
	private $text;
	private $usage;
	private $served_model;
	public function __construct( $text, $usage, $served_model = '' ) {
		$this->text         = (string) $text;
		$this->usage        = $usage;
		$this->served_model = (string) $served_model;
	}
	public function toText()        { return $this->text; }
	public function getTokenUsage() { return $this->usage; }
	public function getModelMetadata() { return new TestModelMetadata( $this->served_model ); }
}

/**
 * Mock result that lacks getModelMetadata() — models an older SDK / a provider
 * that doesn't populate model metadata. snt_ai_record_usage() must degrade to
 * an empty served_model rather than fatal (is_callable guard).
 */
class TestAiResultNoModelMeta {
	private $usage;
	public function __construct( $usage ) { $this->usage = $usage; }
	public function getTokenUsage() { return $this->usage; }
}

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
		++$GLOBALS['__test_ai_builder_construct_count'];
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
		if ( 'generate_text_result' === $name ) {
			// v6.29.0: the wrapper now calls generate_text_result(). When the
			// fixture's generate_returns is a WP_Error, surface it (provider
			// error path → no result object → no usage). Otherwise wrap the
			// body + usage in a result object the wrapper reads.
			$ret = $GLOBALS['__test_ai_builder_generate_returns'] ?? '';
			if ( $ret instanceof WP_Error ) {
				return $ret;
			}
			$u     = $GLOBALS['__test_ai_result_usage'] ?? null;
			$usage = is_array( $u )
				? new TestTokenUsage( $u['prompt'] ?? 0, $u['completion'] ?? 0, $u['total'] ?? 0 )
				: null;
			return new TestAiResult( (string) $ret, $usage, (string) ( $GLOBALS['__test_ai_served_model'] ?? '' ) );
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
	$GLOBALS['__test_ai_builder_construct_count']     = 0;
	$GLOBALS['__test_filters']                       = array();
	// v6.39.2: clear the request-static availability cache between blocks, or the
	// first block's memoized result would poison every later toggle of
	// __test_ai_builder_supports_text. function_exists guard lets the RED run
	// (before the reset helper is implemented) still complete + emit a summary.
	if ( function_exists( 'snt_ai_reset_availability_cache' ) ) {
		snt_ai_reset_availability_cache();
	}
	$GLOBALS['__test_ai_result_usage']               = array(
		'prompt'     => 120,
		'completion' => 40,
		'total'      => 160,
	);
	$GLOBALS['__test_ai_served_model'] = 'claude-sonnet-4-6';
	$GLOBALS['__test_options'] = array();
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
$GLOBALS['__test_ai_builder_throws_on_method'] = 'generate_text_result';
$result = snt_ai_generate_with_constraints( 'p', 's' );
hc_true( is_wp_error( $result ), 'Throwable in chain → WP_Error' );
hc_eq( 'snt_ai_runtime_error', $result->get_error_code(),
	'error code is snt_ai_runtime_error' );
$data = $result->get_error_data();
hc_eq( 500, isset( $data['status'] ) ? $data['status'] : null,
	'runtime error status is 500' );
hc_true( false !== strpos( $result->get_error_message(), 'fixture: generate_text_result throws' ),
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
$gen_idx  = fixture_first_call_index( 'generate_text_result' );
hc_true( $pref_idx >= 0, 'using_model_preference was called' );
hc_true( $gen_idx >= 0, 'generate_text_result was called' );
hc_true( $pref_idx < $gen_idx,
	'using_model_preference precedes generate_text_result (so the pin actually takes effect)' );

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

// ─── Test 16: usage recorded on happy path (v6.29.0) ─────────────────
echo "\nTest 16: snt_ai_generate_with_constraints — records token usage\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'a body';
$GLOBALS['__test_ai_result_usage']             = array(
	'prompt'     => 1500,
	'completion' => 400,
	'total'      => 1900,
);
$out = snt_ai_generate_with_constraints( 'p', 's', 512, 'insights_narration' );
hc_eq( 'a body', $out, 'body still returned via toText()' );
$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
hc_eq( 1, count( $log ), 'one usage entry recorded' );
hc_eq( 1500, $log[0]['prompt'] ?? null, 'prompt tokens recorded' );
hc_eq( 400, $log[0]['completion'] ?? null, 'completion tokens recorded' );
hc_eq( 1900, $log[0]['total'] ?? null, 'total tokens recorded' );
hc_eq( 'insights_narration', $log[0]['feature'] ?? null, 'feature label recorded' );
hc_eq( 'claude-sonnet-4-6', $log[0]['model'] ?? null, 'requested model preference recorded' );

// ─── Test 17: feature label defaults to 'generic' ───────────────────
echo "\nTest 17: snt_ai_generate_with_constraints — feature defaults to 'generic'\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'x';
snt_ai_generate_with_constraints( 'p', 's' );
$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
hc_eq( 'generic', $log[0]['feature'] ?? null, 'untagged call logs as generic' );

// ─── Test 18: empty body still records usage (tokens were spent) ─────
echo "\nTest 18: snt_ai_generate_with_constraints — empty body still logs usage\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = '   '; // trims to empty → WP_Error 502
$res = snt_ai_generate_with_constraints( 'p', 's', 256, 'excerpt' );
hc_true( is_wp_error( $res ), 'empty body still returns WP_Error (unchanged)' );
$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
hc_eq( 1, count( $log ), 'usage recorded anyway — the call consumed prompt tokens' );

// ─── Test 19: provider WP_Error result → no usage recorded ──────────
echo "\nTest 19: snt_ai_generate_with_constraints — provider WP_Error logs no usage\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = new WP_Error( 'provider_oops', 'rate limit', array( 'status' => 429 ) );
$res = snt_ai_generate_with_constraints( 'p', 's' );
hc_true( is_wp_error( $res ), 'provider error propagates' );
$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
hc_eq( 0, count( $log ), 'no usage entry when there is no result object' );

// ─── Test 20: snt_ai_usage_summary aggregation + by_feature + window ──
echo "\nTest 20: snt_ai_usage_summary — aggregation, by_feature, day window\n";
fixture_reset();
$now = time();
$GLOBALS['__test_options'][ SN_AI_USAGE_LOG_OPT ] = array(
	array( 'ts' => $now - 100,                   'feature' => 'insights',           'model' => 'm', 'prompt' => 100, 'completion' => 50, 'total' => 150 ),
	array( 'ts' => $now - 200,                   'feature' => 'insights_narration', 'model' => 'm', 'prompt' => 200, 'completion' => 60, 'total' => 260 ),
	array( 'ts' => $now - 100 * DAY_IN_SECONDS,  'feature' => 'insights',           'model' => 'm', 'prompt' => 999, 'completion' => 99, 'total' => 1098 ), // outside 30d
);
$sum = snt_ai_usage_summary( 30 );
hc_eq( 2, $sum['calls'], 'only in-window calls counted (old 100-day entry excluded)' );
hc_eq( 410, $sum['total'], 'in-window totals summed (150 + 260)' );
hc_eq( 300, $sum['prompt'], 'in-window prompt summed (100 + 200)' );
hc_eq( 150, $sum['by_feature']['insights']['total'] ?? null, 'by_feature insights total (in-window only)' );
hc_eq( 260, $sum['by_feature']['insights_narration']['total'] ?? null, 'by_feature narration total' );
hc_eq( 1, $sum['by_feature']['insights']['calls'] ?? null, 'out-of-window insights entry excluded from by_feature' );

// ─── Test 21: usage log capped FIFO at SN_AI_USAGE_LOG_CAP ───────────
echo "\nTest 21: usage log capped at SN_AI_USAGE_LOG_CAP (FIFO)\n";
fixture_reset();
$big = array();
for ( $i = 0; $i < SN_AI_USAGE_LOG_CAP + 25; $i++ ) {
	$big[] = array( 'ts' => time(), 'feature' => 'f', 'model' => 'm', 'prompt' => 1, 'completion' => 1, 'total' => 2 );
}
$GLOBALS['__test_options'][ SN_AI_USAGE_LOG_OPT ] = $big;
$GLOBALS['__test_ai_builder_supports_text']    = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'one more';
snt_ai_generate_with_constraints( 'p', 's', 64, 'f' );
$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
hc_eq( SN_AI_USAGE_LOG_CAP, count( $log ), 'log capped at SN_AI_USAGE_LOG_CAP after append' );

// ─── Test 22: availability is memoized within a request (v6.39.2 PERF) ─
//
// snt_ai_can_text_generate() runs on 15-23 admin call sites per page load.
// Pre-v6.39.2 each call rebuilt a wp_ai_client_prompt('check') builder and
// re-ran the support check. Memoizing collapses that to one build per request.
// We prove it by counting builder constructions across two calls.
echo "\nTest 22: snt_ai_can_text_generate memoizes within a request\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text'] = true;
hc_eq( true, snt_ai_can_text_generate(), 'first call returns true' );
hc_eq( true, snt_ai_can_text_generate(), 'second call returns true (from cache)' );
hc_eq( 1, $GLOBALS['__test_ai_builder_construct_count'],
	'builder constructed exactly once across two calls (memoized, not re-derived)' );

// ─── Test 23: snt_ai_reset_availability_cache forces a fresh re-check ──
//
// The cache is request-static; only an explicit reset re-derives it. The
// test harness depends on this to toggle provider state between blocks.
echo "\nTest 23: snt_ai_reset_availability_cache re-derives the value\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text'] = true;
hc_eq( true, snt_ai_can_text_generate(), 'cached true on first derive' );
$GLOBALS['__test_ai_builder_supports_text'] = false; // provider drops mid-request (only reachable in test)
hc_eq( true, snt_ai_can_text_generate(), 'still true — value is memoized, not re-derived' );
snt_ai_reset_availability_cache();
hc_eq( false, snt_ai_can_text_generate(), 'after reset, re-derives to false' );

// ─── Test 24: catch path runs once → error_log fires at most once ─────
//
// When the support check throws, the catch logs once and caches false.
// Subsequent calls return the cached false WITHOUT re-entering the try (so
// no error_log spam on a broken provider). construct_count is the proxy:
// one construction = one catch = at most one error_log.
echo "\nTest 24: broken-provider catch path runs once (no error_log spam)\n";
fixture_reset();
$GLOBALS['__test_ai_builder_throws_on_method'] = 'is_supported_for_text_generation';
hc_eq( false, snt_ai_can_text_generate(), 'support-check throw → false' );
hc_eq( false, snt_ai_can_text_generate(), 'second call still false (cached, no re-throw)' );
hc_eq( 1, $GLOBALS['__test_ai_builder_construct_count'],
	'builder constructed once → catch + error_log ran at most once per request' );

// ─── Test 25: served model recorded; requested pin preserved (v6.39.2) ─
//
// Cost attribution must survive a provider substituting a different model
// than the pinned preference. We record BOTH: 'model' (requested) stays the
// pin for backward-compat with snt_ai_usage_summary; 'served_model' carries
// what the provider actually billed (getModelMetadata()->getId()).
echo "\nTest 25: snt_ai_record_usage records served model under substitution\n";
fixture_reset();
$GLOBALS['__test_ai_builder_supports_text'] = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'body';
$GLOBALS['__test_ai_served_model'] = 'claude-haiku-4-5'; // provider served Haiku despite the Sonnet pin
snt_ai_generate_with_constraints( 'p', 's', 256, 'tag_suggest' );
$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
hc_eq( 'claude-sonnet-4-6', $log[0]['model'] ?? null, 'requested model pin preserved in model field' );
hc_eq( 'claude-haiku-4-5', $log[0]['served_model'] ?? null, 'served model recorded from getModelMetadata()->getId()' );

// ─── Test 26: served-model accessor missing → degrades, no fatal ──────
echo "\nTest 26: snt_ai_record_usage degrades when getModelMetadata is absent\n";
fixture_reset();
$res_no_meta = new TestAiResultNoModelMeta( new TestTokenUsage( 10, 5, 15 ) );
snt_ai_record_usage( 'generic', 'claude-sonnet-4-6', $res_no_meta );
$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
hc_eq( 1, count( $log ), 'usage still recorded when model metadata is unavailable' );
hc_eq( '', $log[0]['served_model'] ?? null, 'served_model degrades to empty string (is_callable guard)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

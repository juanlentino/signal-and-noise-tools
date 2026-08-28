<?php
/**
 * Standalone test: the Insights wire-request shaper.
 *
 * WHY THE SHAPER EXISTS. On claude-sonnet-5 with no explicit effort config,
 * thinking is CEILING-BOUNDED — it eats whatever max_tokens allows and leaves
 * the answer nothing. Raising the ceiling has now been falsified TWICE here
 * (v10.53.0 on the agent path, v13.20.5 on this one). The fix is an explicit
 * effort config, which makes thinking DEMAND-bounded.
 *
 * WHAT THIS PINS, in order of how badly it would hurt to get wrong:
 *   1. Requests that are not ours come back BYTE-IDENTICAL. This filter runs on
 *      `http_request_args` at PHP_INT_MAX, so it sees every outbound HTTP
 *      request WordPress makes while armed — other plugins' Anthropic traffic
 *      included. A decode+re-encode of an untouched body would still perturb
 *      the transport, so "unchanged" has to mean identical, not equivalent.
 *   2. A request that already decided how it reasons is left alone.
 *   3. The injection itself, and that it is disableable.
 *
 * Standalone — no PHPUnit. Run: php tests/insights-generation-budget.php
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

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// ─── a minimal filter registry, so arm/disarm is exercised for real ──
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $tag ][ $prio ][] = $cb; return true; }
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $tag, $cb, $prio = 10 ) {
		if ( ! isset( $GLOBALS['__filters'][ $tag ][ $prio ] ) ) { return false; }
		$GLOBALS['__filters'][ $tag ][ $prio ] = array_values( array_filter(
			$GLOBALS['__filters'][ $tag ][ $prio ],
			static function ( $c ) use ( $cb ) { return $c !== $cb; }
		) );
		if ( ! $GLOBALS['__filters'][ $tag ][ $prio ] ) { unset( $GLOBALS['__filters'][ $tag ][ $prio ] ); }
		return true;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $cb = false ) {
		foreach ( $GLOBALS['__filters'][ $tag ] ?? array() as $cbs ) {
			if ( in_array( $cb, $cbs, true ) ) { return true; }
		}
		return false;
	}
}
// apply_filters honours a per-test override so the disable path is real.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( isset( $GLOBALS['__override'][ $tag ] ) ) { return $GLOBALS['__override'][ $tag ]; }
		return $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }

require_once __DIR__ . '/../inc/insights-generation-budget.php';

/** Build a representative outbound request. */
function gb_args( $body, $timeout = 30 ) {
	return array( 'method' => 'POST', 'timeout' => $timeout, 'headers' => array( 'x' => 'y' ), 'body' => wp_json_encode( $body ) );
}
$ANTHROPIC = 'https://api.anthropic.com/v1/messages';
$BASE      = array( 'model' => 'claude-sonnet-5', 'max_tokens' => 4096, 'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ) );

// ─── 1. PASS-THROUGH must be byte-identical ──────────────────────────
$in  = gb_args( $BASE );
$out = snt_insights_budget_shape( $in, 'https://api.openai.com/v1/chat/completions' );
gb( 'another provider: args byte-identical', serialize( $in ) === serialize( $out ) );

$out = snt_insights_budget_shape( $in, 'https://juanlentino.com/wp-json/x' );
gb( 'our own site: args byte-identical', serialize( $in ) === serialize( $out ) );

$older = gb_args( array( 'model' => 'claude-3-5-sonnet-20241022', 'max_tokens' => 4096 ) );
$out   = snt_insights_budget_shape( $older, $ANTHROPIC );
gb( 'non-Claude-5 model: byte-identical (adaptive would 400 there)', serialize( $older ) === serialize( $out ) );

$nobody = array( 'method' => 'GET', 'timeout' => 30 );
gb( 'no body: byte-identical', serialize( $nobody ) === serialize( snt_insights_budget_shape( $nobody, $ANTHROPIC ) ) );

$junk = array( 'timeout' => 30, 'body' => 'not json at all' );
gb( 'unparseable body: byte-identical', serialize( $junk ) === serialize( snt_insights_budget_shape( $junk, $ANTHROPIC ) ) );

// ─── 2. DEFERENCE: an existing decision wins ─────────────────────────
$has_thinking = gb_args( array_merge( $BASE, array( 'thinking' => array( 'type' => 'enabled', 'budget_tokens' => 1024 ) ) ) );
$out          = snt_insights_budget_shape( $has_thinking, $ANTHROPIC );
$decoded      = json_decode( $out['body'], true );
gb( 'existing thinking: not overwritten', 'enabled' === $decoded['thinking']['type'] );
gb( 'existing thinking: no output_config injected beside it', ! isset( $decoded['output_config'] ) );

$has_cfg = gb_args( array_merge( $BASE, array( 'output_config' => array( 'effort' => 'high' ) ) ) );
$decoded = json_decode( snt_insights_budget_shape( $has_cfg, $ANTHROPIC )['body'], true );
gb( 'existing output_config: effort untouched', 'high' === $decoded['output_config']['effort'] );
gb( 'existing output_config: no thinking injected beside it', ! isset( $decoded['thinking'] ) );

// ─── 3. THE INJECTION ────────────────────────────────────────────────
$out     = snt_insights_budget_shape( gb_args( $BASE ), $ANTHROPIC );
$decoded = json_decode( $out['body'], true );
gb( 'injects adaptive thinking', isset( $decoded['thinking']['type'] ) && 'adaptive' === $decoded['thinking']['type'] );
gb( 'injects an effort level', isset( $decoded['output_config']['effort'] ) && 'low' === $decoded['output_config']['effort'] );
gb( 'does NOT use thinking.type=enabled (Claude 5 rejects it with a 400)', 'enabled' !== ( $decoded['thinking']['type'] ?? '' ) );
gb( 'raises the wire ceiling above the builder value', (int) $decoded['max_tokens'] === SN_INSIGHTS_WIRE_MAX_TOKENS );
gb( 'wire ceiling exceeds the helper clamp of 4096', SN_INSIGHTS_WIRE_MAX_TOKENS > 4096 );
gb( 'extends the transport timeout', (int) $out['timeout'] === SN_INSIGHTS_WIRE_TIMEOUT );
gb( 'timeout is above the 30s that v13.20.5 died against', SN_INSIGHTS_WIRE_TIMEOUT > 30 );
gb( 'leaves unrelated args alone', 'POST' === $out['method'] && array( 'x' => 'y' ) === $out['headers'] );
gb( 'preserves the original message payload', $decoded['messages'] === $BASE['messages'] );

// Ceiling is RAISE-ONLY: a request already asking for more keeps its own.
$bigger  = gb_args( array_merge( $BASE, array( 'max_tokens' => 32000 ) ) );
$decoded = json_decode( snt_insights_budget_shape( $bigger, $ANTHROPIC )['body'], true );
gb( 'raise-only: a larger existing ceiling is not lowered', 32000 === (int) $decoded['max_tokens'] );

// ─── 4. DISABLEABLE ──────────────────────────────────────────────────
$GLOBALS['__override']['snt_insights_anthropic_effort'] = '';
$decoded = json_decode( snt_insights_budget_shape( gb_args( $BASE ), $ANTHROPIC )['body'], true );
gb( 'effort filter set to "": no thinking injected', ! isset( $decoded['thinking'] ) );
gb( 'effort filter set to "": no output_config injected', ! isset( $decoded['output_config'] ) );
unset( $GLOBALS['__override']['snt_insights_anthropic_effort'] );

// ─── 4b. THE CEILING IS CONDITIONAL ON THINKING BEING BOUNDED ────────
// Raising the ceiling of an UNBOUNDED request is the v13.20.5 failure with a
// bigger number: thinking simply takes the extra room. If the effort injection
// is disabled, the ceiling must stay where the builder put it.
$GLOBALS['__override']['snt_insights_anthropic_effort'] = '';
$out     = snt_insights_budget_shape( gb_args( $BASE ), $ANTHROPIC );
$decoded = json_decode( $out['body'], true );
gb( 'effort disabled: ceiling NOT raised (unbounded thinking would eat it)', 4096 === (int) $decoded['max_tokens'] );
gb( 'effort disabled: timeout NOT extended', 30 === (int) $out['timeout'] );
gb( 'effort disabled: args byte-identical to the input', serialize( gb_args( $BASE ) ) === serialize( $out ) );
unset( $GLOBALS['__override']['snt_insights_anthropic_effort'] );

// But a request someone ELSE already bounded does get the headroom.
$pre     = gb_args( array_merge( $BASE, array( 'output_config' => array( 'effort' => 'medium' ) ) ) );
$decoded = json_decode( snt_insights_budget_shape( $pre, $ANTHROPIC )['body'], true );
gb( 'externally-bounded request: ceiling IS raised', SN_INSIGHTS_WIRE_MAX_TOKENS === (int) $decoded['max_tokens'] );
gb( 'externally-bounded request: its own effort preserved', 'medium' === $decoded['output_config']['effort'] );

// ─── 5. ARM / DISARM ─────────────────────────────────────────────────
gb( 'starts unhooked', false === has_filter( 'http_request_args', 'snt_insights_budget_shape' ) );
snt_insights_budget_arm();
gb( 'arm: hooked', true === has_filter( 'http_request_args', 'snt_insights_budget_shape' ) );
snt_insights_budget_arm();
gb( 'arm twice: still exactly one registration', 1 === count( $GLOBALS['__filters']['http_request_args'][ PHP_INT_MAX ] ) );
snt_insights_budget_disarm();
gb( 'disarm: unhooked again', false === has_filter( 'http_request_args', 'snt_insights_budget_shape' ) );

// ─── NEGATIVE CONTROL ────────────────────────────────────────────────
// The pass-through assertions above are only meaningful if this shaper is
// capable of changing an args array at all. Prove the two states differ.
$untouched = snt_insights_budget_shape( gb_args( $BASE ), 'https://example.com/' );
$shaped    = snt_insights_budget_shape( gb_args( $BASE ), $ANTHROPIC );
gb( 'control: shaped and untouched genuinely differ', serialize( $untouched ) !== serialize( $shaped ) );
gb( 'control: the model gate is what separates them', 'claude-sonnet-5' === $BASE['model'] && snt_insights_budget_model_is_claude5( 'claude-sonnet-5' ) );
gb( 'control: model matcher rejects a Claude 4 id', ! snt_insights_budget_model_is_claude5( 'claude-opus-4-6' ) );
gb( 'control: model matcher accepts other Claude 5 ids', snt_insights_budget_model_is_claude5( 'claude-opus-5' ) && snt_insights_budget_model_is_claude5( 'claude-fable-5' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Standalone fixture tests for the prompt-cache verdict + its wp-admin panel.
 *
 * The verdict is the part that can mislead, so most of these are about it
 * refusing to overclaim in either direction:
 *
 *   - "cannot cache" is only ever said when an UPPER BOUND on the prefix falls
 *     short of the floor. The bound is the smaller of a dense byte estimate
 *     and the request's own reported `input_tokens` — the cacheable prefix is
 *     a strict subset of the input, so that second bound is arithmetic, not
 *     inference. Live calibration: a 922-byte request measured 297 input
 *     tokens (3.10 B/token), which is why the byte divisor is 3.0 and not the
 *     usual ~4.
 *   - floors are per MODEL and not monotonic (1,024 on Sonnet 5, 4,096 on
 *     Haiku 4.5, 512 on Opus 5). A single site-wide comparison would be wrong
 *     for half the traffic, so the verdict groups by model.
 *   - an unknown model yields "no floor on file", never a guessed floor.
 *   - `no_data` is a state, not a verdict: an empty log must not render as
 *     "caching cannot pay".
 *
 * @since plugin v10.52.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// ─── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__opts'] = array();
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return false; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, (int) $ts ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '1 hour'; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = false ) { return $GLOBALS['__opts'][ $n ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $n, $v, $a = null ) { $GLOBALS['__opts'][ $n ] = $v; return true; } }

require_once __DIR__ . '/../inc/ai-cache-probe.php';
require_once __DIR__ . '/../inc/ai-bootstrap.php';
require_once __DIR__ . '/../inc/insights-admin.php';

$pass = 0;
$fail = 0;
function hc_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $msg\n";
	} else {
		++$fail;
		echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n";
	}
}
function hc_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { ++$pass; echo "  PASS: $msg\n"; } else { ++$fail; echo "  FAIL: $msg\n"; }
}
function hc_contains( $hay, $needle, $msg ) {
	hc_true( false !== strpos( $hay, $needle ), $msg );
}
function hc_lacks( $hay, $needle, $msg ) {
	hc_true( false === strpos( $hay, $needle ), $msg );
}

const T0 = 1785955200;

/**
 * One probe row. Defaults reproduce a real SN call measured live on
 * 2026-08-05: Sonnet 5, no tools, 677-byte system, 297 input tokens, and both
 * cache fields present and zero.
 */
function row( $over = array() ) {
	return array_merge(
		array(
			'ts'          => T0,
			'model'       => 'claude-sonnet-5',
			'prefix_hash' => 'cf4d3ca4b2b1',
			'req_bytes'   => 922,
			'tools_bytes' => 0,
			'tools_count' => 0,
			'sys_bytes'   => 677,
			'msg_bytes'   => 179,
			'msg_count'   => 1,
			'in'          => 297,
			'out'         => 123,
			'cache_write' => 0,
			'cache_read'  => 0,
		),
		$over
	);
}

// ─── Group: the floor table ───────────────────────────────────────────
echo "\nGroup: minimum cacheable prefix per model\n";
hc_eq( 1024, snt_ai_cache_probe_min_prefix_tokens( 'claude-sonnet-5' ), 'Sonnet 5 floor is 1,024 tokens' );
hc_eq( 4096, snt_ai_cache_probe_min_prefix_tokens( 'claude-haiku-4-5' ), 'Haiku 4.5 floor is 4,096 tokens (the economy tier is the HARDEST to cache)' );
hc_eq( 512, snt_ai_cache_probe_min_prefix_tokens( 'claude-opus-5' ), 'Opus 5 floor is 512 tokens' );
hc_eq( 4096, snt_ai_cache_probe_min_prefix_tokens( 'claude-opus-4-6' ), 'Opus 4.6 floor is 4,096 — floors are NOT monotonic across generations' );
hc_eq( null, snt_ai_cache_probe_min_prefix_tokens( 'claude-not-a-model' ), 'an unknown model returns null, never a guessed floor' );
hc_eq( null, snt_ai_cache_probe_min_prefix_tokens( '' ), 'an empty model id returns null' );

// ─── Group: the token upper bound ─────────────────────────────────────
echo "\nGroup: prefix token upper bound\n";
hc_eq( 226, snt_ai_cache_probe_tokens_hi( 677 ), '677 bytes estimates to 226 tokens at the calibrated 3.0 B/token' );
hc_true(
	snt_ai_cache_probe_tokens_hi( 922 ) >= 297,
	'the byte estimate for a 922-byte request is not BELOW its measured 297 tokens (erring high, as designed)'
);
hc_eq( 100, snt_ai_cache_probe_tokens_hi( 90000, 100 ), 'reported input_tokens caps a wild byte estimate (prefix is a subset of input)' );
hc_eq( 34, snt_ai_cache_probe_tokens_hi( 100, 5000 ), 'the byte estimate wins when input_tokens is the looser bound' );
hc_eq( 34, snt_ai_cache_probe_tokens_hi( 100, null ), 'a null input_tokens falls back to the byte estimate alone' );

// ─── Group: verdict states ────────────────────────────────────────────
echo "\nGroup: verdict states\n";

hc_eq( 'no_data', snt_ai_cache_probe_verdict( array() )['state'], 'an empty log is no_data, NOT "cannot cache"' );
hc_eq( null, snt_ai_cache_probe_verdict( array() )['best'], 'no_data has no best candidate' );
hc_eq( 'no_data', snt_ai_cache_probe_verdict( 'corrupt' )['state'], 'a corrupt log degrades to no_data without fataling' );

// The live case: 677-byte prefix, 297 input tokens, Sonnet 5 floor 1,024.
$live = snt_ai_cache_probe_verdict( array( row(), row( array( 'out' => 146 ) ), row( array( 'ts' => T0 + 23, 'prefix_hash' => '900446217d50', 'sys_bytes' => 42, 'req_bytes' => 169, 'in' => 26, 'out' => 4 ) ) ) );
hc_eq( 'below_floor', $live['state'], 'the live 677-byte/297-token case reads as below_floor' );
hc_eq( 3, (int) $live['summary']['calls'], 'all three live calls counted' );
hc_eq( 1, (int) $live['summary']['repeatable'], 'the two same-second identical prefixes count as one repeat' );
hc_true( 297 >= (int) $live['models'][0]['max_prefix_tokens'], 'the prefix bound never exceeds the reported input tokens' );
hc_eq( false, $live['models'][0]['may_clear_floor'], 'Sonnet 5 at 297 input tokens cannot clear a 1,024 floor' );

// A repeat below the floor is worth nothing — the verdict must not be seduced
// by repeatable > 0. This is the exact misreading the panel exists to prevent.
$repeats_small = snt_ai_cache_probe_verdict( array( row(), row(), row(), row() ) );
hc_eq( 'below_floor', $repeats_small['state'], 'three repeats of a sub-floor prefix are still below_floor' );
hc_true( (int) $repeats_small['summary']['repeatable'] >= 3, 'those repeats are still counted and shown' );

// Copilot-shaped: a 23 KB tool payload, repeated inside the TTL.
$copilot = array(
	row( array( 'tools_bytes' => 23000, 'tools_count' => 42, 'prefix_hash' => 'aaaaaaaaaaaa', 'in' => 6200, 'req_bytes' => 24000 ) ),
	row( array( 'ts' => T0 + 40, 'tools_bytes' => 23000, 'tools_count' => 42, 'prefix_hash' => 'aaaaaaaaaaaa', 'in' => 6400, 'req_bytes' => 24500 ) ),
);
$v_copilot = snt_ai_cache_probe_verdict( $copilot );
hc_eq( 'candidate', $v_copilot['state'], 'a 23 KB prefix repeated inside the TTL is a candidate' );
hc_eq( true, $v_copilot['best']['may_clear_floor'], 'the best row clears its floor' );
hc_eq( 1, (int) $v_copilot['best']['repeatable'], 'the best row carries the repeat count' );

// Same payload, repeat OUTSIDE the window: a write with no read.
$v_stale = snt_ai_cache_probe_verdict(
	array(
		$copilot[0],
		row( array( 'ts' => T0 + 4000, 'tools_bytes' => 23000, 'tools_count' => 42, 'prefix_hash' => 'aaaaaaaaaaaa', 'in' => 6400 ) ),
	)
);
hc_eq( 'no_repeats', $v_stale['state'], 'a big prefix repeated OUTSIDE the TTL is no_repeats, not candidate' );

// Mixed traffic: the Copilot candidate must not be masked by sub-floor SN calls.
$v_mixed = snt_ai_cache_probe_verdict( array_merge( array( row(), row() ), $copilot ) );
hc_eq( 'candidate', $v_mixed['state'], 'sub-floor SN traffic does not mask a genuine candidate' );
hc_eq( 'claude-sonnet-5', $v_mixed['best']['model'], 'the best row is reported with its model' );

// Per-model floors: identical bytes, different verdicts.
$per_model = snt_ai_cache_probe_verdict(
	array(
		row( array( 'model' => 'claude-haiku-4-5', 'tools_bytes' => 12000, 'prefix_hash' => 'h1', 'in' => 3500 ) ),
		row( array( 'model' => 'claude-opus-5', 'tools_bytes' => 12000, 'prefix_hash' => 'o1', 'in' => 3500 ) ),
	)
);
$by_model = array();
foreach ( $per_model['models'] as $m ) {
	$by_model[ $m['model'] ] = $m;
}
hc_eq( false, $by_model['claude-haiku-4-5']['may_clear_floor'], 'the same 3,500-token prefix is BELOW Haiku 4.5\'s 4,096 floor' );
hc_eq( true, $by_model['claude-opus-5']['may_clear_floor'], 'and ABOVE Opus 5\'s 512 floor — the per-model split is load-bearing' );

// Unknown model: no claim, either way.
$v_unknown = snt_ai_cache_probe_verdict( array( row( array( 'model' => 'claude-future-9', 'tools_bytes' => 40000, 'in' => 12000 ) ) ) );
hc_eq( 'unknown_floor', $v_unknown['state'], 'an unknown model yields unknown_floor rather than a guess' );
hc_eq( null, $v_unknown['models'][0]['may_clear_floor'], 'may_clear_floor is null (unknowable), not false' );

// Caching actually on.
$v_active = snt_ai_cache_probe_verdict( array( row( array( 'cache_read' => 5800, 'in' => 12 ) ) ) );
hc_eq( 'caching_active', $v_active['state'], 'an observed cache read reports caching_active' );

// ─── Group: the panel render ──────────────────────────────────────────
echo "\nGroup: panel render contract\n";

function render_with( $log ) {
	$GLOBALS['__opts'][ SN_AI_CACHE_PROBE_OPT ] = $log;
	ob_start();
	snt_insights_render_cache_probe_section();
	return (string) ob_get_clean();
}

$html_empty = render_with( array() );
hc_contains( $html_empty, 'Nothing measured yet', 'empty log renders the not-yet-measured state' );
hc_contains( $html_empty, 'not a verdict', 'the empty state says explicitly that it is not a verdict' );
hc_lacks( $html_empty, 'cannot pay here', 'the empty state never claims caching cannot pay' );
hc_lacks( $html_empty, '<table', 'the empty state renders no table of zeros' );

$html_live = render_with( array( row(), row( array( 'out' => 146 ) ) ) );
hc_contains( $html_live, 'Caching cannot pay here', 'the live sub-floor case renders the settled verdict' );
hc_contains( $html_live, 'claude-sonnet-5', 'the model is named' );
hc_contains( $html_live, '1,024 tokens', 'the model-specific floor is shown, not a generic one' );
hc_contains( $html_live, 'below the floor', 'the per-model row states which side of the floor it landed' );
hc_contains( $html_live, '677', 'the measured prefix size is shown' );
hc_contains( $html_live, 'different from never having asked', 'measured-zero is distinguished from never-measured in the copy' );
hc_contains( $html_live, 'ai-provider-for-anthropic/issues/33', 'the panel points at the upstream blocker' );

$html_candidate = render_with( $copilot );
hc_contains( $html_candidate, 'Caching would pay', 'a genuine candidate renders as such' );
hc_contains( $html_candidate, 'clears the floor', 'the candidate row says it clears the floor' );
// 23,000 bytes of tools PLUS the 677-byte system instruction: the prefix is
// both, because Anthropic renders tools → system → messages and the breakpoint
// would sit after the system block.
hc_contains( $html_candidate, '23,677', 'the large prefix size is shown as tools + system, not tools alone' );

// A panel that renders prompt content would defeat the probe's privacy rule.
$html_priv = render_with( array( row( array( 'prefix_hash' => 'SECRETHASH12' ) ) ) );
hc_lacks( $html_priv, 'SECRETHASH12', 'the panel does not print raw prefix hashes' );

// Guard: no notices/warnings on malformed input reaching the renderer.
$html_junk = render_with( array( 'nonsense', array( 'ts' => 'x' ), row() ) );
hc_true( '' !== $html_junk, 'a log with junk rows still renders' );
hc_contains( $html_junk, 'Prompt-cache probe', 'the section heading survives junk rows' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

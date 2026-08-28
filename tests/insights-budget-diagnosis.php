<?php
/**
 * Standalone test: an Insights parse failure reports its TOKEN ACCOUNTING.
 *
 * THE LIVE CASE THIS EXISTS FOR (2026-08-28). A scan failed with
 * `snt_insights_invalid_json` and the notice showed the model's entire reply:
 * `[ {`. The parser was correct — three characters contain no recoverable
 * array — but the message named JSON, and the real finding was invisible: the
 * call had generated and BILLED its whole 2048-token output budget. Solving the
 * usage table against the pricing table put output at ~2048 and input at
 * ~16,265, which is a budget failure wearing a parse failure's error code.
 *
 * Output tokens that never reach the returned text still bill, and extended
 * thinking counts against max_tokens — so "short answer + exhausted budget" is
 * a distinct diagnosis from "model returned junk", and the admin page must be
 * able to tell them apart WITHOUT anyone opening the request logs.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED: the notice never claims thinking was on.
 * We did not observe the provider config, and naming an unobserved cause is the
 * same overreach that made the original message useless. It reports numbers.
 *
 * Standalone — no PHPUnit. Run: php tests/insights-budget-diagnosis.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

function bd( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; } else { $fail++; echo "  FAIL  $label\n"; }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }

// ── snt_ai_usage_last(): the accessor the diagnosis reads ────────────
$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = false ) { return $GLOBALS['__opts'][ $n ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $n, $v, $a = null ) { $GLOBALS['__opts'][ $n ] = $v; return true; } }

// Load the REAL constant definitions (SN_AI_USAGE_LOG_OPT/_CAP) rather than
// restating them here — a stubbed copy is exactly how a suite drifts away from
// the option name the plugin actually writes.
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return false; } }

require_once __DIR__ . '/../inc/ai-bootstrap.php';
require_once __DIR__ . '/../inc/ai-bootstrap/usage-log.php';

bd( 'usage_last: null on an empty log', null === snt_ai_usage_last( 'insights' ) );

$GLOBALS['__opts'][ SN_AI_USAGE_LOG_OPT ] = array(
	array( 'feature' => 'insights', 'completion' => 11, 'prompt' => 100 ),
	array( 'feature' => 'drift_detect', 'completion' => 22, 'prompt' => 200 ),
	array( 'feature' => 'insights', 'completion' => 33, 'prompt' => 300 ),
);
$last = snt_ai_usage_last( 'insights' );
bd( 'usage_last: returns the NEWEST matching entry, not the first', is_array( $last ) && 33 === (int) $last['completion'] );
bd( 'usage_last: does not leak another feature', 22 !== (int) $last['completion'] );
bd( 'usage_last: null for a feature with no entries', null === snt_ai_usage_last( 'never_called' ) );

// ── the rendered notice ──────────────────────────────────────────────
// The renderer reads snt_insights_last_error(); stub it over a global so each
// case drives the REAL sn_admin_flash_to_notice() rather than a copy of it.
$GLOBALS['__last_err'] = null;
function snt_insights_last_error() { return $GLOBALS['__last_err']; }

require_once __DIR__ . '/../inc/admin-flash-messages.php';

/** @param array|null $budget @return string rendered notice body */
function bd_notice( $budget ) {
	$GLOBALS['__last_err'] = array(
		'code'    => 'snt_insights_invalid_json',
		'message' => 'AI response was not valid JSON.',
		'raw'     => '[ {',
		'budget'  => $budget,
		'at'      => time(),
	);
	$out = sn_admin_flash_to_notice( 'insights_failed' );
	return is_array( $out ) && isset( $out[1] ) ? (string) $out[1] : '';
}

// The live shape: budget fully consumed, three characters of text.
$exhausted = bd_notice( array( 'max_tokens' => 2048, 'completion' => 2048, 'prompt' => 16265, 'chars' => 3 ) );
bd( 'exhausted: names it a budget failure, not a JSON one', false !== stripos( $exhausted, 'budget failure' ) );
bd( 'exhausted: states the budget figure', false !== strpos( $exhausted, '2,048' ) );
bd( 'exhausted: states how little text came back', false !== strpos( $exhausted, '3 characters' ) );
bd( 'exhausted: names the constant to raise', false !== strpos( $exhausted, 'SN_INSIGHTS_MAX_TOKENS' ) );
bd( 'exhausted: still shows the raw reply', false !== strpos( $exhausted, '[ {' ) );
bd( 'exhausted: does NOT assert thinking was on (unobserved)', false === stripos( $exhausted, 'thinking was' ) );

// A genuinely malformed answer that did NOT exhaust the budget must read
// differently — otherwise the diagnosis is a constant and says nothing.
$roomy = bd_notice( array( 'max_tokens' => 2048, 'completion' => 40, 'prompt' => 16265, 'chars' => 12 ) );
bd( 'roomy: does NOT claim a budget failure', false === stripos( $roomy, 'budget failure' ) );
bd( 'roomy: says the budget was not the limit', false !== stripos( $roomy, 'not the limit' ) );

// No usage record is its own answer — never rendered as "0 tokens".
$unknown = bd_notice( array( 'max_tokens' => 2048, 'completion' => null, 'prompt' => null, 'chars' => 3 ) );
bd( 'unknown: reports that no token record was found', false !== stripos( $unknown, 'no token record' ) );
bd( 'unknown: does not fabricate a zero', false === strpos( $unknown, '0 of' ) );

// Legacy shape (no budget block at all) must still render, unchanged.
$legacy = bd_notice( null );
bd( 'legacy: an error with no budget block still renders', '' !== $legacy && false !== strpos( $legacy, 'not valid JSON' ) );
bd( 'legacy: adds no budget sentence', false === stripos( $legacy, 'budget' ) );

// ── NEGATIVE CONTROL: the branches must DIFFER ───────────────────────
// Pinning each string alone would pass against an implementation that always
// returned the same sentence. The DIFFERENCE is the property under test.
bd( 'control: exhausted !== roomy', $exhausted !== $roomy );
bd( 'control: exhausted !== unknown', $exhausted !== $unknown );
bd( 'control: roomy !== unknown', $roomy !== $unknown );
bd( 'control: every budget branch differs from legacy', $exhausted !== $legacy && $roomy !== $legacy && $unknown !== $legacy );

// The 95% threshold is a boundary, so pin both sides of it.
$just_under = bd_notice( array( 'max_tokens' => 2048, 'completion' => 1900, 'prompt' => 1, 'chars' => 3 ) );
$just_over  = bd_notice( array( 'max_tokens' => 2048, 'completion' => 1946, 'prompt' => 1, 'chars' => 3 ) );
bd( 'boundary: 1900/2048 is NOT called a budget failure', false === stripos( $just_under, 'budget failure' ) );
bd( 'boundary: 1946/2048 IS called a budget failure', false !== stripos( $just_over, 'budget failure' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

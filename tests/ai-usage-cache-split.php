<?php
/**
 * Behavioral tests for the cache-aware usage join (plugin v10.70.0).
 *
 * The WP AI Client's Anthropic provider SUMS input_tokens +
 * cache_creation_input_tokens + cache_read_input_tokens into one inputTokens
 * figure (WordPress/ai-provider-for-anthropic#33). Pricing that sum at the
 * model's input rate over-bills cached spans by up to 10x, because cache reads
 * bill at 0.1x and writes at 1.25x. Over-reporting is the dangerous direction:
 * it trips the monthly budget cap early and silently disables AI features.
 *
 * This suite covers the seam that fixes it: the probe already observes the
 * true split at the HTTP layer, so record_usage joins against that observation
 * instead of trusting the flattened DTO. The join lives between two modules
 * and is owned by neither, hence its own suite.
 *
 * @since plugin v10.70.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u ) { return parse_url( $u ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f ) { return gmdate( $f ); } }

$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; } }

require_once __DIR__ . '/../inc/ai-cache-probe.php';
require_once __DIR__ . '/../inc/ai-bootstrap.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "ok   — $m\n"; } else { $fail++; echo "FAIL — $m\n"; } }
function near( $a, $b ) { return abs( $a - $b ) < 1e-9; }

$RATE_IN  = 3.0;   // claude-sonnet-5 input  $/MTok
$RATE_OUT = 15.0;  // claude-sonnet-5 output $/MTok

// ── The multipliers are the load-bearing constants ───────────────────────
ok( near( SN_AI_CACHE_WRITE_MULT, 1.25 ), 'cache WRITE multiplier is 1.25x input' );
ok( near( SN_AI_CACHE_READ_MULT, 0.1 ), 'cache READ multiplier is 0.1x input' );

// ── Pricing with an explicit split ───────────────────────────────────────
// 1000 fresh input + 8000 cache read + 0 write. The flattened DTO would report
// prompt=9000 and bill all of it at 1.0x.
$split    = array( 'in' => 1000, 'cache_write' => 0, 'cache_read' => 8000 );
$expected = ( 1000 * 1.0 + 0 * 1.25 + 8000 * 0.1 ) * $RATE_IN / 1e6 + 500 * $RATE_OUT / 1e6;
$actual   = snt_ai_estimate_cost( 'claude-sonnet-5', 9000, 500, $split );
ok( near( $actual, $expected ), 'split pricing bills cache reads at 0.1x, not 1.0x' );

$flat = snt_ai_estimate_cost( 'claude-sonnet-5', 9000, 500 );
ok( $actual < $flat, 'the split price is strictly cheaper than the flattened price' );
// The whole point: the flattened figure over-bills. Assert the RELATIONSHIP
// (how much cheaper) rather than a literal dollar amount.
ok( near( $flat / $actual, ( 9000 * 1.0 + 500 * ( $RATE_OUT / $RATE_IN ) ) / ( 1800 + 500 * ( $RATE_OUT / $RATE_IN ) ) ), 'over-billing ratio matches the 0.1x read rate exactly' );

// Cache WRITES cost MORE than fresh input — a split that is all-write must be
// dearer than the flattened figure, or the multiplier is wired backwards.
$w_split = array( 'in' => 0, 'cache_write' => 9000, 'cache_read' => 0 );
ok( snt_ai_estimate_cost( 'claude-sonnet-5', 9000, 500, $w_split ) > $flat, 'an all-write split is DEARER than flat (1.25x), so the multipliers are not inverted' );

// ── Backward compatibility: the 3-arg form is untouched ──────────────────
ok( near( snt_ai_estimate_cost( 'claude-sonnet-5', 1000, 1000 ), ( 1000 * $RATE_IN + 1000 * $RATE_OUT ) / 1e6 ), '3-arg form still prices the old way' );
ok( near( snt_ai_estimate_cost( 'some-unknown-model', 1000, 500, $split ), 0.0 ), 'unknown model still returns 0.0 even with a split (no fabricated rate)' );

// ── The observation queue ────────────────────────────────────────────────
snt_ai_cache_obs_reset();
ok( array() === snt_ai_cache_obs_peek(), 'the observation queue starts empty' );

snt_ai_cache_obs_push( array( 'in' => 1000, 'out' => 500, 'cache_write' => 0, 'cache_read' => 8000 ) );
snt_ai_cache_obs_push( array( 'in' => 20, 'out' => 7, 'cache_write' => 0, 'cache_read' => 0 ) );
ok( 2 === count( snt_ai_cache_obs_peek() ), 'two observations queued' );

// Match is by token identity, not arrival order — a request can make several
// AI calls and the DTO carries no correlation id.
$m = snt_ai_cache_obs_take( 20, 7 ); // 20 + 0 + 0 = 20 summed prompt
ok( is_array( $m ) && 20 === $m['in'], 'takes the observation whose SUMMED input matches, not merely the first queued' );
ok( 1 === count( snt_ai_cache_obs_peek() ), 'a taken observation is consumed, not left to be double-counted' );

$m2 = snt_ai_cache_obs_take( 9000, 500 ); // 1000 + 0 + 8000
ok( is_array( $m2 ) && 8000 === $m2['cache_read'], 'the remaining observation still matches on its own summed total' );
ok( array() === snt_ai_cache_obs_peek(), 'queue drains' );

// ── Fail-safe: no match must never be worse than today ───────────────────
snt_ai_cache_obs_reset();
ok( null === snt_ai_cache_obs_take( 1234, 99 ), 'no matching observation returns null rather than a wrong guess' );

// An observation that did not MEASURE the cache fields (keys absent -> null)
// must not be treated as "measured zero" — that distinction is the probe's
// whole reason for existing.
snt_ai_cache_obs_reset();
snt_ai_cache_obs_push( array( 'in' => 500, 'out' => 10, 'cache_write' => null, 'cache_read' => null ) );
ok( null === snt_ai_cache_obs_take( 500, 10 ), 'an UNMEASURED observation is not offered as a split (null != measured zero)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );

<?php
/**
 * Standalone fixture tests for inc/ai-copilot-spend.php: the Copilot (Ask AI)
 * month spend, a bucket beside the cap (#1597).
 *
 * The station fires `openstation_ai_search_completed` (Stable, OpenStation
 * 1.1.10) with `usage = { prompt, completion, total }` and `model = { id, name }`,
 * either possibly null. One subscriber prices the run with the plugin's own
 * snt_ai_estimate_cost() and folds it into SN_AI_COPILOT_SPEND_OPT, a YYYY-MM
 * option of its own, or counts the turn in SN_AI_COPILOT_UNPRICED_OPT when it
 * cannot price it. The cap's ledger (snt_ai_spend_this_month() and the
 * feature buckets) must not move: the station calls the model directly, so a
 * Copilot figure inside the cap would pause Suggest and Insights sooner
 * without ever pausing Ask AI.
 *
 * Run: php tests/ai-copilot-spend.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "FAIL: $label\n"; }
}

// ── WP stubs: a real action table, an in-memory option store, and a
// current_filter() stack the way WP keeps one, so the family-aware double-fire
// guard (snt_os_compat_seen_once) can tell which hook name is dispatching.
$GLOBALS['__actions']        = array();
$GLOBALS['__current_filter'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
function current_filter() { $c = $GLOBALS['__current_filter']; return empty( $c ) ? false : end( $c ); }
function do_action( $hook ) {
	$args = array_slice( func_get_args(), 1 );
	$GLOBALS['__current_filter'][] = $hook;
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); }
	array_pop( $GLOBALS['__current_filter'] );
}
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function sn_setting( $key, $default = '' ) { return $default; }

// The ledger layer's constants, as inc/ai-bootstrap.php defines them, then the
// two files the subscriber reads (the month key, the price table) and the file
// under test. Not the whole bootstrap: generate.php wants the AI client.
define( 'SN_AI_SPEND_ROLLUP_OPT', 'sn_ai_spend_month' );
define( 'SN_AI_SPEND_FEATURE_OPT', 'sn_ai_spend_month_feature' );
define( 'SN_AI_SPEND_MONTHS', 13 );
define( 'SN_AI_CACHE_WRITE_MULT', 1.25 );
define( 'SN_AI_CACHE_READ_MULT', 0.1 );
require __DIR__ . '/../inc/ai-bootstrap/spend.php';
require __DIR__ . '/../inc/ai-bootstrap/pricing.php';
require __DIR__ . '/../inc/openstation-compat.php'; // snt_os_compat_add_action + snt_os_compat_seen_once
require __DIR__ . '/../inc/ai-copilot-spend.php';

$turn = static function ( array $over = array() ) {
	return array_merge( array(
		'query'       => 'how many notes were published this month',
		'user_id'     => 1,
		'request_id'  => 'r1',
		'answer_type' => 'chat',
		'iterations'  => 1,
		'usage'       => array( 'prompt' => 1000, 'completion' => 500, 'total' => 1500 ),
		'model'       => array( 'id' => 'claude-sonnet-5', 'name' => 'Claude Sonnet 5' ),
	), $over );
};

// ── The subscriber is bound, and the reader exists.
ok( function_exists( 'snt_ai_copilot_spend_this_month' ), 'the Copilot month reader exists' );
ok( in_array( 'snt_ai_copilot_record_turn', $GLOBALS['__actions']['openstation_ai_search_completed'] ?? array(), true ), 'the subscriber is bound to openstation_ai_search_completed at load' );
ok( in_array( 'snt_ai_copilot_record_turn', $GLOBALS['__actions']['desktop_mode_ai_search_completed'] ?? array(), true ), 'the subscriber is bound to desktop_mode_ai_search_completed too: the v0.9.8 name, dual-registered like the rest of the table' );
ok( 0.0 === snt_ai_copilot_spend_this_month(), 'before any turn the reader is a recorded 0.0' );
ok( 0 === snt_ai_copilot_unpriced_this_month(), 'before any turn the unpriced count is 0' );

// ── 1. One priced turn: 1000 in at $3/M plus 500 out at $15/M.
do_action( 'openstation_ai_search_completed', $turn() );
ok( abs( snt_ai_copilot_spend_this_month() - 0.0105 ) < 1e-9, 'one Sonnet 5 turn reads 0.0105 (got ' . snt_ai_copilot_spend_this_month() . ')' );
ok( isset( $GLOBALS['__options'][ SN_AI_COPILOT_SPEND_OPT ][ snt_ai_spend_month_key() ] ), 'the figure lives in the Copilot option under this month\'s YYYY-MM key' );

// ── 2. The cap's ledger did not move.
ok( 0.0 === snt_ai_spend_this_month(), 'snt_ai_spend_this_month() is still 0.0: the cap never sees a Copilot turn' );
ok( array() === snt_ai_spend_this_month_by_feature(), 'the feature ledger is still empty: no Copilot row inside the buckets that sum to the cap\'s total' );
ok( ! isset( $GLOBALS['__options'][ SN_AI_SPEND_ROLLUP_OPT ] ) && ! isset( $GLOBALS['__options'][ SN_AI_SPEND_FEATURE_OPT ] ), 'neither ledger option was written' );

// ── 3. usage null adds nothing to the figure and one to the unpriced count.
do_action( 'openstation_ai_search_completed', $turn( array( 'usage' => null ) ) );
ok( abs( snt_ai_copilot_spend_this_month() - 0.0105 ) < 1e-9, 'a payload with usage null leaves the reader at 0.0105' );
ok( 1 === snt_ai_copilot_unpriced_this_month(), 'a payload with usage null counts as one unpriced turn' );
// The follow-up leg (search.php:1965) carries neither key at all, yet it ran the model.
do_action( 'openstation_ai_search_completed', array( 'query' => 'q', 'user_id' => 1, 'request_id' => 'r2', 'answer_type' => 'chat', 'iterations' => 1 ) );
ok( abs( snt_ai_copilot_spend_this_month() - 0.0105 ) < 1e-9, 'a payload with no usage and no model keys (the follow-up leg) leaves the reader at 0.0105' );
ok( 2 === snt_ai_copilot_unpriced_this_month(), 'the follow-up leg counts as an unpriced turn: it ran, it is not in the figure' );

// ── 4. An unpriced model is counted, never dropped: the station picks its
// model on its own side, so the first id the pricing table lacks must not
// read as no Ask AI this month.
do_action( 'openstation_ai_search_completed', $turn( array( 'model' => array( 'id' => 'some-model-nobody-priced', 'name' => 'x' ) ) ) );
ok( abs( snt_ai_copilot_spend_this_month() - 0.0105 ) < 1e-9, 'an unpriced model.id leaves the reader at 0.0105' );
ok( 3 === snt_ai_copilot_unpriced_this_month(), 'an unpriced model.id counts as an unpriced turn' );
do_action( 'openstation_ai_search_completed', $turn( array( 'model' => null ) ) );
ok( abs( snt_ai_copilot_spend_this_month() - 0.0105 ) < 1e-9, 'model null leaves the reader at 0.0105' );
ok( 4 === snt_ai_copilot_unpriced_this_month(), 'model null counts as an unpriced turn' );
do_action( 'openstation_ai_search_completed', $turn( array( 'usage' => array( 'prompt' => 0, 'completion' => 0, 'total' => 0 ) ) ) );
ok( 5 === snt_ai_copilot_unpriced_this_month(), 'a zero-token usage (the provider reported none) counts as an unpriced turn' );
do_action( 'openstation_ai_search_completed', 'not an array' );
ok( abs( snt_ai_copilot_spend_this_month() - 0.0105 ) < 1e-9 && 5 === snt_ai_copilot_unpriced_this_month(), 'a non-array payload is not a turn: neither the reader nor the count moves' );
ok( isset( $GLOBALS['__options'][ SN_AI_COPILOT_UNPRICED_OPT ][ snt_ai_spend_month_key() ] ) && 5 === $GLOBALS['__options'][ SN_AI_COPILOT_UNPRICED_OPT ][ snt_ai_spend_month_key() ], 'the count lives in its own YYYY-MM option as an int' );

// ── 5. The double-fire guard: the same payload dispatched under the old name
// right after the new one is a shim's shadow, not a second turn. A same-family
// repeat (two real turns with identical payloads) still counts every time.
$before = snt_ai_copilot_spend_this_month();
do_action( 'openstation_ai_search_completed', $turn( array( 'request_id' => 'shadow' ) ) );
do_action( 'desktop_mode_ai_search_completed', $turn( array( 'request_id' => 'shadow' ) ) );
ok( abs( snt_ai_copilot_spend_this_month() - ( $before + 0.0105 ) ) < 1e-9, 'the same payload under both hook names records one turn, not two' );
do_action( 'openstation_ai_search_completed', $turn( array( 'request_id' => 'shadow' ) ) );
ok( abs( snt_ai_copilot_spend_this_month() - ( $before + 0.021 ) ) < 1e-9, 'a same-family repeat of an identical payload is a second real turn and records again' );
snt_os_compat_reset_seen_once();
$GLOBALS['__options'][ SN_AI_COPILOT_SPEND_OPT ][ snt_ai_spend_month_key() ] = 0.0105;

// ── Turns accumulate; a second model prices at its own rate.
do_action( 'openstation_ai_search_completed', $turn( array( 'model' => array( 'id' => 'claude-haiku-4-5', 'name' => 'Haiku' ) ) ) );
ok( abs( snt_ai_copilot_spend_this_month() - 0.014 ) < 1e-9, 'a Haiku turn (1000 in at $1/M plus 500 out at $5/M) adds 0.0035, reading 0.014' );

// ── The window: older months are pruned to SN_AI_SPEND_MONTHS, this month kept.
// Synthetic keys, the sibling pin's shape (tests/ai-bootstrap.php): a seed
// walked back with strtotime( "-N months" ) collapses two steps onto one key
// whenever today's day-of-month overflows a shorter month (Sep 30 minus seven
// months is "Feb 30", which PHP reads as Mar 2), so the seed comes up short
// and the prune never runs on the 29th, 30th and 31st.
$roll = array();
for ( $i = 1; $i <= SN_AI_SPEND_MONTHS; $i++ ) {
	$roll[ sprintf( '2020-%02d', $i ) ] = 1.0; // 2020-01 .. 2020-13: 13 keys that sort below any real month
}
$GLOBALS['__options'][ SN_AI_COPILOT_SPEND_OPT ] = $roll;
do_action( 'openstation_ai_search_completed', $turn() );
$after = $GLOBALS['__options'][ SN_AI_COPILOT_SPEND_OPT ];
ok( SN_AI_SPEND_MONTHS === count( $after ) && isset( $after[ snt_ai_spend_month_key() ] ) && ! isset( $after['2020-01'] ), 'the option keeps SN_AI_SPEND_MONTHS buckets: the oldest month drops, this month stays' );
$GLOBALS['__options'][ SN_AI_COPILOT_UNPRICED_OPT ] = array_map( 'intval', $roll );
do_action( 'openstation_ai_search_completed', $turn( array( 'model' => null ) ) );
$after = $GLOBALS['__options'][ SN_AI_COPILOT_UNPRICED_OPT ];
ok( SN_AI_SPEND_MONTHS === count( $after ) && 1 === ( $after[ snt_ai_spend_month_key() ] ?? 0 ) && ! isset( $after['2020-01'] ), 'the unpriced count keeps the same window: the oldest month drops, this month counts one' );

// ── Shape damage reads as zero, never a fatal.
$GLOBALS['__options'][ SN_AI_COPILOT_SPEND_OPT ]    = 'garbage';
$GLOBALS['__options'][ SN_AI_COPILOT_UNPRICED_OPT ] = 'garbage';
ok( 0.0 === snt_ai_copilot_spend_this_month(), 'a non-array option reads 0.0' );
ok( 0 === snt_ai_copilot_unpriced_this_month(), 'a non-array unpriced option reads 0' );
do_action( 'openstation_ai_search_completed', $turn() );
ok( abs( snt_ai_copilot_spend_this_month() - 0.0105 ) < 1e-9, 'the next turn rebuilds the option from the damaged value' );
do_action( 'openstation_ai_search_completed', $turn( array( 'model' => null ) ) );
ok( 1 === snt_ai_copilot_unpriced_this_month(), 'the next unpriced turn rebuilds the count from the damaged value' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

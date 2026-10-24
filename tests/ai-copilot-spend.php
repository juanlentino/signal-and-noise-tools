<?php
/**
 * Standalone fixture tests for inc/ai-copilot-spend.php: the Copilot (Ask AI),
 * Agents and Other AI month spend, buckets beside the cap (#1597, item 16).
 *
 * Core 7.0 fires `wp_ai_client_after_generate_result` after EVERY AI Client
 * call with the result: the call's own token usage and the model actually
 * served. The recorder prices each call with snt_ai_estimate_cost() into a
 * YYYY-MM option of its own, or counts it as unpriced. Attribution rides
 * OpenStation's `openstation_ai_model_config` filter, which names the
 * `source` right before the station generates; a call nothing names lands
 * in the Other bucket, never dropped. The plugin's own calls through
 * snt_ai_generate_with_constraints() are already in the cap's ledger, so the
 * recorder stands down for them. The cap's ledger never moves for a call
 * the recorder prices.
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

// ── WP stubs: real action and filter tables (the attribution test needs
// `$context` to reach the filter callback), an in-memory option store.
$GLOBALS['__actions'] = array();
$GLOBALS['__filters'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
function do_action( $hook ) {
	$args = array_slice( func_get_args(), 1 );
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); }
}
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 1 );
	foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $args[0] = call_user_func_array( $cb, $args ); }
	return $args[0];
}
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function sn_setting( $key, $default = '' ) { return $default; }
function __( $s, $d = null ) { return $s; }
class WP_Error {
	public function __construct( $c = '', $m = '', $d = array() ) {}
	public function get_error_message() { return 'e'; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function snt_ai_is_available() { return true; }

// ── The SDK shapes the recorder reads, nothing more.
class T_Usage {
	public $p;
	public $c;
	public function __construct( $p, $c ) { $this->p = $p; $this->c = $c; }
	public function getPromptTokens() { return $this->p; }
	public function getCompletionTokens() { return $this->c; }
	public function getTotalTokens() { return $this->p + $this->c; }
}
class T_Meta {
	public $id;
	public function __construct( $id ) { $this->id = $id; }
	public function getId() { return $this->id; }
}
class T_Result {
	public $u;
	public $m;
	public function __construct( $p, $c, $id ) { $this->u = new T_Usage( $p, $c ); $this->m = new T_Meta( $id ); }
	public function getTokenUsage() { return $this->u; }
	public function getModelMetadata() { return $this->m; }
	public function toText() { return 'ok'; }
}
class T_Event {
	public $r;
	public function __construct( $r ) { $this->r = $r; }
	public function getResult() { return $this->r; }
}
// What PromptBuilder::generateResult() does: Before, the provider call, After.
$GLOBALS['__gen'] = array( 1000, 500, 'claude-sonnet-4-6' ); // $3/$15: 1000 in plus 500 out reads 0.0105.
$sdk_call = static function () {
	do_action( 'wp_ai_client_before_generate_result', new stdClass() );
	$r = new T_Result( ...$GLOBALS['__gen'] );
	do_action( 'wp_ai_client_after_generate_result', new T_Event( $r ) );
	return $r;
};
// The plugin's own generation path, through the real helper.
class T_Builder {
	public function __call( $name, $args ) { return 'generate_text_result' === $name ? ( $GLOBALS['__sdk_call'] )() : $this; }
}
$GLOBALS['__sdk_call'] = $sdk_call;
function wp_ai_client_prompt( $p = null ) { return new T_Builder(); }
// OpenStation's openstation_ai_client_generate(): the filter names the source, then generate_result().
$station = static function ( $source ) use ( $sdk_call ) {
	apply_filters( 'openstation_ai_model_config', array(), array( 'user_id' => 1, 'request_id' => 'r', 'source' => $source, 'has_tools' => true, 'has_schema' => false ) );
	return $sdk_call();
};

define( 'SN_AI_SPEND_ROLLUP_OPT', 'sn_ai_spend_month' );
define( 'SN_AI_SPEND_FEATURE_OPT', 'sn_ai_spend_month_feature' );
define( 'SN_AI_SPEND_MONTHS', 13 );
define( 'SN_AI_CACHE_WRITE_MULT', 1.25 );
define( 'SN_AI_CACHE_READ_MULT', 0.1 );
define( 'SN_AI_USAGE_LOG_OPT', 'sn_ai_usage_log' );
define( 'SN_AI_USAGE_LOG_CAP', 200 );
define( 'SN_AI_DEFAULT_MODEL', 'claude-sonnet-4-6' );
define( 'SN_AI_FALLBACK_MODEL', 'claude-sonnet-5' );
require __DIR__ . '/../inc/ai-bootstrap/spend.php';
require __DIR__ . '/../inc/ai-bootstrap/pricing.php';
require __DIR__ . '/../inc/ai-bootstrap/usage-log.php';
require __DIR__ . '/../inc/ai-bootstrap/generate.php';
require __DIR__ . '/../inc/ai-copilot-spend.php';

$read = static function ( $fn ) { return function_exists( $fn ) ? $fn() : null; };
$near = static function ( $a, $b ) { return null !== $a && abs( (float) $a - $b ) < 1e-9; };
$reset = static function () { $GLOBALS['__options'] = array(); $GLOBALS['__gen'] = array( 1000, 500, 'claude-sonnet-4-6' ); };

// ── The recorder rides core's event, and the station's completion hooks are gone.
ok( in_array( 'snt_ai_spend_record_call', $GLOBALS['__actions']['wp_ai_client_after_generate_result'] ?? array(), true ), 'the recorder is bound to core wp_ai_client_after_generate_result' );
ok( in_array( 'snt_ai_spend_note_source', $GLOBALS['__filters']['openstation_ai_model_config'] ?? array(), true ), 'attribution reads the source off openstation_ai_model_config' );
ok( empty( $GLOBALS['__actions']['openstation_ai_search_completed'] ) && empty( $GLOBALS['__actions']['desktop_mode_ai_search_completed'] ), 'nothing in this file listens to the station search-completed hook any more' );
ok( empty( $GLOBALS['__actions']['openstation_agent_completed'] ) && empty( $GLOBALS['__actions']['desktop_mode_agent_completed'] ), 'nothing in this file listens to the station agent-completed hook any more' );
ok( array( 'max_tokens' => 9 ) === apply_filters( 'openstation_ai_model_config', array( 'max_tokens' => 9 ), array( 'source' => 'agents/runner' ) ), 'the attribution filter hands the config back untouched' );
$reset();
ok( 0.0 === $read( 'snt_ai_copilot_spend_this_month' ) && 0.0 === $read( 'snt_ai_agents_spend_this_month' ) && 0.0 === $read( 'snt_ai_other_spend_this_month' ), 'before any call the three readers are a recorded 0.0' );

// ── 1. A Copilot call prices into Copilot; the cap's ledger does not move.
$station( 'ai-copilot/search' );
ok( $near( $read( 'snt_ai_copilot_spend_this_month' ), 0.0105 ), 'one Sonnet 4.6 Copilot call reads 0.0105 (got ' . var_export( $read( 'snt_ai_copilot_spend_this_month' ), true ) . ')' );
ok( 0.0 === snt_ai_spend_this_month() && array() === snt_ai_spend_this_month_by_feature(), 'the cap ledger did not move for a Copilot call' );
$station( 'ai-copilot/followup' );
ok( $near( $read( 'snt_ai_copilot_spend_this_month' ), 0.021 ), 'the follow-up leg is Copilot too, and now it is priced: it reports its own usage' );
// A run that switches models prices every call at its own rate, not the last one's.
$GLOBALS['__gen'] = array( 1000, 500, 'claude-haiku-4-5' );
$station( 'ai-copilot/search' );
ok( $near( $read( 'snt_ai_copilot_spend_this_month' ), 0.0245 ), 'a Haiku call in the same run adds 0.0035 at the Haiku rate, reading 0.0245' );

// ── 2. An agent-runner call prices into Agents, and only there.
$reset();
$station( 'agents/runner' );
ok( $near( $read( 'snt_ai_agents_spend_this_month' ), 0.0105 ), 'agents: one runner call reads 0.0105' );
ok( 0.0 === $read( 'snt_ai_copilot_spend_this_month' ) && 0.0 === $read( 'snt_ai_other_spend_this_month' ), 'agents: neither Copilot nor Other moved' );

// ── 3. Anything nothing names is Other, never dropped: the AI plugin's
// features, and the station sources outside Copilot and the runner.
$reset();
$sdk_call();
ok( $near( $read( 'snt_ai_other_spend_this_month' ), 0.0105 ), 'other: an unlabeled call (the AI plugin) lands in Other' );
foreach ( array( 'agents/draft', 'mio/window', 'widgets/drafts-suggestions', 'ai-copilot/comment-analysis' ) as $src ) { $station( $src ); }
ok( $near( $read( 'snt_ai_other_spend_this_month' ), 0.0525 ), 'other: the four station sources outside Copilot and the runner land in Other too' );
ok( 0.0 === $read( 'snt_ai_copilot_spend_this_month' ) && 0.0 === $read( 'snt_ai_agents_spend_this_month' ), 'other: Copilot and Agents did not move' );

// ── 4. A labeled call that fails inside the provider (Before fired, After did
// not) must not lend its label to the next, unlabeled call.
$reset();
apply_filters( 'openstation_ai_model_config', array(), array( 'source' => 'ai-copilot/search' ) );
do_action( 'wp_ai_client_before_generate_result', new stdClass() ); // the provider throws here
$sdk_call(); // then the AI plugin generates in the same request
ok( 0.0 === $read( 'snt_ai_copilot_spend_this_month' ) && $near( $read( 'snt_ai_other_spend_this_month' ), 0.0105 ), 'a failed Copilot call does not leak its label: the next unlabeled call is Other' );

// ── 5. The plugin's own calls are in the cap's ledger already: no second record.
$reset();
$text = snt_ai_generate_with_constraints( 'p', 's', 64, 'drafts' );
ok( 'ok' === $text && $near( snt_ai_spend_this_month(), 0.0105 ), 'own: the helper priced its call into the cap ledger' );
ok( 0.0 === $read( 'snt_ai_other_spend_this_month' ) && 0.0 === $read( 'snt_ai_copilot_spend_this_month' ), 'own: the recorder stood down, so the call is not counted twice' );
ok( function_exists( 'snt_ai_own_call_depth' ) && 0 === snt_ai_own_call_depth(), 'own: the depth counter is back to zero after the call' );
$sdk_call();
ok( $near( $read( 'snt_ai_other_spend_this_month' ), 0.0105 ), 'own: the next foreign call is recorded again' );

// ── 6. An unpriced call is counted, never a silent $0.00.
$reset();
$GLOBALS['__gen'] = array( 1000, 500, 'some-model-nobody-priced' );
$station( 'ai-copilot/search' );
$station( 'agents/runner' );
$sdk_call();
$GLOBALS['__gen'] = array( 0, 0, 'claude-sonnet-4-6' );
$station( 'ai-copilot/search' );
ok( 2 === $read( 'snt_ai_copilot_unpriced_this_month' ) && 0.0 === $read( 'snt_ai_copilot_spend_this_month' ), 'copilot: an unpriced id and a zero-token call each count as unpriced' );
ok( 1 === $read( 'snt_ai_agents_unpriced_this_month' ) && 1 === $read( 'snt_ai_other_unpriced_this_month' ), 'agents and other count their unpriced calls in their own options' );
do_action( 'wp_ai_client_after_generate_result', 'not an event' );
ok( 1 === $read( 'snt_ai_other_unpriced_this_month' ), 'a value that is not an event moves nothing' );
ok( 2 === ( $GLOBALS['__options'][ SN_AI_COPILOT_UNPRICED_OPT ][ snt_ai_spend_month_key() ] ?? null ), 'the count lives in its own YYYY-MM option as an int' );

// ── 7. The window: older months are pruned to SN_AI_SPEND_MONTHS. Synthetic
// 2020-NN keys sort below any real month (a strtotime walk-back collapses
// two steps onto one key on the 29th, 30th and 31st).
$reset();
$roll = array();
for ( $i = 1; $i <= SN_AI_SPEND_MONTHS; $i++ ) { $roll[ sprintf( '2020-%02d', $i ) ] = 1.0; }
$GLOBALS['__options'][ SN_AI_COPILOT_SPEND_OPT ] = $roll;
$station( 'ai-copilot/search' );
$after = $GLOBALS['__options'][ SN_AI_COPILOT_SPEND_OPT ];
ok( SN_AI_SPEND_MONTHS === count( $after ) && isset( $after[ snt_ai_spend_month_key() ] ) && ! isset( $after['2020-01'] ), 'the option keeps SN_AI_SPEND_MONTHS buckets: the oldest month drops, this month stays' );

// ── 8. Shape damage reads as zero and the next call rebuilds it.
$GLOBALS['__options']['sn_ai_other_spend_month'] = 'garbage';
ok( 0.0 === $read( 'snt_ai_other_spend_this_month' ), 'a non-array option reads 0.0' );
$sdk_call();
ok( $near( $read( 'snt_ai_other_spend_this_month' ), 0.0105 ), 'the next call rebuilds the option from the damaged value' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

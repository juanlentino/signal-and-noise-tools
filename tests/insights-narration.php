<?php
/**
 * Standalone fixture tests for inc/insights-narration.php (weekly digest).
 *
 * Covers prose parsing (valid / fenced / invalid / caps), the cookieless guard
 * in the system instruction, graceful edge/machine inclusion, run() caching +
 * force bypass + feature tagging, and the self-healing cron schedule.
 *
 * Run: php tests/insights-narration.php
 * @since plugin v6.30.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'Test Site'; } }

// ── settings store ──
$GLOBALS['__settings'] = array( 'insights.narration_enabled' => false );
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $key, $default = null ) { return $GLOBALS['__settings'][ $key ] ?? $default; }
}

// ── transient store ──
$GLOBALS['__transients'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; } }

// ── cron store ──
$GLOBALS['__cron'] = array();
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h ) { return $GLOBALS['__cron'][ $h ] ?? false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $ts, $rec, $h ) { $GLOBALS['__cron'][ $h ] = $ts; return true; } }
if ( ! function_exists( 'wp_unschedule_event' ) ) { function wp_unschedule_event( $ts, $h ) { unset( $GLOBALS['__cron'][ $h ] ); return true; } }

// ── analytics reader stubs (canned shapes mirror the real accessors) ──
$GLOBALS['__edge_pageviews'] = 0; // 0 => machine block omitted (unconfigured/graceful)
if ( ! function_exists( 'sn_analytics_range_totals' ) ) {
	function sn_analytics_range_totals( $f, $t, $c = 'human' ) { return array( 'views' => 1430, 'visits' => 880, 'scroll_avg' => 62.0, 'time_avg' => 41.0 ); }
}
if ( ! function_exists( 'sn_analytics_period_deltas' ) ) {
	function sn_analytics_period_deltas( $f, $t, $c = 'human' ) { return array( 'views' => array( 'current' => 1430, 'previous' => 1280, 'pct' => 12, 'dir' => 'up' ) ); }
}
if ( ! function_exists( 'sn_analytics_engaged_rate_delta' ) ) {
	function sn_analytics_engaged_rate_delta( $f, $t, $c = 'human' ) { return array( 'current' => 48, 'previous' => 44, 'pct' => 9, 'dir' => 'up' ); }
}
if ( ! function_exists( 'sn_analytics_top_paths' ) ) {
	function sn_analytics_top_paths( $f, $t, $c = 'human', $l = 25 ) { return array( array( 'path' => '/notes/x', 'views' => 420, 'visits' => 300, 'scroll_avg' => 70, 'time_avg' => 55 ) ); }
}
if ( ! function_exists( 'sn_analytics_top_sources' ) ) {
	function sn_analytics_top_sources( $f, $t, $c = 'human', $l = 10 ) { return array( array( 'value' => 'Hacker News', 'views' => 210, 'visits' => 180, 'hosts' => array( 'news.ycombinator.com' ) ) ); }
}
if ( ! function_exists( 'sn_analytics_top_events' ) ) {
	function sn_analytics_top_events( $f, $t, $l = 25 ) { return array( array( 'name' => 'RSS Feed Request', 'events' => 90, 'visitors' => 40 ) ); }
}
if ( ! function_exists( 'sn_edge_range_totals' ) ) {
	function sn_edge_range_totals( $f, $t ) { return array( 'page_views' => $GLOBALS['__edge_pageviews'], 'threats' => 3 ); }
}
if ( ! function_exists( 'sn_edge_machine_split' ) ) {
	function sn_edge_machine_split( $f, $t ) {
		$edge    = (int) $GLOBALS['__edge_pageviews'];
		$human   = 1430;
		$machine = max( 0, $edge - $human );
		return array( 'edge' => $edge, 'human' => $human, 'machine' => $machine, 'machine_pct' => $edge > 0 ? (int) round( $machine / $edge * 100 ) : 0 );
	}
}

// ── CWV distribution stub (durable buckets reader, v7.2.0). All-zero views ⇒ block omitted. ──
$GLOBALS['__cwv_views'] = array( 'lcp' => array( 0, 0, 0 ), 'inp' => array( 0, 0, 0 ), 'cls' => array( 0, 0, 0 ) );
if ( ! function_exists( 'sn_analytics_distribution' ) ) {
	function sn_analytics_distribution( $metric, $f, $t, $c = 'human' ) {
		$v = $GLOBALS['__cwv_views'][ $metric ] ?? array( 0, 0, 0 );
		return array(
			array( 'label' => 'Good',       'views' => $v[0] ),
			array( 'label' => 'Needs work', 'views' => $v[1] ),
			array( 'label' => 'Poor',       'views' => $v[2] ),
		);
	}
}

// ── login-guard + audit stubs (value-toggled, v7.2.0) ──
$GLOBALS['__lg_headline'] = array( 'configured' => false, 'checked' => 0, 'blocked' => 0, 'block_rate' => 0, 'top_network' => '' );
if ( ! function_exists( 'sn_login_defense_headline' ) ) {
	function sn_login_defense_headline() { return $GLOBALS['__lg_headline']; }
}
$GLOBALS['__lg_top_country'] = array();
if ( ! function_exists( 'sn_login_defense_top_country_sql' ) ) {
	function sn_login_defense_top_country_sql( $d = 30, $l = 10 ) { return 'SQL'; }
}
if ( ! function_exists( 'sn_analytics_query' ) ) {
	function sn_analytics_query( $sql ) { return $GLOBALS['__lg_top_country']; }
}
$GLOBALS['__audit_summary'] = array( 'last_7d_vs_prior' => array( 'current' => 0, 'prior' => 0, 'pct_delta' => 0 ) );
if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
	function snt_audit_get_summary_impl() { return $GLOBALS['__audit_summary']; }
}

// ── AI wrapper stub (records call count + tag; returns configurable response) ──
$GLOBALS['__ai_calls']    = 0;
$GLOBALS['__ai_response'] = '{"headline":"Views up 12% to 1,430","paragraphs":["Traffic rose this week.","/notes/x led with 420 views."],"highlights":["views +12% to 1,430","top source: Hacker News (210)"]}';
if ( ! function_exists( 'snt_ai_generate_with_constraints' ) ) {
	function snt_ai_generate_with_constraints( $prompt, $system, $max = 256, $feature = 'generic' ) {
		$GLOBALS['__ai_calls']++;
		$GLOBALS['__last_ai_feature'] = $feature;
		$GLOBALS['__last_ai_max']     = $max;
		return $GLOBALS['__ai_response'];
	}
}

require_once __DIR__ . '/../inc/insights-narration.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function eq( $e, $a, $m ) { global $pass, $fail; if ( $e === $a ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; } }

// ── Test 1: parse valid ──
echo "\nTest 1: parse valid JSON\n";
$p = snt_narration_parse_response( '{"headline":"H","paragraphs":["a","b"],"highlights":["x"]}' );
ok( is_array( $p ), 'returns array' );
eq( 'H', $p['headline'], 'headline parsed' );
eq( 2, count( $p['paragraphs'] ), '2 paragraphs' );
eq( 1, count( $p['highlights'] ), '1 highlight' );

// ── Test 2: fenced JSON ──
echo "\nTest 2: fenced JSON stripped\n";
$p = snt_narration_parse_response( "```json\n{\"headline\":\"H\",\"paragraphs\":[\"a\"],\"highlights\":[]}\n```" );
ok( is_array( $p ), 'fenced JSON parsed' );
eq( 'H', $p['headline'] ?? null, 'headline from fenced' );
eq( 0, count( $p['highlights'] ), 'empty highlights allowed' );

// ── Test 3: invalid JSON ──
echo "\nTest 3: invalid JSON → WP_Error\n";
ok( is_wp_error( snt_narration_parse_response( 'not json' ) ), 'non-JSON → WP_Error' );
ok( is_wp_error( snt_narration_parse_response( '"a string"' ) ), 'JSON string (non-array) → WP_Error' );

// ── Test 4: missing headline ──
echo "\nTest 4: missing headline → WP_Error\n";
$e = snt_narration_parse_response( '{"paragraphs":["a"]}' );
ok( is_wp_error( $e ), 'no headline → WP_Error' );
eq( 'snt_narration_no_headline', $e->get_error_code(), 'error code' );

// ── Test 5: no paragraphs ──
echo "\nTest 5: empty paragraphs → WP_Error\n";
$e = snt_narration_parse_response( '{"headline":"H","paragraphs":[]}' );
ok( is_wp_error( $e ), 'empty paragraphs → WP_Error' );
eq( 'snt_narration_no_body', $e->get_error_code(), 'error code' );

// ── Test 6: caps ──
echo "\nTest 6: paragraphs <=4, highlights <=6, headline <=120\n";
$big = '{"headline":"' . str_repeat( 'A', 200 ) . '","paragraphs":["a","b","c","d","e","f"],"highlights":["1","2","3","4","5","6","7","8"]}';
$p   = snt_narration_parse_response( $big );
eq( 120, strlen( $p['headline'] ), 'headline truncated to 120' );
eq( 4, count( $p['paragraphs'] ), 'paragraphs capped at 4' );
eq( 6, count( $p['highlights'] ), 'highlights capped at 6' );

// ── Test 7: cookieless guard present ──
echo "\nTest 7: system instruction carries the cookieless guard\n";
$sys = snt_narration_system_instruction();
ok( false !== stripos( $sys, 'COOKIELESS' ), 'mentions COOKIELESS' );
ok( false !== stripos( $sys, 'never infer or mention sessions' ), 'forbids inferring sessions/journeys' );
ok( false !== stripos( $sys, 'Output JSON only' ), 'JSON-only instruction present' );

// ── Test 8: collect_signals machine block graceful ──
echo "\nTest 8: machine block omitted when edge idle, present when it saw hits\n";
$GLOBALS['__edge_pageviews'] = 0;
$s = snt_narration_collect_signals();
ok( ! isset( $s['machine'] ), 'no machine block when edge page_views = 0 (unconfigured/graceful)' );
ok( isset( $s['totals'], $s['deltas'], $s['top_paths'], $s['top_sources'] ), 'human analytics present' );
$GLOBALS['__edge_pageviews'] = 3000; // > human (1430) → machine split present
$s = snt_narration_collect_signals();
ok( isset( $s['machine'] ), 'machine block present when edge saw hits' );
eq( 3, $s['machine']['threats_blocked'] ?? null, 'threats surfaced when > 0' );

// ── Test 9: run caches + force bypass + feature tag + max_tokens ──
echo "\nTest 9: run() caches, serves cache without force, force re-calls\n";
$GLOBALS['__transients'] = array();
$GLOBALS['__ai_calls']   = 0;
$r1 = snt_narration_run( false );
ok( is_array( $r1 ) && isset( $r1['headline'] ), 'first run returns a digest' );
eq( 'insights_narration', $GLOBALS['__last_ai_feature'], 'AI call tagged insights_narration' );
eq( SN_NARRATION_MAX_TOKENS, $GLOBALS['__last_ai_max'], 'max_tokens = SN_NARRATION_MAX_TOKENS (512)' );
eq( 1, $GLOBALS['__ai_calls'], 'one AI call on first run' );
$r2 = snt_narration_run( false );
eq( 1, $GLOBALS['__ai_calls'], 'second run (no force) served from cache — no AI call' );
$r3 = snt_narration_run( true );
eq( 2, $GLOBALS['__ai_calls'], 'force=true bypasses cache → AI call' );
ok( isset( $r1['generated_at'], $r1['elapsed_ms'] ), 'result carries generated_at + elapsed_ms' );

// ── Test 10: parse failure propagates uncached ──
echo "\nTest 10: AI junk → WP_Error, not cached\n";
$GLOBALS['__transients'] = array();
$GLOBALS['__ai_calls']   = 0;
$GLOBALS['__ai_response'] = 'totally not json';
$r = snt_narration_run( true );
ok( is_wp_error( $r ), 'invalid AI output → WP_Error' );
ok( false === get_transient( SN_NARRATION_CACHE_KEY ), 'failed digest is NOT cached' );
$GLOBALS['__ai_response'] = '{"headline":"H","paragraphs":["a"],"highlights":[]}'; // restore

// ── Test 11: cron self-heal schedule/unschedule ──
echo "\nTest 11: maybe_schedule_cron self-heals on the narration toggle\n";
$GLOBALS['__cron'] = array();
$GLOBALS['__settings']['insights.narration_enabled'] = false;
snt_narration_maybe_schedule_cron();
ok( false === wp_next_scheduled( SN_NARRATION_CRON_HOOK ), 'not scheduled when disabled' );
$GLOBALS['__settings']['insights.narration_enabled'] = true;
snt_narration_maybe_schedule_cron();
ok( false !== wp_next_scheduled( SN_NARRATION_CRON_HOOK ), 'scheduled when enabled' );
snt_narration_maybe_schedule_cron(); // idempotent
ok( false !== wp_next_scheduled( SN_NARRATION_CRON_HOOK ), 'idempotent — still scheduled, no duplicate' );
$GLOBALS['__settings']['insights.narration_enabled'] = false;
snt_narration_maybe_schedule_cron();
ok( false === wp_next_scheduled( SN_NARRATION_CRON_HOOK ), 'unscheduled when toggled off' );

// ── Test: cwv block (v7.2.0) — present with data, omitted without ──
echo "\nTest: cwv signal block\n";
$GLOBALS['__cwv_views'] = array( 'lcp' => array( 80, 15, 5 ), 'inp' => array( 90, 8, 2 ), 'cls' => array( 100, 0, 0 ) );
$sig = snt_narration_collect_signals();
ok( isset( $sig['cwv']['lcp'] ), 'cwv block present when vitals rows exist' );
eq( 80, $sig['cwv']['lcp']['good_pct'] ?? -1, 'cwv lcp good_pct computed' );
eq( 100, $sig['cwv']['lcp']['samples'] ?? -1, 'cwv lcp samples summed' );
eq( 0, $sig['cwv']['cls']['poor_pct'] ?? -1, 'cwv cls poor_pct zero' );

$GLOBALS['__cwv_views'] = array( 'lcp' => array( 0, 0, 0 ), 'inp' => array( 0, 0, 0 ), 'cls' => array( 0, 0, 0 ) );
$sig = snt_narration_collect_signals();
ok( ! isset( $sig['cwv'] ), 'cwv block omitted when zero vitals' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

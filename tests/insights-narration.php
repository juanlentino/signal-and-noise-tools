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
	public function get_error_data() { return $this->data; }
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
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; } }

// ── cron store ──
$GLOBALS['__cron'] = array();
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h ) { return $GLOBALS['__cron'][ $h ] ?? false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $ts, $rec, $h ) { $GLOBALS['__cron'][ $h ] = $ts; return true; } }
if ( ! function_exists( 'wp_unschedule_event' ) ) { function wp_unschedule_event( $ts, $h ) { unset( $GLOBALS['__cron'][ $h ] ); return true; } }
// v9.51.2: single-event scheduler recorder (the existing single-arg
// wp_next_scheduled above reads $__cron, which the schedule tests drive
// directly to simulate "already queued") + http_request_timeout filter recorder.
$GLOBALS['__single'] = array();
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $h, $args = array() ) { $GLOBALS['__single'][] = array( $h, $args, $ts ); return true; }
}
$GLOBALS['__filters_added']   = array();
$GLOBALS['__filters_removed'] = array();
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters_added'][] = array( $h, $cb ); return true; } }
if ( ! function_exists( 'remove_filter' ) ) { function remove_filter( $h, $cb, $p = 10 ) { $GLOBALS['__filters_removed'][] = array( $h, $cb ); return true; } }

// ── analytics reader stubs (canned shapes mirror the real accessors) ──
$GLOBALS['__edge_pageviews'] = 0; // 0 => machine block omitted (unconfigured/graceful)
if ( ! function_exists( 'sn_analytics_range_totals' ) ) {
	// Mirrors the REAL post-v9.63.0 merged shape (inc/analytics-read.php @return):
	// legacy quartet + every derive-layer honest field + exact_metrics_since. A
	// legacy-quartet-only stub here would green a payload that silently dropped
	// the honest vocabulary (the stub-drift trap).
	function sn_analytics_range_totals( $f, $t, $c = 'human' ) {
		return array(
			'views' => 1430, 'visits' => 880, 'scroll_avg' => 62.0, 'time_avg' => 41.0,
			'unique_visitor_days' => 880, 'pageview_visits' => 800, 'viewless_visits' => 80,
			'view_visit_ratio' => 1.7875, 'pageviews_per_visitor_day' => 1.625,
			'scroll_avg_per_view' => 58.0, 'time_avg_per_view' => 39.0,
			'scroll_avg_per_visit' => 52.0, 'time_avg_per_visit' => 35.0,
			'integrity_violation' => false, 'exact_metrics_since' => '2026-07-01',
		);
	}
}
if ( ! function_exists( 'sn_analytics_period_deltas' ) ) {
	function sn_analytics_period_deltas( $f, $t, $c = 'human' ) {
		return array(
			'views'           => array( 'current' => 1430, 'previous' => 1280, 'pct' => 12, 'dir' => 'up' ),
			'visits'          => array( 'current' => 880, 'previous' => 840, 'pct' => 5, 'dir' => 'up' ),
			'pageview_visits' => array( 'current' => 800, 'previous' => 760, 'pct' => 5, 'dir' => 'up' ),
		);
	}
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

require_once __DIR__ . '/../inc/ai-markdown-strip.php'; // real shared stripper (v9.64.2) — the parse boundary calls it
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
ok( false !== stripos( $sys, 'new-vs-returning' ), 'forbids new-vs-returning / cross-day identity' );
ok( false !== stripos( $sys, 'Output JSON only' ), 'JSON-only instruction present' );

// ── Test 7b: honest vocabulary (v9.64.1) — payload carries the fields, the
// instruction defines them, and the structural gap is never "unexplained" ──
echo "\nTest 7b: honest-vocabulary payload keys + instruction rules (v9.64.1)\n";
$sig7b = snt_narration_collect_signals();
ok( array_key_exists( 'pageview_visits', $sig7b['totals'] ), 'totals payload carries pageview_visits (the gated headline visits)' );
ok( array_key_exists( 'unique_visitor_days', $sig7b['totals'] ), 'totals payload carries unique_visitor_days' );
ok( array_key_exists( 'viewless_visits', $sig7b['totals'] ), 'totals payload carries viewless_visits' );
ok( array_key_exists( 'integrity_violation', $sig7b['totals'] ), 'totals payload carries the integrity_violation flag' );
eq( 800, $sig7b['totals']['pageview_visits'], 'pageview_visits passes through un-mangled' );
eq( 80, $sig7b['totals']['viewless_visits'], 'viewless_visits passes through un-mangled' );
$sys7b = snt_narration_system_instruction();
ok( false !== strpos( $sys7b, 'pageview_visits' ), 'instruction defines pageview_visits' );
ok( false !== strpos( $sys7b, 'viewless_visits' ), 'instruction defines viewless_visits' );
ok( false !== stripos( $sys7b, 'visitor-days' ), 'instruction speaks the visitor-day vocabulary' );
ok( false !== stripos( $sys7b, 'structural' ), 'instruction names the views-vs-visitor-days gap STRUCTURAL' );
ok( false !== strpos( $sys7b, 'NEVER call it unusual, unexplained, or an anomaly' ), 'instruction forbids the "unexplained anomaly" framing for the structural gap' );
ok( false !== strpos( $sys7b, 'integrity_violation' ), 'instruction keeps a genuine-anomaly branch ONLY for integrity_violation' );
ok( false !== stripos( $sys7b, 'never call' ) || false !== stripos( $sys7b, 'never "visits"' ), 'instruction forbids calling visitor-days "visits"' );

// ── Test 7c: v9.64.2 plain-prose voice contract in the instruction ──
echo "\nTest 7c: v9.64.2 voice contract pins (P1a + P2)\n";
$sys7c = snt_narration_system_instruction();
ok( false !== strpos( $sys7c, 'never write sigma, σ, backtest, interval, robust, confidence, or point estimate' ), 'instruction bans the stats-appendix jargon (P2)' );
ok( false !== strpos( $sys7c, 'NO MARKDOWN' ), 'instruction forbids markdown (P1a)' );
ok( false !== strpos( $sys7c, 'no asterisks, no underscores' ), 'markdown ban names the emphasis marks' );
ok( false !== strpos( $sys7c, 'no emojis' ), 'markdown ban covers emojis' );
ok( false !== strpos( $sys7c, 'at most 4-5 short plain-English sentences' ), 'sentence budget for a phone-glance summary' );
ok( false !== strpos( $sys7c, '"Worth a look:"' ), 'the optional Worth-a-look closer' );
ok( false !== strpos( $sys7c, 'State numbers plainly (47 views, 40 visits)' ), 'numbers stated plainly' );
ok( false !== strpos( $sys7c, '("expect a quiet week")' ) && false !== strpos( $sys7c, 'never as numbers with intervals' ), 'forecast rule: plain words only, never numbers-with-intervals' );
ok( false !== strpos( $sys7c, 'at most one plain sentence' ), 'a genuine anomaly gets one plain sentence, max' );

// ── Test 7d: v9.64.2 markdown stripped at the parse boundary (P1b) ──
echo "\nTest 7d: markdown marks REMOVED (not escaped) from parsed digest strings\n";
$mdp = snt_narration_parse_response( '{"headline":"**Weekly Analytics Digest**","paragraphs":["## Head","Views rose to *47*.","2 * 3 stays"],"highlights":["25 × 4 stays"]}' );
ok( is_array( $mdp ), 'markdown-laden digest still parses' );
eq( 'Weekly Analytics Digest', $mdp['headline'] ?? null, 'headline: ** marks removed, text kept (the live render bug)' );
eq( 'Head', $mdp['paragraphs'][0] ?? null, 'paragraph: heading marker removed' );
eq( 'Views rose to 47.', $mdp['paragraphs'][1] ?? null, 'paragraph: italic marks removed, number kept' );
eq( '2 * 3 stays', $mdp['paragraphs'][2] ?? null, 'paragraph: spaced-asterisk arithmetic untouched' );
eq( '25 × 4 stays', $mdp['highlights'][0] ?? null, 'highlight: multiplication sign untouched' );

// ── Test 7e: v9.64.2 cache key versioned — the stored pre-voice digest is orphaned (P3) ──
echo "\nTest 7e: narration cache key versioned (P3)\n";
eq( 'sn_insights_narration_v2', SN_NARRATION_CACHE_KEY, 'fixed cache key bumped to _v2 — a pre-voice cached digest can never be served again' );

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
eq( SN_NARRATION_MAX_TOKENS, $GLOBALS['__last_ai_max'], 'max_tokens = SN_NARRATION_MAX_TOKENS (1024)' );
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

// ── Test 11: v9.5.0 (R2) — the weekly-digest scheduler was retired ──
// The narrator CORE (run/last/parse/collect) stays for the two narration abilities;
// only the opt-in weekly cron + its enabled-gate leave. Assert the scheduling surface
// is gone and the ability-facing core survives.
echo "\nTest 11: the weekly-digest cron scheduling is retired (R2)\n";
ok( ! function_exists( 'snt_narration_maybe_schedule_cron' ), 'R2: maybe_schedule_cron removed' );
ok( ! function_exists( 'snt_narration_unschedule_cron' ), 'R2: unschedule_cron removed' );
ok( ! function_exists( 'snt_narration_weekly_cron_cb' ), 'R2: weekly_cron_cb removed' );
ok( ! function_exists( 'snt_narration_enabled' ), 'R2: narration enabled-gate removed' );
ok( ! defined( 'SN_NARRATION_CRON_HOOK' ), 'R2: cron-hook const removed' );
ok( function_exists( 'snt_narration_run' ) && function_exists( 'snt_narration_last' ), 'core run/last kept for the abilities' );

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

// ── Test: security block (v7.2.0) — present with activity, omitted when quiet/unconfigured ──
echo "\nTest: security signal block\n";
$GLOBALS['__lg_headline']    = array( 'configured' => true, 'checked' => 500, 'blocked' => 412, 'block_rate' => 82, 'top_network' => 'EvilNet' );
$GLOBALS['__lg_top_country'] = array( array( 'country' => 'CN', 'hits' => 300 ) );
$GLOBALS['__audit_summary']  = array( 'last_7d_vs_prior' => array( 'current' => 37, 'prior' => 20, 'pct_delta' => 85 ) );
$sig = snt_narration_collect_signals();
ok( isset( $sig['security']['login_guard'] ), 'security block present when guard has activity' );
eq( 412, $sig['security']['login_guard']['blocked'] ?? -1, 'security blocked count carried' );
eq( 'CN', $sig['security']['login_guard']['top_country'] ?? '', 'security top country carried' );
eq( 37, $sig['security']['audit']['events_7d'] ?? -1, 'security audit events carried' );

$GLOBALS['__lg_headline']   = array( 'configured' => false, 'checked' => 0, 'blocked' => 0, 'block_rate' => 0, 'top_network' => '' );
$GLOBALS['__audit_summary'] = array( 'last_7d_vs_prior' => array( 'current' => 0, 'prior' => 0, 'pct_delta' => 0 ) );
$sig = snt_narration_collect_signals();
ok( ! isset( $sig['security'] ), 'security block omitted when quiet + unconfigured' );

// ── Test: last-error store/read/clear helpers (v7.2.2, mirrors insights) ──
echo "\nTest: narration last-error helpers\n";
$err = new WP_Error( 'snt_narration_invalid_json', 'AI digest response was not valid JSON.', array( 'raw' => str_repeat( 'x', 500 ) ) );
snt_narration_store_last_error( $err );
$stored = snt_narration_last_error();
ok( is_array( $stored ), 'store+read round-trip' );
eq( 'snt_narration_invalid_json', $stored['code'] ?? '', 'code stored' );
eq( 'AI digest response was not valid JSON.', $stored['message'] ?? '', 'message stored' );
eq( 300, strlen( $stored['raw'] ?? '' ), 'raw captured, bounded to 300 chars' );
snt_narration_store_last_error( 'not-an-error' );
eq( 'snt_narration_invalid_json', ( snt_narration_last_error()['code'] ?? '' ), 'non-WP_Error input is ignored' );
snt_narration_clear_last_error();
ok( null === snt_narration_last_error(), 'clear removes the stored error' );

// ── Test: instruction gains the two conditional rules (v7.2.0) ──
echo "\nTest: instruction conditional rules\n";
$instr = snt_narration_system_instruction();
ok( false !== strpos( $instr, '"cwv" block is present' ), 'instruction has cwv conditional rule' );
ok( false !== strpos( $instr, '"security" block is present' ), 'instruction has security conditional rule' );
ok( false !== stripos( $instr, 'under 200 words' ), 'instruction caps total length for brevity (v9.2.1)' );

// ── Test: budget raised so a normal digest does not truncate (v9.2.1) ──
echo "\nTest: token budget raised to 1024 (was 512 — truncated real-data digests)\n";
eq( 1024, SN_NARRATION_MAX_TOKENS, 'SN_NARRATION_MAX_TOKENS raised to 1024' );

// ── Test: salvage a TRUNCATED digest (v9.2.1 — the live snt_narration_invalid_json bug) ──
// The model hit max_tokens mid-second-paragraph: headline + one COMPLETE paragraph
// done, the 2nd cut off, no highlights, no closing brackets. Direct json_decode
// fails; salvage must recover the complete parts rather than hard-failing.
echo "\nTest: truncated digest salvaged (headline + complete paragraphs recovered)\n";
$truncated = '{"headline":"Traffic ticked up this week","paragraphs":["Views rose 6% to 37 and visits rose 33% to 53.","The engaged-read rate climbed to 48';
$p = snt_narration_parse_response( $truncated );
ok( is_array( $p ), 'truncated digest salvaged to an array (not WP_Error)' );
eq( 'Traffic ticked up this week', $p['headline'] ?? null, 'headline recovered from truncation' );
eq( 1, count( $p['paragraphs'] ?? array() ), 'only the COMPLETE paragraph kept (cut-off one dropped)' );
ok( false === strpos( implode( ' ', $p['paragraphs'] ?? array() ), 'climbed to 48' ), 'the truncated partial paragraph is excluded' );

// Prose preamble before the JSON is also salvaged (the headline regex scans anywhere).
echo "\nTest: prose-preamble digest salvaged\n";
$preamble = 'Here is the digest: {"headline":"A steady week","paragraphs":["Nothing moved much."],"highlights":["views flat at 37"]} Hope this helps.';
$p2 = snt_narration_parse_response( $preamble );
ok( is_array( $p2 ) && 'A steady week' === ( $p2['headline'] ?? null ), 'preamble-wrapped JSON recovered' );

// Truncated so badly even the first paragraph is incomplete → cannot salvage → honest error.
echo "\nTest: unsalvageable truncation (no complete paragraph) → WP_Error\n";
$tooshort = '{"headline":"A quiet week","paragraphs":["Overall activity grew modest';
ok( is_wp_error( snt_narration_parse_response( $tooshort ) ), 'no complete paragraph → WP_Error (honest, never fabricated)' );

// ── v9.51.2: async generation (schedule + cron handler) + scoped AI timeout ──
echo "\nTest: snt_narration_schedule queues a deduped single event\n";
$GLOBALS['__single'] = array();
unset( $GLOBALS['__cron'][ SN_NARRATION_HOOK ] ); // nothing pending
ok( true === snt_narration_schedule( true ), 'schedule returns true when no event is pending' );
ok( 1 === count( $GLOBALS['__single'] ) && SN_NARRATION_HOOK === $GLOBALS['__single'][0][0] && array( true ) === $GLOBALS['__single'][0][1], 'a single event is queued on SN_NARRATION_HOOK with the force arg' );
$GLOBALS['__cron'][ SN_NARRATION_HOOK ] = time(); // simulate "already pending" (harness wp_next_scheduled reads __cron)
$GLOBALS['__single'] = array();
ok( false === snt_narration_schedule( true ), 'schedule dedupes: returns false when an event is already pending' );
ok( 0 === count( $GLOBALS['__single'] ), 'no second event queued when one is pending' );
unset( $GLOBALS['__cron'][ SN_NARRATION_HOOK ] );

echo "\nTest: SN_NARRATION_HOOK runs the generator off the request path\n";
ok( in_array( 'snt_narration_cron_run', $GLOBALS['__acts'][ SN_NARRATION_HOOK ] ?? array(), true )
	|| function_exists( 'snt_narration_cron_run' ), 'the cron handler is registered / callable' );
$GLOBALS['__ai_calls'] = 0;
$GLOBALS['__transients'] = array(); // no cache → generation must run
snt_narration_cron_run( true );
ok( $GLOBALS['__ai_calls'] >= 1, 'the cron handler drives a real generation (AI call fires cron-side)' );

echo "\nTest: the AI call raises then removes the http_request_timeout (scoped, no leak)\n";
$GLOBALS['__filters_added'] = array();
$GLOBALS['__filters_removed'] = array();
snt_narration_call_ai( array( 'window' => array( 'days' => 7 ) ) );
$added_timeout = null;
foreach ( $GLOBALS['__filters_added'] as $f ) { if ( 'http_request_timeout' === $f[0] ) { $added_timeout = $f[1]; } }
ok( null !== $added_timeout, 'http_request_timeout filter is added around the AI call' );
ok( is_callable( $added_timeout ) && SN_NARRATION_HTTP_TIMEOUT === $added_timeout(), 'the added filter raises the timeout to SN_NARRATION_HTTP_TIMEOUT (120s)' );
$removed_same = false;
foreach ( $GLOBALS['__filters_removed'] as $f ) { if ( 'http_request_timeout' === $f[0] && $f[1] === $added_timeout ) { $removed_same = true; } }
ok( $removed_same, 'the SAME filter is removed after the call (never left registered → no leak onto other requests)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

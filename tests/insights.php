<?php
/**
 * Standalone fixture tests for inc/insights.php (v3.6.0).
 *
 * Matches the bot-detection/cron-dashboard pattern: bare PHP, no
 * PHPUnit. Runnable as `php tests/insights.php`. Exits 0 on pass.
 *
 * @since plugin v3.6.0
 */

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS',  86400 );
define( 'WEEK_IN_SECONDS', 604800 );

if ( ! defined( 'OBJECT' )   ) define( 'OBJECT',   'OBJECT' );
if ( ! defined( 'OBJECT_K' ) ) define( 'OBJECT_K', 'OBJECT_K' );
if ( ! defined( 'ARRAY_A' )  ) define( 'ARRAY_A',  'ARRAY_A' );
if ( ! defined( 'ARRAY_N' )  ) define( 'ARRAY_N',  'ARRAY_N' );

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}

$GLOBALS['__test_options']    = array();
$GLOBALS['__test_transients'] = array();

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__test_options'][ $key ] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__test_options'][ $key ] );
	return true;
}
function get_transient( $key ) {
	return isset( $GLOBALS['__test_transients'][ $key ] ) ? $GLOBALS['__test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['__test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__test_transients'][ $key ] );
	return true;
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) { return json_encode( $v ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled() { return false; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event() {}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event() {}
}
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $path, $default = null ) {
		return isset( $GLOBALS['__test_sn_settings'][ $path ] )
			? $GLOBALS['__test_sn_settings'][ $path ]
			: $default;
	}
}

class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// ─── wpdb stub for post queries ──────────────────────────────────────
class Stub_wpdb_insights {
	public $prefix = 'wp_';
	public $posts  = 'wp_posts';
	public $rows   = array();

	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$out = $query;
		foreach ( $args as $a ) {
			$rep = is_int( $a ) || is_float( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'";
			$out = preg_replace( '/%s|%d|%f/', $rep, $out, 1 );
		}
		return $out;
	}
	public function get_results( $query, $output = OBJECT_K ) {
		// Cron history query — distinct hooks with stats from snt_cron_history.
		if ( false !== strpos( $query, 'snt_cron_history' ) ) {
			$rows = isset( $GLOBALS['__test_cron_history'] ) ? $GLOBALS['__test_cron_history'] : array();
			return $rows;
		}
		// Post list query.
		$rows = $this->rows;
		if ( preg_match( "/post_status\s*=\s*'publish'/", $query ) ) {
			$rows = array_values( array_filter( $rows, function( $r ) { return ! empty( $r['post_status'] ) && 'publish' === $r['post_status']; } ) );
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $lm ) ) {
			$rows = array_slice( $rows, 0, (int) $lm[1] );
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new Stub_wpdb_insights();

if ( ! function_exists( 'get_permalink' ) ) {
	// Test stub: return distinct permalink paths so the views-join
	// matches against $views_map[<relative-path>]. Per-test fixtures
	// override via $GLOBALS['__test_permalinks'] when needed.
	function get_permalink( $id ) {
		if ( isset( $GLOBALS['__test_permalinks'][ $id ] ) ) {
			return $GLOBALS['__test_permalinks'][ $id ];
		}
		return "https://juanlentino.com/p/{$id}/";
	}
}
if ( ! function_exists( 'wp_make_link_relative' ) ) {
	function wp_make_link_relative( $url ) {
		$parsed = wp_parse_url( $url );
		return isset( $parsed['path'] ) ? $parsed['path'] : '/';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
		return isset( $GLOBALS['__test_post_terms'][ $post_id ][ $taxonomy ] )
			? $GLOBALS['__test_post_terms'][ $post_id ][ $taxonomy ]
			: array();
	}
}
if ( ! function_exists( 'sn_plausible_dashboard_data' ) ) {
	function sn_plausible_dashboard_data() {
		return isset( $GLOBALS['__test_plausible'] ) ? $GLOBALS['__test_plausible'] : null;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show ) { return ''; }
}
if ( ! function_exists( 'sn_webhooks_all' ) ) {
	function sn_webhooks_all() {
		return isset( $GLOBALS['__test_webhooks'] ) ? $GLOBALS['__test_webhooks'] : array();
	}
}
if ( ! function_exists( 'sn_webhook_log_read' ) ) {
	function sn_webhook_log_read( $id ) {
		return isset( $GLOBALS['__test_webhook_logs'][ $id ] ) ? $GLOBALS['__test_webhook_logs'][ $id ] : array();
	}
}
if ( ! function_exists( 'snt_ai_is_available' ) ) {
	function snt_ai_is_available() {
		return ! empty( $GLOBALS['__test_ai_available'] );
	}
}
if ( ! function_exists( 'snt_ai_generate_with_constraints' ) ) {
	function snt_ai_generate_with_constraints( $prompt, $system, $max_tokens = 256 ) {
		$GLOBALS['__test_ai_last_prompt'] = $prompt;
		$GLOBALS['__test_ai_last_system'] = $system;
		$GLOBALS['__test_ai_last_max']    = $max_tokens;
		if ( isset( $GLOBALS['__test_ai_response'] ) ) {
			return $GLOBALS['__test_ai_response'];
		}
		return new WP_Error( 'snt_ai_unavailable', 'no fixture' );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		if ( ! empty( $GLOBALS['__test_posts_exist'][ $id ] ) ) {
			return (object) array( 'ID' => $id, 'post_status' => 'publish' );
		}
		return null;
	}
}

require_once __DIR__ . '/../inc/insights.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ins_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ins_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test 1: module loads + constants defined ────────────────────────
echo "\nTest 1: module bootstrap\n";
ins_true( defined( 'SN_INSIGHTS_CACHE_KEY' ), 'SN_INSIGHTS_CACHE_KEY defined' );
ins_true( defined( 'SN_INSIGHTS_STATE_OPT' ), 'SN_INSIGHTS_STATE_OPT defined' );
ins_true( defined( 'SN_INSIGHTS_CRON_HOOK' ), 'SN_INSIGHTS_CRON_HOOK defined' );
ins_eq( 7 * DAY_IN_SECONDS, SN_INSIGHTS_CACHE_TTL, 'cache TTL is 7 days' );

// ─── Test 2: collect_signals returns site identity ───────────────────
echo "\nTest 2: snt_insights_collect_signals() — site identity\n";
$GLOBALS['__test_sn_settings'] = array(
	'identity.site_name'        => 'Juan Lentino',
	'identity.site_description' => 'A music producer site',
	'identity.person_name'      => 'Juan Lentino',
	'identity.job_title'        => 'Music Producer',
);
$GLOBALS['wpdb']->rows  = array();
$GLOBALS['__test_plausible'] = array( 'aggregate' => array(), 'pages' => array(), 'sources' => array() );
$signals = snt_insights_collect_signals();
ins_true( is_array( $signals ), 'returns array' );
ins_eq( 'Juan Lentino', $signals['site']['name'], 'site.name' );
ins_eq( 'Music Producer', $signals['site']['job_title'], 'site.job_title' );
ins_eq( 'https://juanlentino.com/', $signals['site']['home_url'], 'site.home_url' );

// ─── Test 3: post list shape + sort by views_7d ──────────────────────
echo "\nTest 3: posts sorted by views_7d desc — realistic Plausible shape + nested permalink join\n";
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'Low traffic',  'post_name' => 'low',  'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
	array( 'ID' => 2, 'post_title' => 'High traffic', 'post_name' => 'high', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5  * DAY_IN_SECONDS ) ),
);
// Simulate the nested /notes/<slug>/ permalink pattern used on juanlentino.com.
$GLOBALS['__test_permalinks'] = array(
	1 => 'https://juanlentino.com/notes/low/',
	2 => 'https://juanlentino.com/notes/high/',
);
// Realistic Plausible breakdown shape: visitors is SCALAR int, not nested.
$GLOBALS['__test_plausible'] = array(
	'aggregate' => array( 'visitors' => array( 'value' => 1000 ) ),
	'pages'     => array(
		array( 'page' => '/notes/high', 'visitors' => 500 ),
		array( 'page' => '/notes/low',  'visitors' => 10 ),
	),
	'sources'   => array(),
);
$signals = snt_insights_collect_signals();
ins_eq( 2, count( $signals['posts'] ), 'two posts' );
ins_eq( 2, $signals['posts'][0]['id'], 'highest-traffic post first' );
ins_eq( 500, $signals['posts'][0]['views_7d'], 'views_7d matched from Plausible (scalar visitors + nested permalink path)' );
ins_eq( 10,  $signals['posts'][1]['views_7d'], 'low-traffic post also matched (proves both join entries land)' );
ins_eq( 'post', $signals['posts'][0]['type'], 'post.type' );

// ─── Test 3b: tiebreak — same views_7d sorts by days_since_publish ASC
echo "\nTest 3b: tiebreak — equal views, older publish date wins (ASC)\n";
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 10, 'post_title' => 'Newer same-traffic', 'post_name' => 'newer', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5  * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5  * DAY_IN_SECONDS ) ),
	array( 'ID' => 11, 'post_title' => 'Older same-traffic', 'post_name' => 'older', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 50 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 50 * DAY_IN_SECONDS ) ),
);
$GLOBALS['__test_permalinks'] = array(
	10 => 'https://juanlentino.com/notes/newer/',
	11 => 'https://juanlentino.com/notes/older/',
);
$GLOBALS['__test_plausible'] = array(
	'aggregate' => array( 'visitors' => array( 'value' => 1000 ) ),
	'pages'     => array(
		array( 'page' => '/notes/newer', 'visitors' => 100 ),
		array( 'page' => '/notes/older', 'visitors' => 100 ),
	),
	'sources'   => array(),
);
$signals = snt_insights_collect_signals();
// Comparator returns $a['days_since_publish'] - $b['days_since_publish']
// for equal views_7d — smaller (newer) days_since_publish comes first.
// Wait: smaller days_since_publish = NEWER. So newer (id=10) wins.
ins_eq( 100, $signals['posts'][0]['views_7d'], 'both posts have views_7d=100' );
ins_eq( 100, $signals['posts'][1]['views_7d'], 'second post also views_7d=100' );
ins_eq( 10, $signals['posts'][0]['id'], 'tiebreak: newer post (days_since_publish ASC) comes first' );
ins_eq( 11, $signals['posts'][1]['id'], 'tiebreak: older post comes second' );

// ─── Test 4: post age cap (2 years) ──────────────────────────────────
echo "\nTest 4: posts older than 2 years excluded\n";
$old_date = gmdate( 'Y-m-d H:i:s', time() - 800 * DAY_IN_SECONDS );  // 800d > 730d cap
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'Old', 'post_name' => 'old', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => $old_date, 'post_modified_gmt' => $old_date ),
	array( 'ID' => 2, 'post_title' => 'New', 'post_name' => 'new', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ),
);
$signals = snt_insights_collect_signals();
ins_eq( 1, count( $signals['posts'] ), 'only the new post included' );
ins_eq( 2, $signals['posts'][0]['id'], 'new post (id=2) survived' );

// ─── Test 5: post count cap (100) ────────────────────────────────────
echo "\nTest 5: post list capped at 100\n";
$rows = array();
for ( $i = 1; $i <= 150; $i++ ) {
	$rows[] = array( 'ID' => $i, 'post_title' => "P{$i}", 'post_name' => "p{$i}", 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
}
$GLOBALS['wpdb']->rows = $rows;
$signals = snt_insights_collect_signals();
ins_eq( 100, count( $signals['posts'] ), 'capped at 100' );

// ─── Test 6: webhooks summary ────────────────────────────────────────
echo "\nTest 6: webhook summary — success rate + last attempt\n";
$GLOBALS['__test_webhooks'] = array(
	array( 'id' => 'wh_n8n', 'name' => 'n8n', 'enabled' => true ),
	array( 'id' => 'wh_off', 'name' => 'Disabled', 'enabled' => false ),
);
$GLOBALS['__test_webhook_logs'] = array(
	'wh_n8n' => array(
		array( 'success' => true,  'fired_at' => time() - 3600 ),
		array( 'success' => false, 'fired_at' => time() - 7200 ),
		array( 'success' => true,  'fired_at' => time() - 10800 ),
	),
);
$GLOBALS['wpdb']->rows = array();
$signals = snt_insights_collect_signals();
ins_eq( 1, $signals['webhooks']['total_active'], 'one enabled webhook counted' );
ins_true( isset( $signals['webhooks']['recent_deliveries_summary']['wh_n8n'] ), 'wh_n8n has summary' );
$wh = $signals['webhooks']['recent_deliveries_summary']['wh_n8n'];
ins_eq( 'n8n', $wh['name'], 'webhook name echoed' );
ins_true( abs( $wh['success_rate'] - 0.6667 ) < 0.01, 'success_rate ≈ 0.67 (2 of 3)' );
ins_true( $wh['last_attempt_ago_seconds'] >= 3600 - 1, 'last_attempt computed' );

// ─── Test 7: cron freshness ──────────────────────────────────────────
echo "\nTest 7: cron_freshness — last_fired + last_24h_count\n";
$GLOBALS['__test_cron_history'] = array(
	array( 'hook' => 'sn_plausible_refresh_dashboard', 'last_fired_ts' => time() - 240,   'fires_24h' => 288 ),
	array( 'hook' => 'sn_rss_tracker_daily_prune',     'last_fired_ts' => time() - 43200, 'fires_24h' => 1 ),
);
$signals = snt_insights_collect_signals();
ins_true( isset( $signals['cron_freshness']['sn_plausible_refresh_dashboard'] ), 'plausible cron present' );
$cron = $signals['cron_freshness']['sn_plausible_refresh_dashboard'];
ins_eq( 4, $cron['last_fired_ago_minutes'], '240s ≈ 4min' );
ins_eq( 288, $cron['last_24h_count'], '288 fires/24h echoed' );

// ─── Test 8: AI call passes signals as JSON + correct system prompt ──
echo "\nTest 8: snt_insights_call_ai builds correct prompt\n";
$GLOBALS['__test_ai_available'] = true;
$GLOBALS['__test_ai_response']  = '[]';
$signals = array( 'site' => array( 'name' => 'Test' ), 'posts' => array() );
$result = snt_insights_call_ai( $signals );
$prompt = $GLOBALS['__test_ai_last_prompt'];
$system = $GLOBALS['__test_ai_last_system'];
ins_true( false !== strpos( $prompt, '"name":"Test"' ), 'signals JSON-encoded into prompt' );
ins_true( false !== strpos( $system, 'content strategist' ), 'system mentions strategist' );
ins_true( false !== strpos( $system, 'exactly 5 recommendations' ), 'system asks for 5 recs' );
ins_true( false !== strpos( $system, 'write_about' ), 'system enumerates types' );
ins_eq( 1500, $GLOBALS['__test_ai_last_max'], 'max_tokens = 1500' );

// ─── Test 9: AI call returns WP_Error when not available ─────────────
echo "\nTest 9: AI unavailable returns WP_Error\n";
$GLOBALS['__test_ai_available'] = false;
$result = snt_insights_call_ai( array( 'site' => array() ) );
ins_true( $result instanceof WP_Error, 'returns WP_Error when AI unavailable' );
ins_eq( 'snt_insights_ai_unavailable', $result->code, 'error code' );

// ─── Test 10: parse valid response (5 recs) ──────────────────────────
echo "\nTest 10: parse_response — happy path\n";
$valid_json = '[
  {"id":"rec_write_synths","type":"write_about","title":"Write about modular synths","rationale":"Your /notes/ posts on modular synths average 4x traffic; you haven\'t published one in 6 months.","evidence_pills":["+340% views","6mo since last"],"target":null},
  {"id":"rec_update_old","type":"update_post","title":"Update post about ableton","rationale":"This post has 500 views/wk but was last modified 14 months ago.","evidence_pills":["500 views/wk","14mo old"],"target":{"post_id":1,"url":"https://x/"}},
  {"id":"rec_cadence","type":"cadence_change","title":"Try publishing Tuesdays","rationale":"Posts published on Tuesdays outperform Fridays 3:1.","evidence_pills":["3:1 ratio"],"target":null},
  {"id":"rec_dd","type":"topic_double_down","title":"More tutorials","rationale":"Tutorial-tagged posts get 2x the traffic of essays.","evidence_pills":["2x traffic"],"target":null},
  {"id":"rec_pivot","type":"topic_pivot","title":"Skip jazz takes","rationale":"Jazz posts get 1/5 the average traffic.","evidence_pills":["20% of average"],"target":null}
]';
$GLOBALS['__test_posts_exist'] = array( 1 => true );
$recs = snt_insights_parse_response( $valid_json );
ins_true( is_array( $recs ), 'returns array' );
ins_eq( 5, count( $recs ), '5 recs parsed' );
ins_eq( 'write_about', $recs[0]['type'], 'first rec type' );

// ─── Test 11: markdown code fence stripping ──────────────────────────
echo "\nTest 11: parse_response strips markdown fences\n";
$fenced = "```json\n" . $valid_json . "\n```";
$recs = snt_insights_parse_response( $fenced );
ins_true( is_array( $recs ), 'returns array (fences stripped)' );
ins_eq( 5, count( $recs ), '5 recs after fence strip' );

// ─── Test 12: drops invalid entries (missing keys, bad type) ─────────
echo "\nTest 12: parse_response drops invalid entries\n";
$mixed = '[
  {"id":"rec_a","type":"write_about","title":"Valid 1","rationale":"r1","evidence_pills":[],"target":null},
  {"type":"write_about","title":"Missing id"},
  {"id":"rec_b","type":"bogus_type","title":"Bad type","rationale":"r","evidence_pills":[],"target":null},
  {"id":"rec_c","type":"update_post","title":"Valid 2","rationale":"r2","evidence_pills":[],"target":null},
  {"id":"rec_d","type":"cadence_change","title":"Valid 3","rationale":"r3","evidence_pills":[],"target":null}
]';
$recs = snt_insights_parse_response( $mixed );
ins_true( is_array( $recs ), 'returns array' );
ins_eq( 3, count( $recs ), 'only 3 valid (2 invalid dropped)' );

// ─── Test 13: fewer than 3 valid → WP_Error ──────────────────────────
echo "\nTest 13: parse_response returns WP_Error when < 3 valid\n";
$too_few = '[
  {"id":"rec_x","type":"write_about","title":"Only valid","rationale":"r","evidence_pills":[],"target":null},
  {"missing":"keys"},
  {"id":"y","type":"bogus","title":"t","rationale":"r","evidence_pills":[],"target":null}
]';
$res = snt_insights_parse_response( $too_few );
ins_true( $res instanceof WP_Error, 'returns WP_Error' );
ins_eq( 'snt_insights_too_few_valid', $res->code, 'error code' );

// ─── Test 14: target.post_id must reference real post ────────────────
echo "\nTest 14: parse_response validates target.post_id\n";
$with_bad_target = '[
  {"id":"a","type":"update_post","title":"v1","rationale":"r","evidence_pills":[],"target":{"post_id":1,"url":"u"}},
  {"id":"b","type":"update_post","title":"v2","rationale":"r","evidence_pills":[],"target":{"post_id":999999,"url":"u"}},
  {"id":"c","type":"update_post","title":"v3","rationale":"r","evidence_pills":[],"target":null},
  {"id":"d","type":"update_post","title":"v4","rationale":"r","evidence_pills":[],"target":null}
]';
$GLOBALS['__test_posts_exist'] = array( 1 => true );
$recs = snt_insights_parse_response( $with_bad_target );
ins_eq( 3, count( $recs ), 'invalid target dropped (post 999999 unknown)' );

// ─── Test 15: title length cap (80 chars) ────────────────────────────
echo "\nTest 15: parse_response rejects titles > 80 chars\n";
$long_title = str_repeat( 'x', 90 );
$with_long = '[
  {"id":"a","type":"write_about","title":"' . $long_title . '","rationale":"r","evidence_pills":[],"target":null},
  {"id":"b","type":"write_about","title":"ok","rationale":"r","evidence_pills":[],"target":null},
  {"id":"c","type":"write_about","title":"ok","rationale":"r","evidence_pills":[],"target":null},
  {"id":"d","type":"write_about","title":"ok","rationale":"r","evidence_pills":[],"target":null}
]';
$recs = snt_insights_parse_response( $with_long );
ins_eq( 3, count( $recs ), 'long title rejected' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Standalone fixture tests for inc/insights.php (v3.6.0).
 *
 * Matches the bot-detection/cron-dashboard pattern: bare PHP, no
 * PHPUnit. Runnable as `php tests/insights.php`. Exits 0 on pass.
 *
 * @since plugin v3.6.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
// Owned by inc/content-surfaces.php at runtime; that module isn't loaded in
// this harness, so define the slug here for the Create-draft Notes-category
// resolution (snt_insights_build_draft_postarr).
if ( ! defined( 'SN_NOTES_CATEGORY_SLUG' ) ) { define( 'SN_NOTES_CATEGORY_SLUG', 'notes' ); }
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
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return isset( $GLOBALS['__test_scheduled'][ $hook ] ) ? $GLOBALS['__test_scheduled'][ $hook ] : false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $ts, $recurrence, $hook ) {
		$GLOBALS['__test_scheduled'][ $hook ] = $ts;
		return true;
	}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $ts, $hook ) {
		unset( $GLOBALS['__test_scheduled'][ $hook ] );
		return true;
	}
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
// v6.0.0: insights reads the first-party rollup accessors (Plausible retired).
if ( ! function_exists( 'sn_analytics_top_paths' ) ) {
	function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) {
		return isset( $GLOBALS['__test_an_pages'] ) ? $GLOBALS['__test_an_pages'] : array();
	}
}
if ( ! function_exists( 'sn_analytics_range_totals' ) ) {
	function sn_analytics_range_totals( $from, $to, $class = 'human' ) {
		return isset( $GLOBALS['__test_an_totals'] ) ? $GLOBALS['__test_an_totals'] : array();
	}
}
if ( ! function_exists( 'sn_analytics_top_dimension' ) ) {
	function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) {
		return isset( $GLOBALS['__test_an_sources'] ) ? $GLOBALS['__test_an_sources'] : array();
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
		// Rich fixture objects (with post_content / post_excerpt) take
		// precedence — used by the body-grounding excerpt tests.
		if ( isset( $GLOBALS['__test_post_objects'][ $id ] ) ) {
			return (object) $GLOBALS['__test_post_objects'][ $id ];
		}
		if ( ! empty( $GLOBALS['__test_posts_exist'][ $id ] ) ) {
			return (object) array( 'ID' => $id, 'post_status' => 'publish' );
		}
		return null;
	}
}

// ─── Body-grounding helper stubs (mirror WP core semantics) ──────────
if ( ! function_exists( 'strip_shortcodes' ) ) {
	// Strip [shortcode] … [/shortcode] and self-closing [shortcode] tokens.
	function strip_shortcodes( $content ) {
		return preg_replace( '/\[\/?[^\]]*\]/', '', (string) $content );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	// Mirror core: split on whitespace, slice to $num_words, append $more.
	function wp_trim_words( $text, $num_words = 55, $more = null ) {
		$text  = trim( (string) $text );
		$words = preg_split( '/[\n\r\t ]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$sep   = ( null === $more ) ? ' …' : $more;
		if ( count( $words ) > $num_words ) {
			$words = array_slice( $words, 0, $num_words );
			return implode( ' ', $words ) . $sep;
		}
		return implode( ' ', $words );
	}
}

// snt_ai_extract_post_text is normally in ai-bootstrap.php; replicate its
// observable contract here (shortcode + tag strip, word-capped, no
// ellipsis, floor of 50 words) so the body-grounding fallback is exercised.
if ( ! function_exists( 'snt_ai_extract_post_text' ) ) {
	function snt_ai_extract_post_text( $post_id, $words = 1000 ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return '';
		}
		$raw = isset( $post->post_content ) ? $post->post_content : '';
		$raw = strip_shortcodes( $raw );
		$raw = wp_strip_all_tags( $raw );
		return (string) wp_trim_words( $raw, max( 50, (int) $words ), '' );
	}
}

// ─── Notes-category + draft-insert stubs (Create-draft, v4.11.0) ─────
// inc/insights.php resolves the Notes category via get_term_by('slug', …)
// guarded by defined(SN_NOTES_CATEGORY_SLUG); content-surfaces.php (which
// owns that constant) is NOT loaded here, so the impl must tolerate both
// the seeded and unseeded states. These stubs let the tests drive each.
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		if ( isset( $GLOBALS['__test_terms'][ $taxonomy ][ $field ][ $value ] ) ) {
			return (object) $GLOBALS['__test_terms'][ $taxonomy ][ $field ][ $value ];
		}
		return false;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		$GLOBALS['__test_inserted_post'] = $postarr;
		if ( isset( $GLOBALS['__test_insert_error'] ) && $wp_error ) {
			return $GLOBALS['__test_insert_error'];
		}
		return isset( $GLOBALS['__test_insert_id'] ) ? (int) $GLOBALS['__test_insert_id'] : 1234;
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
$GLOBALS['__test_an_pages'] = array();
$signals = snt_insights_collect_signals();
ins_true( is_array( $signals ), 'returns array' );
ins_eq( 'Juan Lentino', $signals['site']['name'], 'site.name' );
ins_eq( 'Music Producer', $signals['site']['job_title'], 'site.job_title' );
ins_eq( 'https://juanlentino.com/', $signals['site']['home_url'], 'site.home_url' );

// ─── Test 3: post list shape + sort by views_7d ──────────────────────
echo "\nTest 3: posts sorted by views_7d desc — first-party top_paths shape + nested permalink join\n";
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'Low traffic',  'post_name' => 'low',  'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
	array( 'ID' => 2, 'post_title' => 'High traffic', 'post_name' => 'high', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5  * DAY_IN_SECONDS ) ),
);
// Simulate the nested /notes/<slug>/ permalink pattern used on juanlentino.com.
$GLOBALS['__test_permalinks'] = array(
	1 => 'https://juanlentino.com/notes/low/',
	2 => 'https://juanlentino.com/notes/high/',
);
// First-party top_paths shape: { path, views } rows.
$GLOBALS['__test_an_pages'] = array(
	array( 'path' => '/notes/high', 'views' => 500 ),
	array( 'path' => '/notes/low',  'views' => 10 ),
);
$signals = snt_insights_collect_signals();
ins_eq( 2, count( $signals['posts'] ), 'two posts' );
ins_eq( 2, $signals['posts'][0]['id'], 'highest-traffic post first' );
ins_eq( 500, $signals['posts'][0]['views_7d'], 'views_7d matched from first-party top_paths (path + views + nested permalink)' );
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
$GLOBALS['__test_an_pages'] = array(
	array( 'path' => '/notes/newer', 'views' => 100 ),
	array( 'path' => '/notes/older', 'views' => 100 ),
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
	array( 'hook' => 'sn_analytics_rollup_daily', 'last_fired_ts' => time() - 240,   'fires_24h' => 288 ),
	array( 'hook' => 'sn_rss_tracker_daily_prune',     'last_fired_ts' => time() - 43200, 'fires_24h' => 1 ),
);
$signals = snt_insights_collect_signals();
ins_true( isset( $signals['cron_freshness']['sn_analytics_rollup_daily'] ), 'analytics cron present' );
$cron = $signals['cron_freshness']['sn_analytics_rollup_daily'];
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

// ─── Test 16: state read returns empty arrays by default ─────────────
echo "\nTest 16: state read/write\n";
$GLOBALS['__test_options'][ SN_INSIGHTS_STATE_OPT ] = array();
$state = snt_insights_state_read();
ins_true( isset( $state['dismissed_ids'] ),  'dismissed_ids key present' );
ins_true( isset( $state['snoozed_until'] ),  'snoozed_until key present' );
ins_true( isset( $state['done_ids'] ),       'done_ids key present' );
ins_eq( array(), $state['dismissed_ids'], 'dismissed_ids default empty' );

// ─── Test 17: dismiss + snooze + mark_done ───────────────────────────
echo "\nTest 17: dismiss/snooze/done write through\n";
$GLOBALS['__test_options'][ SN_INSIGHTS_STATE_OPT ] = array();
snt_insights_dismiss( 'rec_a' );
snt_insights_snooze( 'rec_b' );
snt_insights_mark_done( 'rec_c' );
$state = snt_insights_state_read();
ins_true( in_array( 'rec_a', $state['dismissed_ids'], true ), 'dismissed contains rec_a' );
ins_true( isset( $state['snoozed_until']['rec_b'] ),          'snoozed contains rec_b' );
ins_true( in_array( 'rec_c', $state['done_ids'], true ),      'done contains rec_c' );
ins_true( $state['snoozed_until']['rec_b'] > time() + 25 * DAY_IN_SECONDS, 'snooze ~30 days out' );

// ─── Test 18: filter_active hides dismissed + active-snoozed ─────────
echo "\nTest 18: filter_active\n";
$GLOBALS['__test_options'][ SN_INSIGHTS_STATE_OPT ] = array(
	'dismissed_ids' => array( 'rec_a' ),
	'snoozed_until' => array( 'rec_b' => time() + 100, 'rec_c' => time() - 100 ),  // c expired
	'done_ids'      => array( 'rec_d' ),
);
$all = array(
	array( 'id' => 'rec_a' ),  // dismissed → hidden
	array( 'id' => 'rec_b' ),  // snoozed-active → hidden
	array( 'id' => 'rec_c' ),  // snoozed-expired → visible
	array( 'id' => 'rec_d' ),  // done → visible (greyed)
	array( 'id' => 'rec_e' ),  // untouched → visible
);
$active = snt_insights_filter_active( $all );
$visible_ids = array_column( $active, 'id' );
ins_true( ! in_array( 'rec_a', $visible_ids, true ), 'dismissed hidden' );
ins_true( ! in_array( 'rec_b', $visible_ids, true ), 'active-snoozed hidden' );
ins_true( in_array( 'rec_c', $visible_ids, true ),   'expired-snooze visible' );
ins_true( in_array( 'rec_d', $visible_ids, true ),   'done still visible' );
ins_true( in_array( 'rec_e', $visible_ids, true ),   'untouched visible' );

// ─── Test 19: state list FIFO cap (200 entries) ──────────────────────
echo "\nTest 19: state list cap at 200 entries\n";
$big_state = array(
	'dismissed_ids' => array(),
	'snoozed_until' => array(),
	'done_ids'      => array(),
);
for ( $i = 0; $i < 250; $i++ ) {
	$big_state['dismissed_ids'][] = "rec_$i";
}
$GLOBALS['__test_options'][ SN_INSIGHTS_STATE_OPT ] = $big_state;
snt_insights_dismiss( 'new_rec' );  // should evict oldest
$state = snt_insights_state_read();
ins_eq( 200, count( $state['dismissed_ids'] ), 'dismissed_ids capped at 200' );
ins_true( in_array( 'new_rec', $state['dismissed_ids'], true ), 'new entry retained' );
ins_true( ! in_array( 'rec_0', $state['dismissed_ids'], true ), 'oldest evicted' );

// ─── Test 20: run_scan happy path stores in cache ────────────────────
echo "\nTest 20: run_scan caches result\n";
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_ai_available'] = true;
$GLOBALS['__test_ai_response'] = $valid_json;  // from Test 10
$GLOBALS['__test_posts_exist'] = array( 1 => true );
$GLOBALS['wpdb']->rows = array();
$result = snt_insights_run_scan();
ins_true( is_array( $result ), 'run_scan returns array' );
ins_eq( 5, count( $result['recommendations'] ), '5 recs cached' );
ins_true( isset( $result['scanned_at'] ), 'scanned_at set' );
ins_true( ! empty( $GLOBALS['__test_transients'][ SN_INSIGHTS_CACHE_KEY ] ), 'cache written' );

// ─── Test 21: cache hit short-circuits ───────────────────────────────
echo "\nTest 21: run_scan uses cache when present\n";
$GLOBALS['__test_ai_response'] = '[]';  // would fail if called
$cached = snt_insights_run_scan();
ins_eq( 5, count( $cached['recommendations'] ), 'returns cached 5 recs (AI not re-called)' );

// ─── Test 22: force=true bypasses cache ──────────────────────────────
echo "\nTest 22: run_scan force=true bypasses cache\n";
$GLOBALS['__test_ai_response'] = $valid_json;
$forced = snt_insights_run_scan( true );
ins_eq( 5, count( $forced['recommendations'] ), 'force re-ran scan' );

// ─── Test 23: WP_Error from AI propagates ────────────────────────────
echo "\nTest 23: AI failure returns WP_Error (cache untouched)\n";
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_ai_available'] = false;
$res = snt_insights_run_scan( true );
ins_true( $res instanceof WP_Error, 'WP_Error returned' );
ins_true( empty( $GLOBALS['__test_transients'][ SN_INSIGHTS_CACHE_KEY ] ), 'cache NOT written on failure' );

// ─── Test 24: last_scan reads cache ──────────────────────────────────
echo "\nTest 24: last_scan reads transient\n";
$GLOBALS['__test_transients'][ SN_INSIGHTS_CACHE_KEY ] = array(
	'scanned_at'      => 12345,
	'recommendations' => array( array( 'id' => 'x' ) ),
);
$last = snt_insights_last_scan();
ins_eq( 12345, $last['scanned_at'], 'scanned_at echoed' );
ins_eq( 'x', $last['recommendations'][0]['id'], 'rec echoed' );

$GLOBALS['__test_transients'] = array();
ins_eq( null, snt_insights_last_scan(), 'null when transient missing' );

// ─── Test 25: cron scheduled when setting enabled ────────────────────
echo "\nTest 25: maybe_schedule_weekly_cron — opt-in\n";
$GLOBALS['__test_scheduled'] = array();
$GLOBALS['__test_sn_settings']['insights.weekly_cron_enabled'] = true;
snt_insights_maybe_schedule_weekly_cron();
ins_true( isset( $GLOBALS['__test_scheduled'][ SN_INSIGHTS_CRON_HOOK ] ), 'cron event scheduled' );

// ─── Test 26: cron NOT scheduled when setting disabled ───────────────
echo "\nTest 26: maybe_schedule_weekly_cron — opt-out\n";
$GLOBALS['__test_scheduled'] = array();
$GLOBALS['__test_sn_settings']['insights.weekly_cron_enabled'] = false;
snt_insights_maybe_schedule_weekly_cron();
ins_true( ! isset( $GLOBALS['__test_scheduled'][ SN_INSIGHTS_CRON_HOOK ] ), 'cron NOT scheduled when off' );

// ─── Test 27: unschedule when setting flipped to off ─────────────────
echo "\nTest 27: unschedule_weekly_cron\n";
$GLOBALS['__test_scheduled'][ SN_INSIGHTS_CRON_HOOK ] = time() + 86400;
snt_insights_unschedule_weekly_cron();
ins_true( ! isset( $GLOBALS['__test_scheduled'][ SN_INSIGHTS_CRON_HOOK ] ), 'cron unscheduled' );

// ─── Test 28: body-grounding constants defined ───────────────────────
echo "\nTest 28: body-grounding constants\n";
ins_true( defined( 'SN_INSIGHTS_EXCERPT_CAP' ),         'SN_INSIGHTS_EXCERPT_CAP defined' );
ins_true( defined( 'SN_INSIGHTS_EXCERPT_WORDS' ),       'SN_INSIGHTS_EXCERPT_WORDS defined' );
ins_true( defined( 'SN_INSIGHTS_EXCERPT_TOTAL_CHARS' ), 'SN_INSIGHTS_EXCERPT_TOTAL_CHARS defined' );
ins_eq( 25,    SN_INSIGHTS_EXCERPT_CAP,         'excerpt cap = 25' );
ins_eq( 120,   SN_INSIGHTS_EXCERPT_WORDS,       'excerpt words = 120' );
ins_eq( 60000, SN_INSIGHTS_EXCERPT_TOTAL_CHARS, 'excerpt total chars = 60000' );

// Helper: reset all the per-test fixture globals the excerpt tests touch.
function ins_reset_excerpt_fixtures() {
	$GLOBALS['__test_an_pages']      = array();
	$GLOBALS['__test_post_objects']  = array();
	$GLOBALS['__test_posts_exist']   = array();
	$GLOBALS['__test_webhooks']      = array();
	$GLOBALS['__test_webhook_logs']  = array();
	$GLOBALS['__test_cron_history']  = array();
}

// ─── Test 29: top-25 carry excerpt, post #26 does not ────────────────
echo "\nTest 29: only the first 25 posts (by sort) carry an excerpt\n";
ins_reset_excerpt_fixtures();
$rows = array();
$objs = array();
// 30 posts, distinct publish ages so the days_since_publish ASC tiebreak
// (all views_7d=0) makes the youngest post sort first deterministically.
for ( $i = 1; $i <= 30; $i++ ) {
	$age = $i;  // post 1 = youngest (1 day), post 30 = oldest (30 days)
	$rows[] = array( 'ID' => $i, 'post_title' => "P{$i}", 'post_name' => "p{$i}", 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS ) );
	$objs[ $i ] = array( 'ID' => $i, 'post_status' => 'publish', 'post_excerpt' => '', 'post_content' => "Body text for post {$i} with several words to extract." );
}
$GLOBALS['wpdb']->rows          = $rows;
$GLOBALS['__test_post_objects'] = $objs;
$signals = snt_insights_collect_signals();
ins_eq( 30, count( $signals['posts'] ), '30 posts collected' );
// First 25 (sorted) carry a non-empty excerpt; the rest do not.
$with_excerpt = 0;
$without      = 0;
foreach ( $signals['posts'] as $idx => $p ) {
	if ( $idx < 25 ) {
		ins_true( isset( $p['excerpt'] ) && '' !== $p['excerpt'], "post #" . ( $idx + 1 ) . " carries an excerpt" );
		$with_excerpt++;
	} else {
		ins_true( ! isset( $p['excerpt'] ), "post #" . ( $idx + 1 ) . " (>25) carries NO excerpt key" );
		$without++;
	}
}
ins_eq( 25, $with_excerpt, 'exactly 25 posts carry an excerpt' );
ins_eq( 5,  $without,      '5 posts (26-30) carry no excerpt' );

// ─── Test 30: post_excerpt preferred; whitespace-only falls back ─────
echo "\nTest 30: author post_excerpt preferred; whitespace-only falls back to body\n";
ins_reset_excerpt_fixtures();
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'Has excerpt',       'post_name' => 'a', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS ) ),
	array( 'ID' => 2, 'post_title' => 'Whitespace excerpt', 'post_name' => 'b', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ),
);
$GLOBALS['__test_post_objects'] = array(
	1 => array( 'ID' => 1, 'post_status' => 'publish', 'post_excerpt' => 'Curated author summary.', 'post_content' => 'Different body content that should be IGNORED when an author excerpt exists.' ),
	2 => array( 'ID' => 2, 'post_status' => 'publish', 'post_excerpt' => "   \n\t  ", 'post_content' => 'Body fallback text because the author excerpt is whitespace only.' ),
);
$signals = snt_insights_collect_signals();
// posts[0] is the youngest (ID 1), posts[1] is ID 2.
ins_eq( 'Curated author summary.', $signals['posts'][0]['excerpt'], 'author post_excerpt used verbatim' );
ins_true( false === strpos( $signals['posts'][0]['excerpt'], 'IGNORED' ), 'body NOT used when author excerpt present' );
ins_eq( 'Body fallback text because the author excerpt is whitespace only.', $signals['posts'][1]['excerpt'], 'whitespace-only excerpt → body fallback' );

// ─── Test 31: body-derived excerpt respects the 120-word cap ─────────
echo "\nTest 31: body-derived excerpt is capped at SN_INSIGHTS_EXCERPT_WORDS words\n";
ins_reset_excerpt_fixtures();
$long_body = implode( ' ', array_fill( 0, 400, 'word' ) );  // 400 words
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'Long body', 'post_name' => 'long', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS ) ),
);
$GLOBALS['__test_post_objects'] = array(
	1 => array( 'ID' => 1, 'post_status' => 'publish', 'post_excerpt' => '', 'post_content' => $long_body ),
);
$signals = snt_insights_collect_signals();
$word_count = count( preg_split( '/\s+/', trim( $signals['posts'][0]['excerpt'] ), -1, PREG_SPLIT_NO_EMPTY ) );
ins_eq( SN_INSIGHTS_EXCERPT_WORDS, $word_count, 'excerpt truncated to exactly 120 words' );
ins_true( false === strpos( $signals['posts'][0]['excerpt'], '…' ), 'no ellipsis appended' );

// ─── Test 32: running total-chars ceiling truncates the excerpt set ──
echo "\nTest 32: total-chars ceiling stops attaching excerpts mid-set\n";
ins_reset_excerpt_fixtures();
// Each author excerpt is ~5000 chars; 25 posts × 5000 = 125000 > 60000 cap.
// So only the first ~12-13 posts should carry an excerpt before the
// running total trips the ceiling.
$big_excerpt = str_repeat( 'lorem ipsum dolor ', 280 );  // ~5040 chars
ins_true( strlen( $big_excerpt ) > 4000, 'fixture excerpt is large (>4000 chars)' );
$rows = array();
$objs = array();
for ( $i = 1; $i <= 25; $i++ ) {
	$rows[] = array( 'ID' => $i, 'post_title' => "P{$i}", 'post_name' => "p{$i}", 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $i * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - $i * DAY_IN_SECONDS ) );
	$objs[ $i ] = array( 'ID' => $i, 'post_status' => 'publish', 'post_excerpt' => $big_excerpt, 'post_content' => 'unused' );
}
$GLOBALS['wpdb']->rows          = $rows;
$GLOBALS['__test_post_objects'] = $objs;
$signals = snt_insights_collect_signals();
$attached = 0;
$total_chars = 0;
foreach ( $signals['posts'] as $p ) {
	if ( isset( $p['excerpt'] ) ) {
		$attached++;
		$total_chars += strlen( $p['excerpt'] );
	}
}
ins_true( $attached > 0,  'at least one excerpt attached' );
ins_true( $attached < 25, 'ceiling stopped the set before all 25 got excerpts' );
// FX2: the entry that trips the ceiling is truncated to the remaining budget,
// so the running total is bounded EXACTLY by the ceiling — no one-excerpt
// overshoot (this assertion fails against the pre-FX2 code).
ins_true( $total_chars <= SN_INSIGHTS_EXCERPT_TOTAL_CHARS, 'total excerpt chars bounded EXACTLY by the ceiling (no overshoot)' );

// ─── Test 33: excerpts_count surfaced in signal_summary ──────────────
echo "\nTest 33: run_scan signal_summary carries excerpts_count\n";
ins_reset_excerpt_fixtures();
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_ai_available'] = true;
$GLOBALS['__test_ai_response']  = $valid_json;  // 5 valid recs (from Test 10)
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'One', 'post_name' => 'one', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS ) ),
	array( 'ID' => 2, 'post_title' => 'Two', 'post_name' => 'two', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ),
);
$GLOBALS['__test_post_objects'] = array(
	1 => array( 'ID' => 1, 'post_status' => 'publish', 'post_excerpt' => 'Summary one.', 'post_content' => 'body' ),
	2 => array( 'ID' => 2, 'post_status' => 'publish', 'post_excerpt' => 'Summary two.', 'post_content' => 'body' ),
);
// parse_response validates target post_id=1 exists.
$GLOBALS['__test_posts_exist'] = array( 1 => true );
$result = snt_insights_run_scan( true );
ins_true( is_array( $result ), 'run_scan returns array' );
ins_true( isset( $result['signal_summary']['excerpts_count'] ), 'excerpts_count present in signal_summary' );
ins_eq( 2, $result['signal_summary']['excerpts_count'], 'two excerpts attached → count = 2' );

// ─── Test 34: system instruction mentions the content excerpt ────────
echo "\nTest 34: system instruction notes the excerpt grounding\n";
$sys = snt_insights_system_instruction();
ins_true( false !== stripos( $sys, 'excerpt' ), 'system instruction mentions excerpt' );
// Output shape unchanged — still asks for exactly 5 recs.
ins_true( false !== strpos( $sys, 'exactly 5 recommendations' ), 'still asks for exactly 5 recs (shape intact)' );

// ─── Test 35: find_rec hit — returns the matching recommendation ─────
echo "\nTest 35: snt_insights_find_rec() returns the matching rec\n";
$GLOBALS['__test_transients'][ SN_INSIGHTS_CACHE_KEY ] = array(
	'scanned_at'      => 111,
	'recommendations' => array(
		array( 'id' => 'rec_one', 'type' => 'write_about', 'title' => 'First',  'rationale' => 'r1', 'evidence_pills' => array(), 'target' => null ),
		array( 'id' => 'rec_two', 'type' => 'update_post', 'title' => 'Second', 'rationale' => 'r2', 'evidence_pills' => array(), 'target' => null ),
	),
);
$hit = snt_insights_find_rec( 'rec_two' );
ins_true( is_array( $hit ), 'find_rec returns array on hit' );
ins_eq( 'rec_two', $hit['id'], 'matched rec id' );
ins_eq( 'Second', $hit['title'], 'matched rec title' );

// ─── Test 36: find_rec miss — unknown id returns null ────────────────
echo "\nTest 36: snt_insights_find_rec() returns null for an unknown id\n";
ins_eq( null, snt_insights_find_rec( 'rec_missing' ), 'unknown id → null' );
ins_eq( null, snt_insights_find_rec( '' ),            'empty id → null' );

// ─── Test 37: find_rec expired/no-cache → null ───────────────────────
echo "\nTest 37: snt_insights_find_rec() returns null when no scan is cached\n";
$GLOBALS['__test_transients'] = array();  // cache miss / expired
ins_eq( null, snt_insights_find_rec( 'rec_one' ), 'no cache → null' );

// ─── Test 38: build_draft_postarr — shape + valid block body ─────────
echo "\nTest 38: snt_insights_build_draft_postarr() — draft shape + Notes cat + valid wp:paragraph\n";
$GLOBALS['__test_terms'] = array(
	'category' => array( 'slug' => array( 'notes' => array( 'term_id' => 7, 'slug' => 'notes' ) ) ),
);
$rec = array(
	'id'        => 'rec_synths',
	'type'      => 'write_about',
	'title'     => 'Write about modular synths',
	'rationale' => 'Your modular-synth notes average 4x traffic; you have not published one in 6 months.',
);
$postarr = snt_insights_build_draft_postarr( $rec );
ins_true( is_array( $postarr ), 'build_draft_postarr returns array' );
ins_eq( 'draft', $postarr['post_status'], 'post_status = draft' );
ins_eq( 'post',  $postarr['post_type'],   'post_type = post' );
ins_eq( 'Write about modular synths', $postarr['post_title'], 'title from rec' );
ins_true( isset( $postarr['post_category'] ) && in_array( 7, $postarr['post_category'], true ), 'Notes category id (7) assigned' );
// Body must be a VALID, round-trippable wp:paragraph block.
$body = $postarr['post_content'];
ins_true( false !== strpos( $body, '<!-- wp:paragraph -->' ),  'opening paragraph block comment present' );
ins_true( false !== strpos( $body, '<!-- /wp:paragraph -->' ), 'closing paragraph block comment present' );
ins_true( preg_match( '/<!-- wp:paragraph -->\s*<p>.*<\/p>\s*<!-- \/wp:paragraph -->/s', $body ) === 1, 'block wraps a <p>…</p> (valid serialized shape)' );
ins_true( false !== strpos( $body, 'modular-synth notes average 4x traffic' ), 'rationale text is in the body' );

// ─── Test 39: build_draft_postarr — unseeded Notes cat is skipped ────
echo "\nTest 39: build_draft_postarr() omits post_category when Notes is unseeded\n";
$GLOBALS['__test_terms'] = array();  // get_term_by → false
$postarr = snt_insights_build_draft_postarr( $rec );
ins_true( ! isset( $postarr['post_category'] ) || array() === $postarr['post_category'], 'no Notes term → post_category omitted/empty' );
ins_eq( 'draft', $postarr['post_status'], 'still a draft when category is unseeded' );

// ─── Test 40: build_draft_postarr — title falls back when rec has none ─
echo "\nTest 40: build_draft_postarr() supplies a fallback title for a title-less rec\n";
$no_title = array( 'id' => 'rec_x', 'type' => 'write_about', 'title' => '', 'rationale' => 'Some rationale.' );
$postarr  = snt_insights_build_draft_postarr( $no_title );
ins_true( is_string( $postarr['post_title'] ) && '' !== $postarr['post_title'], 'fallback title is non-empty' );

// ─── Test 41: create_draft_from_rec — inserts + returns the new id ───
echo "\nTest 41: snt_insights_create_draft_from_rec() returns the inserted post id\n";
$GLOBALS['__test_terms'] = array(
	'category' => array( 'slug' => array( 'notes' => array( 'term_id' => 7, 'slug' => 'notes' ) ) ),
);
$GLOBALS['__test_insert_id'] = 555;
unset( $GLOBALS['__test_insert_error'], $GLOBALS['__test_inserted_post'] );
$new_id = snt_insights_create_draft_from_rec( $rec );
ins_eq( 555, $new_id, 'returns wp_insert_post id' );
ins_true( is_array( $GLOBALS['__test_inserted_post'] ), 'wp_insert_post received a postarr' );
ins_eq( 'draft', $GLOBALS['__test_inserted_post']['post_status'], 'inserted as draft' );
ins_true( in_array( 7, $GLOBALS['__test_inserted_post']['post_category'], true ), 'inserted with Notes category' );

// ─── Test 42: create_draft_from_rec — propagates a WP_Error ──────────
echo "\nTest 42: snt_insights_create_draft_from_rec() propagates wp_insert_post WP_Error\n";
$GLOBALS['__test_insert_error'] = new WP_Error( 'db_insert_error', 'insert failed' );
$res = snt_insights_create_draft_from_rec( $rec );
ins_true( $res instanceof WP_Error, 'WP_Error propagated' );
ins_eq( 'db_insert_error', $res->code, 'error code preserved' );
unset( $GLOBALS['__test_insert_error'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * CLI fixture for the Spend watch (Actions minutes + AI spend as health
 * signals). Standalone, global-stub style. The planned-row gate is
 * "owner-only, and every number read from what the platforms actually
 * report — never estimated": the assertions pin that failure states render
 * "unknown" (never a fabricated figure) and that no code path computes a
 * number the platform did not return.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( "HOUR_IN_SECONDS" ) ) { define( "HOUR_IN_SECONDS", 3600 ); }

$fails  = 0;
$passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function apply_filters( $tag, $value ) { return $value; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }

// Option/transient stubs record writes so save + cache behavior is assertable.
$GLOBALS['__opts']       = array();
$GLOBALS['__deleted']    = array();
$GLOBALS['__transients'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = true ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); $GLOBALS['__deleted'][] = $k; return true; }
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

// HTTP stub: preset response per host substring; null = WP_Error.
$GLOBALS['__http'] = array();
$GLOBALS['__http_fn'] = null;
function wp_safe_remote_get( $url, $args = array() ) {
	if ( $GLOBALS['__http_fn'] ) { return call_user_func( $GLOBALS['__http_fn'], $url ); }
	foreach ( $GLOBALS['__http'] as $needle => $resp ) {
		if ( false !== strpos( $url, $needle ) ) { return $resp; }
	}
	return array( 'response' => array( 'code' => 500 ), 'body' => '' );
}
function is_wp_error( $x ) { return null === $x; }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }

require __DIR__ . '/../inc/spend-watch.php';

// The legacy plan endpoint (/settings/billing/actions) is 410 Gone under
// GitHub's enhanced billing platform — owner-caught in httpdiag: every
// refresh fired a permanently dead request before the fallback. The module
// must not call it at all.
ok( ! function_exists( 'sn_spend_gh_usage_normalize' ), 'the legacy plan-endpoint normalizer is deleted with its endpoint' );
$gh_src = (string) file_get_contents( __DIR__ . '/../inc/spend-watch.php' );
ok( strpos( $gh_src, 'settings/billing/actions' ) === false,
	'no code path can request the retired legacy endpoint (410 Gone)' );

// --- AI amount walker --------------------------------------------------------
// The cost report's documented unit is CENTS ("decimal strings in lowest
// units") — the walker converts to dollars exactly once, at the sum.
ok( 12.34 === sn_spend_ai_sum_amounts( array( 'data' => array(
	array( 'results' => array( array( 'amount' => '1000' ), array( 'amount' => 234 ) ) ),
) ) ), 'AI walker: sums reported cent amounts and converts to dollars (1234c -> 12.34)' );
ok( null === sn_spend_ai_sum_amounts( array( 'data' => array( array( 'results' => array() ) ) ) ),
	'AI walker: a response with no amounts is unknown, not $0.00' );

// --- enhanced-billing usage report (the ONLY GitHub door) --------------------
// Reports USAGE ONLY (no included-minutes quota) — the render must show what
// the platform said, never an invented "of 3,000".
$report = array( 'usageItems' => array(
	array( 'product' => 'actions', 'sku' => 'actions_linux', 'quantity' => 120.5, 'unitType' => 'Minutes', 'netAmount' => 0.40 ),
	array( 'product' => 'actions', 'sku' => 'actions_macos', 'quantity' => 10, 'unitType' => 'Minutes', 'netAmount' => 0.79 ),
	array( 'product' => 'copilot', 'sku' => 'copilot_seat', 'quantity' => 1, 'unitType' => 'Seats', 'netAmount' => 10.00 ),
) );
$m = sn_spend_gh_report_minutes( $report );
ok( 131 === $m['used'] && 1.19 === $m['billed'],
	'report parser: sums ONLY actions minute items (131 min), billed from netAmount (1.19)' );
ok( 0 === sn_spend_gh_report_minutes( array( 'usageItems' => array() ) )['used'],
	'report parser: empty usageItems = measured ZERO minutes, not unknown' );
ok( null === sn_spend_gh_report_minutes( array( 'nope' => 1 ) ),
	'report parser: missing usageItems = unknown, never a defaulted zero' );

// Fetch fallback: legacy 403 (fine-grained rejected) -> enhanced 200.
$GLOBALS['__transients'] = array();
$GLOBALS['__opts']['sn_spend_gh_token'] = 'github_pat_finegrained';
$GLOBALS['__http']['settings/billing/usage'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $report ) );
$fg = sn_spend_gh_usage();
ok( true === $fg['ok'] && 131 === $fg['used'],
	'fetch: the usage report is requested directly (no dead legacy call first)' );

$h_fg = sn_spend_watch_health_section();
ok( strpos( $h_fg, '131' ) !== false && strpos( $h_fg, '1.19' ) !== false && strpos( $h_fg, 'of ' ) === false,
	'render (usage source): used minutes + billed dollars, and NO invented "of <quota>"' );

// Endpoint failing -> unknown, as before.
$GLOBALS['__transients'] = array();
$GLOBALS['__http']['settings/billing/usage'] = array( 'response' => array( 'code' => 500 ), 'body' => '' );
$both = sn_spend_gh_usage();
ok( false === $both['ok'], 'fetch: a failing usage read records ok=false (renders unknown)' );
unset( $GLOBALS['__http']['settings/billing/usage'] );
$GLOBALS['__transients'] = array();
$GLOBALS['__opts'] = array();

// --- section: owner-only mount + zero-vs-null honesty ------------------------
ok( '' === sn_spend_watch_health_section(), 'unconfigured: the section is absent (the uptime precedent), not a nag' );

$GLOBALS['__opts']['sn_spend_gh_token'] = 'github_pat_test';
$GLOBALS['__http']['api.github.com']    = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'usageItems' => array(
		array( 'product' => 'actions', 'sku' => 'actions_linux', 'quantity' => 2905, 'unitType' => 'Minutes', 'netAmount' => 0 ),
	) ) ),
);
$h = sn_spend_watch_health_section();
ok( strpos( $h, '2,905' ) !== false && strpos( $h, '0.00' ) !== false,
	'configured + healthy fetch: the section shows platform-reported minutes + billed dollars' );
ok( strpos( $h, 'unknown' ) === false, 'healthy fetch: no stray unknown' );

// PROVEN HONEST: an API failure renders unknown — never a fabricated figure.
$GLOBALS['__transients'] = array(); // drop the cached snapshot
$GLOBALS['__http']['api.github.com'] = array( 'response' => array( 'code' => 410 ), 'body' => '' );
$u = sn_spend_watch_health_section();
ok( stripos( $u, 'unknown' ) !== false && strpos( $u, '2,905' ) === false && strpos( $u, '0%' ) === false,
	'fetch failure: Actions minutes read unknown — no stale figure, no fake zero' );

// Failure snapshots cache SHORT: the marker must be distinguishable.
ok( isset( $GLOBALS['__transients']['sn_spend_gh_usage']['ok'] ) && false === $GLOBALS['__transients']['sn_spend_gh_usage']['ok'],
	'failure snapshot cached with ok=false (a retry can tell failure from absence)' );

// AI side: configured + amounts -> dollar figure from the platform only.
$GLOBALS['__transients'] = array();
$GLOBALS['__opts']['sn_spend_ai_admin_key'] = 'sk-ant-admin-test';
$GLOBALS['__http']['api.anthropic.com']     = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'data' => array( array( 'results' => array( array( 'amount' => '750' ) ) ) ), 'has_more' => false ) ),
);
$GLOBALS['__http']['api.github.com'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'total_minutes_used' => 100, 'included_minutes' => 3000 ) ),
);
$a = sn_spend_watch_health_section();
ok( strpos( $a, '7.50' ) !== false, 'AI spend renders the platform-reported month figure (7.50)' );

// Pagination: has_more pages must ALL be summed — a single-page read of a
// month silently under-counts (the endpoint buckets daily).
$GLOBALS['__transients'] = array();
$GLOBALS['__page'] = 0;
$GLOBALS['__http'] = array();
function sn_test_paged_response( $url ) {
	$GLOBALS['__page']++;
	if ( false !== strpos( $url, 'page=' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array(
			'data' => array( array( 'results' => array( array( 'amount' => '50' ) ) ) ), 'has_more' => false,
		) ) );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array(
		'data' => array( array( 'results' => array( array( 'amount' => '100' ) ) ) ),
		'has_more' => true, 'next_page' => 'page_xyz',
	) ) );
}
$GLOBALS['__http_fn'] = 'sn_test_paged_response';
$paged = sn_spend_ai_cost();
ok( true === $paged['ok'] && 1.50 === $paged['total'],
	'AI cost follows next_page and sums all pages (100c + 50c = $1.50)' );
$GLOBALS['__http_fn'] = null;

// --- save handler contract (mirrors the Better Stack idiom) ------------------
$GLOBALS['__opts'] = array();
sn_spend_watch_handle_save( array( 'sn_spend_gh_token' => 'ghp_new', 'sn_spend_ai_admin_key' => '••••abcd' ) );
ok( 'ghp_new' === ( $GLOBALS['__opts']['sn_spend_gh_token'] ?? null ), 'save: fresh GH token stored' );
ok( ! isset( $GLOBALS['__opts']['sn_spend_ai_admin_key'] ), 'save: an obscured round-trip value is NEVER written' );
sn_spend_watch_handle_save( array( 'sn_spend_gh_token' => 'clear' ) );
ok( ! isset( $GLOBALS['__opts']['sn_spend_gh_token'] ) && in_array( 'sn_spend_gh_token', $GLOBALS['__deleted'], true ),
	'save: the literal clear removes the stored token' );

// --- mount guards ------------------------------------------------------------
$widget = (string) file_get_contents( __DIR__ . '/../inc/site-health-widget.php' );
ok( strpos( $widget, 'sn_spend_watch_health_section' ) !== false, 'the S&N Health widget mounts the spend section' );
// Reads the admin-post LAYER, not one file: the handlers live in
// inc/admin-post-actions/*.php behind a thin loader (v12.21.2), so scanning
// the loader alone would find nothing.
$save = (string) implode( '', array_map( 'file_get_contents', array_merge(
	array( __DIR__ . '/../inc/admin-post-actions.php' ),
	glob( __DIR__ . '/../inc/admin-post-actions/*.php' ) ?: array()
) ) );
ok( strpos( $save, 'sn_spend_watch_handle_save' ) !== false, 'the monitoring save handler routes the spend fields' );
$fieldset = (string) file_get_contents( __DIR__ . '/../inc/uptime-status.php' );
ok( strpos( $fieldset, 'sn_spend_watch_settings_fields_html' ) !== false, 'the monitoring fieldset renders the spend fields' );
$main = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
ok( strpos( $main, "inc/spend-watch.php" ) !== false, 'the plugin bootstrap requires the spend module' );

echo "\nResult: $passes passed, $fails failed.\n";
exit( $fails > 0 ? 1 : 0 );

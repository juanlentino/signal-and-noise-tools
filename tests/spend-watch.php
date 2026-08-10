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
function wp_safe_remote_get( $url, $args = array() ) {
	foreach ( $GLOBALS['__http'] as $needle => $resp ) {
		if ( false !== strpos( $url, $needle ) ) { return $resp; }
	}
	return array( 'response' => array( 'code' => 500 ), 'body' => '' );
}
function is_wp_error( $x ) { return null === $x; }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }

require __DIR__ . '/../inc/spend-watch.php';

// --- GitHub usage normalizer -------------------------------------------------
$n = sn_spend_gh_usage_normalize( array( 'total_minutes_used' => 2905, 'included_minutes' => 3000 ) );
ok( 2905 === $n['used'] && 3000 === $n['included'] && 97 === $n['pct'],
	'GH normalizer: used/included/pct straight from the platform payload (97%)' );
ok( null === sn_spend_gh_usage_normalize( array( 'included_minutes' => 3000 ) ),
	'GH normalizer: a payload missing the used figure is REFUSED, never defaulted to 0' );
ok( null === sn_spend_gh_usage_normalize( array( 'total_minutes_used' => 10, 'included_minutes' => 0 ) )['pct'],
	'GH normalizer: included=0 yields pct null (no divide-by-zero, no invented percent)' );

// --- AI amount walker --------------------------------------------------------
ok( 12.34 === sn_spend_ai_sum_amounts( array( 'data' => array(
	array( 'results' => array( array( 'amount' => '10.00' ), array( 'amount' => 2.34 ) ) ),
) ) ), 'AI walker: sums every reported amount across the response (12.34)' );
ok( null === sn_spend_ai_sum_amounts( array( 'data' => array( array( 'results' => array() ) ) ) ),
	'AI walker: a response with no amounts is unknown, not $0.00' );

// --- section: owner-only mount + zero-vs-null honesty ------------------------
ok( '' === sn_spend_watch_health_section(), 'unconfigured: the section is absent (the uptime precedent), not a nag' );

$GLOBALS['__opts']['sn_spend_gh_token'] = 'ghp_test';
$GLOBALS['__http']['api.github.com']    = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'total_minutes_used' => 2905, 'included_minutes' => 3000 ) ),
);
$h = sn_spend_watch_health_section();
ok( strpos( $h, '2,905' ) !== false && strpos( $h, '3,000' ) !== false && strpos( $h, '97%' ) !== false,
	'configured + healthy fetch: the section shows the platform-reported minutes (2,905 of 3,000, 97%)' );
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
	'body'     => json_encode( array( 'data' => array( array( 'results' => array( array( 'amount' => '7.50' ) ) ) ) ) ),
);
$GLOBALS['__http']['api.github.com'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'total_minutes_used' => 100, 'included_minutes' => 3000 ) ),
);
$a = sn_spend_watch_health_section();
ok( strpos( $a, '7.50' ) !== false, 'AI spend renders the platform-reported month figure (7.50)' );

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
$save = (string) file_get_contents( __DIR__ . '/../inc/admin-post-actions.php' );
ok( strpos( $save, 'sn_spend_watch_handle_save' ) !== false, 'the monitoring save handler routes the spend fields' );
$fieldset = (string) file_get_contents( __DIR__ . '/../inc/uptime-status.php' );
ok( strpos( $fieldset, 'sn_spend_watch_settings_fields_html' ) !== false, 'the monitoring fieldset renders the spend fields' );
$main = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
ok( strpos( $main, "inc/spend-watch.php" ) !== false, 'the plugin bootstrap requires the spend module' );

echo "\nResult: $passes passed, $fails failed.\n";
exit( $fails > 0 ? 1 : 0 );

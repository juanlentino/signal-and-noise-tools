<?php
/**
 * i18n sweep contract (v9.42.1): every user-visible string on the analytics
 * admin surface routes through the __()/esc_html__() family with the
 * 'signal-and-noise-tools' text domain — the why-strings, column headers, the
 * three raw titles (Top sources / drill-down / Recommendations), and the
 * inline labels the D-arc left raw. English output must stay byte-identical
 * (default locale returns the msgid unchanged).
 *
 * Two oracles:
 *  1. Behavioral: recording __-family stubs capture (msgid, domain) pairs
 *     while the REAL render functions run — a string is translatable iff it
 *     arrives through the recorder with the plugin domain, and the rendered
 *     English stays byte-identical (stubs return input unchanged).
 *  2. Source contract: call sites whose views need heavy fixtures pin the
 *     wrapped form in source (the tests/analytics-tokens.php idiom), plus the
 *     login-defense D5 §-citation drift fix (§5 → §2).
 *
 * Run: php tests/analytics-i18n.php
 * @since plugin v9.42.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

// ---- Recording __-family stubs (return input unchanged = en_US behavior) ----
$GLOBALS['__i18n'] = array();
function sn_test_i18n_record( $s, $d ) { $GLOBALS['__i18n'][] = array( (string) $s, $d ); }
function __( $s, $d = null ) { sn_test_i18n_record( $s, $d ); return $s; }
function esc_html__( $s, $d = null ) { sn_test_i18n_record( $s, $d ); return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { sn_test_i18n_record( $s, $d ); return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function _n( $s, $p, $n, $d = null ) { $r = 1 === (int) $n ? $s : $p; sn_test_i18n_record( $r, $d ); return $r; }

/** True iff $text was routed through a translation fn with the plugin domain. */
function sn_i18n_seen( $text ) {
	foreach ( $GLOBALS['__i18n'] as $c ) {
		if ( $c[0] === $text && 'signal-and-noise-tools' === $c[1] ) { return true; }
	}
	return false;
}

// ---- WP core stubs (the drilldown-suite idiom: realistic escaping) ----
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function number_format_i18n( $n ) { return (string) (int) $n; }
function wp_kses_post( $s ) { return (string) $s; }
function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); }
function add_query_arg( $a ) { $q = array(); foreach ( (array) $a as $k => $v ) { $q[] = $k . '=' . rawurlencode( (string) $v ); } return '?' . implode( '&', $q ); }
function remove_query_arg( $k, $u = '' ) { return '?cleared'; }
// Recommendations engine data reads: quiet -> empty panel (the view-content-suite idiom).
function sn_analytics_posts_lifecycle( $limit = 0 ) { return null; }
function sn_health_last_scan() { return null; }

require_once __DIR__ . '/../inc/analytics-render-tables.php';   // requires panels + render-helpers itself
require_once __DIR__ . '/../inc/analytics-render-drilldown.php';
require_once __DIR__ . '/../inc/analytics-render-events.php';
require_once __DIR__ . '/../inc/analytics-render-quality.php';
require_once __DIR__ . '/../inc/analytics-recommendations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function capture( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "analytics-i18n suite - plugin v9.42.1\n";

echo "\nTest: drill-down panel: title, headers, labels route through i18n\n";
$html = capture( function () {
	snt_analytics_render_drilldown_panel( 'country', 'DE', array( array( 'path' => '/x', 'views' => 3, 'visits' => 2 ) ) );
} );
ok( false !== strpos( $html, '<span>Top pages · Country = DE</span>' ), 'English title byte-identical through the real panel' );
ok( sn_i18n_seen( 'Top pages · %1$s = %2$s' ), 'drill title is a translatable sprintf msgid' );
ok( sn_i18n_seen( 'Country' ), 'the dim label map is translatable' );
ok( sn_i18n_seen( 'Page' ) && sn_i18n_seen( 'Views' ) && sn_i18n_seen( 'Visits' ), 'drill table headers translatable' );
ok( sn_i18n_seen( 'Clear drill-down' ), 'the Clear drill-down link is translatable' );
ok( false !== strpos( $html, '&larr; Clear drill-down</a>' ), 'the &larr; entity stays outside the msgid, output unchanged' );
$html = capture( function () {
	snt_analytics_render_drilldown_panel( 'device', 'phone', array() );
} );
ok( sn_i18n_seen( 'No pages for this segment in this range (or it needs live Analytics Engine data).' ), 'segment-empty why translatable' );
ok( false !== strpos( $html, 'No pages for this segment in this range (or it needs live Analytics Engine data).' ), 'segment-empty English unchanged' );

echo "\nTest: paths table: headers + empty why\n";
$html = capture( function () {
	snt_analytics_render_paths_table( array( array( 'path' => '/a/', 'views' => 9, 'visits' => 5, 'scroll_avg' => 40.0, 'time_avg' => 30000.0 ) ) );
} );
ok( sn_i18n_seen( 'Path' ) && sn_i18n_seen( 'Scroll' ) && sn_i18n_seen( 'Time' ), 'paths table headers translatable' );
ok( false !== strpos( $html, '>Path</th>' ) && false !== strpos( $html, '>Time</th>' ), 'paths header English unchanged' );
capture( function () { snt_analytics_render_paths_table( array() ); } );
ok( sn_i18n_seen( 'No page views in this range.' ), 'paths empty why translatable' );

echo "\nTest: dim table + low-engagement + pageroles headers/whys\n";
$html = capture( function () {
	snt_analytics_render_dim_table( 'X', array( array( 'value' => 'Direct', 'views' => 4, 'visits' => 2 ) ), 'none' );
} );
ok( false !== strpos( $html, '>Views</th>' ) && false !== strpos( $html, '>Visits</th>' ), 'dim header English unchanged' );
capture( function () { snt_analytics_render_lowengage( array() ); } );
ok( sn_i18n_seen( 'No low-engagement pages in this range: readers are sticking around.' ), 'low-engagement why translatable' );
$html = capture( function () {
	snt_analytics_render_pageroles_table( array( array( 'path' => '/a', 'views' => 2, 'visits' => 2 ) ), 'entry' );
} );
ok( sn_i18n_seen( 'Path' ) && false !== strpos( $html, '>Path</th>' ), 'pageroles headers translatable, English unchanged' );

echo "\nTest: events tables: headers, Property:/Clear, whys\n";
$html = capture( function () {
	snt_analytics_render_events_table( array( array( 'name' => 'click', 'events' => 3, 'visitors' => 2 ) ) );
} );
ok( sn_i18n_seen( 'Event' ) && sn_i18n_seen( 'Events' ) && sn_i18n_seen( 'Visitors' ), 'events table headers translatable' );
ok( false !== strpos( $html, '>Event</th>' ), 'events header English unchanged' );
capture( function () { snt_analytics_render_events_table( array() ); } );
ok( sn_i18n_seen( 'No custom events in this range yet.' ), 'events empty why translatable' );
$html = capture( function () {
	snt_analytics_render_event_props_table( array( array( 'property' => 'button', 'value' => 'cta', 'events' => 3, 'visitors' => 2 ) ), 'button' );
} );
ok( sn_i18n_seen( 'Property:' ) && sn_i18n_seen( 'Clear' ), 'filtered subhead label + Clear link translatable' );
ok( false !== strpos( $html, 'Property: <strong>button</strong>' ), 'filtered subhead English byte-identical' );
ok( sn_i18n_seen( 'Value' ), 'props Value header translatable' );
capture( function () { snt_analytics_render_event_props_table( array(), '' ); } );
ok( sn_i18n_seen( 'No event properties in this range yet.' ), 'props empty why translatable' );

echo "\nTest: quality: legend, bot-networks table, whys\n";
$html = capture( function () {
	snt_analytics_render_bot_breakdown( array(
		'totals'           => array( 'human' => 4, 'suspect' => 1, 'bot' => 2, 'total' => 7 ),
		'top_bot_networks' => array( array( 'value' => 'BadNet', 'views' => 2 ) ),
	) );
} );
ok( sn_i18n_seen( 'Human' ) && sn_i18n_seen( 'Suspect' ) && sn_i18n_seen( 'Bot' ), 'quality legend words translatable' );
ok( false !== strpos( $html, '</span> Human 4' ), 'legend English byte-identical' );
ok( sn_i18n_seen( 'Top bot networks' ) && sn_i18n_seen( 'Network' ), 'bot-networks subhead + header translatable' );
capture( function () { snt_analytics_render_bot_breakdown( array( 'totals' => array() ) ); } );
ok( sn_i18n_seen( 'No traffic recorded in this range yet.' ), 'quality empty why translatable' );

echo "\nTest: Recommendations panel title\n";
$html = capture( function () { snt_analytics_render_recommendations_panel(); } );
ok( sn_i18n_seen( 'Recommendations' ), 'Recommendations title translatable' );
ok( false !== strpos( $html, '<span>Recommendations</span>' ), 'Recommendations English unchanged through the real panel' );

// ---- Settings-surface stubs (credentials form + worker-setup reference — both
// self-contained, no heavy fixtures needed): the v9.42.1 sweep deliberately left
// these two functions' form labels / help prose / setup-doc <li>s raw; this is
// the follow-up finishing the job for inc/analytics-render-settings.php. ----
if ( ! defined( 'SN_CF_ACCOUNT_ID_OPT' ) )      { define( 'SN_CF_ACCOUNT_ID_OPT', 'sn_cf_account_id' ); }
if ( ! defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ) { define( 'SN_CF_ANALYTICS_TOKEN_OPT', 'sn_cf_analytics_token' ); }
function wp_nonce_field( $a = -1, $b = '_wpnonce', $c = true, $d = true ) { echo ''; return ''; }
$GLOBALS['__settings_opts'] = array( SN_CF_ACCOUNT_ID_OPT => '', SN_CF_ANALYTICS_TOKEN_OPT => '' );
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__settings_opts'] ) ? $GLOBALS['__settings_opts'][ $k ] : $d; }
function sn_mask_secret( $s ) { return '' === (string) $s ? '' : '••••'; }
require_once __DIR__ . '/../inc/analytics-render-settings.php';

echo "\nTest: settings surface: credentials form (unlocked) routes labels/help/placeholders/buttons through i18n\n";
$html = capture( 'snt_analytics_render_credentials' );
ok( sn_i18n_seen( 'Credentials' ), 'credentials heading translatable' );
ok( sn_i18n_seen( 'Read-only Cloudflare credentials the dashboard uses to query Analytics Engine. A wp-config constant (%1$s / %2$s) overrides these and locks the field.' ), 'credentials help prose is a translatable sprintf msgid' );
ok( sn_i18n_seen( 'Account ID' ), 'Account ID label translatable' );
ok( sn_i18n_seen( 'Account Analytics Read token' ), 'Account Analytics Read token label translatable' );
ok( sn_i18n_seen( '32-char Cloudflare account ID' ), 'account-id placeholder translatable' );
ok( sn_i18n_seen( 'Paste a fresh token; type ‘clear’ to remove' ), 'token placeholder translatable' );
ok( sn_i18n_seen( 'Save' ), 'Save button translatable' );
ok( sn_i18n_seen( 'Test connection' ), 'Test connection button translatable' );
ok( false !== strpos( $html, '<h3 class="sn-fieldset-h">Credentials</h3>' ), 'credentials heading English byte-identical' );
ok( false !== strpos( $html, 'A wp-config constant (<code>SN_CF_ANALYTICS_TOKEN</code> / <code>SN_CF_ACCOUNT_ID</code>) overrides these and locks the field.' ), 'credentials help prose English byte-identical (the two <code> constants stay outside the msgid)' );
ok( false !== strpos( $html, '<strong>Account ID</strong>' ) && false !== strpos( $html, '<strong>Account Analytics Read token</strong>' ), 'field labels English byte-identical' );
ok( false !== strpos( $html, 'placeholder="32-char Cloudflare account ID"' ), 'account-id placeholder English byte-identical' );
ok( false !== strpos( $html, 'placeholder="Paste a fresh token; type ‘clear’ to remove"' ), 'token placeholder English byte-identical (curly quotes: htmlspecialchars/ENT_QUOTES only touches ASCII \' , same dodge the file already uses at lines 143/175/263/308/386)' );
ok( false !== strpos( $html, '>Save</button>' ) && false !== strpos( $html, '>Test connection</button>' ), 'button labels English byte-identical' );

echo "\nTest: settings surface: credentials form (locked) routes the locked-value copy through i18n\n";
define( 'SN_CF_ACCOUNT_ID', 'abc123' );
define( 'SN_CF_ANALYTICS_TOKEN', 'shh-secret' );
$html2 = capture( 'snt_analytics_render_credentials' );
ok( sn_i18n_seen( '(set in wp-config)' ), 'locked-field value copy translatable' );
ok( false !== strpos( $html2, 'value="(set in wp-config)"' ), 'locked-field value English byte-identical' );
ok( false === strpos( $html2, '<button' ), 'both constants locked → no Save/Test buttons rendered (unrelated to i18n, sanity check on the fixture)' );

echo "\nTest: settings surface: worker-setup reference routes the summary + setup-doc <li>s through i18n\n";
$html3 = capture( 'snt_analytics_render_worker_setup' );
ok( sn_i18n_seen( 'Cloudflare Worker setup (manual, one-time)' ), 'worker-setup summary translatable' );
ok( sn_i18n_seen( 'Read token' ), 'Read-token step label translatable' );
ok( sn_i18n_seen( '(for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with %1$s. The Account ID is in the dashboard URL: %2$s.' ), 'Read-token step prose is a translatable sprintf msgid' );
ok( sn_i18n_seen( 'Deploy the edge Worker + its secrets' ), 'Deploy step label translatable' );
ok( sn_i18n_seen( '(from the analytics-worker repo: this can’t be done from WordPress):' ), 'Deploy step prose translatable' );
ok( sn_i18n_seen( 'Theme beacon' ), 'Theme-beacon step label translatable' );
ok( sn_i18n_seen( 'set %1$s in %2$s to the SAME value as the Worker’s %3$s so the front-end beacon is accepted.' ), 'Theme-beacon step prose is a translatable sprintf msgid' );
ok( sn_i18n_seen( 'Hit %s above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.' ), 'final step prose is a translatable sprintf msgid' );
ok( false !== strpos( $html3, '<summary>Cloudflare Worker setup (manual, one-time)</summary>' ), 'worker-setup summary English byte-identical' );
ok( false !== strpos( $html3, '<li><strong>Read token</strong> (for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with <code>Account · Analytics · Read</code>. The Account ID is in the dashboard URL: <code>dash.cloudflare.com/&lt;account_id&gt;</code>.</li>' ), 'Read-token step English byte-identical (Cloudflare permission scope + URL pattern stay outside the msgid)' );
ok( false !== strpos( $html3, '<li><strong>Deploy the edge Worker + its secrets</strong> (from the analytics-worker repo: this can’t be done from WordPress):<pre class="sn-an-pre">wrangler secret put SN_PX_TOKEN' ), 'Deploy step English byte-identical through the <pre> block' );
ok( false !== strpos( $html3, "wrangler deploy</pre></li>" ), 'wrangler command block unchanged (literal shell commands, not translatable)' );
ok( false !== strpos( $html3, '<li><strong>Theme beacon</strong>: set <code>SN_BEACON_TOKEN</code> in <code>wp-config.php</code> to the SAME value as the Worker’s <code>SN_PX_TOKEN</code> so the front-end beacon is accepted.</li>' ), 'Theme-beacon step English byte-identical (constant names + file name stay outside the msgid)' );
ok( false !== strpos( $html3, '<li>Hit <strong>Test connection</strong> above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.</li>' ), 'final step English byte-identical (reuses the Test connection msgid)' );

echo "\nTest: source contract: wrapped forms pinned at heavy-fixture call sites\n";
$contract = array(
	'inc/analytics-view-content.php'      => array(
		"__( 'Top sources', 'signal-and-noise-tools' )",
		"__( 'No referrers in this range.', 'signal-and-noise-tools' )",
	),
	'inc/analytics-view-geography.php'    => array(
		"__( 'No country data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No country data in this range.', 'signal-and-noise-tools' )",
		"__( 'No city data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No region data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No network data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No edge-location data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No timezone data yet (needs worker v1.7.0 + traffic).', 'signal-and-noise-tools' )",
	),
	'inc/analytics-view-technology.php'   => array(
		"__( 'No browser data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No OS data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No device data in this range.', 'signal-and-noise-tools' )",
		"__( 'No protocol data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No TLS data in this range yet.', 'signal-and-noise-tools' )",
	),
	'inc/analytics-view-engagement.php'   => array(
		"__( 'No TCP round-trips in this range. HTTP/3 connections carry no RTT, so only HTTP/1–2 visitors are measured (needs worker v1.7.0 + traffic).', 'signal-and-noise-tools' )",
		"__( 'Percentiles need live Analytics Engine data for this window.', 'signal-and-noise-tools' )",
		"__( 'No field Core Web Vitals yet: needs the web-vitals beacon (theme v10.14.0) + worker v1.8.0 + traffic.', 'signal-and-noise-tools' )",
		"__( '(reflects the last ~90 days. Analytics Engine raw retention)', 'signal-and-noise-tools' )",
	),
	'inc/analytics-view-quality.php'      => array(
		"__( 'No bot-confidence scores in this range: needs traffic recorded with Cloudflare Bot Management enabled (scores arrive as 1–99).', 'signal-and-noise-tools' )",
	),
	'inc/analytics-admin.php'             => array(
		"esc_html__( 'No analytics data in this range yet. New data appears within ~15 minutes of a visit once the worker is live.', 'signal-and-noise-tools' )",
	),
	'inc/analytics-render-geography.php'  => array(
		"__( 'World map asset missing.', 'signal-and-noise-tools' )",
	),
	'inc/analytics-render-distribution.php' => array(
		"__( 'No referrer data in this range yet.', 'signal-and-noise-tools' )",
		"__( 'No hourly data in this range yet.', 'signal-and-noise-tools' )",
	),
	'inc/analytics-render-anomalies.php'  => array(
		"esc_html__( 'Page', 'signal-and-noise-tools' )",
		"esc_html__( 'Signal', 'signal-and-noise-tools' )",
		"esc_html__( 'Detail', 'signal-and-noise-tools' )",
		"__( 'Deep scroll, fast leave', 'signal-and-noise-tools' )",
		"__( 'Long dwell, low scroll', 'signal-and-noise-tools' )",
	),
	'inc/analytics-render-tables.php'     => array(
		"esc_html__( 'Trend', 'signal-and-noise-tools' )",
	),
	'inc/analytics-widget.php'            => array(
		"__( 'No page views in the last 7 days.', 'signal-and-noise-tools' )",
		"__( 'No referrers in the last 7 days.', 'signal-and-noise-tools' )",
	),
	'inc/analytics-recommendations.php'   => array(
		"__( 'Open', 'signal-and-noise-tools' )",
	),
	'inc/analytics-render-settings.php'   => array(
		"esc_html__( 'Credentials', 'signal-and-noise-tools' )",
		"esc_html__( 'Read-only Cloudflare credentials the dashboard uses to query Analytics Engine. A wp-config constant (%1\$s / %2\$s) overrides these and locks the field.', 'signal-and-noise-tools' )",
		"esc_html__( 'Account ID', 'signal-and-noise-tools' )",
		"esc_attr__( '(set in wp-config)', 'signal-and-noise-tools' )",
		"esc_attr__( '32-char Cloudflare account ID', 'signal-and-noise-tools' )",
		"esc_html__( 'Account Analytics Read token', 'signal-and-noise-tools' )",
		"esc_attr__( 'Paste a fresh token; type ‘clear’ to remove', 'signal-and-noise-tools' )",
		"esc_html__( 'Save', 'signal-and-noise-tools' )",
		"esc_html__( 'Test connection', 'signal-and-noise-tools' )",
		"esc_html__( 'Cloudflare Worker setup (manual, one-time)', 'signal-and-noise-tools' )",
		"esc_html__( 'Read token', 'signal-and-noise-tools' )",
		"esc_html__( '(for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with %1\$s. The Account ID is in the dashboard URL: %2\$s.', 'signal-and-noise-tools' )",
		"esc_html__( 'Deploy the edge Worker + its secrets', 'signal-and-noise-tools' )",
		"esc_html__( '(from the analytics-worker repo: this can’t be done from WordPress):', 'signal-and-noise-tools' )",
		"esc_html__( 'Theme beacon', 'signal-and-noise-tools' )",
		"esc_html__( 'set %1\$s in %2\$s to the SAME value as the Worker’s %3\$s so the front-end beacon is accepted.', 'signal-and-noise-tools' )",
		"esc_html__( 'Hit %s above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.', 'signal-and-noise-tools' )",
	),
);
foreach ( $contract as $file => $needles ) {
	$src = (string) file_get_contents( __DIR__ . '/../' . $file );
	foreach ( $needles as $needle ) {
		ok( false !== strpos( $src, $needle ), $file . ' pins ' . substr( $needle, 0, 60 ) . '…' );
	}
}
$settings = (string) file_get_contents( __DIR__ . '/../inc/analytics-render-settings.php' );
ok( 2 <= substr_count( $settings, "esc_html__( 'Locked by the %s constant.', 'signal-and-noise-tools' )" ), 'settings: both locked-constant notes share one sprintf msgid' );

echo "\nTest: login-defense D5 §-citation drift (adoption is §2, not §5)\n";
$lg = (string) file_get_contents( __DIR__ . '/../inc/login-defense-analytics.php' );
ok( 0 === substr_count( $lg, 'D5 §5' ), 'no stale D5 §5 citations remain (that section is the perf memo)' );
ok( 5 <= substr_count( $lg, 'D5 §2' ), 'all five primitive-adoption comments cite D5 §2' );
ok( false !== strpos( $lg, 'D5 §3' ) && false !== strpos( $lg, 'D5 §4' ), 'the correct §3/§4 citations are untouched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

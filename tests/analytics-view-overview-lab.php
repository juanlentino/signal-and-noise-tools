<?php
/**
 * Tests for inc/analytics-view-overview-lab.php — the v9.67.0 flag-gated
 * "Overview (preview)" static mock (assembly option C, 2026-07-18 dashboard
 * audit: "a static mock render fn behind a feature-flagged tab slug in
 * SN_ANALYTICS_VIEWS for owner review before any data wiring").
 *
 * Contract under test:
 *  - Flag OFF (default): the tab exists NOWHERE — the effective registry
 *    (snt_analytics_views()) is byte-identical to SN_ANALYTICS_VIEWS (still
 *    11 views), ?sn_view=overview-lab resolves to the content default, and
 *    the rendered admin page carries no preview link/label/badge.
 *  - Flag ON (sn_analytics_landing_preview option): the tab registers FIRST,
 *    resolves, owns its chrome, and renders the static mock — every panel
 *    carrying the unmistakable "PREVIEW — sample data" badge (honesty rules
 *    apply to fake data too) plus the follow-up docs note.
 *  - The mock reads NO analytics accessors (none are stubbed here beyond the
 *    dashboard shell's own needs — an accessor call would fatal this suite).
 *  - Settings toggle: fold render + snapshot + the allow-listed save handler
 *    (option present when on, DELETED when off — absent, never a stored 0).
 *  - Abilities surface untouched (source pins).
 *
 * Run: php tests/analytics-view-overview-lab.php
 * @since plugin v9.67.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );

// ---- WP stubs (the tests/analytics-admin.php idiom: realistic escaping) ----
function add_action( $h, $c = null, $p = 10, $a = 1 ) {}
function do_action( $h = '', ...$args ) {}
function apply_filters( $tag, $value ) { return $value; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = null ) {
	if ( null === $url ) { $url = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/wp-admin/index.php?page=sn-analytics'; }
	$sep = ( strpos( (string) $url, '?' ) !== false ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url ) {
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) { return $url; }
	parse_str( $parts[1], $q );
	foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
	return $q ? $parts[0] . '?' . http_build_query( $q ) : $parts[0];
}
$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=sn-analytics';
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); }
function current_user_can( $c ) { return true; }
function wp_kses_post( $s ) { return (string) $s; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" />'; }
function checked( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' checked' : '';
	if ( $echo ) { echo $r; }
	return $r;
}

// Option store seam — the flag lives here. delete_option REMOVES the key
// (absent ≠ stored falsy: the realtime-zero-vs-null discipline).
$GLOBALS['__ol_opts'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['__ol_opts'] ) ? $GLOBALS['__ol_opts'][ $k ] : $default; }
function update_option( $k, $v, $autoload = true ) { $GLOBALS['__ol_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__ol_opts'][ $k ] ); return true; }

// Dashboard-shell seams. NO analytics read accessors are defined beyond these:
// the static mock must not touch sn_analytics_range_totals / _realtime /
// _top_paths / … — a call would be an undefined-function fatal here.
$GLOBALS['__ol_config'] = null;
function sn_analytics_config() { return $GLOBALS['__ol_config']; }
function sn_analytics_last_error() { return null; }
function sn_analytics_granularity( $days ) { return ( (int) $days > 90 ) ? 'week' : 'day'; }

// Flash-message registry deps (inc/admin-flash-messages.php, required below).
function sn_setting( $path, $default = null ) { return $default; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function get_transient( $k ) { return false; }
function snt_insights_last_error() { return null; }

require_once __DIR__ . '/../inc/analytics-panels.php';          // real panel primitives (badge rides header_meta)
require_once __DIR__ . '/../inc/analytics-render-controls.php'; // snt_analytics_window_args (tab-strip links)
require_once __DIR__ . '/../inc/analytics-view-overview-lab.php';
require_once __DIR__ . '/../inc/analytics-admin.php';
require_once __DIR__ . '/../inc/admin-post-handler.php';        // dispatch map pin
require_once __DIR__ . '/../inc/admin-flash-messages.php';      // flash-code pins

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function capture( $cb ) { ob_start(); call_user_func( $cb ); return (string) ob_get_clean(); }

echo "Overview (preview) lab — flag-gated static mock (v9.67.0)\n\n";

echo "Group: flag OFF (default) — the tab exists NOWHERE\n";
$GLOBALS['__ol_opts'] = array(); // no option row = default OFF
ok( function_exists( 'snt_analytics_landing_preview_enabled' ), 'flag helper exists' );
ok( false === snt_analytics_landing_preview_enabled(), 'flag: absent option resolves OFF' );
ok( function_exists( 'snt_analytics_views' ), 'effective-registry accessor exists' );
ok( snt_analytics_views() === SN_ANALYTICS_VIEWS, 'registry: flag off -> IDENTICAL to SN_ANALYTICS_VIEWS (order + labels, today\'s exact array)' );
ok( 11 === count( snt_analytics_views() ), 'registry: flag off -> still exactly 11 views (the v9.66.0 default state)' );
ok( ! array_key_exists( 'overview-lab', snt_analytics_views() ), 'registry: flag off -> overview-lab absent' );
ok( 'content' === snt_analytics_resolve_view( 'overview-lab' ), 'resolve_view: flag off -> ?sn_view=overview-lab falls back to content (the tab does not resolve)' );

// The admin page itself (unconfigured shell = tabs + gate; the S2 §5 shape
// render — the exact surface a screenshot of the page would show).
$_GET['sn_view'] = 'overview-lab';
$html_off = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html_off, 'sn-an-view-tabs' ) !== false, 'page(off): tab strip renders (harness sanity)' );
ok( strpos( $html_off, 'sn_view=overview-lab' ) === false, 'page(off): NO overview-lab tab link anywhere' );
ok( strpos( $html_off, 'Overview (preview)' ) === false, 'page(off): NO preview label anywhere' );
ok( strpos( $html_off, 'sn-an-lab-badge' ) === false, 'page(off): NO preview badge anywhere' );
unset( $_GET['sn_view'] );

echo "\nGroup: flag ON — registration + resolution\n";
update_option( 'sn_analytics_landing_preview', 1 );
ok( true === snt_analytics_landing_preview_enabled(), 'flag: option=1 resolves ON' );
$views_on = snt_analytics_views();
ok( array_key_exists( 'overview-lab', $views_on ), 'registry: flag on -> overview-lab present' );
ok( 'Overview (preview)' === ( $views_on['overview-lab'] ?? '' ), 'registry: labeled "Overview (preview)"' );
ok( 'overview-lab' === array_key_first( $views_on ), 'registry: the preview tab registers FIRST (the future landing slot)' );
ok( 12 === count( $views_on ), 'registry: flag on -> 12 views' );
$views_minus = $views_on;
unset( $views_minus['overview-lab'] );
ok( $views_minus === SN_ANALYTICS_VIEWS, 'registry: flag on adds ONLY the preview tab — the 11 base views are untouched' );
ok( 'overview-lab' === snt_analytics_resolve_view( 'overview-lab' ), 'resolve_view: flag on -> overview-lab resolves' );
ok( true === snt_analytics_view_owns_chrome( 'overview-lab' ), 'owns_chrome: the mock owns its chrome (it IS the assembled overview — no doubled KPI strip)' );

echo "\nGroup: flag ON — the static mock renders (configured)\n";
$GLOBALS['__ol_config'] = array( 'account_id' => 'a', 'token' => 't' );
$_GET['sn_view'] = 'overview-lab';
$html = capture( 'snt_analytics_render_dashboard' );
ok( preg_match( '/nav-tab nav-tab-active" href="[^"]*sn_view=overview-lab/', $html ) === 1, 'mock: the preview tab is the active tab' );
ok( substr_count( $html, 'nav-tab-active' ) === 1, 'mock: exactly one active tab' );
ok( strpos( $html, 'sn_view=content' ) !== false && strpos( $html, 'sn_view=login-defense' ) !== false, 'mock: the existing tabs stay in the strip (drill-downs kept)' );

// Chrome ownership: none of the shared pageview header renders here.
ok( strpos( $html, 'sn-toolbar' ) === false, 'mock: NO shared toolbar (owns chrome)' );
ok( strpos( $html, 'postbox sn-overview' ) === false, 'mock: NO shared Overview panel (the mock replaces it)' );
ok( strpos( $html, 'sn-an-headline' ) === false, 'mock: NO insights band' );

// The PREVIEW badge: every panel carries it (a screenshot can never pass as
// real numbers). Panel count is measured from the primitive's own marker.
$badges = substr_count( $html, 'sn-an-lab-badge' );
$panels = substr_count( $html, 'sn-an-postbox' );
ok( $badges > 0, 'badge: PREVIEW badge present (count > 0)' );
ok( $panels > 0, 'badge: mock renders real panels (harness sanity)' );
ok( $badges === $panels, "badge: EVERY panel carries the badge ($badges badges / $panels panels)" );
ok( substr_count( $html, 'PREVIEW — sample data' ) >= $panels, 'badge: the literal "PREVIEW — sample data" text appears on every panel' );

// Honest headline KPIs — the v9.63 vocabulary over the site's real shape.
ok( strpos( $html, '<p class="sn-kpi-label">Views</p>' ) !== false && strpos( $html, '<p class="sn-kpi-value">47</p>' ) !== false, 'headline: Views 47' );
ok( strpos( $html, '<p class="sn-kpi-label">Visits</p>' ) !== false && strpos( $html, '<p class="sn-kpi-value">40</p>' ) !== false, 'headline: Visits 40 (gated pageview_visits)' );
ok( strpos( $html, '91 visitor-days' ) !== false && strpos( $html, '51 viewless (no pageview)' ) !== false, 'headline: the visitor-day secondary line (91 / 51 — the real structural shape)' );
ok( strpos( $html, 'exact metrics since' ) !== false, 'headline: exact_metrics_since footnote present' );
ok( strpos( $html, '<p class="sn-kpi-label">Now</p>' ) !== false, 'headline: realtime Now card present' );

// Session quality strip: engine KPIs + the durable-trend mini.
ok( strpos( $html, 'Sessions' ) !== false && strpos( $html, 'Bounce' ) !== false, 'session quality: engine KPIs present' );
ok( strpos( $html, 'durable rollup' ) !== false, 'session quality: the durable-trend mini names its source (wp_sn_session_daily)' );

// Trend minis: >= 2 sparklines with UNIQUE gradient ids (the duplicate-SVG-id trap).
preg_match_all( '/id="(snSparkFill[A-Za-z0-9]*)"/', $html, $m_grad );
ok( count( $m_grad[1] ) >= 2 && count( $m_grad[1] ) === count( array_unique( $m_grad[1] ) ), 'trends: >=2 sparkline minis, no duplicate gradient ids' );

// Sources + UTM minis.
ok( strpos( $html, 'news.ycombinator.com' ) !== false && strpos( $html, 'google.com' ) !== false, 'sources: real-shape referrer hosts' );
ok( strpos( $html, 'qr-provhub' ) !== false, 'utm: the real qr-provhub campaign shape' );

// Geography + device minis.
ok( strpos( $html, 'Argentina' ) !== false && strpos( $html, 'United States' ) !== false, 'geography: real-shape country mix' );
ok( strpos( $html, 'Desktop' ) !== false && strpos( $html, 'Mobile' ) !== false, 'devices: device mix' );

// Realtime tile + entry/exit minis.
ok( strpos( $html, 'Active visitors' ) !== false, 'realtime: active-visitor tile' );
ok( strpos( $html, 'Entry pages' ) !== false && strpos( $html, 'Exit pages' ) !== false, 'entry/exit: both minis present' );
ok( strpos( $html, '/provhub/' ) !== false, 'fixture: real path names (/provhub/)' );

// The docs note: what this is + that wiring is a follow-up decision.
ok( strpos( $html, 'sn-an-lab-note' ) !== false, 'note: footer docs note present' );
ok( stripos( $html, 'sample data' ) !== false && stripos( $html, 'follow-up' ) !== false, 'note: says sample data + follow-up decision' );
ok( stripos( $html, 'option C' ) !== false, 'note: names the audit assembly option' );
unset( $_GET['sn_view'] );

echo "\nGroup: flag ON — unconfigured shell still shows the tab (S2 \xC2\xA75 shape rule)\n";
$GLOBALS['__ol_config'] = null;
$html_shape = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html_shape, 'sn_view=overview-lab' ) !== false, 'shape: the preview tab link renders pre-configuration' );
ok( strpos( $html_shape, 'sn-an-gate' ) !== false, 'shape: the config gate still renders' );
$GLOBALS['__ol_config'] = array( 'account_id' => 'a', 'token' => 't' );

echo "\nGroup: settings toggle (Monitoring -> Analytics fold)\n";
ok( function_exists( 'snt_analytics_render_landing_preview' ), 'settings: fold render fn exists' );
ok( function_exists( 'snt_an_landing_preview_snapshot' ), 'settings: snapshot fn exists' );
ok( stripos( snt_an_landing_preview_snapshot(), 'on' ) === 0, 'snapshot: flag on -> starts with "On"' );
$toggle_html = capture( 'snt_analytics_render_landing_preview' );
ok( strpos( $toggle_html, 'name="sn_landing_preview"' ) !== false, 'toggle: checkbox input present' );
ok( strpos( $toggle_html, ' checked' ) !== false, 'toggle: reflects the ON state' );
ok( strpos( $toggle_html, 'value="analytics_landing_preview_save"' ) !== false, 'toggle: posts the allow-listed sn_action' );
ok( strpos( $toggle_html, 'name="_wpnonce"' ) !== false, 'toggle: carries the nonce' );
delete_option( 'sn_analytics_landing_preview' );
ok( stripos( snt_an_landing_preview_snapshot(), 'off' ) === 0, 'snapshot: flag off -> starts with "Off"' );
$toggle_html = capture( 'snt_analytics_render_landing_preview' );
ok( strpos( $toggle_html, ' checked' ) === false, 'toggle: reflects the OFF state' );
// Source contract (the tests/analytics-tokens.php idiom): the settings section
// mounts the fold, guarded, in the writable column.
$admin_src = (string) file_get_contents( __DIR__ . '/../inc/analytics-admin.php' );
ok( strpos( $admin_src, 'snt_analytics_render_landing_preview' ) !== false && strpos( $admin_src, 'snt_an_landing_preview_snapshot' ) !== false,
	'settings section: mounts the landing-preview fold (source pin)' );

echo "\nGroup: save handler (option present when on, DELETED when off)\n";
ok( function_exists( 'sn_handle_analytics_landing_preview_save' ), 'handler exists' );
$GLOBALS['__ol_opts'] = array();
ok( 'analytics_landing_preview_saved' === sn_handle_analytics_landing_preview_save( array( 'sn_landing_preview' => '1' ) ), 'handler: off -> on returns saved' );
ok( array_key_exists( 'sn_analytics_landing_preview', $GLOBALS['__ol_opts'] ) && $GLOBALS['__ol_opts']['sn_analytics_landing_preview'], 'handler: option stored truthy' );
ok( 'analytics_landing_preview_unchanged' === sn_handle_analytics_landing_preview_save( array( 'sn_landing_preview' => '1' ) ), 'handler: on -> on returns unchanged' );
ok( 'analytics_landing_preview_saved' === sn_handle_analytics_landing_preview_save( array() ), 'handler: on -> off returns saved' );
ok( ! array_key_exists( 'sn_analytics_landing_preview', $GLOBALS['__ol_opts'] ), 'handler: off DELETES the option row (absent, never a stored 0)' );
ok( 'analytics_landing_preview_unchanged' === sn_handle_analytics_landing_preview_save( array() ), 'handler: off -> off returns unchanged' );
$map = sn_admin_post_handlers();
ok( 'sn_handle_analytics_landing_preview_save' === ( $map['analytics_landing_preview_save'] ?? '' ), 'dispatch: analytics_landing_preview_save is allow-listed' );
$saved_notice = sn_admin_flash_to_notice( 'analytics_landing_preview_saved' );
$unch_notice  = sn_admin_flash_to_notice( 'analytics_landing_preview_unchanged' );
ok( is_array( $saved_notice ) && 'success' === $saved_notice[0], 'flash: saved code resolves to a success notice' );
ok( is_array( $unch_notice ) && 'info' === $unch_notice[0], 'flash: unchanged code resolves to an info notice' );

echo "\nGroup: abilities/schema surface untouched\n";
$lab_src = (string) file_get_contents( __DIR__ . '/../inc/analytics-view-overview-lab.php' );
ok( strpos( $lab_src, 'wp_register_ability' ) === false && strpos( $lab_src, 'wp_abilities_api_init' ) === false
	&& strpos( $lab_src, 'register_rest_route' ) === false,
	'lab file: registers no ability and no REST route (the registration calls never appear)' );
foreach ( array( 'abilities-analytics.php', 'abilities-registration.php' ) as $f ) {
	$src = (string) file_get_contents( __DIR__ . '/../inc/' . $f );
	ok( strpos( $src, 'overview-lab' ) === false && strpos( $src, 'landing_preview' ) === false,
		"abilities: inc/$f untouched by the preview surface" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

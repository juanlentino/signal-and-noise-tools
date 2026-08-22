<?php
/**
 * Tests for the v6.38.0 week-over-week delta badges in the "Analytics — Overview"
 * widget. sn_aw_stat() gains an optional 3rd $delta arg; sn_aw_delta_badge()
 * renders it; sn_aw_snapshot() wires sn_analytics_period_deltas() +
 * sn_analytics_engaged_rate_delta() onto the trended KPIs (Views, Visits, Avg
 * scroll, Avg time, Engaged). Filtered has no delta accessor and stays plain.
 *
 * Run: php tests/analytics-widget-deltas.php
 * @since plugin v6.38.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

// v12.10.0 seam: the Analytics screen moved to its own top-level menu and its
// URL is now an accessor owned by inc/analytics-dashboard-page.php. Stubbed
// here rather than guarded with function_exists() in the producer — a guard
// there would silently emit an empty href and every link assertion would still
// pass.
if ( ! function_exists( 'snt_analytics_page_url' ) ) {
	function snt_analytics_page_url( $args = array() ) {
		$url = 'https://example.test/wp-admin/admin.php?page=sn-analytics';
		if ( is_array( $args ) && array() !== $args ) {
			foreach ( $args as $k => $v ) { $url .= '&' . $k . '=' . $v; }
		}
		return $url;
	}
}


define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
function wp_add_dashboard_widget( $id, $title, $cb ) {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function current_user_can( $c ) { return true; }
function add_query_arg( $args, $url = '' ) { return (string) $url . '?' . http_build_query( (array) $args ); }
function home_url( $path = '' ) { return 'https://juanlentino.com' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $tag, $value ) { return $value; }

// First-party analytics seam, including the DELTA accessors (which the legacy
// analytics-widget.php test deliberately omits — their absence keeps the legacy
// no-badge markup; their presence here drives the new badges).
$GLOBALS['__pw'] = array(
	'totals'  => array( 'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 108000.0 ),
	'engaged' => 74,
	'classes' => array( 'human' => array( 'views' => 1163 ), 'suspect' => array( 'views' => 18 ), 'bot' => array( 'views' => 23 ) ),
	'deltas'  => array(
		'views'      => array( 'pct' => 15,  'dir' => 'up' ),
		'visits'     => array( 'pct' => -3,  'dir' => 'down' ),
		'scroll_avg' => array( 'pct' => 2,   'dir' => 'up' ),
		'time_avg'   => array( 'pct' => 0,   'dir' => 'flat' ),
	),
	'engaged_delta' => array( 'pct' => 5, 'dir' => 'up' ),
);
function sn_analytics_config() { return array( 'a' => 1 ); }
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__pw']['totals']; }
function sn_analytics_realtime( $class = 'human' ) { return 7; }
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return array(); }
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return array(); }
function sn_analytics_engaged_rate( $from, $to, $class = 'human' ) { return $GLOBALS['__pw']['engaged']; }
function sn_analytics_class_totals( $from, $to ) { return $GLOBALS['__pw']['classes']; }
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) { return $GLOBALS['__pw']['deltas']; }
function sn_analytics_engaged_rate_delta( $from, $to, $class = 'human' ) { return $GLOBALS['__pw']['engaged_delta']; }

require_once __DIR__ . '/../inc/analytics-sources.php';
require_once __DIR__ . '/../inc/analytics-widget.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function cap( $cb ) { ob_start(); $cb(); return ob_get_clean(); }

echo "Overview widget week-over-week delta badges\n\n";

echo "Group: sn_aw_delta_badge renders direction + signed pct\n";
ok( strpos( cap( function () { sn_aw_delta_badge( array( 'pct' => 12, 'dir' => 'up' ) ); } ), 'sn-aw-delta--up' ) !== false
	&& strpos( cap( function () { sn_aw_delta_badge( array( 'pct' => 12, 'dir' => 'up' ) ); } ), '▲ +12%' ) !== false,
	'up: ▲ +12% with sn-aw-delta--up class' );
ok( strpos( cap( function () { sn_aw_delta_badge( array( 'pct' => -8, 'dir' => 'down' ) ); } ), 'sn-aw-delta--down' ) !== false
	&& strpos( cap( function () { sn_aw_delta_badge( array( 'pct' => -8, 'dir' => 'down' ) ); } ), '▼ -8%' ) !== false,
	'down: ▼ -8% with sn-aw-delta--down class' );
ok( strpos( cap( function () { sn_aw_delta_badge( array( 'pct' => 0, 'dir' => 'flat' ) ); } ), '■ 0%' ) !== false,
	'flat: ■ 0% with sn-aw-delta--flat class' );

echo "\nGroup: v8.5.0 — the absolute prior value rides a tooltip\n";
$b = cap( function () { sn_aw_delta_badge( array( 'pct' => 101, 'dir' => 'up', 'current' => 1204, 'previous' => 600 ) ); } );
ok( strpos( $b, 'title="previous period: 600"' ) !== false, 'badge carries the prior-period absolute in a title attr (page parity)' );
$b = cap( function () { sn_aw_delta_badge( array( 'pct' => 12, 'dir' => 'up' ) ); } );
ok( strpos( $b, 'title=' ) === false, 'no previous value → no tooltip (renders exactly as before)' );

echo "\nGroup: pct null (no prior window) mirrors the page badge: new / em-dash\n";
ok( strpos( cap( function () { sn_aw_delta_badge( array( 'pct' => null, 'dir' => 'up' ) ); } ), '▲ new' ) !== false,
	'pct null + up → ▲ new (brand-new traffic, no division)' );
ok( strpos( cap( function () { sn_aw_delta_badge( array( 'pct' => null, 'dir' => 'flat' ) ); } ), '■ —' ) !== false,
	'pct null + flat → ■ — (no prior, no change)' );

echo "\nGroup: graceful degradation (no badge)\n";
ok( '' === cap( function () { sn_aw_delta_badge( null ); } ), 'null delta → empty string (no badge)' );
ok( '' === cap( function () { sn_aw_delta_badge( array( 'pct' => 5 ) ); } ), 'delta missing dir → no badge' );
ok( strpos( cap( function () { sn_aw_stat( 'Views', 1204 ); } ), 'sn-aw-delta' ) === false,
	'sn_aw_stat without a 3rd arg renders no badge (back-compat)' );

echo "\nGroup: sn_aw_stat composes value + label + badge\n";
$tile = cap( function () { sn_aw_stat( 'Views', 1204, array( 'pct' => 15, 'dir' => 'up' ) ); } );
ok( strpos( $tile, '<div class="sn-aw-stat-n">1,204</div>' ) !== false, 'tile keeps the stat number element intact' );
ok( strpos( $tile, 'sn-aw-stat-l">Views <span class="sn-aw-delta sn-aw-delta--up">▲ +15%</span></div>' ) !== false,
	'tile appends the badge inside the label cell, after the label text' );

echo "\nGroup: sn_aw_snapshot wires deltas onto trended KPIs, leaves Filtered plain\n";
$snap = cap( 'sn_aw_snapshot' );
ok( strpos( $snap, '>Views <span class="sn-aw-delta sn-aw-delta--up">▲ +15%' ) !== false, 'snapshot: Views carries +15% up badge' );
ok( strpos( $snap, '>Visits <span class="sn-aw-delta sn-aw-delta--down">▼ -3%' ) !== false, 'snapshot: Visits carries -3% down badge' );
ok( strpos( $snap, '>Engaged <span class="sn-aw-delta sn-aw-delta--up">▲ +5%' ) !== false, 'snapshot: Engaged carries the engaged-rate-delta badge' );
ok( strpos( $snap, 'sn-aw-stat-l">Filtered</div>' ) !== false, 'snapshot: Filtered tile has NO badge (no delta accessor for it)' );

echo "\nGroup: badge output is escaped\n";
ok( strpos( cap( function () { sn_aw_delta_badge( array( 'pct' => 1, 'dir' => '"x"<b>' ) ); } ), '<b>' ) === false,
	'dir is esc_attr-escaped into the class attribute (no raw markup injection)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

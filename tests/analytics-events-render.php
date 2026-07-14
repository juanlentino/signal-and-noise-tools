<?php
/**
 * Tests for the Events-tab render partials in inc/analytics-admin-render.php:
 * snt_analytics_render_events_table() + snt_analytics_render_event_props_table().
 * Behavioral: drives each render over fixture arrays and asserts on captured HTML.
 * Run: php tests/analytics-events-render.php
 * @since plugin v6.3.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

// Escaping + i18n + URL stubs (mirror tests/analytics-admin.php).
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = '' ) { return $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=sn-analytics&sn_view=events&sn_range=30&sn_class=human';
function add_query_arg( $args, $url = null ) {
	if ( null === $url ) { $url = $_SERVER['REQUEST_URI']; }
	$sep = ( strpos( (string) $url, '?' ) !== false ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url = null ) {
	if ( null === $url ) { $url = $_SERVER['REQUEST_URI']; }
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) { return $url; }
	parse_str( $parts[1], $q );
	foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
	return $q ? $parts[0] . '?' . http_build_query( $q ) : $parts[0];
}

require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
require_once __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function capture( $cb ) { ob_start(); $cb(); return (string) ob_get_clean(); }

echo "Analytics events render partials\n\n";

echo "Group: events leaderboard\n";
$ev = array(
	array( 'name' => 'signup', 'events' => 120, 'visitors' => 90 ),
	array( 'name' => 'code-copy', 'events' => 44, 'visitors' => 30 ),
);
$html = capture( function () use ( $ev ) { snt_analytics_render_events_table( $ev ); } );
ok( strpos( $html, 'Custom events' ) !== false, 'events: panel heading' );
ok( strpos( $html, 'signup' ) !== false && strpos( $html, '120' ) !== false, 'events: row name + events count' );
ok( strpos( $html, '>90<' ) !== false, 'events: visitors column' );
unset( $GLOBALS['sn_an_empty_panels'] );
$html = capture( function () { snt_analytics_render_events_table( array() ); } );
ok( '' === $html, 'events: empty rows fold instead of rendering inline (D4 §4)' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Custom events' === $noted[0]['title'] && false !== strpos( $noted[0]['why'], 'No custom events' ), 'events: empty state copy carried as the fold why' );
$html = capture( function () { snt_analytics_render_events_table( array( array( 'name' => 'x"<script>', 'events' => 1, 'visitors' => 1 ) ) ); } );
ok( strpos( $html, '<script>' ) === false, 'events: name is escaped' );

echo "\nGroup: event-property breakdown (unfiltered)\n";
$props = array(
	array( 'property' => 'utm_source', 'value' => 'hn', 'events' => 50, 'visitors' => 40 ),
	array( 'property' => 'plan', 'value' => 'pro', 'events' => 12, 'visitors' => 10 ),
);
$html = capture( function () use ( $props ) { snt_analytics_render_event_props_table( $props, '' ); } );
ok( strpos( $html, 'Event properties' ) !== false, 'props: panel heading' );
ok( strpos( $html, '>Property<' ) !== false, 'props(unfiltered): Property column shown' );
ok( strpos( $html, 'sn_event_prop=utm_source' ) !== false, 'props(unfiltered): property is a drill-down link' );
ok( strpos( $html, 'utm_source' ) !== false && strpos( $html, 'hn' ) !== false, 'props: property + value rendered' );

echo "\nGroup: event-property breakdown (filtered)\n";
$html = capture( function () use ( $props ) { snt_analytics_render_event_props_table( $props, 'utm_source' ); } );
ok( strpos( $html, 'Property: <strong>utm_source</strong>' ) !== false, 'props(filtered): active-property heading' );
ok( strpos( $html, '>Clear<' ) !== false, 'props(filtered): Clear link present' );
ok( strpos( $html, '<th>Property</th>' ) === false, 'props(filtered): Property column dropped' );
ok( strpos( $html, 'sn_event_prop=' ) === false, 'props(filtered): no further drill-down links' );
unset( $GLOBALS['sn_an_empty_panels'] );
$html = capture( function () { snt_analytics_render_event_props_table( array(), '' ); } );
ok( '' === $html, 'props(unfiltered): empty rows fold instead of rendering inline (D4 §4)' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Event properties' === $noted[0]['title'] && false !== strpos( $noted[0]['why'], 'No event properties' ), 'props(unfiltered): empty state copy carried as the fold why' );

// D4 §4 carve-out: a FILTERED empty (?sn_event_prop active) stays an OPEN panel
// with its original empty copy — the Clear escape hatch must survive; folding
// here would strand the user with no in-UI way back to the unfiltered table.
unset( $GLOBALS['sn_an_empty_panels'] );
$html = capture( function () { snt_analytics_render_event_props_table( array(), 'utm_source' ); } );
ok( strpos( $html, 'No event properties' ) !== false, 'props(filtered): empty renders the OPEN panel with its original copy (active-filter carve-out)' );
ok( strpos( $html, 'Property: <strong>utm_source</strong>' ) !== false && strpos( $html, '>Clear<' ) !== false, 'props(filtered): active-property heading + Clear escape hatch survive on empty' );
ok( array() === (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() ), 'props(filtered): nothing noted to the fold' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

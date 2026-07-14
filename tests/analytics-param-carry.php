<?php
/**
 * THE param-carry contract (v9.39.0 D3): two param classes, one rule each.
 * Context params (sn_view, sn_range, sn_from/sn_to, sn_class, sn_compare)
 * survive every in-dashboard navigation; view-local filters (sn_drill,
 * sn_event_prop, sn_lg_range) survive window/class/compare changes and RESET
 * on view switch. One group per builder that ENCODES policy; builders that
 * conform by construction (drill/event-prop links, export) stay pinned in
 * their own suites. Run: php tests/analytics-param-carry.php
 * @since plugin v9.39.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_RANGES', array( 7, 14, 30, 90, 365 ) );

// REAL query-string semantics (the naive stubs elsewhere can't test carry).
$GLOBALS['__current_url'] = '';
function sn_pc_parse( $url ) {
	$parts = explode( '?', (string) $url, 2 );
	$q     = array();
	if ( isset( $parts[1] ) ) { parse_str( $parts[1], $q ); }
	return array( $parts[0], $q );
}
function sn_pc_build( $path, $q ) { return $q ? $path . '?' . http_build_query( $q ) : $path; }
function add_query_arg( $args = array(), $url = '' ) {
	if ( '' === $url ) { $url = $GLOBALS['__current_url']; }
	if ( array() === $args ) { return $url; }
	list( $path, $q ) = sn_pc_parse( $url );
	foreach ( (array) $args as $k => $v ) { $q[ $k ] = $v; }
	return sn_pc_build( $path, $q );
}
function remove_query_arg( $keys, $url = '' ) {
	if ( '' === $url ) { $url = $GLOBALS['__current_url']; }
	list( $path, $q ) = sn_pc_parse( $url );
	foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
	return sn_pc_build( $path, $q );
}
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function esc_url( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function wp_nonce_field( $a ) { echo ''; }
function number_format_i18n( $n, $d = 0 ) { return (string) $n; }

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function link_args( $html, $needle ) {
	if ( ! preg_match_all( '/href="([^"]*)"/', $html, $m ) ) { return null; }
	foreach ( $m[1] as $href ) {
		if ( false !== strpos( $href, $needle ) ) { list( , $q ) = sn_pc_parse( html_entity_decode( $href ) ); return $q; }
	}
	return null;
}

$GLOBALS['__current_url'] = 'https://x/wp-admin/index.php?page=sn-analytics&sn_view=events&sn_range=30&sn_class=bot&sn_compare=yoy&sn_drill=country%3AUS&sn_event_prop=utm_source&sn_lg_range=90';

echo "Group: contract — compare pills carry the window (the D3 bug fix)\n";
ob_start(); snt_analytics_render_controls( 30, 'bot', '', '', 'yoy' ); $ctl = ob_get_clean();
$prev = link_args( $ctl, 'sn_compare=prev' );
ok( is_array( $prev ) && '30' === ( $prev['sn_range'] ?? '' ) && 'bot' === ( $prev['sn_class'] ?? '' ), 'compare link carries sn_range + sn_class (no more 7d/human reset)' );
ok( is_array( $prev ) && 'utm_source' === ( $prev['sn_event_prop'] ?? '' ) && 'country:US' === ( $prev['sn_drill'] ?? '' ), 'compare link carries the view-local filters' );
$off = null;
if ( preg_match_all( '/href="([^"]*)"/', $ctl, $mm ) ) {
	foreach ( $mm[1] as $href ) {
		list( , $q ) = sn_pc_parse( html_entity_decode( $href ) );
		if ( ! isset( $q['sn_compare'] ) && isset( $q['sn_range'] ) && '30' === $q['sn_range'] && isset( $q['sn_class'] ) && 'bot' === $q['sn_class'] ) { $off = $q; break; }
	}
}
ok( is_array( $off ), 'compare Off variant still carries the window while dropping sn_compare' );

echo "\nGroup: contract — custom form carries context + view-local hidden fields\n";
ok( false !== strpos( $ctl, 'name="sn_compare" value="yoy"' ), 'custom form: sn_compare hidden field' );
ok( false !== strpos( $ctl, 'name="sn_drill" value="country:US"' ), 'custom form: sn_drill hidden field' );
ok( false !== strpos( $ctl, 'name="sn_event_prop" value="utm_source"' ), 'custom form: sn_event_prop hidden field' );
ok( false === strpos( $ctl, 'name="sn_lg_range"' ), 'custom form: sn_lg_range NOT carried (login-defense owns it)' );

echo "\nGroup: contract — rolling/calendar links carry everything except what they set\n";
$roll = link_args( $ctl, 'sn_range=90' );
ok( is_array( $roll ) && 'yoy' === ( $roll['sn_compare'] ?? '' ) && 'country:US' === ( $roll['sn_drill'] ?? '' ) && 'utm_source' === ( $roll['sn_event_prop'] ?? '' ), 'rolling link carries compare + view-local filters' );
$cal = link_args( $ctl, 'sn_range=ytd' );
ok( is_array( $cal ) && 'yoy' === ( $cal['sn_compare'] ?? '' ) && 'events' === ( $cal['sn_view'] ?? '' ), 'calendar link carries compare + view' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

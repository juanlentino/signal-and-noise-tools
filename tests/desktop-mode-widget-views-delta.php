<?php
/**
 * SN Traffic (sn-site-views): the arrow carries the direction (no +/- sign),
 * direction color only for a meaningful change; the groups SN Audience and
 * SN RSS Subscribers carried paint under the sparkline; the rows SN Reading
 * already shows (the north star's engaged readers, read 2+ pages, downloads,
 * DOI downloads, inquiries) and the bot share are gone; one Open Analytics link.
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
$GLOBALS['t'] = array();
function add_action() {}
function snt_os_compat_add_filter() {}
function __( $s ) { return $s; }
function sn_setting( $k, $d = null ) { return $d; }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['t'][ $k ] = $v; return true; }
function current_time() { return '2026-09-27 12:00:00'; }
class WP_REST_Response { public $data; public function __construct( $d ) { $this->data = $d; } }
require __DIR__ . '/../inc/north-star.php';
require __DIR__ . '/../inc/desktop-mode-payloads.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "SN Site Views deltas\n\n";

// ── The north star left the card: SN Reading carries those figures ──
$GLOBALS['t'][ SNT_NSM_CACHE_KEY ] = array( 'configured' => true, 'value' => 3, 'previous' => 2, 'layers' => array() );
$res = snt_desktop_site_views_payload()->data;
ok( ! isset( $res['north_star'] ), 'the payload no longer reads the north star on every response: the card paints none of it' );
function snt_desktop_traffic_groups( $win ) { return array( array( 'title' => 'Countries', 'rows' => array(), 'empty' => 'none', 'win' => $win ) ); }
$GLOBALS['t'] = array();
$res = snt_desktop_site_views_payload()->data;
ok( 'Countries' === ( $res['groups'][0]['title'] ?? '' ) && array( 'from' => '2026-09-14', 'to' => '2026-09-27', 'days' => 14 ) === $res['groups'][0]['win'], 'the payload carries SN Traffic\'s groups, read over the same 14 days as the sparkline' );
ok( isset( $GLOBALS['t']['sn_desktop_site_views_2026-09-27']['groups'] ), 'the groups ride the payload\'s own 15-minute cache' );

// ── The rendered rows (node, the real widget file) ──
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) {
	echo "SKIP: node not on PATH, render pins not run\n";
} else {
	$render = function ( array $p, $raw = false ) use ( $node ) {
		$p += array( 'days' => array( array( 'date' => 'd', 'views' => 1 ) ), 'total' => 100, 'delta_pct' => null );
		$out = json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/site-views-render.js' ) . ' ' . escapeshellarg( json_encode( $p ) ) ), true );
		if ( $raw ) { return $out; }
		$rows = array();
		foreach ( (array) ( $out['rows'] ?? array() ) as $r ) { $rows[ $r['text'] ] = $r['color']; }
		return $rows;
	};
	$down = '#c9503f';
	$muted = function ( $c ) { return 0 === strpos( (string) $c, 'var(--os-ui-color-text-subtle' ); };

	// Text: arrow present, no sign.
	$r = $render( array( 'delta_pct' => -41.3 ) );
	ok( isset( $r['▼ 41.3% vs. prior 14 days'] ), 'views down: "▼ 41.3% vs. prior 14 days"' );
	$r0 = $render( array( 'delta_pct' => 0 ) );
	ok( isset( $r0['▲ 0% vs. prior 14 days'] ) && $muted( $r0['▲ 0% vs. prior 14 days'] ), 'zero: the flat views line is muted' );
	$all = implode( ' ', array_keys( $r + $r0 ) );
	ok( 0 === preg_match( '/[▲▼] [+\-−]/u', $all ), 'no sign follows any arrow' );
	ok( $down === $r['▼ 41.3% vs. prior 14 days'], 'views -41.3% on 100 (abs 70): red' );

	// SN Traffic: the folded groups paint, the duplicates of SN Reading do not.
	$full = array(
		'today'      => 7,
		'north_star' => array( 'value' => 4, 'previous' => 1, 'deep' => 2, 'actions' => 3, 'doi' => array( 'value' => 5, 'window' => '28d' ), 'inquiries' => 1 ),
		'bot_pct'    => 61,
		'top_mover'  => array( 'path' => '/notes', 'delta' => -16, 'views' => 10 ),
		'top_paths'  => array( array( 'path' => '/a', 'views' => 9 ), array( 'path' => '/b', 'views' => 4 ) ),
		'groups'     => array(
			array( 'title' => 'Countries', 'rows' => array( array( 'label' => 'US', 'value' => '30 · 75%' ) ) ),
			array( 'title' => 'Feed subscribers', 'rows' => array(), 'empty' => 'No feed requests logged yet.' ),
		),
	);
	$t = $render( $full );
	foreach ( array( 'Today so far', 'Countries', 'US', '30 · 75%', 'Feed subscribers', 'No feed requests logged yet.', 'Top pages', '/a', '9' ) as $want ) {
		ok( isset( $t[ $want ] ), "Traffic paints \"$want\"" );
	}
	$text = implode( ' | ', array_keys( $t ) );
	ok( 0 === preg_match( '/Engaged readers|Read 2\+ pages|Downloads, outbound|DOI downloads|Inquiries|Bot share/', $text ), 'none of the rows SN Reading already shows, and no bot share: ' . $text );
	ok( ! isset( $t['▼ 16'] ) && false === strpos( $text, '/notes' ), 'no top mover: it is not among the approved rows' );
	$out = $render( $full, true );
	ok( 1 === $out['links'] && 'Open Analytics' === $out['link']['text'] && 'Open Analytics, from the SN Traffic widget' === $out['link']['name'] && true === $out['link']['arrowHidden'], 'exactly one footer link, Open Analytics, its name starting with the visible words and the arrow hidden' );
	ok( 'status' === $out['bodyRole'] && 3 === count( array_filter( $out['roles'], static fn( $r ) => 'heading' === $r ) ), 'the body is a status region and each group title (two groups, Top pages) is a heading' );
	ok( count( array_filter( $out['roles'], static fn( $r ) => 'list' === $r ) ) >= 3, 'rows are lists (today, each group with rows, top pages)' );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );

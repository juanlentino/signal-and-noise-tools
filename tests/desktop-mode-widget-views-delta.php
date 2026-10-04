<?php
/**
 * SN Site Views: the arrow carries the direction (no +/- sign), direction
 * colour only for a meaningful change, and the card's engaged-readers number
 * is the north-star ability's own reading, never a copy frozen in the card's
 * 15-minute payload cache.
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

// ── The mismatch: the card reads the ability's reading ──
// The ability's cached reading says 3; the card's day payload was cached
// earlier with a frozen north star of 4. The card must answer 3.
$GLOBALS['t'][ SNT_NSM_CACHE_KEY ] = array( 'configured' => true, 'value' => 3, 'previous' => 2, 'layers' => array() );
$GLOBALS['t']['sn_desktop_site_views_2026-09-27'] = array( 'days' => array(), 'total' => 0, 'north_star' => array( 'value' => 4, 'previous' => 1 ) );
$res = snt_desktop_site_views_payload()->data;
$ability = snt_ability_north_star();
ok( 3 === ( $res['north_star']['value'] ?? null ), 'a cached card payload holding a stale 4 answers the current 3' );
ok( $ability['value'] === $res['north_star']['value'] && $ability['previous'] === $res['north_star']['previous'], 'card value and previous equal the north-star ability from the same data' );
$GLOBALS['t'][ SNT_NSM_CACHE_KEY ]['configured'] = false;
$res = snt_desktop_site_views_payload()->data;
ok( ! isset( $res['north_star'] ), 'analytics unset: no north star, and the frozen copy is dropped too' );

// ── The rendered rows (node, the real widget file) ──
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) {
	echo "SKIP: node not on PATH, render pins not run\n";
} else {
	$render = function ( array $p ) use ( $node ) {
		$p += array( 'days' => array( array( 'date' => 'd', 'views' => 1 ) ), 'total' => 100, 'delta_pct' => null );
		$out = json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/site-views-render.js' ) . ' ' . escapeshellarg( json_encode( $p ) ) ), true );
		$rows = array();
		foreach ( (array) ( $out['rows'] ?? array() ) as $r ) { $rows[ $r['text'] ] = $r['color']; }
		return $rows;
	};
	$up = '#3fb950'; $down = '#c9503f';
	$muted = function ( $c ) { return 0 === strpos( (string) $c, 'var(--os-ui-color-text-subtle' ); };

	// 21.2.1: the Engaged row moved to SN Reading (tests/desktop-mode-analytics-widgets.php pins its text and color there).
	// Text: arrow present, no sign.
	$r = $render( array( 'north_star' => array( 'value' => 4, 'previous' => 1 ), 'engaged' => array( 'rate' => 37, 'pts' => -10, 'dir' => 'down' ), 'top_mover' => array( 'path' => '/notes', 'delta' => -16, 'views' => 10 ), 'delta_pct' => -41.3 ) );
	ok( isset( $r['4 ▲ 3'] ), 'up: "4 ▲ 3", no plus sign' );
	ok( isset( $r['▼ 16'] ), 'mover down: "▼ 16", no minus sign' );
	ok( isset( $r['▼ 41.3% vs. prior 14 days'] ), 'views down: "▼ 41.3% vs. prior 14 days"' );
	$r0 = $render( array( 'north_star' => array( 'value' => 4, 'previous' => 4 ), 'delta_pct' => 0 ) );
	ok( isset( $r0['4'] ) && isset( $r0['▲ 0% vs. prior 14 days'] ) && $muted( $r0['▲ 0% vs. prior 14 days'] ), 'zero: bare value, flat views line muted' );
	$all = implode( ' ', array_keys( $r + $r0 ) );
	ok( 0 === preg_match( '/[▲▼] [+\-−]/u', $all ), 'no sign follows any arrow' );

	// Colour thresholds: abs >= 5 AND rel >= 20%; points >= 5.
	ok( $muted( $r['4 ▲ 3'] ), '+3 on 1 (abs 3 < 5): muted' );
	ok( $down === $r['▼ 16'], 'mover -16 on 26: red' );
	ok( $down === $r['▼ 41.3% vs. prior 14 days'], 'views -41.3% on 100 (abs 70): red' );
	$t = $render( array( 'north_star' => array( 'value' => 30, 'previous' => 25 ), 'engaged' => array( 'rate' => 40, 'pts' => 5, 'dir' => 'up' ) ) );
	ok( $up === ( $t['30 ▲ 5'] ?? '' ), 'at threshold (abs 5, rel 20%): green' );
	$u = $render( array( 'north_star' => array( 'value' => 31, 'previous' => 26 ), 'engaged' => array( 'rate' => 40, 'pts' => 4, 'dir' => 'up' ), 'top_mover' => array( 'path' => '/a', 'delta' => 4, 'views' => 4 ) ) );
	ok( $muted( $u['31 ▲ 5'] ?? '' ), 'just under (abs 5, rel 19.2%): muted' );
	ok( $muted( $u['▲ 4'] ?? '' ), 'just under (mover abs 4, from zero): muted' );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );

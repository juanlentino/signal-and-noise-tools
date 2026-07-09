<?php
/**
 * Standalone fixture tests for the v8.5.0 Movers query + rail tile: top posts
 * by views delta, current window vs the prior window, class-following, 15-min
 * transient, dropped-out paths still count as negative movers.
 *
 * Run: php tests/analytics-movers.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return str_replace( array( '"', "'", '<', '>' ), '', (string) $u ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return (string) (int) $n; } }

$GLOBALS['__transients'] = array();
$GLOBALS['__transient_ttl'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; $GLOBALS['__transient_ttl'][ $k ] = $ttl; return true; } }

// Window + paths stubs: the movers fn must consume these exact seams.
if ( ! function_exists( 'sn_analytics_prior_window' ) ) {
	function sn_analytics_prior_window( $from, $to ) { return array( 'P_FROM', 'P_TO' ); }
}
$GLOBALS['__paths_calls'] = array();
if ( ! function_exists( 'sn_analytics_top_paths' ) ) {
	function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) {
		$GLOBALS['__paths_calls'][] = array( $from, $to, $class, $limit );
		if ( 'P_FROM' === $from ) {
			return array(
				array( 'path' => '/a/', 'views' => 100 ),
				array( 'path' => '/b/', 'views' => 40 ),
				array( 'path' => '/gone/', 'views' => 25 ),
				array( 'path' => '/flat/', 'views' => 10 ),
			);
		}
		return array(
			array( 'path' => '/a/', 'views' => 141 ),  // +41
			array( 'path' => '/new/', 'views' => 30 ), // +30
			array( 'path' => '/b/', 'views' => 22 ),   // -18
			array( 'path' => '/flat/', 'views' => 10 ),// 0 — not a mover
		);
	}
}

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-annotations.php';
require_once __DIR__ . '/../inc/analytics-movers.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function capture( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "analytics-movers suite - plugin v8.5.0\n";

echo "\nTest: delta math + ranking by absolute movement\n";
$movers = sn_analytics_movers( '2026-07-01', '2026-07-07', 'human', 3 );
ok( is_array( $movers ) && 3 === count( $movers ), 'returns the requested top-3' );
ok( '/a/' === $movers[0]['path'] && 41 === $movers[0]['delta'], 'biggest absolute mover first (+41)' );
ok( '/new/' === $movers[1]['path'] && 30 === $movers[1]['delta'], 'new path counts (prior 0 -> +30)' );
ok( '/gone/' === $movers[2]['path'] && -25 === $movers[2]['delta'], 'dropped-out path counts as a negative mover' );
$all = sn_analytics_movers_uncached( '2026-07-01', '2026-07-07', 'human', 10 );
$paths = array_map( function ( $m ) { return $m['path']; }, $all );
ok( ! in_array( '/flat/', $paths, true ), 'zero-delta paths are not movers' );

echo "\nTest: class follows the page filter\n";
$GLOBALS['__paths_calls'] = array();
sn_analytics_movers_uncached( '2026-07-01', '2026-07-07', 'bot', 3 );
ok( 'bot' === ( $GLOBALS['__paths_calls'][0][2] ?? '' ) && 'bot' === ( $GLOBALS['__paths_calls'][1][2] ?? '' ), 'both window queries use the selected class' );

echo "\nTest: transient cache\n";
$GLOBALS['__transients'] = array();
$GLOBALS['__paths_calls'] = array();
sn_analytics_movers( '2026-07-01', '2026-07-07', 'human', 3 );
$first = count( $GLOBALS['__paths_calls'] );
sn_analytics_movers( '2026-07-01', '2026-07-07', 'human', 3 );
ok( count( $GLOBALS['__paths_calls'] ) === $first, 'second call is a cache hit (no new queries)' );
$ttls = array_values( $GLOBALS['__transient_ttl'] );
ok( in_array( 15 * MINUTE_IN_SECONDS, $ttls, true ), '15-minute TTL' );

echo "\nTest: movers tile render\n";
$GLOBALS['__transients'] = array();
$html = capture( function () { snt_analytics_render_movers_tile( '2026-07-01', '2026-07-07', 'human' ); } );
ok( false !== strpos( $html, 'sn-an-postbox' ), 'tile renders through the panel primitive' );
ok( false !== strpos( $html, '>Movers<' ), 'panel titled Movers' );
ok( false !== strpos( $html, 'vs prior period' ), 'delta basis named in the header meta' );
ok( false !== strpos( $html, '/a/' ) && false !== strpos( $html, '+41' ), 'positive mover row with sign' );
ok( false !== strpos( $html, 'sn-an-delta-up' ) && false !== strpos( $html, 'sn-an-delta-down' ), 'semantic delta classes' );
ok( false !== strpos( $html, 'sn_view=posts' ), 'tile links to the Posts tab' );

echo "\nTest: movers tile enrichment (v8.5.0 rail-fill)\n";
ok( false !== strpos( $html, 'sn-an-mover-views' ), 'rows carry the muted current-views figure beside the delta' );

echo "\nTest: movers tile empty state\n";
$GLOBALS['__transients'] = array( 'sn_an_movers_' . md5( '2026-01-01|2026-01-02|human|5' ) => array() );
$html = capture( function () { snt_analytics_render_movers_tile( '2026-01-01', '2026-01-02', 'human' ); } );
ok( false !== strpos( $html, 'No movement in this range yet.' ), 'empty state copy' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

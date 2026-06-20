<?php
/**
 * Standalone fixture tests for snt_dashboard_render_rss_summary().
 *
 * Regression for v6.30.1: the "Open RSS tab" link pointed at the unregistered
 * `page=sn-rss` slug, which tripped WP's "Sorry, you are not allowed to access
 * this page" guard. Locks the canonical Content → RSS sub-section destination.
 *
 * Run: php tests/dashboard-rss-summary.php
 * @since plugin v6.30.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'esc_html' ) )   { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) )   { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) )    { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = '' ) { return $s; } }
if ( ! function_exists( '__' ) )         { function __( $s, $d = '' ) { return $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
if ( ! function_exists( 'admin_url' ) )  { function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return $u . '&_n=1'; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '5 minutes'; } }

// Canned RSS tracker stats (shape mirrors sn_rss_tracker_window_stats_multi()).
if ( ! function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
	function sn_rss_tracker_window_stats_multi( $windows ) {
		return array(
			'most_recent' => gmdate( 'Y-m-d H:i:s', time() - 300 ),
			'windows'     => array(
				1  => array( 'total' => 17,   'uniques' => 4 ),
				7  => array( 'total' => 194,  'uniques' => 15 ),
				30 => array( 'total' => 1174, 'uniques' => 44 ),
			),
		);
	}
}

require __DIR__ . '/../inc/admin-tab-dashboard.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

ob_start();
snt_dashboard_render_rss_summary();
$html = ob_get_clean();

echo "\nTest: Open RSS tab link is canonical (v6.30.1 regression)\n";
ok( false !== strpos( $html, 'Open RSS tab' ), 'renders the Open RSS tab link' );
ok( false !== strpos( $html, 'page=sn-theme-options&tab=content&sub=rss' ), 'link points at the canonical Content → RSS sub-section' );
ok( false === strpos( $html, 'page=sn-rss' ), 'no longer uses the dead page=sn-rss slug (the wp_die cause)' );
// Sanity: the stats still render.
ok( false !== strpos( $html, 'RSS feed activity' ), 'renders the RSS feed activity heading' );
ok( false !== strpos( $html, '194' ), '7d total rendered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

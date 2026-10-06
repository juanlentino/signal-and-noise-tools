<?php
/**
 * Tests for the Dashboard-home analytics token enqueue (inc/analytics-widget.php).
 *
 * The four standalone analytics boxes were folded away in v11.30.0, and their
 * sn_aw_* renders plus assets/analytics/analytics-widget.css were later removed
 * as dead code. What remains is the shared 'snt-analytics-tokens' enqueue,
 * gated to the WP Dashboard home screen (index.php) and cache-busted by
 * SNT_VERSION. A WRONG screen gate would load it everywhere, so this asserts it
 * loads on the dashboard and NOT on any other admin screen.
 *
 * Run: php tests/analytics-widget-enqueue.php
 * @since plugin v6.11.5
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '9.9.9-test' );

if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

// Recorder for wp_enqueue_style: captures every enqueue call for assertions.
$GLOBALS['__enq'] = array();
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['__enq'][] = array( 'handle' => $handle, 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}

require_once __DIR__ . '/../inc/analytics-widget.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

function aw_all_enqueues_for( $hook ) {
	$GLOBALS['__enq'] = array();
	if ( function_exists( 'sn_aw_enqueue_styles' ) ) {
		sn_aw_enqueue_styles( $hook );
	}
	return $GLOBALS['__enq'];
}

echo "Dashboard-home token enqueue\n\n";

ok( function_exists( 'sn_aw_enqueue_styles' ), 'enqueue: sn_aw_enqueue_styles() is defined (named, testable callback)' );

echo "\nGroup: enqueued on the Dashboard home screen\n";
$all_dash = aw_all_enqueues_for( 'index.php' );
ok( 1 === count( $all_dash ), 'enqueue: exactly one stylesheet on index.php (the tokens sheet)' );
$tok_row = $all_dash[0] ?? array();
ok( 'snt-analytics-tokens' === ( $tok_row['handle'] ?? '' ), 'enqueue: registered under the snt-analytics-tokens handle' );
ok( ( $tok_row['src'] ?? '' ) === SNT_URL . 'assets/analytics/analytics-tokens.css', 'enqueue: tokens src is assets/analytics/analytics-tokens.css under SNT_URL' );
ok( ( $tok_row['ver'] ?? '' ) === SNT_VERSION, 'enqueue: tokens cache-busted by SNT_VERSION' );

echo "\nGroup: NOT enqueued off the dashboard\n";
foreach ( array( 'post.php', 'edit.php', 'options-general.php', 'toplevel_page_sn-theme-options', 'sn-theme-options_page_sn-monitoring' ) as $other ) {
	ok( 0 === count( aw_all_enqueues_for( $other ) ), "enqueue: tokens stylesheet NOT loaded on '$other'" );
}

echo "\nGroup: the removed widget stylesheet stays gone\n";
ok( ! file_exists( __DIR__ . '/../assets/analytics/analytics-widget.css' ), 'assets/analytics/analytics-widget.css no longer exists (its renders had no caller)' );
ok( ! function_exists( 'sn_aw_overview' ) && ! function_exists( 'sn_aw_snapshot' ), 'the sn_aw_* render functions are gone' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

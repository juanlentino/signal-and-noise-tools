<?php
/**
 * Wiring tests: the three sn_analytics_signals() call sites (insights band,
 * recommendations self-fetch, WP-home widget) must pass sn_analytics_signal_opts()
 * as the 4th argument (settings hub, v9.36.0). sn_analytics_signals is a SPY here
 * (defined before the requires), so each call's args are captured; the sentinel
 * opts stub proves the value flows through unmodified.
 *
 * Run: php tests/analytics-signal-opts-wiring.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

// ── WP stubs ──
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {}
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $t, $v, ...$a ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function sanitize_key( $k ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $k ) ); }

// ── Spy + sentinel (defined BEFORE the requires; neither file declares these) ──
$GLOBALS['__sig_calls'] = array();
function sn_analytics_signals( $from, $to, $class = 'human', $opts = array() ) {
	$GLOBALS['__sig_calls'][] = array( 'from' => $from, 'to' => $to, 'class' => $class, 'opts' => $opts );
	return array(); // empty → every caller takes its no-signals path
}
function sn_analytics_signal_opts() {
	return array( 'baseline_days' => 77, 'z' => 9.9 ); // sentinel
}

require __DIR__ . '/../inc/analytics-insights.php';
require __DIR__ . '/../inc/analytics-recommendations.php';
require __DIR__ . '/../inc/analytics-widget.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$sentinel = array( 'baseline_days' => 77, 'z' => 9.9 );

echo "Group: insights band\n";
$GLOBALS['__sig_calls'] = array();
ob_start();
try { snt_analytics_render_insights_band( '2026-07-01', '2026-07-07', 'human', 'day' ); } catch ( Throwable $e ) { /* post-spy markup deps may be absent in isolation */ }
ob_end_clean();
ok( 1 === count( $GLOBALS['__sig_calls'] ), 'band fetched signals exactly once' );
ok( $sentinel === ( $GLOBALS['__sig_calls'][0]['opts'] ?? null ), 'band passes sn_analytics_signal_opts() through' );

echo "Group: recommendations self-fetch\n";
// v9.38.0 (D2): the AI priority brief retired — sn_analytics_recommend() no
// longer self-fetches signals at all (the digest is the screen's one voice).
$GLOBALS['__sig_calls'] = array();
try { sn_analytics_recommend( null ); } catch ( Throwable $e ) {}
ok( 0 === count( $GLOBALS['__sig_calls'] ), 'recommend(null): the private trailing-14d signals self-fetch is GONE (D2)' );

echo "Group: WP-home widget header\n";
$GLOBALS['__sig_calls'] = array();
if ( ! function_exists( 'snt_analytics_render_signal_chip' ) ) { function snt_analytics_render_signal_chip( $s ) { return ''; } }
ob_start();
try { sn_aw_insight_header(); } catch ( Throwable $e ) {}
ob_end_clean();
ok( 1 === count( $GLOBALS['__sig_calls'] ), 'widget header fetched signals exactly once' );
ok( $sentinel === ( $GLOBALS['__sig_calls'][0]['opts'] ?? null ), 'widget passes sn_analytics_signal_opts() through' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );

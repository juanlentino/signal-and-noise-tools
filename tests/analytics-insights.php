<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

// Stubs: signal engine + narrator (recorders/fixtures).
$GLOBALS['__sig'] = array();
function sn_analytics_signals( $from, $to, $class = 'human', $opts = array() ) { return $GLOBALS['__sig']; }
$GLOBALS['__narr'] = array( 'narrative' => '', 'source' => 'fallback' );
function sn_analytics_narrate( $summary, $signals ) { return $GLOBALS['__narr']; }

require __DIR__ . '/../inc/analytics-insights.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$sig = array(
	'kind' => 'anomaly', 'tier' => 'predictive', 'direction' => 'up',
	'confidence' => 'high', 'plain_label' => 'Views ran above the 30-day norm on 2026-06-20',
);
echo "Group: signal chip\n";
$chip = snt_analytics_render_signal_chip( $sig );
ok( false !== strpos( $chip, 'Predictive' ) && false !== strpos( $chip, 'high' ), 'chip: badges tier + confidence' );
ok( false !== strpos( $chip, 'Views ran above' ), 'chip: shows the plain_label' );

echo "\nGroup: insights band\n";
$GLOBALS['__sig'] = array( $sig );
$GLOBALS['__narr'] = array( 'narrative' => '<p>Spike on the 20th; consider why.</p>', 'source' => 'ai' );
ob_start(); snt_analytics_render_insights_band( '2026-06-14', '2026-06-20', 'human', 'day' ); $h = ob_get_clean();
ok( false !== strpos( $h, 'sn-an-insights' ) && false !== strpos( $h, 'Spike on the 20th' ), 'band: renders the narrative' );
ok( false !== strpos( $h, 'sn-an-signal' ) && false !== strpos( $h, 'data-source="ai"' ), 'band: renders badged chips + marks the source' );
$GLOBALS['__sig'] = array();
$GLOBALS['__narr'] = array( 'narrative' => '', 'source' => 'fallback' );
ob_start(); snt_analytics_render_insights_band( '2026-06-14', '2026-06-20', 'human', 'day' ); $e = ob_get_clean();
ok( false !== strpos( $e, 'needs ~2 weeks' ), 'band: honest empty-state when no signals/narrative' );

echo "\nGroup: v9.33.0 — the band's narrative is the weekly digest\n";
$GLOBALS['__digest'] = array( 'digest' => '', 'source' => 'fallback' );
$GLOBALS['__digest_in'] = null;
if ( ! function_exists( 'sn_analytics_digest' ) ) {
	function sn_analytics_digest( $summary, $signals ) { $GLOBALS['__digest_in'] = array( $summary, $signals ); return $GLOBALS['__digest']; }
}
if ( ! function_exists( 'sn_analytics_range_totals' ) ) {
	function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return array( 'views' => 1204, 'visits' => 389 ); }
}
$GLOBALS['__sig'] = array( $sig );
$GLOBALS['__digest'] = array( 'digest' => '<p>Weekly digest body: refresh /notes/x.</p>', 'source' => 'ai' );
ob_start(); snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' ); $wd = ob_get_clean();
ok( false !== strpos( $wd, 'Weekly digest body' ) && false !== strpos( $wd, 'data-source="ai"' ), 'band: narrative slot renders the weekly digest + its source' );
ok( is_array( $GLOBALS['__digest_in'] ) && 1204 === ( $GLOBALS['__digest_in'][0]['views'] ?? 0 ) && 'anomaly' === ( $GLOBALS['__digest_in'][1][0]['kind'] ?? '' ), 'band: digest receives the real range totals + the signals' );
$GLOBALS['__sig'] = array();
$GLOBALS['__digest'] = array( 'digest' => '', 'source' => 'fallback' );
ob_start(); snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' ); $we = ob_get_clean();
ok( false !== strpos( $we, 'needs ~2 weeks' ), 'band: empty digest keeps the honest empty state' );

echo "\nGroup: v9.35.0 — band framing (I6)\n";
$GLOBALS['__sig'] = array( $sig );
$GLOBALS['__digest'] = array( 'digest' => '<p>Body.</p>', 'source' => 'ai' );
ob_start(); snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' ); $fb = ob_get_clean();
ok( false !== strpos( $fb, 'Prescriptive' ) && false !== strpos( $fb, 'Predictive' ), 'band head: tier names survive when the shared component is absent (fallback)' );
ok( false !== strpos( $fb, 'sn-an-methods-note' ) && false !== strpos( $fb, 'median/MAD' ) && false !== strpos( $fb, '2 weeks' ), 'band: the methods note names the stats and the honesty limit (the limit IS the flex)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

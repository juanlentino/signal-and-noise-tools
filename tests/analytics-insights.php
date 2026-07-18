<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'wp_specialchars_decode' ) ) { function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $s, ENT_QUOTES ); } }

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
ok( false !== strpos( $h, 'sn-an-headline' ) && false !== strpos( $h, 'Spike on the 20th' ), 'band: renders the narrative (details headline)' );
ok( false !== strpos( $h, 'sn-an-signal' ) && false !== strpos( $h, 'data-source="ai"' ), 'band: renders badged chips + marks the source' );
$GLOBALS['__sig'] = array();
$GLOBALS['__narr'] = array( 'narrative' => '', 'source' => 'fallback' );
ob_start(); snt_analytics_render_insights_band( '2026-06-14', '2026-06-20', 'human', 'day' ); $e = ob_get_clean();
ok( false !== strpos( $e, 'needs ~2 weeks' ), 'band: honest empty-state when no signals/narrative' );

echo "\nGroup: v9.33.0 — the band's narrative is the weekly digest\n";
$GLOBALS['__digest'] = array( 'digest' => '', 'source' => 'fallback' );
$GLOBALS['__digest_in'] = null;
if ( ! function_exists( 'sn_analytics_digest' ) ) {
	function sn_analytics_digest( $summary, $signals, $top_action = '' ) { $GLOBALS['__digest_in'] = array( $summary, $signals, $top_action ); return $GLOBALS['__digest']; }
}
if ( ! function_exists( 'sn_analytics_range_totals' ) ) {
	// Mirrors the REAL post-v9.63.0 merged shape (inc/analytics-read.php @return)
	// so the pass-through pin below proves the digest receives the honest
	// vocabulary, not just the legacy quartet (the stub-drift trap).
	function sn_analytics_range_totals( $from, $to, $class = 'human' ) {
		return array(
			'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 41.0,
			'unique_visitor_days' => 389, 'pageview_visits' => 350, 'viewless_visits' => 39,
			'view_visit_ratio' => 3.44, 'pageviews_per_visitor_day' => 3.095,
			'scroll_avg_per_view' => 58.0, 'time_avg_per_view' => 39.0,
			'scroll_avg_per_visit' => 52.0, 'time_avg_per_visit' => 35.0,
			'integrity_violation' => false, 'exact_metrics_since' => '2026-07-01',
		);
	}
}
// v9.38.0 (D2): the band feeds the digest the top deterministic recommendation
// card's title. Guarded stub so callers without the recs engine loaded degrade.
if ( ! function_exists( 'sn_analytics_recommendations' ) ) {
	function sn_analytics_recommendations() { return $GLOBALS['__rec_cards'] ?? array(); }
}
$GLOBALS['__sig'] = array( $sig );
$GLOBALS['__digest'] = array( 'digest' => '<p>Weekly digest body: refresh /notes/x.</p>', 'source' => 'ai' );
ob_start(); snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' ); $wd = ob_get_clean();
ok( false !== strpos( $wd, 'Weekly digest body' ) && false !== strpos( $wd, 'data-source="ai"' ), 'band: narrative slot renders the weekly digest + its source' );
ok( is_array( $GLOBALS['__digest_in'] ) && 1204 === ( $GLOBALS['__digest_in'][0]['views'] ?? 0 ) && 'anomaly' === ( $GLOBALS['__digest_in'][1][0]['kind'] ?? '' ), 'band: digest receives the real range totals + the signals' );
ok( 350 === ( $GLOBALS['__digest_in'][0]['pageview_visits'] ?? null ) && 39 === ( $GLOBALS['__digest_in'][0]['viewless_visits'] ?? null ), 'band: the honest vocabulary fields reach the digest untouched (v9.64.1)' );

// v9.38.0 (D2): the band feeds the digest the top deterministic recommendation
// card's title so the start-here thread survives the recs-brief retirement.
$GLOBALS['__rec_cards'] = array( array( 'title' => 'Fix the cooling post' ) );
ob_start(); snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' ); ob_get_clean();
ok( 'Fix the cooling post' === ( $GLOBALS['__digest_in'][2] ?? null ), 'band: passes the top recommendation card title as the digest top_action' );
$GLOBALS['__rec_cards'] = array();
ob_start(); snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' ); ob_get_clean();
ok( '' === ( $GLOBALS['__digest_in'][2] ?? 'x' ), 'band: no recommendation cards -> empty top_action' );

$GLOBALS['__sig'] = array();
$GLOBALS['__digest'] = array( 'digest' => '', 'source' => 'fallback' );
ob_start(); snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' ); $we = ob_get_clean();
ok( false !== strpos( $we, 'needs ~2 weeks' ), 'band: empty digest keeps the honest empty state' );

echo "\nGroup: v9.35.0 — band framing (I6)\n";
$GLOBALS['__sig'] = array( $sig );
$GLOBALS['__digest'] = array( 'digest' => '<p>Body.</p>', 'source' => 'ai' );
ob_start(); snt_analytics_render_insights_band( '2026-07-06', '2026-07-12', 'human', 'day' ); $fb = ob_get_clean();
ok( false === strpos( $fb, 'sn-an-tier-badges' ), 'band head: the PRESCRIPTIVE/PREDICTIVE badge pair is retired (chips alone carry tiers, D1 badge diet)' );
ok( false !== strpos( $fb, 'Predictive' ), 'chips still badge their tier' );
ok( false !== strpos( $fb, 'sn-an-methods-note' ) && false !== strpos( $fb, 'median/MAD' ) && false !== strpos( $fb, '2 weeks' ), 'band: the methods note names the stats and the honesty limit (the limit IS the flex)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

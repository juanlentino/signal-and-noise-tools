<?php
/**
 * Signal & Noise — the analytics-maturity explainer ([sn_analytics_maturity]).
 * The "how this works" case-study content (maturity I6, spec §11): the four-tier
 * model, per-tier engines, and the honesty principles. STATIC by design — no
 * live metrics, no per-person data — so the owner can publish it on a public
 * page as the portfolio artifact. All output escaped at the point of build.
 * @package SignalNoiseTools @since 9.35.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The tier rows: slug → [label, question, engine]. The slugs mirror the shared
 * badge component's whitelist (snt_analytics_tier_badge).
 * @return array<string,array{0:string,1:string,2:string}>
 */
function sn_analytics_maturity_tiers() {
	return array(
		'descriptive'  => array( __( 'Descriptive', 'signal-and-noise-tools' ), __( 'What happened?', 'signal-and-noise-tools' ), __( 'SQL rollups over first-party, cookieless pageviews', 'signal-and-noise-tools' ) ),
		'diagnostic'   => array( __( 'Diagnostic', 'signal-and-noise-tools' ), __( 'Why did it happen?', 'signal-and-noise-tools' ), __( 'AI narration over typed signals, with a deterministic template as the floor', 'signal-and-noise-tools' ) ),
		'predictive'   => array( __( 'Predictive', 'signal-and-noise-tools' ), __( 'What is likely next?', 'signal-and-noise-tools' ), __( 'Transparent statistics: robust median/MAD anomalies, Theil-Sen trends, backtested Holt forecasts', 'signal-and-noise-tools' ) ),
		'prescriptive' => array( __( 'Prescriptive', 'signal-and-noise-tools' ), __( 'What should we do?', 'signal-and-noise-tools' ), __( 'Rules-built action cards; AI prioritizes and explains but can never invent an action', 'signal-and-noise-tools' ) ),
	);
}

/**
 * [sn_analytics_maturity] — the explainer. Returns (never echoes) per the
 * shortcode contract; safe for a public page (static content only).
 * @return string
 */
function sn_analytics_maturity_shortcode() {
	$out  = '<div class="sn-maturity">';
	$out .= '<h2>' . esc_html__( 'How this analytics stack works', 'signal-and-noise-tools' ) . '</h2>';
	$out .= '<p>' . esc_html__( 'Four maturity tiers, each answering one question. Descriptive and predictive are deterministic (SQL + statistics); diagnostic and prescriptive narration is where the AI earns its seat - always with a deterministic fallback, so every surface renders with AI off.', 'signal-and-noise-tools' ) . '</p>';
	$out .= '<table class="sn-maturity-table"><thead><tr><th>' . esc_html__( 'Tier', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_analytics_maturity_tiers() as $slug => $t ) {
		$out .= '<tr class="sn-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $t[0] ) . '</td><td>' . esc_html( $t[1] ) . '</td><td>' . esc_html( $t[2] ) . '</td></tr>';
	}
	$out .= '</tbody></table>';
	$out .= '<h3>' . esc_html__( 'Honest by construction', 'signal-and-noise-tools' ) . '</h3><ul class="sn-maturity-principles">';
	$principles = array(
		__( 'Robust statistics (median/MAD, Theil-Sen) - a single wild day cannot fake a trend.', 'signal-and-noise-tools' ),
		__( 'Minimum-sample floors: below ~2 weeks of history, signals are suppressed, not guessed.', 'signal-and-noise-tools' ),
		__( 'The predictive baseline is anchored to the window end - changing the display range never changes the stats.', 'signal-and-noise-tools' ),
		__( 'Forecast confidence is measured, not asserted: a rolling backtest scores held-out interval coverage, and every forecast names its calibration.', 'signal-and-noise-tools' ),
		__( 'Every AI narration has a deterministic floor and a monthly budget cap; over budget, the floor renders.', 'signal-and-noise-tools' ),
		__( 'First-party and cookieless: aggregate counts only, never per-person data.', 'signal-and-noise-tools' ),
	);
	foreach ( $principles as $p ) {
		$out .= '<li>' . esc_html( $p ) . '</li>';
	}
	$out .= '</ul></div>';
	return $out;
}
add_shortcode( 'sn_analytics_maturity', 'sn_analytics_maturity_shortcode' );

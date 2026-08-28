<?php
/**
 * Signal & Noise — the analytics-maturity explainer ([sn_analytics_maturity]).
 * The "how this works" case-study content (maturity I6, spec §11): the four-tier
 * model, per-tier engines, and the honesty principles — refreshed for the 2026-07
 * analytics-integrity arc (v9.63.0→v9.69.0) in v9.70.0, which also added the
 * `format` attribute (full | table | principles | compact, whitelisted) and the
 * render-time front-end stylesheet (assets/maturity-front.css, the
 * provenance-front idiom: enqueue only when the shortcode actually renders).
 * STATIC by design — no live metrics, no per-person data — so the owner can
 * publish it on a public page as the portfolio artifact. All output escaped at
 * the point of build; the shortcode returns, never echoes.
 * @package SignalNoiseTools @since 9.35.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The format whitelist. Unknown values fall back to 'full' — pinned; the raw
 * attribute value never reaches a class attribute.
 */
const SN_MATURITY_FORMATS = array( 'full', 'table', 'principles', 'compact' );

/**
 * The tier rows: slug → [label, question, engine]. The slugs mirror the shared
 * badge component's whitelist (snt_analytics_tier_badge). Every engine claim is
 * verifiable against docs/analytics-integrity-design.md + the CHANGELOG.
 * @return array<string,array{0:string,1:string,2:string}>
 */
function sn_analytics_maturity_tiers() {
	return array(
		'descriptive'  => array( __( 'Descriptive', 'signal-and-noise-tools' ), __( 'What happened?', 'signal-and-noise-tools' ), __( 'SQL rollups over first-party, cookieless pageviews in a vocabulary that names its units: pageview-gated visits vs unique visitor-days, viewless days counted, per-view and per-visit engagement exact from a stated date, and durable session-quality and entry/exit history beyond the edge store\'s 90-day retention', 'signal-and-noise-tools' ) ),
		'diagnostic'   => array( __( 'Diagnostic', 'signal-and-noise-tools' ), __( 'Why did it happen?', 'signal-and-noise-tools' ), __( 'AI narration governed by two contracts - an input contract (the model receives defined counts plus the structural explanation, so the explained can never be narrated as an anomaly) and a voice contract (plain prose, no jargon) - with a deterministic template as the floor', 'signal-and-noise-tools' ) ),
		'predictive'   => array( __( 'Predictive', 'signal-and-noise-tools' ), __( 'What is likely next?', 'signal-and-noise-tools' ), __( 'Transparent statistics: robust median/MAD anomalies, Theil-Sen trends, backtested Holt forecasts', 'signal-and-noise-tools' ) ),
		'prescriptive' => array( __( 'Prescriptive', 'signal-and-noise-tools' ), __( 'What should we do?', 'signal-and-noise-tools' ), __( 'Rules-built action cards plus a landing surface that triages - threshold-gated attention flags (a percentage bar and an absolute floor, sentiment-aware: only changes that need a human) reorder the view; AI prioritizes and explains but can never invent an action', 'signal-and-noise-tools' ) ),
	);
}

/**
 * The honesty principles: the six that held since v9.35.0, the six the 2026-07
 * integrity arc earned, and a thirteenth graduated off the roadmap board (v13.19.0). Raw strings; escaped at the point of build.
 * @return string[]
 */
function sn_analytics_maturity_principles() {
	return array(
		__( 'Robust statistics (median/MAD, Theil-Sen) - a single wild day cannot fake a trend.', 'signal-and-noise-tools' ),
		__( 'Minimum-sample floors: below ~2 weeks of history, signals are suppressed, not guessed.', 'signal-and-noise-tools' ),
		__( 'The predictive baseline is anchored to the window end - changing the display range never changes the stats.', 'signal-and-noise-tools' ),
		__( 'Forecast confidence is measured, not asserted: a rolling backtest scores held-out interval coverage, and every forecast names its calibration.', 'signal-and-noise-tools' ),
		__( 'Every AI narration has a deterministic floor and a monthly budget cap; over budget, the floor renders.', 'signal-and-noise-tools' ),
		__( 'First-party and cookieless: aggregate counts only, never per-person data.', 'signal-and-noise-tools' ),
		__( 'Views can never undercount visits by construction; the impossible case trips a monitored alarm, never a silent clamp.', 'signal-and-noise-tools' ),
		__( 'A failed read renders "could not be read" - a failure never impersonates a quiet week.', 'signal-and-noise-tools' ),
		__( 'Zero, null, and absent are three different answers, end to end: a measured 0 is never conflated with "never measured" or "not asked".', 'signal-and-noise-tools' ),
		__( 'Every unit is named: sessions, visitor-days, and views are different units and never share a label.', 'signal-and-noise-tools' ),
		__( 'Visitor identity is forward-secret: each day\'s salt is deleted at rotation, so yesterday\'s visitors are unrecoverable, even by the operator.', 'signal-and-noise-tools' ),
		__( 'Even chart colors carry meaning honestly: a worsening metric is never painted green.', 'signal-and-noise-tools' ),
		// THE THIRTEENTH. Graduated off the hub roadmap board (v13.19.0) when the
		// Analytics done column hit the ceiling -- the first graduation onto THIS
		// page, the two earlier Analytics exits having been bare retirements onto
		// surfaces that already stated them. Phrased as an honesty claim, like its
		// siblings, rather than as a feature announcement: the board says what
		// shipped, this list says how the numbers behave.
		__( 'An AI-sent reader is a different signal from a search-sent reader, and the rollups keep them apart - counted as its own aggregate segment, because lumping them together hides the shift.', 'signal-and-noise-tools' ),
	);
}

/** The intro section (full format only). @return string Escaped HTML. */
function sn_analytics_maturity_intro_html() {
	return '<h2>' . esc_html__( 'How this analytics stack works', 'signal-and-noise-tools' ) . '</h2>'
		. '<p>' . esc_html__( 'Four maturity tiers, each answering one question. Descriptive and predictive are deterministic (SQL + statistics); diagnostic and prescriptive narration is where the AI earns its seat - governed by explicit input and voice contracts, always with a deterministic fallback, so every surface renders with AI off.', 'signal-and-noise-tools' ) . '</p>';
}

/** The tier table. @return string Escaped HTML. */
function sn_analytics_maturity_table_html() {
	$out = '<table class="sn-maturity-table"><thead><tr><th>' . esc_html__( 'Tier', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_analytics_maturity_tiers() as $slug => $t ) {
		$out .= '<tr class="sn-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $t[0] ) . '</td><td>' . esc_html( $t[1] ) . '</td><td>' . esc_html( $t[2] ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/** The principles section (heading + list). @return string Escaped HTML. */
function sn_analytics_maturity_principles_html() {
	$out = '<h3>' . esc_html__( 'Honest by construction', 'signal-and-noise-tools' ) . '</h3><ul class="sn-maturity-principles">';
	foreach ( sn_analytics_maturity_principles() as $p ) {
		$out .= '<li>' . esc_html( $p ) . '</li>';
	}
	return $out . '</ul>';
}

/**
 * The compact strip: one sentence + a badge per tier (the snt_analytics_tier_badge
 * markup idiom, on this surface's own public classes). @return string Escaped HTML.
 */
function sn_analytics_maturity_compact_html() {
	$out = '<p class="sn-maturity-compact-intro">' . esc_html__( 'Four analytics maturity tiers - deterministic SQL and statistics at the base, contract-governed AI narration on top, honest by construction.', 'signal-and-noise-tools' ) . '</p>'
		. '<div class="sn-maturity-strip">';
	foreach ( sn_analytics_maturity_tiers() as $slug => $t ) {
		$out .= '<span class="sn-maturity-badge sn-maturity-badge--' . esc_attr( $slug ) . '">' . esc_html( $t[0] ) . '</span>';
	}
	return $out . '</div>';
}

/**
 * Enqueue the front-end stylesheet. Called from the shortcode callback only, so
 * the CSS ships exactly when the explainer renders (wp_enqueue_style mid-page
 * prints the tag via the footer queue; repeat calls are core-deduped by handle).
 */
function sn_analytics_maturity_enqueue() {
	wp_enqueue_style(
		'sn-maturity-front',
		plugins_url( 'assets/maturity-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * [sn_analytics_maturity format="full|table|principles|compact"] — the explainer.
 * Returns (never echoes) per the shortcode contract; safe for a public page
 * (static content only). Unknown formats fall back to 'full' (whitelist).
 *
 * @param array|string $atts Shortcode attributes (core passes '' when bare).
 * @return string
 */
function sn_analytics_maturity_shortcode( $atts = array() ) {
	$atts   = shortcode_atts( array( 'format' => 'full' ), $atts, 'sn_analytics_maturity' );
	$format = in_array( $atts['format'], SN_MATURITY_FORMATS, true ) ? $atts['format'] : 'full';
	sn_analytics_maturity_enqueue();
	$out = '<div class="sn-maturity sn-maturity--' . esc_attr( $format ) . '">';
	if ( 'table' === $format ) {
		$out .= sn_analytics_maturity_table_html();
	} elseif ( 'principles' === $format ) {
		$out .= sn_analytics_maturity_principles_html();
	} elseif ( 'compact' === $format ) {
		$out .= sn_analytics_maturity_compact_html();
	} else {
		$out .= sn_analytics_maturity_intro_html() . sn_analytics_maturity_table_html() . sn_analytics_maturity_principles_html();
	}
	return $out . '</div>';
}
add_shortcode( 'sn_analytics_maturity', 'sn_analytics_maturity_shortcode' );

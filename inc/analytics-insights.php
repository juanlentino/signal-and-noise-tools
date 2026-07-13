<?php
/**
 * Signal & Noise — Insights band (dashboard renderer for the predictive +
 * prescriptive tiers). Consumes signals + narrator; renders narrative + tier-
 * badged chips, or an honest empty state. Reuses existing panel tokens.
 * @package SignalNoiseTools @since 9.30.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * First sentence of a narrative, tag-stripped and clamped for the headline
 * summary row (D1 §3): split on the first sentence terminator, then clamped
 * to 140 display chars (ellipsis only when truncated). mb-safe; a narrative
 * with no terminator returns the clamped whole string.
 *
 * @param string $text Narrative (may contain HTML).
 * @return string Plain-text lead.
 */
function snt_analytics_headline_lead( $text ) {
	// Block/list boundaries must survive the tag strip as whitespace — the
	// fallback digest concatenates sibling <p>/<ul> nodes with zero space.
	$text = preg_replace( '~(</[a-z][a-z0-9]*>|<br\s*/?\s*>)~i', '$1 ', (string) $text );
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	if ( '' === $text ) {
		return '';
	}
	$parts = preg_split( '/(?<=[.!?])\s/u', $text, 2 );
	$lead  = is_array( $parts ) ? $parts[0] : $text;
	return mb_strimwidth( $lead, 0, 140, '…', 'UTF-8' );
}

/** One tier-badged signal chip. Built from escaped fragments. */
function snt_analytics_render_signal_chip( $signal ) {
	$tier = ucfirst( (string) ( $signal['tier'] ?? 'predictive' ) );
	$dir  = (string) ( $signal['direction'] ?? '' );
	$icon = ( 'up' === $dir ) ? '▲' : ( ( 'down' === $dir ) ? '▼' : '•' );
	$sr   = ( 'up' === $dir ) ? __( 'rising', 'signal-and-noise-tools' ) : ( ( 'down' === $dir ) ? __( 'falling', 'signal-and-noise-tools' ) : '' );
	return '<span class="sn-an-signal sn-an-signal--' . esc_attr( (string) ( $signal['kind'] ?? '' ) ) . '">'
		. ( function_exists( 'snt_analytics_tier_badge' ) && '' !== snt_analytics_tier_badge( strtolower( $tier ) )
			? snt_analytics_tier_badge( strtolower( $tier ) ) . ' '
			: '<span class="sn-an-signal-badge">' . esc_html( $tier ) . '</span> ' )
		. '<span class="sn-an-signal-dir" aria-hidden="true">' . esc_html( $icon ) . '</span> '
		. ( '' !== $sr ? '<span class="screen-reader-text">' . esc_html( $sr ) . '</span> ' : '' )
		. esc_html( (string) ( $signal['plain_label'] ?? '' ) )
		. ' <span class="sn-an-signal-conf">' . esc_html( (string) ( $signal['confidence'] ?? '' ) ) . '</span>'
		. '</span>';
}

/** The Insights band — leads the dashboard (spec §4). Guarded for partial installs. */
function snt_analytics_render_insights_band( $from, $to, $class, $granularity ) {
	if ( ! function_exists( 'sn_analytics_signals' ) ) { return; }
	$opts    = function_exists( 'sn_analytics_signal_opts' ) ? sn_analytics_signal_opts() : array();
	$signals = sn_analytics_signals( $from, $to, $class, $opts );
	$summary = function_exists( 'sn_analytics_range_totals' ) ? sn_analytics_range_totals( (string) $from, (string) $to, $class ) : array();
	// v9.33.0 (maturity I4): the band's narrative slot is the weekly executive
	// digest (longer-form, fed the real range totals); narrate() remains the
	// compact path for the WP-home widget and the guard fallback here.
	if ( function_exists( 'sn_analytics_digest' ) ) {
		$d    = sn_analytics_digest( $summary, $signals );
		$narr = array( 'narrative' => (string) ( $d['digest'] ?? '' ), 'source' => (string) ( $d['source'] ?? 'fallback' ) );
	} else {
		$narr = function_exists( 'sn_analytics_narrate' )
			? sn_analytics_narrate( $summary, $signals )
			: array( 'narrative' => '', 'source' => 'fallback' );
	}

	echo '<div class="sn-an-insights">';
	// v9.35.0 (maturity I6): real tier badges via the shared component; the static
	// text stays as the floor for partial installs (the harness-isolation contract).
	$tier_marks = function_exists( 'snt_analytics_tier_badge' )
		? snt_analytics_tier_badge( 'prescriptive' ) . ' ' . snt_analytics_tier_badge( 'predictive' )
		: '<span class="sn-an-tier-note">Prescriptive &middot; Predictive</span>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from the escaped shared component / static fallback markup.
	echo '<div class="sn-an-insights-head"><span>Insights</span> <span class="sn-an-tier-badges">' . $tier_marks . '</span></div>';
	if ( '' !== trim( (string) $narr['narrative'] ) ) {
		echo '<div class="sn-an-insights-narrative" data-source="' . esc_attr( (string) $narr['source'] ) . '">' . wp_kses_post( $narr['narrative'] ) . '</div>';
	} else {
		echo '<p class="sn-an-note">Predictive needs ~2 weeks of history — insights will appear here as data accrues.</p>';
	}
	if ( ! empty( $signals ) ) {
		echo '<div class="sn-an-signal-chips">';
		foreach ( array_slice( $signals, 0, 6 ) as $s ) {
			echo snt_analytics_render_signal_chip( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chip is assembled from esc_html/esc_attr fragments in the helper.
		}
		echo '</div>';
	}
	// Spec §12: naming the limit IS the flex — say what the stats are and what
	// they need, on the surface itself.
	echo '<p class="sn-an-methods-note">' . esc_html__( 'Transparent statistics over first-party rollups: robust median/MAD anomalies, Theil-Sen trends, backtested Holt forecasts with intervals. Signals need ~2 weeks of history - nothing is shown the data cannot support.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';
}

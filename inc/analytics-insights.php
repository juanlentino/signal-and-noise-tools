<?php
/**
 * Signal & Noise — Insights band (dashboard renderer for the predictive +
 * prescriptive tiers). Consumes signals + narrator; renders narrative + tier-
 * badged chips, or an honest empty state. Reuses existing panel tokens.
 * @package SignalNoiseTools @since 9.30.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One tier-badged signal chip. Built from escaped fragments. */
function snt_analytics_render_signal_chip( $signal ) {
	$tier = ucfirst( (string) ( $signal['tier'] ?? 'predictive' ) );
	$dir  = (string) ( $signal['direction'] ?? '' );
	$icon = ( 'up' === $dir ) ? '▲' : ( ( 'down' === $dir ) ? '▼' : '•' );
	return '<span class="sn-an-signal sn-an-signal--' . esc_attr( (string) ( $signal['kind'] ?? '' ) ) . '">'
		. ( function_exists( 'snt_analytics_tier_badge' ) && '' !== snt_analytics_tier_badge( strtolower( $tier ) )
			? snt_analytics_tier_badge( strtolower( $tier ) ) . ' '
			: '<span class="sn-an-signal-badge">' . esc_html( $tier ) . '</span> ' )
		. '<span class="sn-an-signal-dir">' . esc_html( $icon ) . '</span> '
		. esc_html( (string) ( $signal['plain_label'] ?? '' ) )
		. ' <span class="sn-an-signal-conf">' . esc_html( (string) ( $signal['confidence'] ?? '' ) ) . '</span>'
		. '</span>';
}

/** The Insights band — leads the dashboard (spec §4). Guarded for partial installs. */
function snt_analytics_render_insights_band( $from, $to, $class, $granularity ) {
	if ( ! function_exists( 'sn_analytics_signals' ) ) { return; }
	$signals = sn_analytics_signals( $from, $to, $class );
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

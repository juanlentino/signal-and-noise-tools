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
	// The digest narrative arrives with its text already esc_html'd — decode
	// once after stripping so the single esc_html() at output is the ONLY
	// encode (no &amp;#039; garble in the summary).
	$text = wp_specialchars_decode( wp_strip_all_tags( $text ), ENT_QUOTES );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
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

/**
 * The headline band (v9.37.0 dashboard D1): a native <details> replacing the
 * full Insights band. Collapsed = top signal chip + the digest lead sentence +
 * a static "Full insights (N signals)" indicator. Expanded = the full digest
 * narrative, the remaining chips (chip 0 never repeats), and the methods-note
 * footer. Data flow unchanged from v9.30.0/v9.36.0: sn_analytics_digest() over
 * sn_analytics_signals() with the tuned opts; digest → narrate() → honest
 * "needs ~2 weeks" note fallback chain intact. Zero JS — WAI-APG disclosure
 * semantics come free with <details>/<summary>.
 *
 * @param string $from        Window start (Y-m-d).
 * @param string $to          Window end (Y-m-d).
 * @param string $class       Traffic class.
 * @param string $granularity 'day' | 'week' | 'month'.
 */
function snt_analytics_render_insights_band( $from, $to, $class, $granularity ) {
	if ( ! function_exists( 'sn_analytics_signals' ) ) {
		return;
	}
	$opts    = function_exists( 'sn_analytics_signal_opts' ) ? sn_analytics_signal_opts() : array();
	$signals = sn_analytics_signals( $from, $to, $class, $opts );
	$summary = function_exists( 'sn_analytics_range_totals' ) ? sn_analytics_range_totals( (string) $from, (string) $to, $class ) : array();
	if ( function_exists( 'sn_analytics_digest' ) ) {
		$d    = sn_analytics_digest( $summary, $signals );
		$narr = array( 'narrative' => (string) ( $d['digest'] ?? '' ), 'source' => (string) ( $d['source'] ?? 'fallback' ) );
	} else {
		$narr = function_exists( 'sn_analytics_narrate' )
			? sn_analytics_narrate( $summary, $signals )
			: array( 'narrative' => '', 'source' => 'fallback' );
	}

	$narrative = trim( (string) $narr['narrative'] );
	$has_narr  = '' !== $narrative;
	$lead      = $has_narr
		? snt_analytics_headline_lead( $narrative )
		: __( 'Predictive needs ~2 weeks of history — insights will appear here as data accrues.', 'signal-and-noise-tools' );
	$count     = count( $signals );

	echo '<details class="sn-an-headline">';
	echo '<summary>';
	if ( $count > 0 ) {
		echo snt_analytics_render_signal_chip( $signals[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chip renderer escapes every dynamic value.
		// Literal separator: CSS flex gap does not reach the accessible name.
		echo ' ';
	}
	echo '<span class="sn-an-headline-lead">' . esc_html( $lead ) . '</span>';
	if ( $count > 0 ) {
		/* translators: %d: number of analytics signals. */
		echo ' <span class="sn-an-headline-more">' . esc_html( sprintf( _n( 'Full insights (%d signal)', 'Full insights (%d signals)', $count, 'signal-and-noise-tools' ), $count ) ) . '</span>';
	}
	echo '</summary>';
	echo '<div class="sn-an-headline-body">';
	if ( $has_narr ) {
		echo '<div class="sn-an-insights-narrative" data-source="' . esc_attr( (string) $narr['source'] ) . '">' . wp_kses_post( $narrative ) . '</div>';
	}
	if ( $count > 1 ) {
		echo '<div class="sn-an-signal-chips">';
		foreach ( array_slice( $signals, 1 ) as $s ) {
			echo snt_analytics_render_signal_chip( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chip renderer escapes every dynamic value.
		}
		echo '</div>';
	}
	echo '<p class="sn-an-methods-note">' . esc_html__( 'Transparent statistics over first-party rollups: robust median/MAD anomalies, Theil-Sen trends, backtested Holt forecasts with intervals. Signals need ~2 weeks of history - nothing is shown the data cannot support.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';
	echo '</details>';
}

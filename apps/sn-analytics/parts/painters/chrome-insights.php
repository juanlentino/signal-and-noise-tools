<?php
/**
 * S&N Analytics — chrome/insights: the headline band as a kit section.
 *
 * Classic: snt_analytics_render_insights_band() in inc/analytics-insights.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_chrome_insights( array $ctx ) {
	if ( ! function_exists( 'sn_analytics_signals' ) ) {
		return '';
	}
	$from    = (string) ( $ctx['from'] ?? '' );
	$to      = (string) ( $ctx['to'] ?? '' );
	$class   = (string) ( $ctx['class'] ?? 'human' );
	$opts    = function_exists( 'sn_analytics_signal_opts' ) ? sn_analytics_signal_opts() : array();
	$signals = sn_analytics_signals( $from, $to, $class, $opts );
	$summary = function_exists( 'sn_analytics_range_totals' ) ? sn_analytics_range_totals( $from, $to, $class ) : array();
	$lead    = __( 'Predictive needs ~2 weeks of history: insights will appear here as data accrues.', 'signal-and-noise-tools' );
	if ( function_exists( 'sn_analytics_digest' ) ) {
		$digest = sn_analytics_digest( $summary, $signals, '' );
		$narr   = trim( (string) ( $digest['digest'] ?? '' ) );
		if ( '' !== $narr && function_exists( 'snt_analytics_headline_lead' ) ) {
			$lead = snt_analytics_headline_lead( $narr );
		} elseif ( '' !== $narr ) {
			$lead = wp_strip_all_tags( $narr );
		}
	}
	$chips = '';
	foreach ( (array) $signals as $signal ) {
		if ( ! is_array( $signal ) ) {
			continue;
		}
		$chips .= \snt_kit_chip( (string) ( $signal['plain_label'] ?? '' ), (string) ( $signal['kind'] ?? '' ) );
	}
	$count = count( (array) $signals );
	$more  = $count > 0
		? '<p class="snt-hint">' . \snt_kit_esc( sprintf( _n( 'Full insights (%d signal)', 'Full insights (%d signals)', $count, 'signal-and-noise-tools' ), $count ) ) . '</p>'
		: '';
	return \snt_kit_section(
		__( 'Insights', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( $lead ) . '</p>' . $chips . $more
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'Transparent statistics over first-party rollups: robust median/MAD anomalies, Theil-Sen trends, backtested Holt forecasts with intervals. Signals need ~2 weeks of history - nothing is shown the data cannot support.', 'signal-and-noise-tools' ) ) . '</p>'
	);
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['chrome/insights'] = __NAMESPACE__ . '\\paint_chrome_insights';
		return $painters;
	}
);

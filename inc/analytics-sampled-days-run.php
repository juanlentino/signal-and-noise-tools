<?php
/**
 * Signal & Noise Tools: the nightly run behind the sampled-day record
 * (inc/analytics-sampled-days.php). It writes nothing, and records why, when
 * the over-cap list did not read (bots would read as human and fake an
 * onset), a read failed, or a set reached the row cap.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the trailing 90 site days and merge. The class expression comes from
 * the rollup's own parts: folded first, then the pre-fold shape, as the
 * rollup itself falls back (inc/analytics-rollup-run-parts.php).
 */
function sn_analytics_sampled_days_run() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() || ! function_exists( 'sn_analytics_stitched_rows' ) ) {
		return;
	}
	$done = static function ( $outcome ) {
		update_option( SN_ANALYTICS_SAMPLED_RUN_OPT, array( 'at' => time(), 'outcome' => $outcome ), false );
		if ( 0 !== strpos( $outcome, 'wrote' ) ) {
			error_log( '[sn-analytics] sampled-day record not written: ' . $outcome );
		}
	};
	if ( empty( sn_analytics_overcap_vdays()['ok'] ) ) {
		$done( 'overcap_failed' );
		return;
	}
	$tz = sn_analytics_site_tz_name();
	foreach ( array( true, false ) as $fold ) {
		list( $class, $tail ) = sn_analytics_rollup_class_parts( $fold );
		$sets                 = sn_analytics_stitched_rows(
			static fn( $source, $range ) => sn_analytics_sampled_sql( $source, $range, $tz, $class, $tail ),
			sn_analytics_trailing_from( SN_ANALYTICS_SAMPLED_WINDOW_DAYS ),
			gmdate( 'Y-m-d H:i:s' )
		);
		if ( null !== $sets ) {
			break;
		}
	}
	if ( null === $sets ) {
		$done( 'read_failed' );
		return;
	}
	$cap = defined( 'SN_ANALYTICS_AE_ROW_CAP' ) ? SN_ANALYTICS_AE_ROW_CAP : 10000;
	if ( array() !== array_filter( $sets, static fn( $s ) => count( $s ) >= $cap ) ) {
		$done( 'truncated' );
		return;
	}
	update_option( SN_ANALYTICS_SAMPLED_OPT, sn_analytics_sampled_merge( (array) get_option( SN_ANALYTICS_SAMPLED_OPT, array() ), sn_analytics_sampled_fold( $sets ) ), false );
	$done( $fold ? 'wrote' : 'wrote_unfolded' );
}
add_action( 'sn_analytics_rollup_daily', 'sn_analytics_sampled_days_run', 20 ); // SN_ANALYTICS_ROLLUP_DAILY_HOOK, after the rollup; never the 15-minute on-demand hook.

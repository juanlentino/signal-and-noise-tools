<?php
/**
 * Signal & Noise Tools — Analytics view: Engagement (v8.5.0 extraction).
 *
 * Hour/day heatmap, scroll/time/RTT distributions, percentiles, and the
 * field Core Web Vitals bands. Moved verbatim from the dispatcher's switch
 * (inc/analytics-admin.php).
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

/**
 * Render the Engagement view body.
 *
 * @param string $from  Window start (Y-m-d).
 * @param string $to    Window end (Y-m-d).
 * @param string $class Traffic class.
 */
function snt_analytics_render_view_engagement( $from, $to, $class ) {
	snt_analytics_render_heatmap( sn_analytics_hour_dow_grid( $from, $to, $class ) );
	echo '<div class="sn-an-grid">';
	snt_analytics_render_distribution( __( 'Scroll depth', 'signal-and-noise-tools' ), sn_analytics_distribution( 'scroll', $from, $to, $class ) );
	snt_analytics_render_distribution( __( 'Time on page', 'signal-and-noise-tools' ), sn_analytics_distribution( 'time', $from, $to, $class ) );
	// v6.27.0: connection RTT (worker v1.7.0, double4 = clientTcpRtt). TCP-only —
	// HTTP/3 / QUIC requests carry no RTT, so the distribution measures HTTP/1–2.
	snt_analytics_render_distribution( __( 'Connection RTT', 'signal-and-noise-tools' ), sn_analytics_distribution( 'rtt', $from, $to, $class ), 'No TCP round-trips in this range — HTTP/3 connections carry no RTT, so only HTTP/1–2 visitors are measured (needs worker v1.7.0 + traffic).' );
	echo '</div>';
	$pctl_note  = ( strtotime( (string) $to ) - strtotime( (string) $from ) > 90 * DAY_IN_SECONDS )
		? '(reflects the last ~90 days — Analytics Engine raw retention)'
		: '';
	$pctl_empty = 'Percentiles need live Analytics Engine data for this window.';
	echo '<div class="sn-an-grid">';
	snt_analytics_render_percentiles( __( 'Scroll depth — percentiles', 'signal-and-noise-tools' ), sn_analytics_percentiles( 'scroll', $from, $to, $class ), 'pct', $pctl_empty, $pctl_note );
	snt_analytics_render_percentiles( __( 'Time on page — percentiles', 'signal-and-noise-tools' ), sn_analytics_percentiles( 'time', $from, $to, $class ), 'time', $pctl_empty, $pctl_note );
	echo '</div>';
	// v6.28.0: field Core Web Vitals — real-user LCP/INP/CLS in Google's
	// good/needs-work/poor bands (worker v1.8.0 / theme beacon v10.14.0).
	// Empty until those ship + traffic flows.
	$cwv_empty = 'No field Core Web Vitals yet — needs the web-vitals beacon (theme v10.14.0) + worker v1.8.0 + traffic.';
	// D5 §6: fetch the three CWV distributions BEFORE the section separator so an
	// all-empty CWV block can skip the sep instead of orphaning it above three
	// folded panels (the reads are reused below — no double fetch).
	$lcp_rows = sn_analytics_distribution( 'lcp', $from, $to, $class );
	$inp_rows = sn_analytics_distribution( 'inp', $from, $to, $class );
	$cls_rows = sn_analytics_distribution( 'cls', $from, $to, $class );
	$cwv_has_data = false;
	foreach ( array( $lcp_rows, $inp_rows, $cls_rows ) as $rows ) {
		foreach ( (array) $rows as $r ) {
			if ( (int) ( $r['views'] ?? 0 ) > 0 ) {
				$cwv_has_data = true;
				break 2;
			}
		}
	}
	if ( $cwv_has_data ) {
		echo '<p class="sn-an-sep sn-an-sep--full">' . esc_html__( 'Field Core Web Vitals — what real visitors experienced (vs the synthetic Lighthouse lab score).', 'signal-and-noise-tools' ) . '</p>';
	}
	echo '<div class="sn-an-cwv-grid">';
	snt_analytics_render_distribution( __( 'LCP (field)', 'signal-and-noise-tools' ), $lcp_rows, $cwv_empty );
	snt_analytics_render_distribution( __( 'INP (field)', 'signal-and-noise-tools' ), $inp_rows, $cwv_empty );
	snt_analytics_render_distribution( __( 'CLS (field)', 'signal-and-noise-tools' ), $cls_rows, $cwv_empty );
	echo '</div>';
	snt_analytics_render_anomalies( sn_analytics_engagement_anomalies( $from, $to, $class ) );
	snt_an_flush_empty_fold();
}

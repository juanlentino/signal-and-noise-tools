<?php
/**
 * Signal & Noise Tools — Analytics view: Events (v8.5.0 extraction).
 *
 * Custom-events leaderboard + the property breakdown with its Lane-A
 * ?sn_event_prop drill (a read-only GET filter — the param read moved here
 * verbatim from the dispatcher's switch, phpcs rationale included).
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

/**
 * Render the Events view body.
 *
 * @param string $from Window start (Y-m-d).
 * @param string $to   Window end (Y-m-d).
 */
function snt_analytics_render_view_events( $from, $to ) {
	// Custom events carry no traffic-class dimension (from/to only), so the
	// global Human/Suspect/Bot control is inert here — say so explicitly.
	echo '<p class="sn-an-sep">Custom events are <strong>not segmented by traffic class</strong> — the class filter above does not apply to this view.</p>';
	$ev_prop = isset( $_GET['sn_event_prop'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_event_prop'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter on an admin report, no state change.
	echo '<div class="sn-an-grid">';
	snt_analytics_render_events_table( sn_analytics_top_events( $from, $to, 25 ) );
	snt_analytics_render_event_props_table( sn_analytics_top_event_props( $from, $to, $ev_prop, 50 ), $ev_prop );
	echo '</div>';
	snt_an_flush_empty_fold();
}

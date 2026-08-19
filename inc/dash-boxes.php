<?php
/**
 * Signal & Noise — the Dashboard metaboxes.
 *
 * Six thin callbacks. Every one reads snt_dashboard_snapshot() and none
 * computes anything: the builders they wrap are already pure and tested.
 *
 * @package SignalNoiseTools
 * @since 11.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A box title carrying its state as a dot.
 *
 * The dot belongs in the TITLE, not the body. Core lets a user collapse a box
 * (`closedpostboxes_{page}`); if state lived in the body, collapsing Systems
 * would hide an open finding. Collapse must hide detail, never state — the
 * same guarantee the superseded pin design made.
 *
 * @since 11.29.0
 * @param string $label
 * @param string $state One of SN_DASH_STATES, or a pill kind.
 * @return string Escaped HTML.
 */
function snt_dash_box_title( $label, $state ) {
	return '<span class="sn-dash-dot sn-dash-dot--' . esc_attr( (string) $state ) . '" aria-hidden="true"></span>'
		. esc_html( (string) $label );
}

/**
 * Render a list of glance cards as console rows.
 *
 * Order comes from sn_admin_glance_sort_by_attention() — the v10.48.0 sort,
 * reused rather than reimplemented, so a card that opts out of promotion is
 * honoured here exactly as it is everywhere else.
 *
 * @since 11.29.0
 * @param array<int,array<string,mixed>> $cards
 * @return void
 */
function snt_dash_render_rows( array $cards ) {
	if ( empty( $cards ) ) {
		return;
	}

	echo '<ul class="sn-dash-rows">';
	foreach ( sn_admin_glance_sort_by_attention( $cards ) as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$kind  = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : 'ok';
		$href  = isset( $card['href'] ) ? (string) $card['href'] : '';
		$label = (string) ( $card['label'] ?? '' );
		$value = (string) ( $card['value'] ?? '' );

		echo '<li class="sn-dash-row">';
		echo '<span class="sn-dash-dot sn-dash-dot--' . esc_attr( $kind ) . '" aria-hidden="true"></span>';
		if ( '' !== $href ) {
			echo '<a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
		} else {
			echo '<span>' . esc_html( $label ) . '</span>';
		}
		echo '<span class="sn-dash-row__value">' . esc_html( $value ) . '</span>';
		echo '</li>';
	}
	echo '</ul>';
}

/**
 * Systems — every check that is a verdict about whether something is wrong.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_systems() {
	$snap   = snt_dashboard_snapshot();
	$labels = array( 'Health', 'Cron', 'Caches', 'Provenance' );
	$rows   = array();
	foreach ( $snap['cards'] as $card ) {
		if ( is_array( $card ) && in_array( (string) ( $card['label'] ?? '' ), $labels, true ) ) {
			$rows[] = $card;
		}
	}
	snt_dash_render_rows( $rows );
}

/**
 * Fleet — component versions, with the recent deploys folded in.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_fleet() {
	$snap = snt_dashboard_snapshot();
	$zone = sn_dash_zone_fleet(
		snt_dashboard_fleet_components( $snap['theme'], $snap['plugin'], $snap['workers'] ),
		$snap['last_deploy_ago']
	);
	snt_dash_render_rows( $zone['cards'] );

	if ( ! empty( $snap['runs'] ) && function_exists( 'snt_dashboard_render_deploy_row' ) ) {
		echo '<ul class="sn-deploy-list">';
		foreach ( $snap['runs'] as $run ) {
			snt_dashboard_render_deploy_row( $run );
		}
		echo '</ul>';
	}
}

/**
 * Traffic — the 30-day trend.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_traffic() {
	if ( ! function_exists( 'sn_analytics_daily_series' ) || ! function_exists( 'snt_analytics_sparkline' ) ) {
		echo '<p class="description"><em>' . esc_html__( 'Analytics is not configured.', 'signal-and-noise-tools' ) . '</em></p>';
		return;
	}

	$from   = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
	$to     = gmdate( 'Y-m-d', time() );
	$series = sn_analytics_daily_series( $from, $to, 'human', 'day' );

	if ( empty( $series ) ) {
		// Absent is not zero: an empty window means nothing was recorded, and a
		// flat line at zero would assert traffic we never measured.
		echo '<p class="description"><em>' . esc_html__( 'No traffic recorded in this window.', 'signal-and-noise-tools' ) . '</em></p>';
		return;
	}

	echo '<div class="sn-dash-trend">';
	// snt_analytics_sparkline returns pre-escaped SVG (coords esc_attr'd, chrome static).
	echo snt_analytics_sparkline( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped SVG from the shared helper.
	echo '</div>';
}

/**
 * At a glance — the five figures.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_glance() {
	$snap = snt_dashboard_snapshot();
	sn_dash_render_measurement_strip( sn_dash_measurement_figures( $snap['measurement'] ) );
}

/**
 * Maintenance — the four actions, unchanged.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_maintenance() {
	snt_dashboard_render_maintenance_actions();
}

/**
 * Diagnostics — the override detail list.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_diagnostics() {
	snt_dashboard_render_diagnostics();
}

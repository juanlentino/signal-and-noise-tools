<?php
/**
 * Signal & Noise — Machine Readers leaf composition (pure), classic.
 *
 * Configuration only since the evidence-basis arc: the readings (who reads,
 * for what, how each count is known) are S&N Analytics views, Machine readers
 * and Reader evidence (inc/analytics-machine-views.php). This leaf keeps the
 * sensor's status and the way to those views, the two Site Health verdicts,
 * and the worker readout beside the settings form; the monthly rights
 * evidence follows (sn_admin_render_rights_evidence()). Pure: it takes
 * pre-rendered fragments and returns the leaf as a string. No folds.
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The way to the readings, in S&N Analytics, with visible text.
 *
 * @return string
 */
function snt_mr_classic_analytics_links() {
	$url = static fn( $view ) => function_exists( 'snt_mr_analytics_view_url' ) ? snt_mr_analytics_view_url( $view ) : admin_url( 'admin.php?page=sn-analytics&sn_view=' . $view );
	return '<p class="sn-mr-links"><a class="button" href="' . esc_url( $url( 'machine-readers' ) ) . '">' . esc_html__( 'Machine readers in S&N Analytics', 'signal-and-noise-tools' ) . '</a> '
		. '<a class="button" href="' . esc_url( $url( 'reader-evidence' ) ) . '">' . esc_html__( 'Reader evidence in S&N Analytics', 'signal-and-noise-tools' ) . '</a></p>';
}

/**
 * Compose the leaf.
 *
 * @param array $ctx {
 *     @type string $sensor_status_html Rendered sensor pills.
 *     @type array  $verdicts           Site Health verdicts: heading => {status, summary}.
 *     @type string $edge_readout_html  Rendered worker-version notice.
 *     @type string $settings_form_html Rendered settings form.
 * }
 * @return string
 */
function snt_mr_compose_tab( $ctx ) {
	$ctx = (array) $ctx;
	$out = '<div class="sn-an-settings-leaf sn-mr-leaf">';

	$out .= '<div class="sn-fieldset sn-fieldset--wide sn-an-pipeline sn-mr-hero">';
	$out .= '<h3 class="sn-fieldset-h">' . esc_html__( 'Sensor status', 'signal-and-noise-tools' ) . '</h3>';
	$out .= '<p class="sn-an-settings-help">' . esc_html__( 'Edge sensor → Analytics Engine → S&N Analytics. Presence checks only, secret values are never shown. Who reads, for what, and how each count is known are read in S&N Analytics.', 'signal-and-noise-tools' ) . '</p>';
	$out .= (string) ( $ctx['sensor_status_html'] ?? '' );
	$out .= snt_mr_classic_analytics_links();
	$out .= '</div>';

	$boxes = '';
	foreach ( (array) ( $ctx['verdicts'] ?? array() ) as $heading => $v ) {
		$tone   = 'good' === ( $v['status'] ?? '' ) ? 'success' : ( 'critical' === ( $v['status'] ?? '' ) ? 'error' : 'warning' );
		$boxes .= '<div class="sn-fieldset"><h3 class="sn-fieldset-h">' . esc_html( (string) $heading ) . '</h3>'
			. '<div class="notice notice-' . esc_attr( $tone ) . ' inline"><p>' . esc_html( (string) ( $v['summary'] ?? '' ) ) . '</p></div></div>';
	}
	if ( '' !== $boxes ) {
		$out .= '<div class="sn-2up sn-mr-2up">' . $boxes . '</div>';
	}

	$out .= '<div class="sn-2up sn-mr-2up">';
	$out .= '<div class="sn-fieldset"><h3 class="sn-fieldset-h">' . esc_html__( 'Edge sensor', 'signal-and-noise-tools' ) . '</h3>';
	$out .= '<p class="sn-an-settings-help">' . esc_html__( 'The deployed rights-signals Worker, from its version endpoint. Cached for up to 15 minutes, so a fresh deploy can take that long to appear here — purge caches to read it now.', 'signal-and-noise-tools' ) . '</p>';
	$out .= (string) ( $ctx['edge_readout_html'] ?? '' ) . '</div>';
	$out .= '<div class="sn-fieldset"><h3 class="sn-fieldset-h">' . esc_html__( 'Sensor settings', 'signal-and-noise-tools' ) . '</h3>' . (string) ( $ctx['settings_form_html'] ?? '' ) . '</div>';
	$out .= '</div>'; // .sn-2up

	return $out . '</div>'; // .sn-an-settings-leaf
}

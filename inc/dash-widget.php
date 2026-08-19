<?php
/**
 * Signal & Noise — the consolidated index.php dashboard widget.
 *
 * ONE BOX. v11.30.0 folds four into it: Login defense, Analytics — Overview,
 * Analytics — Top content, and S&N Health. That is the same move v8.3.0 made
 * when it folded S&N Uptime into S&N Health — "one 'is everything okay' surface
 * instead of a fifth dashboard box" (owner call, 2026-07-02) — applied to the
 * boxes that survived it. Four widgets each answering a fragment meant the home
 * screen never answered the question.
 *
 * WHAT THIS WIDGET IS FOR. One decision: is anything wrong? Google's SRE
 * practice calls this progressive disclosure — the summary answers in about a
 * second and links to the screen that holds the detail. It deliberately does
 * NOT reproduce the full dashboard: a widget column is ~400px, and Few's
 * single-screen rule is what makes the full layout work at all.
 *
 * ZERO COST ON RENDER, non-negotiable. index.php renders on every admin login,
 * so this reads cached options only — never a remote call, never a scan. Same
 * discipline the uptime widget established in v8.2.0.
 *
 * @package SignalNoiseTools
 * @since 11.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', 'sn_dash_widget_register' );

/**
 * Register the one box.
 *
 * Gated view_stats || manage_options — the WIDER of the two gates it replaces,
 * because the analytics boxes were reachable that way and consolidating must
 * not quietly revoke someone's access. The narrower manage_options concerns
 * are tiered inside the render instead.
 *
 * @since 11.30.0
 * @return void
 */
function sn_dash_widget_register() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'sn_dashboard', __( 'Signal & Noise', 'signal-and-noise-tools' ), 'sn_dash_widget_render' );
}

/**
 * The glance cards this widget can build without spending anything.
 *
 * Every source here is a cached option or an in-memory walk. Nothing that
 * probes, scans or fetches belongs in this function — see the file header.
 *
 * @since 11.30.0
 * @return array<int,array<string,mixed>>
 */
function sn_dash_widget_cards() {
	$cards = array();

	// The cached scan only. sn_health_run_scan() walks every post and makes
	// remote probes; calling it here would put that on every admin login.
	if ( function_exists( 'sn_health_last_scan' ) && function_exists( 'sn_health_finding_total' ) ) {
		$scan = sn_health_last_scan();
		if ( is_array( $scan ) ) {
			$scan  = function_exists( 'sn_health_scan_for_surface' ) ? sn_health_scan_for_surface( $scan ) : $scan;
			$total = (int) sn_health_finding_total( $scan );
			$cards[] = array(
				'label' => __( 'Health', 'signal-and-noise-tools' ),
				/* translators: %d health findings */
				'value' => sprintf( _n( '%d finding', '%d findings', $total, 'signal-and-noise-tools' ), $total ),
				'pill'  => array( 'kind' => $total > 0 ? 'warn' : 'ok', 'text' => $total > 0 ? 'findings' : 'clear' ),
			);
		}
	}

	// _get_cron_array() is an option read; the summary is a walk over it.
	if ( function_exists( 'snt_cron_summary_for_localize' ) ) {
		$cron    = snt_cron_summary_for_localize();
		$state   = (string) ( $cron['state'] ?? 'ok' );
		$cards[] = array(
			'label' => __( 'Cron', 'signal-and-noise-tools' ),
			'value' => '' !== (string) ( $cron['note'] ?? '' )
				? (string) $cron['note']
				/* translators: %d scheduled events */
				: sprintf( _n( '%d event', '%d events', (int) ( $cron['total'] ?? 0 ), 'signal-and-noise-tools' ), (int) ( $cron['total'] ?? 0 ) ),
			'pill'  => array( 'kind' => $state, 'text' => $state ),
		);
	}

	return $cards;
}

/**
 * Render the box: verdict, then exceptions, then a way through.
 *
 * @since 11.30.0
 * @return void
 */
function sn_dash_widget_render() {
	$admin   = current_user_can( 'manage_options' );
	$verdict = sn_dash_verdict( $admin ? sn_dash_widget_cards() : array( array( 'label' => '', 'pill' => array( 'kind' => 'ok' ) ) ) );
	$url     = admin_url( 'admin.php?page=sn-theme-options&tab=dashboard' );

	echo '<div class="sn-dw sn-dw--' . esc_attr( $verdict['state'] ) . '">';
	echo '<p class="sn-dw__verdict">' . esc_html( $verdict['headline'] ) . '</p>';

	// The exception band is manage_options business: findings and cron faults
	// are only actionable by an admin, and the S&N Health widget this replaces
	// was gated exactly that way. A view_stats reader gets the verdict only.
	if ( $admin && ! empty( $verdict['exceptions'] ) ) {
		echo '<ul class="sn-dw__exceptions">';
		foreach ( $verdict['exceptions'] as $ex ) {
			echo '<li class="sn-dw__ex sn-dw__ex--' . esc_attr( $ex['kind'] ) . '">';
			echo '<b>' . esc_html( $ex['label'] ) . '</b> ' . esc_html( $ex['detail'] );
			echo '</li>';
		}
		echo '</ul>';
	}

	echo '<p class="sn-dw__foot"><a href="' . esc_url( $url ) . '">'
		. esc_html__( 'Open the dashboard', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
	echo '</div>';
}

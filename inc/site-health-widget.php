<?php
/**
 * "S&N Health" dashboard widget — the latest Content-Health scan at a glance on
 * the WP home dashboard (index.php), beside the two Analytics widgets and the
 * Login-defense widget. Owner-approved 2026-06-29 as the second sanctioned
 * exception to the no-new-widgets line (inc/login-defense-widget.php was the
 * first). Ships inside v7.0.0.
 *
 * Read-only and zero-cost on render: it reads sn_health_last_scan() (the cached
 * autoload=no option) ONLY and NEVER triggers sn_health_run_scan() — index.php
 * renders on every admin login, and a scan walks all posts + does remote probes.
 *
 * Presentation reuses the analytics widgets' .sn-aw-* vocabulary (enqueued on the
 * Dashboard home screen by inc/analytics-widget.php), so this box reads as a
 * sibling of the other three. The finding total + ranked flagged checks come from
 * the shared inc/health-summary.php accessors, so this widget, the Dashboard-tab
 * glance card, and its attention strip never disagree on what is off.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Max ranked rows shown in the findings state before overflowing to the link. */
const SN_SITE_HEALTH_WIDGET_MAX_ROWS = 4;

add_action( 'wp_dashboard_setup', 'sn_site_health_widget_register' );

/**
 * Register the widget. manage_options only — intentionally narrower than the
 * analytics / login widgets' view_stats || manage_options gate, because the
 * findings and the link target are only actionable by an admin.
 */
function sn_site_health_widget_register() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'sn_site_health', __( 'S&N Health', 'signal-and-noise-tools' ), 'sn_site_health_widget_render' );
}

/**
 * Render the glance. Three states: no scan cached, all clear, findings present.
 */
function sn_site_health_widget_render() {
	$health_url = admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' );
	$scan       = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;

	// ── State 1: no scan cached. Dormant, matches the siblings' .sn-aw-err. ──
	if ( ! is_array( $scan ) ) {
		echo '<p class="sn-aw-err">'
			. esc_html__( 'No health scan yet. Run one from the Health tab.', 'signal-and-noise-tools' )
			. ' <a href="' . esc_url( $health_url ) . '">' . esc_html__( 'Open the Health tab', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
		return;
	}

	$total      = sn_health_finding_total( $scan );
	$scanned_at = ! empty( $scan['scanned_at'] ) ? (int) $scan['scanned_at'] : 0;

	// ── State 2: all clear. ──
	if ( $total < 1 ) {
		echo '<p class="sn-aw-foot"><strong>' . esc_html__( 'All clear.', 'signal-and-noise-tools' ) . '</strong> '
			. esc_html__( 'No health findings.', 'signal-and-noise-tools' );
		if ( $scanned_at > 0 ) {
			echo ' ' . esc_html( sprintf(
				/* translators: %s: human-readable time difference, e.g. "2 hours". */
				__( 'Scanned %s ago.', 'signal-and-noise-tools' ),
				human_time_diff( $scanned_at, time() )
			) );
		}
		echo '</p>';
		return;
	}

	// ── State 3: findings present. ──
	$flagged     = sn_health_flagged_checks( $scan );
	$flag_count  = count( $flagged );
	$find_label  = 1 === $total ? __( 'Finding', 'signal-and-noise-tools' ) : __( 'Findings', 'signal-and-noise-tools' );
	$check_label = 1 === $flag_count ? __( 'Check flagged', 'signal-and-noise-tools' ) : __( 'Checks flagged', 'signal-and-noise-tools' );

	echo '<div class="sn-aw-grid">';
	echo '<div class="sn-aw-stat"><div class="sn-aw-stat-n">' . esc_html( number_format_i18n( $total ) ) . '</div>'
		. '<div class="sn-aw-stat-l">' . esc_html( $find_label ) . '</div></div>';
	echo '<div class="sn-aw-stat"><div class="sn-aw-stat-n">' . esc_html( number_format_i18n( $flag_count ) ) . '</div>'
		. '<div class="sn-aw-stat-l">' . esc_html( $check_label ) . '</div></div>';
	echo '</div>';

	$visible = array_slice( $flagged, 0, SN_SITE_HEALTH_WIDGET_MAX_ROWS, true );
	echo '<ul class="sn-aw-list">';
	foreach ( $visible as $check ) {
		echo '<li><span class="k">' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</span>'
			. '<span class="v">' . esc_html( number_format_i18n( (int) ( $check['count'] ?? 0 ) ) ) . '</span></li>';
	}
	echo '</ul>';

	echo '<p class="sn-aw-foot"><a href="' . esc_url( $health_url ) . '">';
	if ( $flag_count > SN_SITE_HEALTH_WIDGET_MAX_ROWS ) {
		echo esc_html( sprintf(
			/* translators: %s: total finding count. */
			__( 'View all %s findings', 'signal-and-noise-tools' ),
			number_format_i18n( $total )
		) );
	} else {
		echo esc_html__( 'Open the Health tab', 'signal-and-noise-tools' );
	}
	echo ' &rarr;</a></p>';
}

<?php
/**
 * Login defense dashboard widget: at-a-glance attack stats on the WP home
 * dashboard + a link to the full Analytics-dashboard Login defense view. Owner-requested
 * (the sanctioned exception to the no-new-widgets line). Mirrors the grandfathered
 * inc/analytics-widget.php registration. Read-only.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ── CONSOLIDATED AWAY IN v11.30.0 ──────────────────────────────────────────
 * The standalone dashboard-widget registration is REMOVED. Four S&N boxes on
 * index.php — Login defense, Analytics Overview, Analytics Top content and
 * S&N Health — each answered a fragment, so the home screen never answered the
 * question. They are now one "Signal & Noise" widget (inc/dash-widget.php)
 * carrying the verdict and its exceptions, linking through to the full screen.
 *
 * This is the same move v8.3.0 made when it folded S&N Uptime into S&N Health.
 * Removal guards in tests/dash-widget.php keep this registration gone.
 *
 * The RENDER functions below are deliberately kept: they are still reachable
 * from the full Dashboard screen, and deleting them would take working surfaces
 * out with a layout change.
 * ──────────────────────────────────────────────────────────────────────────
 */


/**
 * Register the widget (same capability gate as inc/analytics-widget.php).
 */
function sn_login_defense_widget_register() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// v11.30.0: no longer registers a box. Kept as a named no-op so any caller
	// or test referencing it still resolves, and so the removal is visible here
	// rather than being an unexplained absence.
	return;
}

/**
 * Render the glance: blocked (7d), block rate, top attacker network + a link to
 * the full view. Dormant-gates when Cloudflare Analytics is not connected.
 *
 * Presentation reuses the analytics widgets' .sn-aw-* vocabulary (blocked +
 * block-rate as a two-up .sn-aw-grid of .sn-aw-stat tiles; top-network + link in
 * .sn-aw-foot). That stylesheet (assets/analytics/analytics-widget.css) is
 * enqueued by inc/analytics-widget.php on the Dashboard home screen (index.php) —
 * the same screen this widget renders on — so no separate enqueue is needed and
 * this box reads as a sibling of the two "Analytics —" widgets instead of a bare
 * default list.
 */
function sn_login_defense_widget_render() {
	$h = function_exists( 'sn_login_defense_headline' ) ? sn_login_defense_headline() : array( 'configured' => false );
	if ( empty( $h['configured'] ) ) {
		// v6.47.0: match the two sibling analytics widgets' dormant treatment —
		// the styled .sn-aw-err class (not a bare <p>) and the same prerequisite
		// (the edge worker), so all three boxes read as one design system + tell
		// one story when Cloudflare Analytics is disconnected.
		echo '<p class="sn-aw-err">' . esc_html__( 'Login defense stats need the Cloudflare analytics edge worker connected: see the Analytics widgets above for setup.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	echo '<div class="sn-aw-grid">';
	echo '<div class="sn-aw-stat"><div class="sn-aw-stat-n">' . esc_html( number_format_i18n( (int) $h['blocked'] ) ) . '</div>'
		. '<div class="sn-aw-stat-l">' . esc_html__( 'Blocked (7d)', 'signal-and-noise-tools' ) . '</div></div>';
	echo '<div class="sn-aw-stat"><div class="sn-aw-stat-n">' . esc_html( (int) $h['block_rate'] . '%' ) . '</div>'
		. '<div class="sn-aw-stat-l">' . esc_html__( 'Block rate', 'signal-and-noise-tools' ) . '</div></div>';
	echo '</div>';
	// v8.5.0 pairing: the 7d blocked microspark from the headline's cached
	// trend (no widget-side query) — same .sn-aw-trend treatment as the
	// Overview widget's views sparkline.
	if ( ! empty( $h['trend'] ) && function_exists( 'snt_analytics_sparkline' ) ) {
		echo '<div class="sn-aw-trend">';
		echo snt_analytics_sparkline( $h['trend'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped SVG from the shared helper.
		echo '<span class="sn-aw-trend-l">' . esc_html__( '7-day blocked', 'signal-and-noise-tools' ) . '</span>';
		echo '</div>';
	}
	// v6.44.0: surface the denominator (the returned-but-previously-ignored `checked`
	// total) so the block rate has volume context — 40% of 10 reads very differently
	// from 40% of 10,000. Forward-only 7d window, same source as the tiles above.
	echo '<p class="sn-aw-foot">' . esc_html( sprintf(
		/* translators: 1: blocked count, 2: total requests checked */
		__( '%1$s of %2$s requests blocked (7d)', 'signal-and-noise-tools' ),
		number_format_i18n( (int) $h['blocked'] ),
		number_format_i18n( (int) ( $h['checked'] ?? 0 ) )
	) ) . '</p>';
	if ( '' !== (string) $h['top_network'] ) {
		echo '<p class="sn-aw-foot">' . esc_html__( 'Top network:', 'signal-and-noise-tools' )
			. ' <strong>' . esc_html( $h['top_network'] ) . '</strong></p>';
	}
	echo '<p class="sn-aw-foot"><a href="' . esc_url( snt_analytics_page_url( array( 'sn_view' => 'login-defense' ) ) ) . '">'
		. esc_html__( 'View login defense', 'signal-and-noise-tools' ) . ' →</a></p>';
}

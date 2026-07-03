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
	wp_add_dashboard_widget( 'sn_site_health', __( 'S&N Health', 'signal-and-noise-tools' ), 'sn_site_health_widget_render_full' );
}

/**
 * The registered callback since v8.3.0: the health render plus the Uptime
 * section (inc/uptime-status-widget.php) — the standalone "S&N Uptime"
 * widget was consolidated into this one on the owner's call. The inner
 * render keeps its early-return states, so the section is appended HERE,
 * not inline; the section is '' when no Better Stack token is configured,
 * and its data loads async (this render stays zero-cost either way).
 */
function sn_site_health_widget_render_full() {
	sn_site_health_widget_render();
	if ( function_exists( 'sn_uptime_status_health_section' ) ) {
		echo sn_uptime_status_health_section(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes at build.
	}
}

/**
 * Render the glance. Three states, each led by a state-colored status header
 * (glyph + headline + subline) so the widget reads as an intentional status
 * card, not a stray line: dormant (no scan), ok (all clear), warn (findings).
 * v7.1.0 redesign — native wp-admin palette, styles in analytics-widget.css.
 */
function sn_site_health_widget_render() {
	$health_url = admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' );
	$scan       = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;

	// ── State 1: no scan cached. Dormant — a neutral prompt, not an error. ──
	if ( ! is_array( $scan ) ) {
		sn_site_health_widget_head(
			'dormant',
			__( 'No scan yet', 'signal-and-noise-tools' ),
			__( 'Run a scan from the Health tab to populate this.', 'signal-and-noise-tools' )
		);
		echo '<p class="sn-aw-foot"><a href="' . esc_url( $health_url ) . '">'
			. esc_html__( 'Open the Health tab', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
		return;
	}

	$total       = sn_health_finding_total( $scan );
	$check_total = function_exists( 'sn_health_check_total' ) ? sn_health_check_total( $scan ) : 0;
	$scanned_at  = ! empty( $scan['scanned_at'] ) ? (int) $scan['scanned_at'] : 0;
	$ago         = $scanned_at > 0
		/* translators: %s: human-readable time difference, e.g. "2 hours". */
		? sprintf( __( 'Scanned %s ago', 'signal-and-noise-tools' ), human_time_diff( $scanned_at, time() ) )
		: '';

	// v8.5.0 pairing: the advisory tier joins the glance — an open advisory
	// queue is information, not alarm, so it rides the subline in BOTH states.
	$advisories = function_exists( 'sn_health_advisory_total' ) ? (int) sn_health_advisory_total( $scan ) : 0;

	// ── State 2: all clear. Green affirmative + "M checks passed". ──
	if ( $total < 1 ) {
		$sub = $check_total > 0
			/* translators: %s: number of health checks that passed. */
			? sprintf( __( '%s checks passed', 'signal-and-noise-tools' ), number_format_i18n( $check_total ) )
			: __( 'No health findings', 'signal-and-noise-tools' );
		if ( $advisories > 0 ) {
			/* translators: %s: open advisory count. */
			$sub .= ' · ' . sprintf( __( '%s advisories', 'signal-and-noise-tools' ), number_format_i18n( $advisories ) );
		}
		if ( '' !== $ago ) {
			$sub .= ' · ' . $ago;
		}
		sn_site_health_widget_head( 'ok', __( 'All clear', 'signal-and-noise-tools' ), $sub );
		return;
	}

	// ── State 3: findings present. Warn header + ranked list. ──
	$flagged    = sn_health_flagged_checks( $scan );
	$flag_count = count( $flagged );
	$headline   = number_format_i18n( $total ) . ' '
		. ( 1 === $total ? __( 'finding', 'signal-and-noise-tools' ) : __( 'findings', 'signal-and-noise-tools' ) );
	$sub        = $check_total > 0
		/* translators: 1: flagged check count, 2: total check count. */
		? sprintf( __( 'across %1$s of %2$s checks', 'signal-and-noise-tools' ), number_format_i18n( $flag_count ), number_format_i18n( $check_total ) )
		/* translators: %s: flagged check count. */
		: sprintf( __( 'across %s checks', 'signal-and-noise-tools' ), number_format_i18n( $flag_count ) );
	if ( $advisories > 0 ) {
		/* translators: %s: open advisory count. */
		$sub .= ' · ' . sprintf( __( '%s advisories', 'signal-and-noise-tools' ), number_format_i18n( $advisories ) );
	}
	if ( '' !== $ago ) {
		$sub .= ' · ' . $ago;
	}
	sn_site_health_widget_head( 'warn', $headline, $sub );

	$visible = array_slice( $flagged, 0, SN_SITE_HEALTH_WIDGET_MAX_ROWS, true );
	echo '<ul class="sn-aw-list sn-hw-list">';
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

/**
 * Render the state-colored status header: a round glyph badge + headline + a
 * muted subline. Static SVG glyphs (echoed as literals so WPCS EscapeOutput is
 * satisfied); the two dynamic text values are escaped at the sink here, so
 * callers pass raw strings. Kinds: 'ok' (green check), 'warn' (amber alert),
 * 'dormant' (muted dash).
 *
 * @param string $kind     One of ok|warn|dormant.
 * @param string $headline Raw headline text (escaped here).
 * @param string $sub      Raw subline text (escaped here).
 * @return void
 * @since 7.1.0
 */
function sn_site_health_widget_head( $kind, $headline, $sub ) {
	echo '<div class="sn-hw-head sn-hw-head--' . esc_attr( $kind ) . '">';
	echo '<span class="sn-hw-ico" aria-hidden="true">';
	if ( 'ok' === $kind ) {
		echo '<svg viewBox="0 0 20 20"><path d="M8.6 14.6l-4.3-4.3 1.4-1.4 2.9 2.9 6-6 1.4 1.4z"/></svg>';
	} elseif ( 'warn' === $kind ) {
		echo '<svg viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm-1 4h2v6H9V6zm0 8h2v2H9v-2z"/></svg>';
	} else {
		echo '<svg viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm-4 7h8v2H6V9z"/></svg>';
	}
	echo '</span>';
	echo '<div class="sn-hw-txt"><p class="sn-hw-h">' . esc_html( $headline ) . '</p>'
		. '<p class="sn-hw-sub">' . esc_html( $sub ) . '</p></div>';
	echo '</div>';
}

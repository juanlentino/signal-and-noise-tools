<?php
/**
 * Signal & Noise Tools — Analytics view: Overview (preview) — the v9.67.0
 * flag-gated STATIC MOCK of the assembled landing surface (assembly option C,
 * 2026-07-18 dashboard audit: "a static mock render fn behind a feature-flagged
 * tab slug in SN_ANALYTICS_VIEWS for owner review before any data wiring").
 *
 * Everything on this tab is HARDCODED sample data shaped like the real site
 * (views 47 / gated visits 40 / 91 visitor-days / 51 viewless; /provhub/ and
 * the QR campaign; the AR-first country mix) — it reads NO analytics accessor,
 * ever. Every panel carries the "PREVIEW — sample data" badge so a screenshot
 * can never be mistaken for real numbers: honesty rules apply to fake data too.
 *
 * The flag: the `sn_analytics_landing_preview` option (default absent = OFF —
 * the tab then exists nowhere: registry, drilldowns, and abilities untouched).
 * Toggle via one WP-CLI line (`wp option update sn_analytics_landing_preview 1`
 * / `wp option delete sn_analytics_landing_preview`) or the Monitoring →
 * Analytics "Landing preview" fold (handler at the end of this file — the
 * inc/schedule-admin.php subsystem-cohesion precedent).
 *
 * Composition: EXCLUSIVELY the existing snt_an_* panel primitives
 * (inc/analytics-panels.php). Light-only, no JS, no <wpd-*> — this is a
 * wp-admin view, not a desktop-mode window (widgets stay widgets).
 *
 * @package SignalNoiseTools
 * @since 9.67.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // panel chrome + KPI row + trend + k/v table primitives

// The feature-flag option row. Absent (default) = the preview tab does not exist.
const SN_ANALYTICS_LANDING_PREVIEW_OPT = 'sn_analytics_landing_preview';

/**
 * True iff the Overview (preview) landing-surface mock is enabled. Reads the
 * plain option (one WP-CLI line to toggle), then offers the house filter seam
 * (the sn_websub_enabled / sn_health_*_check_enabled idiom). Guarded for
 * isolated CLI harnesses that load this file without a WP option store.
 *
 * @since 9.67.0
 * @return bool
 */
function snt_analytics_landing_preview_enabled() {
	$enabled = function_exists( 'get_option' )
		? (bool) get_option( SN_ANALYTICS_LANDING_PREVIEW_OPT, false )
		: false;
	if ( function_exists( 'apply_filters' ) ) {
		$enabled = (bool) apply_filters( 'sn_analytics_landing_preview_enabled', $enabled );
	}
	return $enabled;
}

/**
 * The unmistakable per-panel marker: a warning-yellow "PREVIEW — sample data"
 * chip riding every panel header via the primitive's header_meta slot (kses'd
 * at snt_an_panel_open). Returned pre-built from escaped fragments, the
 * snt_analytics_tier_badge() idiom.
 *
 * @since 9.67.0
 * @return string
 */
function snt_an_lab_badge() {
	return '<span class="sn-an-lab-badge">' . esc_html__( 'PREVIEW — sample data', 'signal-and-noise-tools' ) . '</span>';
}

/**
 * Render the Overview (preview) view body: the full research-§6 menu in ONE
 * glance — honest headline KPIs (the v9.63 vocabulary), session quality
 * (engine KPIs + durable-trend mini), top sources + UTM mini, geography +
 * device minis, realtime tile, entry/exit minis — all static fixture data.
 *
 * Zero-arg on purpose: the mock is window-inert (no $from/$to/$class), and
 * taking them would imply data it does not read.
 *
 * @since 9.67.0
 */
function snt_analytics_render_view_overview_lab() {
	$badge = snt_an_lab_badge();

	// ── 1. Honest headline (v9.63 vocabulary): Views / gated Visits / Now /
	// exact per-view engagement, plus the visitor-day secondary line. ──
	snt_an_panel_open( __( 'Headline', 'signal-and-noise-tools' ), array( 'header_meta' => $badge ) );
	snt_an_kpi_row(
		array(
			array( 'l' => __( 'Views', 'signal-and-noise-tools' ), 'n' => number_format_i18n( 47 ), 'promoted' => true, 'sub' => __( 'last 7 days', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Visits', 'signal-and-noise-tools' ), 'n' => number_format_i18n( 40 ), 'promoted' => true, 'sub' => __( 'pageview-gated', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Now', 'signal-and-noise-tools' ), 'n' => number_format_i18n( 2 ), 'live' => true ),
			array( 'l' => __( 'Scroll / view', 'signal-and-noise-tools' ), 'n' => '58%', 'sub' => __( 'exact', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Time / view', 'signal-and-noise-tools' ), 'n' => '1m 42s', 'sub' => __( 'exact', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Engaged', 'signal-and-noise-tools' ), 'n' => '38%' ),
		),
		array( 'empty_slot' => 'omit' )
	);
	echo '<p class="sn-an-visitor-note">' . esc_html(
		sprintf(
			/* translators: 1: visitor-day count, 2: viewless visitor-day count, 3: first exact-metrics day (Y-m-d). */
			__( '%1$s visitor-days · %2$s viewless (no pageview) · exact metrics since %3$s', 'signal-and-noise-tools' ),
			number_format_i18n( 91 ),
			number_format_i18n( 51 ),
			'2026-04-18'
		)
	) . '</p>';
	snt_an_trend_svg(
		array( 2, 3, 1, 2, 3, 2, 3, 5, 9, 6, 7, 8, 5, 7 ),
		array(
			'head'      => __( 'Views — last 14 days', 'signal-and-noise-tools' ),
			'meta'      => __( 'sample series', 'signal-and-noise-tools' ),
			'axis'      => array( 'Jul 5', 'Jul 18' ),
			'id_suffix' => 'LabViews',
		)
	);
	snt_an_panel_close();

	// ── 2. Session quality: within-day engine KPIs + the durable trend the
	// nightly wp_sn_session_daily rollup already accumulates (read by nothing
	// today — this mock shows where that reader would land). ──
	snt_an_panel_open( __( 'Session quality', 'signal-and-noise-tools' ), array( 'header_meta' => $badge ) );
	snt_an_kpi_row(
		array(
			array( 'l' => __( 'Sessions', 'signal-and-noise-tools' ), 'n' => number_format_i18n( 44 ), 'sub' => __( 'within-day engine', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Bounce', 'signal-and-noise-tools' ), 'n' => '52%' ),
			array( 'l' => __( 'Pages / session', 'signal-and-noise-tools' ), 'n' => '1.4' ),
			array( 'l' => __( 'Median duration', 'signal-and-noise-tools' ), 'n' => '1m 05s' ),
			array( 'l' => __( 'Engaged rate', 'signal-and-noise-tools' ), 'n' => '38%' ),
		),
		array( 'empty_slot' => 'omit' )
	);
	snt_an_trend_svg(
		array( 64, 61, 58, 60, 55, 54, 52, 52 ),
		array(
			'head'      => __( 'Bounce rate — 8 weeks', 'signal-and-noise-tools' ),
			'meta'      => __( 'durable rollup (wp_sn_session_daily)', 'signal-and-noise-tools' ),
			'axis'      => array( 'W21', 'W28' ),
			'id_suffix' => 'LabBounce',
		)
	);
	snt_an_panel_close();

	// ── 3–8. The minis, in the shared 2-col grid. ──
	echo '<div class="sn-an-grid">';

	snt_an_kv_table(
		__( 'Top sources', 'signal-and-noise-tools' ),
		array(
			array( __( 'Direct', 'signal-and-noise-tools' ), '18', '16' ),
			array( 'google.com', '11', '9' ),
			array( 'news.ycombinator.com', '7', '6' ),
			array( 'linkedin.com', '5', '4' ),
			array( 'chatgpt.com', '3', '3' ),
		),
		array( __( 'Source', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Visits', 'signal-and-noise-tools' ) ),
		array( 'header_meta' => $badge )
	);

	snt_an_kv_table(
		__( 'Campaigns (UTM)', 'signal-and-noise-tools' ),
		array(
			array( 'qr-provhub', 'qr / talk', '6', '5' ),
			array( 'newsletter', 'email / letter', '3', '3' ),
		),
		array( __( 'Campaign', 'signal-and-noise-tools' ), __( 'Source / medium', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Visits', 'signal-and-noise-tools' ) ),
		array( 'header_meta' => $badge )
	);

	snt_an_kv_table(
		__( 'Geography', 'signal-and-noise-tools' ),
		array(
			array( 'Argentina', '14', '12' ),
			array( 'United States', '9', '8' ),
			array( 'Germany', '5', '4' ),
			array( 'Spain', '4', '3' ),
			array( 'Brazil', '3', '3' ),
		),
		array( __( 'Country', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Visits', 'signal-and-noise-tools' ) ),
		array( 'header_meta' => $badge )
	);

	snt_an_kv_table(
		__( 'Devices', 'signal-and-noise-tools' ),
		array(
			array( __( 'Desktop', 'signal-and-noise-tools' ), '29', '24' ),
			array( __( 'Mobile', 'signal-and-noise-tools' ), '17', '15' ),
			array( __( 'Tablet', 'signal-and-noise-tools' ), '1', '1' ),
		),
		array( __( 'Device', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Visits', 'signal-and-noise-tools' ) ),
		array( 'header_meta' => $badge )
	);

	// Realtime tile (the 5-minute active window + site-local views today).
	snt_an_panel_open( __( 'Right now', 'signal-and-noise-tools' ), array( 'header_meta' => $badge ) );
	snt_an_kpi_row(
		array(
			array( 'l' => __( 'Active visitors', 'signal-and-noise-tools' ), 'n' => number_format_i18n( 2 ), 'live' => true ),
			array( 'l' => __( 'Views today', 'signal-and-noise-tools' ), 'n' => number_format_i18n( 6 ), 'sub' => __( 'site-local day', 'signal-and-noise-tools' ) ),
		),
		array( 'empty_slot' => 'omit' )
	);
	snt_an_panel_close();

	snt_an_kv_table(
		__( 'Entry pages', 'signal-and-noise-tools' ),
		array(
			array( '/', '16' ),
			array( '/provhub/', '9' ),
			array( '/notes/', '8' ),
			array( '/now/', '4' ),
		),
		array( __( 'Page', 'signal-and-noise-tools' ), __( 'Entries', 'signal-and-noise-tools' ) ),
		array( 'header_meta' => $badge )
	);

	snt_an_kv_table(
		__( 'Exit pages', 'signal-and-noise-tools' ),
		array(
			array( '/provhub/', '11' ),
			array( '/notes/', '7' ),
			array( '/now/', '5' ),
			array( '/uses/', '3' ),
		),
		array( __( 'Page', 'signal-and-noise-tools' ), __( 'Exits', 'signal-and-noise-tools' ) ),
		array( 'header_meta' => $badge )
	);

	echo '</div>';

	// The docs note: what this tab is, and that wiring is a separate decision.
	echo '<p class="sn-an-lab-note">' . esc_html__( 'Static design mock — assembly option C from the 2026-07-18 dashboard audit. Every number on this tab is hardcoded sample data shaped like the real site; nothing here reads the analytics pipeline. Wiring this surface to live accessors is a separate follow-up decision. Hide it under Monitoring → Analytics → Landing preview, or: wp option delete sn_analytics_landing_preview', 'signal-and-noise-tools' ) . '</p>';
}

/**
 * One-line state snapshot for the Monitoring → Analytics "Landing preview"
 * settings fold (the snt_an_*_snapshot idiom).
 *
 * @since 9.67.0
 * @return string
 */
function snt_an_landing_preview_snapshot() {
	return snt_analytics_landing_preview_enabled()
		? __( 'On — preview tab visible on the dashboard', 'signal-and-noise-tools' )
		: __( 'Off — preview tab hidden', 'signal-and-noise-tools' );
}

/**
 * The "Landing preview" settings card body (mounted behind snt_an_settings_fold
 * by snt_analytics_render_settings_section): one checkbox + Save, posting the
 * allow-listed analytics_landing_preview_save action on the standard
 * sn-theme-options route.
 *
 * @since 9.67.0
 */
function snt_analytics_render_landing_preview() {
	$on = snt_analytics_landing_preview_enabled();
	echo '<form method="post" class="sn-an-settings sn-an-landing-preview">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<p><label><input type="checkbox" name="sn_landing_preview" value="1"' . checked( $on, true, false ) . '> '
		. esc_html__( 'Show the "Overview (preview)" tab on the Analytics dashboard', 'signal-and-noise-tools' ) . '</label></p>';
	echo '<p class="description">'
		. esc_html__( 'A static design mock of the assembled Overview landing surface — sample data only, no live numbers. WP-CLI: wp option update sn_analytics_landing_preview 1 (on) · wp option delete sn_analytics_landing_preview (off).', 'signal-and-noise-tools' )
		. '</p>';
	echo '<p><button type="submit" class="button button-secondary" name="sn_action" value="analytics_landing_preview_save">'
		. esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form>';
}

/**
 * Save handler for analytics_landing_preview_save (allow-listed in
 * inc/admin-post-handler.php; nonce checked centrally by
 * sn_handle_admin_post() before any handler runs — the standard contract).
 * Lives here with its subsystem (the inc/schedule-admin.php precedent).
 *
 * OFF deletes the option row rather than storing 0 — "absent" is the default
 * state, and a stored falsy would read as configured-off forever (the
 * zero-vs-null discipline applied to flags).
 *
 * @since 9.67.0
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_landing_preview_saved' | 'analytics_landing_preview_unchanged'.
 */
function sn_handle_analytics_landing_preview_save( $post ) {
	$on    = ! empty( $post['sn_landing_preview'] );
	$prior = (bool) get_option( SN_ANALYTICS_LANDING_PREVIEW_OPT, false );
	if ( $on === $prior ) {
		return 'analytics_landing_preview_unchanged';
	}
	if ( $on ) {
		update_option( SN_ANALYTICS_LANDING_PREVIEW_OPT, 1, false );
	} else {
		delete_option( SN_ANALYTICS_LANDING_PREVIEW_OPT );
	}
	return 'analytics_landing_preview_saved';
}

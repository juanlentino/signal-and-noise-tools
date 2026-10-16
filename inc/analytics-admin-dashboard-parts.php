<?php
/**
 * Signal & Noise Tools: analytics-admin.php, the custom and compare windows,
 * the settings URL and the dashboard renderer.
 *
 * Split from inc/analytics-admin.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate + clamp a user custom window. Rejects malformed dates (→ null), swaps a
 * reversed pair, clamps `to`/`from` to today, and `from` to sn_analytics_min_day()
 * when available. Returns [$from,$to] or null (caller falls back to the default).
 *
 * @param string   $from_raw
 * @param string   $to_raw
 * @param int|null $now Unix anchor.
 * @return array{0:string,1:string}|null
 */
function snt_analytics_resolve_custom_window( $from_raw, $to_raw, $now = null ) {
	$now   = ( null === $now ) ? time() : (int) $now;
	$today = gmdate( 'Y-m-d', $now );
	$from  = trim( (string) $from_raw );
	$to    = trim( (string) $to_raw );
	if ( ! snt_analytics_is_ymd( $from ) || ! snt_analytics_is_ymd( $to ) ) {
		return null;
	}
	if ( $from > $to ) { // ISO YYYY-MM-DD sorts lexically
		$tmp = $from; $from = $to; $to = $tmp;
	}
	if ( $to > $today ) {
		$to = $today;
	}
	if ( $from > $today ) {
		$from = $today;
	}
	if ( function_exists( 'sn_analytics_min_day' ) ) {
		$min = sn_analytics_min_day();
		if ( snt_analytics_is_ymd( $min ) && $from < $min ) {
			$from = $min;
		}
	}
	if ( $from > $to ) {
		return null;
	}
	return array( $from, $to );
}

/**
 * Single resolver for the dashboard/export window. Returns [$range_token,$from,$to]
 * — $range_token is the scalar used for URL/display (7|14|30|90|365|'all'|preset|'custom'),
 * $from/$to the concrete inclusive window. Presets + custom resolve here; int/'all'
 * delegate to the unchanged resolve_range + range_dates.
 *
 * @param mixed    $range_raw
 * @param string   $from_raw
 * @param string   $to_raw
 * @param int|null $now
 * @return array{0:int|string,1:string,2:string}
 */
function snt_analytics_resolve_window( $range_raw, $from_raw = '', $to_raw = '', $now = null ) {
	$range_raw = (string) $range_raw;
	$presets   = array( 'this-week', 'this-month', 'this-quarter', 'ytd', 'last-month', 'last-quarter', 'prev-year' );
	if ( in_array( $range_raw, $presets, true ) ) {
		list( $from, $to ) = snt_analytics_preset_dates( $range_raw, $now );
		return array( $range_raw, $from, $to );
	}
	if ( 'custom' === $range_raw ) {
		$win = snt_analytics_resolve_custom_window( $from_raw, $to_raw, $now );
		if ( null !== $win ) {
			return array( 'custom', $win[0], $win[1] );
		}
		$range = 7;
	} else {
		$range = snt_analytics_resolve_range( $range_raw );
	}
	list( $from, $to ) = snt_analytics_range_dates( $range, $now );
	return array( $range, $from, $to );
}

/**
 * Comparison window for [$from,$to] (maturity I5, spec §10): 'prev' = the
 * same-length window immediately before (adjacent, no overlap); 'yoy' = the same
 * dates one year earlier (Feb 29 clamps to Feb 28 — PHP's relative-year math
 * would normalize it to Mar 1). Pure calendar math; DISPLAY-ONLY — the
 * predictive baseline is $to-anchored and never reads this.
 *
 * @param string $from YYYY-MM-DD.
 * @param string $to   YYYY-MM-DD.
 * @param string $mode 'prev' | 'yoy'.
 * @return array{0:string,1:string}
 */
function snt_analytics_compare_window( $from, $to, $mode ) {
	$f = strtotime( (string) $from . ' UTC' );
	$t = strtotime( (string) $to . ' UTC' );
	if ( 'yoy' === (string) $mode ) {
		$cf = sprintf( '%04d%s', (int) gmdate( 'Y', $f ) - 1, gmdate( '-m-d', $f ) );
		$ct = sprintf( '%04d%s', (int) gmdate( 'Y', $t ) - 1, gmdate( '-m-d', $t ) );
		if ( ! checkdate( (int) substr( $cf, 5, 2 ), (int) substr( $cf, 8, 2 ), (int) substr( $cf, 0, 4 ) ) ) {
			$cf = substr( $cf, 0, 4 ) . '-02-28';
		}
		if ( ! checkdate( (int) substr( $ct, 5, 2 ), (int) substr( $ct, 8, 2 ), (int) substr( $ct, 0, 4 ) ) ) {
			$ct = substr( $ct, 0, 4 ) . '-02-28';
		}
		return array( $cf, $ct );
	}
	$len = (int) floor( ( $t - $f ) / DAY_IN_SECONDS ) + 1;
	return array( gmdate( 'Y-m-d', $f - $len * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $f - DAY_IN_SECONDS ) );
}

/**
 * Whitelist the ?sn_compare GET value: 'prev' | 'yoy' | 'off' (default).
 *
 * @param mixed $raw
 * @return string
 */
function snt_analytics_resolve_compare( $raw ) {
	$c = (string) $raw;
	return in_array( $c, array( 'prev', 'yoy' ), true ) ? $c : 'off';
}

/**
 * The settings page the dashboard's "Configure →" link points at (and where the
 * creds form lives): Measurement → Analytics. Built on the page=sn-theme-options
 * route so the form POST hits the allow-listed admin-post handler.
 */
function snt_analytics_settings_url() {
	return admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=analytics' );
}

/**
 * Render the comprehensive READ-ONLY analytics dashboard. Lives on the native WP
 * Dashboard → Analytics page (inc/analytics-dashboard-page.php); the credential
 * settings are split out to Measurement → Analytics
 * (snt_analytics_render_settings_section). No <h1> heading or settings form here
 * — the page chrome owns the title, and the read view carries no form.
 *
 * v5.5.0 layout: a persistent header (controls + separation + delta cards +
 * trend) above a WP-native tab strip (Content · Technology · Geography ·
 * Engagement · Quality · Events). The active tab (?sn_view=, whitelisted) lazily fetches
 * ONLY its own panels' data. Every dimension/derived panel renders its own empty
 * state until the edge data accrues (worker v1.1.0 — no backfill).
 *
 * S2 §5: the v9.37.0 (D1) "tabs lead the page" rule now applies to EVERY install
 * state, not just every configured view — the tab strip renders BEFORE the
 * config gate, so the dashboard's shape (which views exist) is visible even
 * before Cloudflare credentials are set. Only the data below the gate (header
 * region, insights band, per-view panels) is withheld until sn_analytics_config()
 * resolves. This resolves the product question parked at PR #275 (should the
 * tabs render pre-configuration) in favor of "yes" — one visible fact per gate:
 * *what* the dashboard covers is free, *its data* is gated.
 *
 * Note: period-over-period deltas are suppressed for the 'all' range. Trend
 * granularity is daily for windows ≤90 days, weekly beyond.
 */
function snt_analytics_render_dashboard() {
	// Read-only display params — sanitized + whitelisted (no nonce: not state-changing).
	$range_raw = isset( $_GET['sn_range'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_range'] ) ) : '7';
	$from_raw  = isset( $_GET['sn_from'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_from'] ) ) : '';
	$to_raw    = isset( $_GET['sn_to'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_to'] ) ) : '';
	$class     = snt_analytics_resolve_class( isset( $_GET['sn_class'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_class'] ) ) : 'human' );
	$compare   = snt_analytics_resolve_compare( isset( $_GET['sn_compare'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_compare'] ) ) : '' );
	$view      = snt_analytics_resolve_view( isset( $_GET['sn_view'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_view'] ) ) : 'overview' );

	// Window resolution is date math + GET whitelisting with no AE/config-gated
	// accessor reads — so it's safe (and now necessary) to resolve BEFORE the
	// config gate: the tab strip below needs $range/$from/$to to build its
	// window-preserving links on EVERY install state, configured or not.
	// Caveat for future movers: 'all'/'custom' DO read sn_analytics_min_day()
	// (local rollup MIN, transient-cached; the table exists on every install via
	// the init-time installer, and an empty table safely falls back to today).
	list( $range, $from, $to ) = snt_analytics_resolve_window( $range_raw, $from_raw, $to_raw );

	// Config gate: the tab strip renders regardless (S2 §5 — the dashboard's
	// shape is visible from day one); only the data below the gate is withheld.
	// The machine views (SN_ANALYTICS_SENSOR_VIEWS) read the edge sensor with its
	// own credentials and pass the gate. For every other view it is agnostic on
	// purpose: login-defense checks this SAME
	// sn_analytics_config() flag (not a separate credential), so routing it to
	// its own dormant card here would just show an identical message under a
	// different label — one generic gate for every tab keeps the empty state
	// coherent while switching tabs pre-configuration.
	if ( snt_analytics_view_needs_ae( $view ) && ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) ) {
		snt_analytics_render_view_tabs( $view, $range, $class, $from, $to );
		snt_analytics_render_empty();
		return;
	}

	// Granularity from the resolved window day-count — works for every range incl.
	// presets/custom, and is behaviour-identical to the old (int)$range for fixed ranges.
	$gran_days   = (int) floor( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
	$granularity = sn_analytics_granularity( $gran_days );

	// Views that own their chrome (login-defense) skip the shared pageview header
	// entirely — they bring their own KPI cards, trend, and range control and would
	// otherwise stack pageview stats above their own. Computed once; gates the three
	// pageview-only regions below (fetches, header render, post-switch empty hint).
	$owns_chrome = snt_analytics_view_owns_chrome( $view );

	// The AE diagnostic (admins only) leads every view but is decided after the
	// view's reads: a read that succeeds clears the stored error, so a load whose
	// reads all answered shows no old error above a report that loaded fine.
	ob_start();

	// ── v9.37.0 (D1): tabs lead the page on EVERY view — always-visible top
	// navigation (core 5.2 link-tab semantics live in the renderer itself).
	snt_analytics_render_view_tabs( $view, $range, $class, $from, $to );

	// The headline band (collapsed <details>) renders on shared-chrome,
	// class-segmented views only: NOT on login-defense (owns chrome) and NOT
	// on edge (not class-segmented). Guarded for partial installs.
	if ( ! $owns_chrome && 'edge' !== $view && function_exists( 'snt_analytics_render_insights_band' ) ) {
		snt_analytics_render_insights_band( $from, $to, $class, $granularity );
	}

	// ── Persistent header (shared-chrome views). v8.5.0: the whole frame lives
	// in inc/analytics-header-region.php; it returns the range totals so the
	// tail empty-hint below keeps its signal. v9.37.0 (D1): renders BELOW the
	// tabs + headline band.
	$totals = array();
	if ( ! $owns_chrome ) {
		$totals = snt_analytics_render_header_region( $view, $range, $class, $from, $to, $granularity, $compare );
	} elseif ( 'login-defense' === $view && function_exists( 'sn_login_defense_render_header' ) ) {
		// The chrome-owning view renders its OWN header (range + Overview +
		// breakdown) below the shared tabs, in the same slot the pageview
		// header occupies — the frame still matches (no tab-bar jump).
		sn_login_defense_render_header();
	}

	echo '<div class="sn-an-view">';

	// Cross-tab drill-down: ?sn_drill=<dim>:<value> → "Top pages where <dim>=<value>"
	// (on-demand AE, whitelisted + cached). The panel renders ONLY on the view that
	// owns the drilled dim — so a stale drill carried onto another tab shows nothing
	// (no orphan panel above a view with no such table).
	$sn_drill_dims = array(
		'technology' => array( 'browser', 'os', 'device', 'protocol', 'tls' ),
		'geography'  => array( 'country', 'city', 'region', 'network', 'colo', 'timezone' ),
		'content'    => array( 'referrer' ),
	);
	$sn_drill = isset( $_GET['sn_drill'] ) ? sn_analytics_drilldown_parse( sanitize_text_field( wp_unslash( $_GET['sn_drill'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter on an admin report, no state change.
	if ( null !== $sn_drill && in_array( $sn_drill[0], $sn_drill_dims[ $view ] ?? array(), true ) ) {
		$drill_note = ( strtotime( (string) $to ) - strtotime( (string) $from ) > 90 * DAY_IN_SECONDS )
			? '(reflects the last ~90 days. Analytics Engine raw retention)'
			: '';
		snt_analytics_render_drilldown_panel( $sn_drill[0], $sn_drill[1], sn_analytics_drilldown( $sn_drill[0], $sn_drill[1], $from, $to, $class ), $drill_note );
	}

	snt_analytics_render_view_body( $view, $from, $to, $class, $granularity, $range, $compare );
	echo '</div>';

	// Empty hint when configured but the tables are still dormant — keyed on the
	// always-fetched totals, so it shows on whichever tab you land on first. Gated on
	// ! $owns_chrome: $totals is unset for chrome-owning views (login-defense brings
	// its own empty states), so reading it there would warn + show a false notice.
	if ( ! $owns_chrome && (int) ( $totals['views'] ?? 0 ) === 0 ) {
		echo '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No analytics data in this range yet. New data appears within ~15 minutes of a visit once the worker is live.', 'signal-and-noise-tools' ) . '</p>';
	}
	$report = (string) ob_get_clean();
	snt_analytics_render_error();
	echo $report; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it was rendered.
}


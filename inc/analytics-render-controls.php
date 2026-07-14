<?php
/**
 * Signal & Noise — Analytics toolbar partials: the ONE range control (rolling +
 * calendar + custom, v9.39.0 D3), the class/compare pills, the window-preserving
 * link args, and the separation meta (automated-traffic disclosure folded into
 * the toolbar row, v9.37.0 D1). Native wp-admin markup; every dynamic value is
 * escaped at the point of output. Extracted from analytics-admin-render.php
 * (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query args for a dashboard link that preserves the active window. Carries
 * sn_from/sn_to ONLY for a custom range (presets/fixed ranges re-resolve from their
 * token alone, so threading dates through them would just bloat the URL). Lives here
 * with render_controls (its primary consumer); render_view_tabs reuses it and always
 * runs with this file loaded.
 *
 * @return array<string,string>
 */
function snt_analytics_window_args( $range, $class, $from, $to ) {
	$args = array( 'sn_range' => (string) $range, 'sn_class' => (string) $class );
	if ( 'custom' === (string) $range ) {
		$args['sn_from'] = (string) $from;
		$args['sn_to']   = (string) $to;
	}
	return $args;
}

/**
 * The 7 calendar-preset tokens → display labels (single source for the
 * range panel's Calendar row AND the summary label; v9.39.0 D3).
 *
 * @return array<string,string>
 */
function snt_analytics_preset_labels() {
	return array(
		'this-week'    => __( 'This week', 'signal-and-noise-tools' ),
		'this-month'   => __( 'This month', 'signal-and-noise-tools' ),
		'this-quarter' => __( 'This quarter', 'signal-and-noise-tools' ),
		'ytd'          => __( 'Year to date', 'signal-and-noise-tools' ),
		'last-month'   => __( 'Last month', 'signal-and-noise-tools' ),
		'last-quarter' => __( 'Last quarter', 'signal-and-noise-tools' ),
		'prev-year'    => __( 'Previous year', 'signal-and-noise-tools' ),
	);
}

/**
 * Humanize the RESOLVED range token for the one-control summary (v9.39.0 D3).
 * Junk never reaches here in production (the dispatcher resolves first), but
 * the helper degrades safely: unknown token → the raw token; malformed custom
 * dates → 'Custom'. Custom shows the raw ISO window — the compare note's
 * existing date idiom, deliberately not re-localized (D5 may revisit).
 *
 * @param int|string $range Resolved token.
 * @param string     $from  Custom window start (Y-m-d).
 * @param string     $to    Custom window end (Y-m-d).
 * @return string
 */
function snt_analytics_range_label( $range, $from = '', $to = '' ) {
	$range   = (string) $range;
	$rolling = array(
		'7'   => __( 'Last 7 days', 'signal-and-noise-tools' ),
		'14'  => __( 'Last 14 days', 'signal-and-noise-tools' ),
		'30'  => __( 'Last 30 days', 'signal-and-noise-tools' ),
		'90'  => __( 'Last 90 days', 'signal-and-noise-tools' ),
		'365' => __( 'Last year', 'signal-and-noise-tools' ),
	);
	if ( isset( $rolling[ $range ] ) ) {
		return $rolling[ $range ];
	}
	if ( 'all' === $range ) {
		return __( 'All history', 'signal-and-noise-tools' );
	}
	$presets = snt_analytics_preset_labels();
	if ( isset( $presets[ $range ] ) ) {
		return $presets[ $range ];
	}
	if ( 'custom' === $range ) {
		$ymd = '/^\d{4}-\d{2}-\d{2}$/';
		if ( preg_match( $ymd, (string) $from ) && preg_match( $ymd, (string) $to ) ) {
			return $from . ' – ' . $to;
		}
		return __( 'Custom', 'signal-and-noise-tools' );
	}
	return $range;
}

/**
 * The persistent toolbar row: the ONE range control (rolling + calendar +
 * custom, v9.39.0 D3), the class segmented control, the compare pills, export,
 * and the separation meta — every GET link preserving the route.
 *
 * THE param-carry matrix (v9.39.0 D3 — tests/analytics-param-carry.php is the contract):
 *   Context (sn_view, sn_range, sn_from/sn_to [custom only], sn_class, sn_compare):
 *     survive every in-dashboard navigation.
 *   View-local (sn_drill, sn_event_prop, sn_lg_range):
 *     survive window/class/compare changes; RESET on view switch (the tab
 *     strip-list in snt_analytics_render_view_tabs owns the reset).
 *   Deliberate exceptions: export (window+class only); recs/widget deep links
 *   (cross-surface, bare); login-defense internals (sn_lg_range never leaves it).
 *
 * @param int|string $range        Active window (int days or 'all').
 * @param string     $class        Active class.
 * @param string     $from         Custom window start (only carried when $range==='custom').
 * @param string     $to           Custom window end.
 * @param string     $compare      'off' | 'prev' | 'yoy'.
 * @param array      $class_totals { class => {views,visits} } — separation meta source (optional).
 */
function snt_analytics_render_controls( $range, $class, $from = '', $to = '', $compare = 'off', $class_totals = array() ) {
	// Context-aware base: preserve the CURRENT route so the controls work wherever
	// this view is hooked. v5.3.0 moved the analytics dashboard onto the Dashboard
	// tab; deriving the base from the request (vs. a hardcoded Monitoring path)
	// keeps the 7/30/90 + class links on whatever page is rendering them.
	$base = remove_query_arg( array( 'sn_range', 'sn_class', 'sn_from', 'sn_to' ), add_query_arg( array() ) );
	if ( '' === (string) $base ) {
		$base = admin_url( 'admin.php?page=sn-theme-options&tab=dashboard' );
	}

	echo '<div class="sn-toolbar">';

	// ── The ONE range control (v9.39.0 D3, owner variant C): a single native
	// <details> dropdown labeled with the current range; rolling, calendar,
	// and custom all live inside. Replaces the pills row + preset links +
	// the trailing daterange disclosure. Zero JS; no auto-open — the summary
	// itself names the active window.
	$range_labels  = array( 7 => '7d', 14 => '14d', 30 => '30d', 90 => '90d', 365 => '1y' );
	$preset_labels = snt_analytics_preset_labels();
	$fb_parts      = explode( '?', (string) $base, 2 );
	$action        = $fb_parts[0];
	$hidden        = array();
	if ( isset( $fb_parts[1] ) ) {
		parse_str( $fb_parts[1], $hidden );
	}
	$today     = gmdate( 'Y-m-d' );
	$is_custom = ( 'custom' === (string) $range );

	echo '<details class="sn-an-range">';
	echo '<summary><span class="sn-control-label">' . esc_html__( 'Range', 'signal-and-noise-tools' ) . '</span> <span class="sn-an-range-current">' . esc_html( snt_analytics_range_label( $range, $from, $to ) ) . '</span></summary>';
	echo '<div class="sn-an-range-panel">';
	// Rolling row — the six fast entries, same links as the old pills.
	echo '<p class="sn-an-range-row"><span class="sn-control-label">' . esc_html__( 'Rolling', 'signal-and-noise-tools' ) . '</span><span class="button-group">';
	foreach ( SN_ANALYTICS_RANGES as $r ) {
		$url       = add_query_arg( snt_analytics_window_args( $r, $class, $from, $to ), $base );
		$is_active = ( (string) $r === (string) $range );
		$label     = isset( $range_labels[ $r ] ) ? $range_labels[ $r ] : ( $r . 'd' );
		echo '<a class="button button-small' . ( $is_active ? ' active' : '' ) . '"'
			. ( $is_active ? ' aria-pressed="true"' : '' )
			. ' href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	$url_all    = add_query_arg( snt_analytics_window_args( 'all', $class, $from, $to ), $base );
	$active_all = ( 'all' === (string) $range );
	echo '<a class="button button-small' . ( $active_all ? ' active' : '' ) . '"'
		. ( $active_all ? ' aria-pressed="true"' : '' )
		. ' href="' . esc_url( $url_all ) . '">' . esc_html__( 'All', 'signal-and-noise-tools' ) . '</a>';
	echo '</span></p>';
	// Calendar row — the seven presets (now with the pills' active/pressed idiom).
	echo '<p class="sn-an-range-row"><span class="sn-control-label">' . esc_html__( 'Calendar', 'signal-and-noise-tools' ) . '</span><span class="button-group">';
	foreach ( $preset_labels as $key => $label ) {
		$purl      = add_query_arg( array( 'sn_range' => $key, 'sn_class' => $class ), $base );
		$is_active = ( (string) $range === $key );
		echo '<a class="button button-small' . ( $is_active ? ' active' : '' ) . '"'
			. ( $is_active ? ' aria-pressed="true"' : '' )
			. ' href="' . esc_url( $purl ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</span></p>';
	// Custom row — the GET form. Hidden-field whitelist is now final (v9.39.0 D3):
	// page/tab/sn_view (route) + sn_compare/sn_drill/sn_event_prop (context + the
	// view-local filters that must survive a window change). sn_lg_range is
	// deliberately excluded — login-defense owns that param, never this form.
	echo '<form class="sn-an-custom-form" method="get" action="' . esc_url( $action ) . '">';
	foreach ( $hidden as $hk => $hv ) {
		if ( in_array( $hk, array( 'page', 'tab', 'sn_view', 'sn_compare', 'sn_drill', 'sn_event_prop' ), true ) ) {
			echo '<input type="hidden" name="' . esc_attr( $hk ) . '" value="' . esc_attr( (string) $hv ) . '">';
		}
	}
	echo '<input type="hidden" name="sn_range" value="custom">';
	echo '<input type="hidden" name="sn_class" value="' . esc_attr( (string) $class ) . '">';
	echo '<label>' . esc_html__( 'From', 'signal-and-noise-tools' ) . ' <input type="date" name="sn_from" value="' . esc_attr( $is_custom ? (string) $from : '' ) . '" max="' . esc_attr( $today ) . '"></label> ';
	echo '<label>' . esc_html__( 'To', 'signal-and-noise-tools' ) . ' <input type="date" name="sn_to" value="' . esc_attr( $is_custom ? (string) $to : '' ) . '" max="' . esc_attr( $today ) . '"></label> ';
	echo '<button type="submit" class="button button-small">' . esc_html__( 'Apply', 'signal-and-noise-tools' ) . '</button>';
	echo '</form>';
	echo '</div>';
	echo '</details>';

	// Class pills.
	$class_labels = array( 'human' => 'Human', 'suspect' => 'Suspect', 'bot' => 'Bot' );
	echo '<div class="sn-control-group" role="group" aria-label="' . esc_attr__( 'Traffic class', 'signal-and-noise-tools' ) . '">';
	echo '<span class="sn-control-label">' . esc_html__( 'Class', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="button-group">';
	foreach ( $class_labels as $key => $label ) {
		$url      = add_query_arg( snt_analytics_window_args( $range, $key, $from, $to ), $base );
		$is_active = ( $key === $class );
		echo '<a class="button button-small' . ( $is_active ? ' active' : '' ) . '"'
			. ( $is_active ? ' aria-pressed="true"' : '' )
			. ' href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</span></div>';

	// v9.34.0 (maturity I5): first-class comparison — Off · Previous · Year over year.
	// v9.39.0 (D3): these pills now build off $base (which already preserves every
	// context/view-local param present on the request — sn_range/sn_class included)
	// PLUS the resolved window args, so clicking a mode never resets the window back
	// to a 7d/human default (the D3 bug fix); only sn_compare itself changes.
	$compare_labels = array(
		'off'  => __( 'Off', 'signal-and-noise-tools' ),
		'prev' => __( 'Previous', 'signal-and-noise-tools' ),
		'yoy'  => __( 'Year over year', 'signal-and-noise-tools' ),
	);
	$cbase = remove_query_arg( array( 'sn_compare' ), $base );
	echo '<div class="sn-control-group" role="group" aria-label="' . esc_attr__( 'Compare', 'signal-and-noise-tools' ) . '">';
	echo '<span class="sn-control-label">' . esc_html__( 'Compare', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="button-group">';
	foreach ( $compare_labels as $key => $label ) {
		$curl = add_query_arg(
			snt_analytics_window_args( $range, $class, $from, $to ) + ( 'off' === $key ? array() : array( 'sn_compare' => $key ) ),
			$cbase
		);
		$is_active = ( $key === (string) $compare );
		echo '<a class="button button-small' . ( $is_active ? ' active' : '' ) . '"'
			. ( $is_active ? ' aria-pressed="true"' : '' )
			. ' href="' . esc_url( $curl ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</span></div>';

	echo '<span class="sn-toolbar-spacer"></span>';

	// Export — POST form with button-secondary pills.
	echo '<div class="sn-control-group" role="group" aria-label="' . esc_attr__( 'Export', 'signal-and-noise-tools' ) . '">';
	echo '<span class="sn-control-label">' . esc_html__( 'Export', 'signal-and-noise-tools' ) . '</span>';
	echo '<form class="sn-an-export" method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="page" value="sn-theme-options">';
	echo '<input type="hidden" name="sn_action" value="analytics_export">';
	echo '<input type="hidden" name="sn_range" value="' . esc_attr( (string) $range ) . '">';
	echo '<input type="hidden" name="sn_class" value="' . esc_attr( (string) $class ) . '">';
	if ( 'custom' === (string) $range ) {
		echo '<input type="hidden" name="sn_from" value="' . esc_attr( (string) $from ) . '">';
		echo '<input type="hidden" name="sn_to" value="' . esc_attr( (string) $to ) . '">';
	}
	echo '<button type="submit" name="format" value="csv" class="button button-secondary button-small">CSV</button> ';
	echo '<button type="submit" name="format" value="json" class="button button-secondary button-small">JSON</button>';
	echo '</form></div>';

	// v9.37.0 (D1): the permanent separation notice becomes muted toolbar meta —
	// rendered only when automated traffic exists. The "Showing <class>" clause
	// is dropped (the active class pill already says it); bot-share detail stays
	// on the Content view's pulse footer + the Quality view.
	$sep_bot     = (int) ( $class_totals['bot']['views'] ?? 0 );
	$sep_suspect = (int) ( $class_totals['suspect']['views'] ?? 0 );
	$sep_auto    = $sep_bot + $sep_suspect;
	$sep_total   = $sep_auto + (int) ( $class_totals['human']['views'] ?? 0 );
	if ( $sep_auto > 0 ) {
		/* translators: 1: automated view count, 2: bot view count, 3: suspect view count. */
		$sep_meta = sprintf( __( '%1$s automated filtered (%2$s bot · %3$s suspect)', 'signal-and-noise-tools' ), number_format_i18n( $sep_auto ), number_format_i18n( $sep_bot ), number_format_i18n( $sep_suspect ) );
		if ( $sep_total > 0 ) {
			/* translators: %d: percentage of all recorded traffic that is automated. */
			$sep_meta .= ' · ' . sprintf( __( '%d%% of all traffic', 'signal-and-noise-tools' ), round( $sep_auto / $sep_total * 100 ) );
		}
		echo '<span class="sn-an-sep-meta">' . esc_html( $sep_meta ) . '</span>';
	}

	echo '</div>';
}

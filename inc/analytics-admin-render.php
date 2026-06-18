<?php
/**
 * Signal & Noise — Analytics tab partials. Native wp-admin markup; every
 * dynamic value is escaped at the point of output (no PHPCS EscapeOutput
 * exclusion needed). See inc/analytics-admin.php for the orchestrator.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format a millisecond duration as "Nm SSs" / "Ns".
 *
 * @param float $ms Milliseconds.
 * @return string
 */
function snt_analytics_fmt_time( $ms ) {
	$secs = (int) round( (float) $ms / 1000 );
	if ( $secs < 60 ) {
		return $secs . 's';
	}
	$m = (int) floor( $secs / 60 );
	$s = $secs % 60;
	return $m . 'm ' . str_pad( (string) $s, 2, '0', STR_PAD_LEFT ) . 's';
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
 * Range picker + class segmented control (GET links preserving the route).
 *
 * @param int|string $range Active window (int days or 'all').
 * @param string     $class Active class.
 * @param string     $from  Custom window start (only carried when $range==='custom').
 * @param string     $to    Custom window end.
 */
function snt_analytics_render_controls( $range, $class, $from = '', $to = '' ) {
	// Context-aware base: preserve the CURRENT route so the controls work wherever
	// this view is hooked. v5.3.0 moved the analytics dashboard onto the Dashboard
	// tab; deriving the base from the request (vs. a hardcoded Monitoring path)
	// keeps the 7/30/90 + class links on whatever page is rendering them.
	$base = remove_query_arg( array( 'sn_range', 'sn_class', 'sn_from', 'sn_to' ), add_query_arg( array() ) );
	if ( '' === (string) $base ) {
		$base = admin_url( 'admin.php?page=sn-theme-options&tab=dashboard' );
	}

	// Range pills — GET links styled as .button .button-small (zero JS; active state server-set).
	// Must stay in sync with SN_ANALYTICS_RANGES; the $r . 'd' fallback fires only for unlabelled entries.
	$range_labels = array( 7 => '7d', 30 => '30d', 90 => '90d', 365 => '1y' );
	echo '<div class="sn-toolbar">';
	echo '<div class="sn-control-group" role="group" aria-label="' . esc_attr__( 'Date range', 'signal-and-noise-tools' ) . '">';
	echo '<span class="sn-control-label">' . esc_html__( 'Range', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="button-group">';
	foreach ( SN_ANALYTICS_RANGES as $r ) {
		$url      = add_query_arg( snt_analytics_window_args( $r, $class, $from, $to ), $base );
		$is_active = ( (string) $r === (string) $range );
		$label    = isset( $range_labels[ $r ] ) ? $range_labels[ $r ] : ( $r . 'd' );
		echo '<a class="button button-small' . ( $is_active ? ' active' : '' ) . '"'
			. ( $is_active ? ' aria-pressed="true"' : '' )
			. ' href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	$url_all    = add_query_arg( snt_analytics_window_args( 'all', $class, $from, $to ), $base );
	$active_all = ( 'all' === (string) $range );
	echo '<a class="button button-small' . ( $active_all ? ' active' : '' ) . '"'
		. ( $active_all ? ' aria-pressed="true"' : '' )
		. ' href="' . esc_url( $url_all ) . '">' . esc_html__( 'All', 'signal-and-noise-tools' ) . '</a>';
	echo '</span></div>';

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

	echo '</div>';

	// Custom range + presets (zero-JS): preset links re-resolve each load; the custom
	// GET form posts sn_range=custom + sn_from/sn_to back to this page. Lives below the
	// pill toolbar in a collapsible disclosure so it doesn't crowd the inline controls.
	$presets   = array( 'ytd' => 'Year to date', 'last-month' => 'Last month', 'last-quarter' => 'Last quarter', 'prev-year' => 'Previous year' );
	$is_custom = ( 'custom' === (string) $range );
	$is_preset = isset( $presets[ (string) $range ] );
	$fb_parts  = explode( '?', (string) $base, 2 );
	$action    = $fb_parts[0];
	$hidden    = array();
	if ( isset( $fb_parts[1] ) ) {
		parse_str( $fb_parts[1], $hidden );
	}
	$today = gmdate( 'Y-m-d' );

	echo '<details class="sn-an-daterange"' . ( ( $is_custom || $is_preset ) ? ' open' : '' ) . '>';
	echo '<summary>' . esc_html__( 'Custom range', 'signal-and-noise-tools' ) . '</summary>';
	echo '<div class="sn-an-presets">';
	foreach ( $presets as $key => $label ) {
		$purl = add_query_arg( array( 'sn_range' => $key, 'sn_class' => $class ), $base );
		echo '<a class="button button-small' . ( ( (string) $range === $key ) ? ' active' : '' ) . '" href="' . esc_url( $purl ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</div>';
	echo '<form class="sn-an-custom-form" method="get" action="' . esc_url( $action ) . '">';
	foreach ( $hidden as $hk => $hv ) {
		if ( in_array( $hk, array( 'page', 'tab', 'sn_view' ), true ) ) {
			echo '<input type="hidden" name="' . esc_attr( $hk ) . '" value="' . esc_attr( (string) $hv ) . '">';
		}
	}
	echo '<input type="hidden" name="sn_range" value="custom">';
	echo '<input type="hidden" name="sn_class" value="' . esc_attr( (string) $class ) . '">';
	echo '<label>' . esc_html__( 'From', 'signal-and-noise-tools' ) . ' <input type="date" name="sn_from" value="' . esc_attr( $is_custom ? (string) $from : '' ) . '" max="' . esc_attr( $today ) . '"></label> ';
	echo '<label>' . esc_html__( 'To', 'signal-and-noise-tools' ) . ' <input type="date" name="sn_to" value="' . esc_attr( $is_custom ? (string) $to : '' ) . '" max="' . esc_attr( $today ) . '"></label> ';
	echo '<button type="submit" class="button button-small">' . esc_html__( 'Apply', 'signal-and-noise-tools' ) . '</button>';
	echo '</form></details>';
}

/**
 * "Showing <class> traffic · N automated filtered (X bot · Y suspect)".
 *
 * @param array  $class_totals { class => {views,visits} }
 * @param string $class        Active class.
 */
function snt_analytics_render_separation( $class_totals, $class ) {
	$bot     = (int) ( $class_totals['bot']['views'] ?? 0 );
	$suspect = (int) ( $class_totals['suspect']['views'] ?? 0 );
	$auto    = $bot + $suspect;
	echo '<div class="notice notice-info inline"><p>';
	echo 'Showing <strong>' . esc_html( $class ) . '</strong> traffic';
	if ( $auto > 0 ) {
		echo ' · ' . esc_html( number_format_i18n( $auto ) ) . ' automated filtered ('
			. esc_html( number_format_i18n( $bot ) ) . ' bot · '
			. esc_html( number_format_i18n( $suspect ) ) . ' suspect)';
	}
	echo '</p></div>';
}

/**
 * Clamped Catmull-Rom → cubic-bézier smoothing (tension 1/6) shared by the trend
 * charts. Given the plotted x/y coords and the chart's vertical bounds, returns the
 * SVG path `d` for a smooth line through the points. Control-point Y is clamped to
 * [top,base] so a spiky series can't overshoot the box. Pure function — the caller
 * esc_attr's the returned string at the point of output.
 *
 * @param array $px  Plotted x coords (numeric), ascending.
 * @param array $py  Plotted y coords (numeric), same length as $px.
 * @param float $top Min y (chart top).
 * @param float $base Max y (chart baseline).
 * @return string SVG path `d` ('' if no points; bare moveto for a single point).
 */
function snt_analytics_smooth_path( $px, $py, $top, $base ) {
	$n = count( $px );
	if ( $n < 1 ) {
		return '';
	}
	$d = 'M ' . $px[0] . ',' . $py[0];
	for ( $i = 0; $i < $n - 1; $i++ ) {
		$p0x = $px[ max( $i - 1, 0 ) ];
		$p0y = $py[ max( $i - 1, 0 ) ];
		$p3x = $px[ min( $i + 2, $n - 1 ) ];
		$p3y = $py[ min( $i + 2, $n - 1 ) ];
		$c1x = round( $px[ $i ] + ( $px[ $i + 1 ] - $p0x ) / 6, 2 );
		$c1y = round( min( $base, max( $top, $py[ $i ] + ( $py[ $i + 1 ] - $p0y ) / 6 ) ), 2 );
		$c2x = round( $px[ $i + 1 ] - ( $p3x - $px[ $i ] ) / 6, 2 );
		$c2y = round( min( $base, max( $top, $py[ $i + 1 ] - ( $p3y - $py[ $i ] ) / 6 ) ), 2 );
		$d  .= ' C ' . $c1x . ',' . $c1y . ' ' . $c2x . ',' . $c2y . ' ' . $px[ $i + 1 ] . ',' . $py[ $i + 1 ];
	}
	return $d;
}

/**
 * Slim SVG sparkline trend (polyline + area fill + last-point dot + axis labels).
 * Replaces the old chunky bar strip. Inside a native .postbox (no collapse toggle).
 * All injected SVG coords are numeric/esc_attr'd; static SVG chrome is phpcs-clean.
 *
 * @param array  $series      [{day,views,visits}] ascending.
 * @param string $granularity 'day' (default) or 'week' — controls the aria-label.
 */
function snt_analytics_render_trend( $series, $granularity = 'day' ) {
	if ( empty( $series ) ) {
		return;
	}
	$n    = count( $series );
	$max  = 1;
	foreach ( $series as $r ) {
		$max = max( $max, (int) $r['views'] );
	}
	$w    = 600.0;
	$top  = 8.0;
	$base = 78.0;
	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px   = array();
	$py   = array();
	foreach ( array_values( $series ) as $i => $r ) {
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( (int) $r['views'] / $max ) * ( $base - $top ), 2 );
	}

	// Smooth line via the shared helper (clamped Catmull-Rom → bézier).
	$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	$last_x = $px[ $n - 1 ];
	// Area = the smooth line dropped to the baseline and closed.
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $last_x . ',' . $base . ' Z';
	$peak     = 0;
	$peak_day = '';
	foreach ( $series as $r ) {
		if ( (int) $r['views'] >= $peak ) {
			$peak     = (int) $r['views'];
			$peak_day = (string) $r['day'];
		}
	}
	$aria = ( 'week' === $granularity )
		? __( 'Weekly views trend', 'signal-and-noise-tools' )
		: __( 'Daily views trend', 'signal-and-noise-tools' );

	// Body-only trend band (v6.5.2): rendered inside the fused Overview panel,
	// directly below the KPI strip — no separate postbox/header.
	echo '<div class="sn-overview-trend">';
	echo '<div class="sn-trend-head"><span class="sn-trend-title">' . esc_html__( 'Views per day', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-trend-meta">' . esc_html( sprintf( /* translators: %s peak view count */ __( 'peak %s', 'signal-and-noise-tools' ), number_format_i18n( $peak ) ) ) . '</span></div>';
	echo '<div class="sn-spark-wrap">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric coords esc_attr'd, static SVG chrome.
	echo '<svg class="sn-spark" viewBox="0 0 600 84" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $aria ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG defs, no dynamic values.
	echo '<defs><linearGradient id="snSparkFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2271b1" stop-opacity="0.16"/><stop offset="55%" stop-color="#2271b1" stop-opacity="0.04"/><stop offset="100%" stop-color="#2271b1" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#snSparkFill)" stroke="none"/>';
	// non-scaling-stroke keeps the line a crisp 2px regardless of the horizontal stretch (preserveAspectRatio=none).
	echo '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	echo '</svg></div>';
	echo '<div class="sn-spark-axis"><span>' . esc_html( (string) $series[0]['day'] ) . '</span><span>' . esc_html( (string) end( $series )['day'] ) . '</span></div>';
	echo '</div>';
}

/**
 * Echo a period-over-period delta badge (▲/▼/■ + signed pct). pct null → "new"
 * (prev window was empty). No-op when no delta is supplied. Echoes (rather than
 * returns) so the escaping is at the point of output (phpcs-visible).
 *
 * @param array|null $delta {pct:?int, dir:string}
 */
function snt_analytics_render_delta_badge( $delta ) {
	if ( ! is_array( $delta ) || ! isset( $delta['dir'] ) ) {
		return;
	}
	$dir   = (string) $delta['dir'];
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct )
		? ( 'up' === $dir ? 'new' : '—' )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	echo ' <span class="sn-an-delta sn-an-delta--' . esc_attr( $dir ) . '">' . esc_html( $arrow . ' ' . $text ) . '</span>';
}

/**
 * Echo a period-over-period delta badge in the new KPI strip style (▲/▼/■ + signed pct).
 * pct null → "new" (prev window was empty). No-op when no valid delta is supplied.
 *
 * @param array|null $delta {pct:?int, dir:string}
 */
function snt_analytics_render_delta_badge_kpi( $delta ) {
	if ( ! is_array( $delta ) || ! isset( $delta['dir'] ) ) {
		return;
	}
	$dir   = (string) $delta['dir'];
	$cls   = 'up' === $dir ? 'sn-delta-up' : ( 'down' === $dir ? 'sn-delta-down' : 'sn-delta-flat' );
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct )
		? ( 'up' === $dir ? 'new' : '—' )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	echo '<span class="sn-kpi-delta ' . esc_attr( $cls ) . '"><span class="sn-delta-arrow">' . esc_html( $arrow ) . '</span> ' . esc_html( $text ) . '</span>';
}

/**
 * Fused dense KPI strip: Views + Visits (promoted), Now, Avg scroll, Avg time,
 * and optionally Engaged. Views/Visits/Avg scroll/Avg time carry a
 * period-over-period delta badge when $deltas is given. "Now" is always live.
 * Wraps in a native .postbox (no collapse toggle — clean static header).
 *
 * @param int|null   $now     Realtime visitor count (null = not available).
 * @param array      $totals  {views,visits,scroll_avg,time_avg}
 * @param array      $deltas  {views,visits,scroll_avg,time_avg} => {pct,dir}
 * @param array{current:?int,previous?:?int,pct?:?int,dir?:string}|null $engaged Engaged-rate data,
 *                                                                                or null to omit the card.
 */
function snt_analytics_render_cards( $now, $totals, $deltas = array(), $engaged = null ) {
	$cards = array(
		array( 'l' => 'Views',      'n' => number_format_i18n( (int) ( $totals['views'] ?? 0 ) ),  'delta' => $deltas['views'] ?? null,      'promoted' => true ),
		array( 'l' => 'Visits',     'n' => number_format_i18n( (int) ( $totals['visits'] ?? 0 ) ), 'delta' => $deltas['visits'] ?? null,     'promoted' => true ),
		array( 'l' => 'Now',        'n' => ( null === $now ? '—' : number_format_i18n( (int) $now ) ), 'live' => true ),
		array( 'l' => 'Avg scroll', 'n' => (int) round( (float) ( $totals['scroll_avg'] ?? 0 ) ) . '%', 'delta' => $deltas['scroll_avg'] ?? null ),
		array( 'l' => 'Avg time',   'n' => snt_analytics_fmt_time( (float) ( $totals['time_avg'] ?? 0 ) ), 'delta' => $deltas['time_avg'] ?? null ),
	);
	if ( is_array( $engaged ) && null !== ( $engaged['current'] ?? null ) ) {
		$cards[] = array( 'l' => 'Engaged', 'n' => (int) $engaged['current'] . '%', 'delta' => ( isset( $engaged['dir'] ) ? $engaged : null ) );
	}
	// Body-only (no postbox wrapper): render_dashboard fuses this KPI strip and the
	// trend chart into a single "Overview" panel (v6.5.2) so the sparkline is the
	// panel's footer rather than a lonely half-empty box.
	echo '<div class="sn-kpi-row">';
	foreach ( $cards as $c ) {
		echo '<div class="sn-kpi' . ( ! empty( $c['promoted'] ) ? ' sn-kpi-promoted' : '' ) . '">';
		echo '<p class="sn-kpi-label">' . esc_html( $c['l'] ) . '</p>';
		echo '<p class="sn-kpi-value">' . esc_html( $c['n'] ) . '</p>';
		if ( ! empty( $c['live'] ) ) {
			echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html__( 'live', 'signal-and-noise-tools' ) . '</span>';
		} elseif ( ! empty( $c['delta'] ) ) {
			snt_analytics_render_delta_badge_kpi( $c['delta'] );
		} else {
			echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html__( 'no change', 'signal-and-noise-tools' ) . '</span>';
		}
		echo '</div>';
	}
	echo '</div>';
}

/**
 * Referrer-source category panel: Search / Social / Direct / Other as labelled
 * percentage bars (folded from the referrer dimension in inc/analytics-derived.php).
 *
 * @param array $cats [{category,label,views,visits}]
 */
function snt_analytics_render_referrer_categories( $cats ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Traffic sources', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside inside-flush"><div class="sn-an-panel sn-an-refcats">';
	$total = 0;
	foreach ( (array) $cats as $c ) {
		$total += (int) ( $c['views'] ?? 0 );
	}
	if ( $total <= 0 ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No referrer data in this range yet.</p></div></div></div>';
		return;
	}
	echo '<div class="sn-an-refcats-bars">';
	foreach ( (array) $cats as $c ) {
		$v   = (int) ( $c['views'] ?? 0 );
		$pct = (int) round( $v / $total * 100 );
		echo '<div class="sn-an-refcat">';
		echo '<div class="sn-an-refcat-h"><span>' . esc_html( (string) ( $c['label'] ?? '' ) ) . '</span>'
			. '<span class="num">' . esc_html( number_format_i18n( $v ) . ' · ' . $pct . '%' ) . '</span></div>';
		echo '<div class="sn-an-refcat-bar"><span style="width:' . esc_attr( max( 1, $pct ) ) . '%"></span></div>';
		echo '</div>';
	}
	echo '</div></div></div></div>';
}

/**
 * Distribution panel (scroll-depth or time-on-page bands) as horizontal bars
 * scaled to the peak band. Bands come pre-ordered + zero-filled from
 * sn_analytics_distribution().
 *
 * @param string $title
 * @param array  $rows  [{label,views}]
 */
function snt_analytics_render_distribution( $title, $rows, $empty_msg = '' ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div><div class="inside inside-flush"><div class="sn-an-panel sn-an-dist">';
	$max = 0;
	foreach ( (array) $rows as $r ) {
		$max = max( $max, (int) ( $r['views'] ?? 0 ) );
	}
	if ( $max <= 0 ) {
		$msg = ( '' !== $empty_msg ) ? $empty_msg : 'No ' . strtolower( $title ) . ' data in this range yet.';
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html( $msg ) . '</p></div></div></div>';
		return;
	}
	echo '<div class="sn-an-dist-bars">';
	foreach ( (array) $rows as $r ) {
		$v   = (int) ( $r['views'] ?? 0 );
		$pct = (int) round( $v / $max * 100 );
		echo '<div class="sn-an-dist-row">';
		echo '<span class="sn-an-dist-l">' . esc_html( (string) ( $r['label'] ?? '' ) ) . '</span>';
		echo '<span class="sn-an-dist-bar"><span style="width:' . esc_attr( max( 1, $pct ) ) . '%"></span></span>';
		echo '<span class="sn-an-dist-n num">' . esc_html( number_format_i18n( $v ) ) . '</span>';
		echo '</div>';
	}
	echo '</div></div></div></div>';
}

/**
 * Hour-of-day × day-of-week heatmap (CSS grid, cell alpha = intensity). The grid
 * + peak come from sn_analytics_hour_dow_grid(); UTC because AE timestamps are UTC.
 *
 * @param array $heatmap {grid:array<int,array<int,int>>, max:int}
 */
function snt_analytics_render_heatmap( $heatmap ) {
	$grid = ( isset( $heatmap['grid'] ) && is_array( $heatmap['grid'] ) ) ? $heatmap['grid'] : array();
	$max  = (int) ( $heatmap['max'] ?? 0 );
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Activity by hour (UTC)', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside inside-flush"><div class="sn-an-panel sn-an-heatmap-panel">';
	if ( $max <= 0 || empty( $grid ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No hourly data in this range yet.</p></div></div></div>';
		return;
	}
	$days = array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' );
	echo '<div class="sn-an-heatmap" aria-hidden="true">';
	foreach ( $days as $dow => $label ) {
		echo '<div class="sn-an-hm-row"><span class="sn-an-hm-day">' . esc_html( $label ) . '</span>';
		for ( $h = 0; $h < 24; $h++ ) {
			$v     = isset( $grid[ $dow ][ $h ] ) ? (int) $grid[ $dow ][ $h ] : 0;
			$hh    = str_pad( (string) $h, 2, '0', STR_PAD_LEFT );
			$title = $label . ' ' . $hh . ':00 · ' . number_format_i18n( $v ) . ' views';
			// $max > 0 is guaranteed here (the panel returns early on an empty grid).
			if ( $v > 0 ) {
				$alpha = max( 0.12, round( $v / $max, 2 ) );
				echo '<span class="sn-an-hm-cell" style="background:rgba(34,113,177,' . esc_attr( $alpha ) . ')" title="' . esc_attr( $title ) . '"></span>';
			} else {
				echo '<span class="sn-an-hm-cell" title="' . esc_attr( $title ) . '"></span>';
			}
		}
		echo '</div>';
	}
	echo '</div>'; // close .sn-an-heatmap (decorative; aria-hidden)

	// Accessible companion: a visually-hidden data table carrying the same grid,
	// with day row headers + hour column headers. The visual grid above is
	// aria-hidden, so AT users get this structured table instead of an opaque image.
	echo '<table class="screen-reader-text"><caption>'
		. esc_html__( 'Visits by hour of day (UTC) and day of week', 'signal-and-noise-tools' ) . '</caption>';
	echo '<thead><tr><th scope="col">' . esc_html__( 'Day', 'signal-and-noise-tools' ) . '</th>';
	for ( $h = 0; $h < 24; $h++ ) {
		echo '<th scope="col">' . esc_html( str_pad( (string) $h, 2, '0', STR_PAD_LEFT ) . ':00' ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $days as $dow => $label ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
		for ( $h = 0; $h < 24; $h++ ) {
			$cv = isset( $grid[ $dow ][ $h ] ) ? (int) $grid[ $dow ][ $h ] : 0;
			echo '<td>' . esc_html( number_format_i18n( $cv ) ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';

	echo '</div></div></div>'; // close .sn-an-panel, .inside, .postbox
}

/**
 * Traffic-quality panel: a stacked human/suspect/bot bar + the top bot networks
 * (the new edge ASN dimension filtered to class='bot'). Data from
 * sn_analytics_bot_breakdown().
 *
 * @param array $bb {totals:{human,suspect,bot,total}, top_bot_networks:[{value,views,visits}]}
 */
function snt_analytics_render_bot_breakdown( $bb ) {
	$t       = ( isset( $bb['totals'] ) && is_array( $bb['totals'] ) ) ? $bb['totals'] : array();
	$human   = (int) ( $t['human'] ?? 0 );
	$suspect = (int) ( $t['suspect'] ?? 0 );
	$bot     = (int) ( $t['bot'] ?? 0 );
	$total   = (int) ( $t['total'] ?? ( $human + $suspect + $bot ) );

	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Traffic quality', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside inside-flush"><div class="sn-an-panel sn-an-botbreak">';
	if ( $total <= 0 ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No traffic recorded in this range yet.</p></div></div></div>';
		return;
	}
	echo '<div class="sn-an-quality-bar">';
	foreach ( array( 'human' => $human, 'suspect' => $suspect, 'bot' => $bot ) as $cls => $v ) {
		if ( $v <= 0 ) {
			continue;
		}
		$pct = round( $v / $total * 100, 1 );
		echo '<span class="sn-an-q sn-an-q--' . esc_attr( $cls ) . '" style="width:' . esc_attr( $pct ) . '%" '
			. 'title="' . esc_attr( ucfirst( $cls ) . ': ' . number_format_i18n( $v ) . ' (' . $pct . '%)' ) . '"></span>';
	}
	echo '</div>';
	echo '<p class="sn-an-q-legend">';
	echo '<span class="sn-an-q-key sn-an-q--human"></span> Human ' . esc_html( number_format_i18n( $human ) );
	echo ' · <span class="sn-an-q-key sn-an-q--suspect"></span> Suspect ' . esc_html( number_format_i18n( $suspect ) );
	echo ' · <span class="sn-an-q-key sn-an-q--bot"></span> Bot ' . esc_html( number_format_i18n( $bot ) );
	echo '</p>';

	$nets = ( isset( $bb['top_bot_networks'] ) && is_array( $bb['top_bot_networks'] ) ) ? $bb['top_bot_networks'] : array();
	if ( ! empty( $nets ) ) {
		echo '<h4 class="sn-an-subh">Top bot networks</h4><table class="sn-an-table wp-list-table widefat striped"><thead><tr><th scope="col">Network</th><th scope="col" class="num">Views</th></tr></thead><tbody>';
		foreach ( $nets as $n ) {
			echo '<tr><td>' . esc_html( (string) ( $n['value'] ?? '' ) ) . '</td>'
				. '<td class="num">' . esc_html( number_format_i18n( (int) ( $n['views'] ?? 0 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div></div></div>';
}

/**
 * Top-pages panel (path + views + visits + scroll + time).
 *
 * @param array $paths [{path,views,visits,scroll_avg,time_avg}]
 */
function snt_analytics_render_paths_table( $paths ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Top pages', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	if ( empty( $paths ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No page views in this range.</p></div></div>';
		return;
	}
	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">Path</th>'
		. '<th scope="col" class="manage-column num">Views</th>'
		. '<th scope="col" class="manage-column num">Visits</th>'
		. '<th scope="col" class="manage-column num">Scroll</th>'
		. '<th scope="col" class="manage-column num">Time</th>'
		. '</tr></thead><tbody>';
	foreach ( $paths as $r ) {
		echo '<tr>'
			. '<td class="column-primary" data-colname="Path"><strong>' . esc_html( (string) $r['path'] ) . '</strong></td>'
			. '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num" data-colname="Visits">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td>'
			. '<td class="num" data-colname="Scroll">' . esc_html( (int) round( (float) $r['scroll_avg'] ) . '%' ) . '</td>'
			. '<td class="num" data-colname="Time">' . esc_html( snt_analytics_fmt_time( (float) $r['time_avg'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table></div></div>';
}

/**
 * A dimension breakdown panel (value + views + visits), with optional per-row
 * trend sparklines. Pass $series as a value→[{day,views}] map to activate them;
 * omit (or pass an empty array) for the original back-compatible layout.
 *
 * @param string $title
 * @param array  $rows   [{value,views,visits}]
 * @param string $empty  Empty-state copy.
 * @param array  $series Optional value-keyed series map for sparklines.
 */
function snt_analytics_render_dim_table( $title, $rows, $empty, $series = array(), $drill_dim = '' ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html( $empty ) . '</p></div></div>';
		return;
	}
	$has_spark = ! empty( $series );
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th scope="col" class="manage-column column-primary">' . esc_html( $title ) . '</th>';
	if ( $has_spark ) {
		echo '<th scope="col" class="manage-column">Trend</th>';
	}
	echo '<th scope="col" class="manage-column num">Views</th><th scope="col" class="manage-column num">Visits</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$v = (string) $r['value'];
		echo '<tr><td class="column-primary" data-colname="' . esc_attr( $title ) . '">';
		if ( '' !== $drill_dim ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'sn_drill' => $drill_dim . ':' . $v ) ) ) . '"><strong>' . esc_html( $v ) . '</strong></a>';
		} else {
			echo '<strong>' . esc_html( $v ) . '</strong>';
		}
		echo '</td>';
		if ( $has_spark ) {
			echo '<td>' . snt_analytics_sparkline( $series[ $v ] ?? array() ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped markup: an SVG with esc_attr'd path d + a per-call gradient id minted by the helper.
		}
		echo '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>';
		echo '<td class="num" data-colname="Visits">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div></div>';
}

/**
 * Inline micro-sparkline (returns a string so it can sit in a table cell). A tiny
 * smooth-line SVG mini-area mirroring the Overview chart's treatment via the shared
 * smooth-path helper. A static counter mints a unique gradient id per call so the
 * many sparklines on one page don't collide on a duplicate <linearGradient> id.
 *
 * @param array $series [{day:string, views:int}]
 * @return string HTML
 */
function snt_analytics_sparkline( $series ) {
	if ( empty( $series ) ) {
		return '<span class="sn-an-spark sn-an-spark--empty"></span>';
	}
	static $uid = 0;
	$gid  = 'sn-spark-fill-' . ( ++$uid );
	$n    = count( $series );
	$max  = 1;
	foreach ( $series as $row ) {
		$max = max( $max, (int) $row['views'] );
	}
	$w    = 72.0;
	$top  = 2.0;
	$base = 16.0;
	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px   = array();
	$py   = array();
	foreach ( array_values( $series ) as $i => $row ) {
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( (int) $row['views'] / $max ) * ( $base - $top ), 2 );
	}
	// A single data point smooths to a bare moveto (invisible). Pad it to a flat
	// full-width line so a one-bucket dimension still shows a mark — the old bar
	// sparkline drew a single full-height bar; don't regress to nothing.
	if ( count( $px ) < 2 ) {
		$px = array( 0.0, $w );
		$py = array( $py[0], $py[0] );
	}
	$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	$last_x = $px[ count( $px ) - 1 ];
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $last_x . ',' . $base . ' Z';

	$out  = '<span class="sn-an-spark">';
	$out .= '<svg viewBox="0 0 72 18" preserveAspectRatio="none" aria-hidden="true">';
	$out .= '<defs><linearGradient id="' . esc_attr( $gid ) . '" x1="0" y1="0" x2="0" y2="1">';
	$out .= '<stop offset="0%" stop-color="#2271b1" stop-opacity="0.18"/><stop offset="100%" stop-color="#2271b1" stop-opacity="0"/></linearGradient></defs>';
	$out .= '<path d="' . esc_attr( $area_d ) . '" fill="url(#' . esc_attr( $gid ) . ')" stroke="none"/>';
	$out .= '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#2271b1" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	$out .= '</svg></span>';
	return $out;
}

/**
 * Settings form + Cloudflare Worker setup console. Doubles as the unconfigured
 * empty-state and lives inside a <details> once data is flowing. Read creds are
 * option-backed with wp-config-constant precedence (a locked field when the
 * constant is set). The Worker deploy itself is a manual CF step — documented,
 * not automated.
 */
function snt_analytics_render_settings() {
	$token_locked = defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN;
	$acct_locked  = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID;
	$acct_opt     = (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' );
	$token_opt    = (string) get_option( SN_CF_ANALYTICS_TOKEN_OPT, '' );
	$configured   = (bool) ( function_exists( 'sn_analytics_config' ) && sn_analytics_config() );

	echo '<form method="post" class="sn-an-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">Credentials</h3>';
	echo '<p class="sn-an-settings-help">Read-only Cloudflare credentials the dashboard uses to query Analytics Engine. A wp-config constant (<code>SN_CF_ANALYTICS_TOKEN</code> / <code>SN_CF_ACCOUNT_ID</code>) overrides these and locks the field.</p>';

	// Account ID.
	echo '<p><label for="sn_cf_account_id"><strong>Account ID</strong></label><br>';
	if ( $acct_locked ) {
		echo '<input type="text" id="sn_cf_account_id" value="(set in wp-config)" disabled class="regular-text">';
		echo '<br><span class="sn-an-empty">Locked by the <code>SN_CF_ACCOUNT_ID</code> constant.</span>';
	} else {
		echo '<input type="text" id="sn_cf_account_id" name="sn_cf_account_id" value="' . esc_attr( $acct_opt ) . '" class="regular-text" placeholder="32-char Cloudflare account ID">';
	}
	echo '</p>';

	// Read token (masked).
	echo '<p><label for="sn_cf_analytics_token"><strong>Account Analytics Read token</strong></label><br>';
	if ( $token_locked ) {
		echo '<input type="text" id="sn_cf_analytics_token" value="••••" disabled class="regular-text">';
		echo '<br><span class="sn-an-empty">Locked by the <code>SN_CF_ANALYTICS_TOKEN</code> constant.</span>';
	} else {
		echo '<input type="text" id="sn_cf_analytics_token" name="sn_cf_analytics_token" value="' . esc_attr( sn_mask_secret( $token_opt ) ) . '" class="regular-text" placeholder="Paste a fresh token; type \'clear\' to remove">';
	}
	echo '</p>';

	if ( ! ( $token_locked && $acct_locked ) ) {
		echo '<p><button type="submit" name="sn_action" value="analytics_save" class="button button-primary">Save</button> ';
		echo '<button type="submit" name="sn_action" value="analytics_test" class="button"' . ( $configured ? '' : ' disabled' ) . '>Test connection</button></p>';
	}
	echo '</form>';

	// The deployed edge-Worker version, read live from /_sn/version (guarded +
	// SWR-cached). Sits above the manual setup steps — "what's live" before
	// "how to deploy it".
	if ( function_exists( 'sn_worker_version_render_card' ) ) {
		sn_worker_version_render_card();
	}

	snt_analytics_render_worker_setup();
}

/**
 * Read-only Cloudflare Worker setup reference. The plugin can't run wrangler;
 * this shows the exact steps so the Cloudflare side is copy-paste, not guesswork.
 */
function snt_analytics_render_worker_setup() {
	echo '<details class="sn-an-worker"><summary>Cloudflare Worker setup (manual, one-time)</summary>';
	echo '<ol class="sn-an-steps">';
	echo '<li><strong>Read token</strong> (for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with <code>Account · Analytics · Read</code>. The Account ID is in the dashboard URL: <code>dash.cloudflare.com/&lt;account_id&gt;</code>.</li>';
	echo '<li><strong>Deploy the edge Worker + its secrets</strong> (from the analytics-worker repo — this can\'t be done from WordPress):<pre class="sn-an-pre">wrangler secret put SN_PX_TOKEN' . "\n" . 'wrangler secret put SN_PX_SALT_SEED' . "\n" . 'wrangler deploy</pre></li>';
	echo '<li><strong>Theme beacon</strong>: set <code>SN_BEACON_TOKEN</code> in <code>wp-config.php</code> to the SAME value as the Worker\'s <code>SN_PX_TOKEN</code> so the front-end beacon is accepted.</li>';
	echo '<li>Hit <strong>Test connection</strong> above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.</li>';
	echo '</ol></details>';
}

/**
 * "Pages losing readers" panel: pages with meaningful traffic but weak
 * engagement (low scroll AND low dwell). Data from sn_analytics_low_engagement_paths().
 *
 * @param array $rows [{path,views,scroll_avg,time_avg}]
 */
function snt_analytics_render_lowengage( $rows ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Pages losing readers', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No low-engagement pages in this range — readers are sticking around.</p></div></div>';
		return;
	}
	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">Page</th>'
		. '<th scope="col" class="manage-column num">Views</th>'
		. '<th scope="col" class="manage-column num">Scroll</th>'
		. '<th scope="col" class="manage-column num">Time</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>'
			. '<td class="column-primary" data-colname="Page"><strong>' . esc_html( (string) $r['path'] ) . '</strong></td>'
			. '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num" data-colname="Scroll">' . esc_html( (int) round( (float) $r['scroll_avg'] ) . '%' ) . '</td>'
			. '<td class="num" data-colname="Time">' . esc_html( snt_analytics_fmt_time( (float) $r['time_avg'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table></div></div>';
}

/**
 * Bot-share trend panel: a smooth SVG line + gradient area of per-bucket bot% over
 * the window, scaled to the peak with the absolute peak labelled. Red accent to match
 * the bot segment of the Quality-tab stacked bar. Data from sn_analytics_class_series()
 * (durable — no AE). Mirrors snt_analytics_render_trend()'s SVG treatment via the
 * shared smooth-path helper; only one renders per page so a fixed gradient id is safe.
 *
 * @param array $rows [{day:string, bot_pct:int, total:int, bot:int}]
 */
function snt_analytics_render_bot_trend( $rows ) {
	if ( empty( $rows ) ) {
		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Bot share over time', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside inside-flush"><p class="sn-an-empty sn-an-empty--panel">No traffic recorded in this range yet.</p></div></div>';
		return;
	}
	$n    = count( $rows );
	$peak = 0;
	foreach ( $rows as $r ) {
		$peak = max( $peak, (int) ( $r['bot_pct'] ?? 0 ) );
	}
	$scale = max( 1, $peak ); // scale-to-peak so a typically-low rate is readable; absolute peak is labelled.
	$w     = 600.0;
	$top   = 8.0;
	$base  = 78.0;
	$step  = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px    = array();
	$py    = array();
	foreach ( array_values( $rows ) as $i => $r ) {
		$pct  = max( 0, min( 100, (int) ( $r['bot_pct'] ?? 0 ) ) );
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( $pct / $scale ) * ( $base - $top ), 2 );
	}
	// A single day smooths to a bare moveto (invisible); pad to a flat full-width line.
	if ( count( $px ) < 2 ) {
		$px = array( 0.0, $w );
		$py = array( $py[0], $py[0] );
	}
	$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $px[ count( $px ) - 1 ] . ',' . $base . ' Z';
	$first  = (string) ( $rows[0]['day'] ?? '' );
	$last   = (string) ( end( $rows )['day'] ?? '' );
	$meta   = sprintf( /* translators: %s peak bot percentage */ __( 'peak %s%% bot', 'signal-and-noise-tools' ), number_format_i18n( (int) $peak ) );

	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Bot share over time', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside inside-flush">';
	echo '<div class="sn-an-bot-trend">';
	echo '<div class="sn-trend-head"><span class="sn-trend-title">' . esc_html__( 'Bot share', 'signal-and-noise-tools' ) . '</span><span class="sn-trend-meta">' . esc_html( $meta ) . '</span></div>';
	echo '<div class="sn-spark-wrap">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric coords esc_attr'd, static SVG chrome.
	echo '<svg class="sn-an-bot-spark" viewBox="0 0 600 84" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Bot share trend', 'signal-and-noise-tools' ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG defs, no dynamic values.
	echo '<defs><linearGradient id="snBotTrendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#d63638" stop-opacity="0.16"/><stop offset="55%" stop-color="#d63638" stop-opacity="0.04"/><stop offset="100%" stop-color="#d63638" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#snBotTrendFill)" stroke="none"/>';
	echo '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#d63638" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	echo '</svg></div>';
	echo '<div class="sn-spark-axis"><span>' . esc_html( $first ) . '</span><span>' . esc_html( $last ) . '</span></div>';
	echo '</div></div></div>';
}

/**
 * One-time Plausible-history import panel (v6.0.0). A multipart form with one
 * optional file input per supported CSV export, plus a one-shot summary of the
 * last import (read from a short transient). Posts sn_action=analytics_import on
 * the page=sn-theme-options route, so the shared admin-post handler accepts it.
 */
function snt_analytics_render_import() {
	if ( ! function_exists( 'sn_analytics_import_types' ) ) {
		return;
	}

	echo '<details class="sn-an-worker"><summary>Import history from Plausible (one-time CSV)</summary>';
	echo '<p class="sn-an-settings-help">Retiring Plausible? In Plausible, export each report to CSV, then upload them here to back-fill the first-party dashboard. Import <strong>history from before the edge worker went live</strong> — days the worker already tracks are overwritten by the next data refresh, so avoid importing dates that overlap live data. Re-importing is safe (idempotent). Pages, sources, locations, devices, browsers, and operating systems map across; the hour heatmap, scroll/time distributions, and network/edge/protocol/TLS dimensions start fresh from the worker.</p>';

	// One-shot summary of the last import.
	$report = function_exists( 'get_transient' ) ? get_transient( 'sn_analytics_import_report' ) : false;
	if ( is_array( $report ) ) {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'sn_analytics_import_report' );
		}
		echo '<div class="notice notice-success notice-alt inline"><p><strong>Imported:</strong> '
			. esc_html( number_format_i18n( (int) ( $report['daily'] ?? 0 ) ) ) . ' page-day rows';
		if ( ! empty( $report['dims'] ) && is_array( $report['dims'] ) ) {
			$bits = array();
			foreach ( $report['dims'] as $dim => $n ) {
				$bits[] = (string) $dim . ': ' . number_format_i18n( (int) $n );
			}
			echo ' · ' . esc_html( implode( ' · ', $bits ) );
		}
		if ( ! empty( $report['events'] ) ) {
			echo ' · custom events: ' . esc_html( number_format_i18n( (int) $report['events'] ) );
		}
		if ( ! empty( $report['event_props'] ) ) {
			echo ' · custom props: ' . esc_html( number_format_i18n( (int) $report['event_props'] ) );
		}
		echo '.</p></div>';
	}

	echo '<form method="post" enctype="multipart/form-data" class="sn-an-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<table class="form-table" role="presentation"><tbody>';
	foreach ( sn_analytics_import_types() as $type => $label ) {
		$id = 'sn_import_' . $type;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" accept=".csv,text/csv"></td></tr>';
	}
	echo '</tbody></table>';
	echo '<p><button type="submit" name="sn_action" value="analytics_import" class="button button-primary">Import CSV history</button> ';
	echo '<span class="sn-an-empty">All fields optional — upload whichever reports you have.</span></p>';
	echo '</form></details>';
}

/**
 * Custom-events leaderboard panel (event name → events / visitors). Durable read
 * (wp_sn_analytics_events, shipped v6.2.0). Custom events carry NO traffic-class
 * dimension, so the global Human/Suspect/Bot control does not apply here — the
 * Events view renders an explicit note to that effect (see snt_analytics_render_dashboard).
 *
 * @param array $rows [{name,events,visitors}]
 */
function snt_analytics_render_events_table( $rows ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Custom events', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No custom events in this range yet.</p></div></div>';
		return;
	}
	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">Event</th>'
		. '<th scope="col" class="manage-column num">Events</th>'
		. '<th scope="col" class="manage-column num">Visitors</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>'
			. '<td class="column-primary" data-colname="Event"><strong>' . esc_html( (string) $r['name'] ) . '</strong></td>'
			. '<td class="num" data-colname="Events">' . esc_html( number_format_i18n( (int) $r['events'] ) ) . '</td>'
			. '<td class="num" data-colname="Visitors">' . esc_html( number_format_i18n( (int) $r['visitors'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table></div></div>';
}

/**
 * Entry/exit pages panel (path · views · visits). $role drives the title and
 * captions: 'entry' = landing pages (arrivals from search/links/direct, merged
 * live + historical); 'exit' = last-page-of-visit (historical only — true live
 * exit awaits the deferred session model). Human-only: no traffic-class control
 * applies here, consistent with the human-only Plausible history.
 *
 * Clones snt_analytics_render_paths_table()'s WP-native markup (.postbox +
 * .inside.sn-an-table-inside + .wp-list-table.widefat.striped). Reuses existing
 * CSS — no new stylesheet rule needed.
 *
 * @param array  $rows [{path,views,visits}]
 * @param string $role 'entry' | 'exit'.
 */
function snt_analytics_render_pageroles_table( $rows, $role ) {
	$is_exit = ( 'exit' === $role );
	$title   = $is_exit ? __( 'Exit pages', 'signal-and-noise-tools' ) : __( 'Entry pages', 'signal-and-noise-tools' );
	$caption = $is_exit
		? __( 'Where visits ended (historical) — live exit pages await the session model.', 'signal-and-noise-tools' )
		: __( 'Where visits began — arrivals from search, links, or direct.', 'signal-and-noise-tools' );
	$empty   = $is_exit
		? __( 'No exit pages in this range yet.', 'signal-and-noise-tools' )
		: __( 'No entry pages in this range yet.', 'signal-and-noise-tools' );

	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	echo '<p class="sn-an-settings-help" style="padding:0 12px">' . esc_html( $caption ) . '</p>';
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html( $empty ) . '</p></div></div>';
		return;
	}
	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">Path</th>'
		. '<th scope="col" class="manage-column num">Views</th>'
		. '<th scope="col" class="manage-column num">Visits</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>'
			. '<td class="column-primary" data-colname="Path"><strong>' . esc_html( (string) $r['path'] ) . '</strong></td>'
			. '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num" data-colname="Visits">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table></div></div>';
}

/**
 * Custom-event property breakdown (property · value → events / visitors) with a
 * Lane-A drill-down: each property is a link to ?sn_event_prop=<name> (a server
 * reload that filters this panel to one property). Durable read
 * (wp_sn_analytics_event_props) — property+value are co-present in one table, so
 * this filter is a genuine durable query (unlike cross-tab dimension drill-down,
 * which needs the AE source). When filtered, the Property column collapses to a
 * heading + Clear link.
 *
 * @param array  $rows        [{property,value,events,visitors}]
 * @param string $active_prop The ?sn_event_prop filter, or '' for all properties.
 */
function snt_analytics_render_event_props_table( $rows, $active_prop = '' ) {
	$filtered = ( '' !== (string) $active_prop );
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Event properties', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	if ( $filtered ) {
		$clear = remove_query_arg( 'sn_event_prop', add_query_arg( array() ) );
		echo '<p class="sn-an-subh sn-an-subh--panel">Property: <strong>' . esc_html( (string) $active_prop ) . '</strong> · '
			. '<a href="' . esc_url( $clear ) . '">Clear</a></p>';
	}
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No event properties in this range yet.</p></div></div>';
		return;
	}
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	if ( ! $filtered ) {
		echo '<th scope="col" class="manage-column">Property</th>';
	}
	echo '<th scope="col" class="manage-column column-primary">Value</th>'
		. '<th scope="col" class="manage-column num">Events</th>'
		. '<th scope="col" class="manage-column num">Visitors</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>';
		if ( ! $filtered ) {
			$prop = (string) $r['property'];
			$url  = add_query_arg( array( 'sn_event_prop' => $prop ) );
			echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( $prop ) . '</a></td>';
		}
		echo '<td class="column-primary" data-colname="Value">' . esc_html( (string) $r['value'] ) . '</td>'
			. '<td class="num" data-colname="Events">' . esc_html( number_format_i18n( (int) $r['events'] ) ) . '</td>'
			. '<td class="num" data-colname="Visitors">' . esc_html( number_format_i18n( (int) $r['visitors'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table></div></div>';
}

/**
 * Recolor a vendored world-map SVG: rewrite each country <path>'s inline fill to a
 * quantile-tier WP-blue alpha (neutral for zero/absent) and inject a per-country
 * <title>. Pure string transform — no IO. Country paths are keyed id="XX" (uppercase
 * ISO-3166-1 alpha-2); structural ids (svg2/robinson/defs4) are left untouched.
 *
 * @param string $svg   Raw SVG markup (211 self-closing <path id="XX" … fill:#hex />).
 * @param array  $views ISO alpha-2 => view count (case-insensitive; <=0 ignored).
 * @param array  $names ISO alpha-2 => display name (uppercase keys); falls back to the code.
 * @param int    $tiers Number of shading tiers (default 5).
 * @return string Recolored SVG with <title>s; returns '' if $svg is empty.
 */
function snt_analytics_recolor_world_svg( $svg, $views, $names = array(), $tiers = 5 ) {
	$svg = (string) $svg;
	if ( '' === $svg ) {
		return '';
	}
	$tiers = max( 1, (int) $tiers );

	// Uppercase-normalize + drop non-positive.
	$norm = array();
	foreach ( (array) $views as $iso => $v ) {
		$v = (int) $v;
		if ( $v > 0 ) {
			$norm[ strtoupper( (string) $iso ) ] = $v;
		}
	}
	$sorted = array_values( $norm );
	sort( $sorted );
	$count = count( $sorted );

	$upper_names = array();
	foreach ( (array) $names as $iso => $name ) {
		$upper_names[ strtoupper( (string) $iso ) ] = (string) $name;
	}

	return (string) preg_replace_callback( '/<path\b[^>]*?\/>/', static function ( $m ) use ( $norm, $upper_names, $sorted, $count, $tiers ) {
		$path = $m[0];
		if ( ! preg_match( '/\bid="([A-Z]{2})"/', $path, $idm ) ) {
			return $path; // structural / non-country path — leave as-is.
		}
		$iso = $idm[1];
		$v   = isset( $norm[ $iso ] ) ? $norm[ $iso ] : 0;

		if ( $v > 0 && $count > 0 ) {
			$le = 0;
			foreach ( $sorted as $s ) {
				if ( $s <= $v ) {
					++$le;
				}
			}
			$tier  = max( 1, min( $tiers, (int) ceil( ( $le / $count ) * $tiers ) ) );
			$alpha = round( 0.15 + ( $tier - 1 ) / max( 1, $tiers - 1 ) * 0.75, 2 );
			$fill  = 'rgba(34,113,177,' . $alpha . ')';
		} else {
			$fill = '#f0f0f1';
		}

		// Rewrite the first inline fill:#hex (default #f2f2f2) to the computed fill.
		$path = preg_replace( '/fill:\s*#[0-9a-fA-F]{3,6}/', 'fill:' . $fill, $path, 1 );

		// Inject a <title> (esc'd) and convert the self-closing /> to <path>…</path>.
		// Label precedence: caller-supplied name → the SVG path's own data-name
		// (SimpleMaps ships data-name="United States" etc.) → the bare ISO code.
		$label = isset( $upper_names[ $iso ] ) ? $upper_names[ $iso ] : $iso;
		if ( ! isset( $upper_names[ $iso ] ) && preg_match( '/\bdata-name="([^"]*)"/', $path, $nm ) && '' !== $nm[1] ) {
			$label = $nm[1];
		}
		$title = $v > 0 ? ( $label . ' — ' . number_format_i18n( $v ) . ' views' ) : $label;
		$path  = preg_replace( '/\s*\/>$/', '><title>' . esc_html( $title ) . '</title></path>', $path );

		return $path;
	}, $svg );
}

/**
 * Country choropleth panel: shades the world map by view intensity from the durable
 * `country` dimension rows. Empty-state when no country has views; otherwise loads the
 * vendored SVG (static-cached) and echoes the recolored, titled map in an accessible
 * panel. Mirrors snt_analytics_render_heatmap()'s panel + a11y shape. No JS, no AE.
 *
 * @param string $title Panel heading.
 * @param array  $rows  [{value: ISO-2, views, visits}] from sn_analytics_top_dimension('country', …).
 * @param string $empty Empty-state copy.
 */
function snt_analytics_render_choropleth( $title, $rows, $empty ) {
	$views = array();
	$names = array();
	foreach ( (array) $rows as $r ) {
		$iso = strtoupper( (string) ( $r['value'] ?? '' ) );
		if ( '' === $iso ) {
			continue;
		}
		$views[ $iso ] = (int) ( $r['views'] ?? 0 );
	}

	$has_data = false;
	foreach ( $views as $v ) {
		if ( $v > 0 ) {
			$has_data = true;
			break;
		}
	}

	echo '<div class="sn-an-choropleth postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div>';
	echo '<div class="inside inside-flush sn-map-inside">';

	if ( ! $has_data ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html( $empty ) . '</p></div></div>';
		return;
	}

	$svg = snt_analytics_choropleth_svg();
	if ( '' === $svg ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html( $empty ) . '</p></div></div>';
		return;
	}

	echo '<figure class="sn-map-figure">';
	echo '<div role="img" aria-label="' . esc_attr( __( 'World map shaded by views per country', 'signal-and-noise-tools' ) ) . '">';
	echo snt_analytics_recolor_world_svg( $svg, $views, $names ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped markup: vendored static SVG + numeric fills + esc_html'd <title>s.
	echo '</div></figure>';
	echo '<div class="sn-map-legend" aria-hidden="true">';
	echo '<span class="sn-legend-item"><span class="sn-legend-swatch sn-legend-swatch--low"></span> ' . esc_html__( 'Low', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-legend-item"><span class="sn-legend-swatch sn-legend-swatch--medium"></span> ' . esc_html__( 'Medium', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-legend-item"><span class="sn-legend-swatch sn-legend-swatch--high"></span> ' . esc_html__( 'High', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-legend-item sn-legend-item--meta">' . esc_html__( 'Views by country', 'signal-and-noise-tools' ) . '</span>';
	echo '</div>';
	echo '</div></div>';
}

/**
 * Load + statically cache the vendored world-map SVG. Returns '' if the asset is
 * missing (the choropleth then degrades to its empty-state).
 *
 * @return string
 */
function snt_analytics_choropleth_svg() {
	static $svg = null;
	if ( null === $svg ) {
		$path = dirname( __DIR__ ) . '/assets/analytics/world-map.svg';
		$svg  = is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}
	return $svg;
}

/**
 * Render a 3-stat percentile panel (p50/p75/p90) for one metric. Native wp-admin
 * .postbox shell mirroring snt_analytics_render_distribution. $rows is the
 * [{label,value}] list from sn_analytics_percentiles() — null/empty shows the
 * empty-state (the on-demand AE query failed or AE is unconfigured), never fatal.
 *
 * @param string                                          $title     Panel title.
 * @param array<int,array{label:string,value:float}>|null $rows      Percentile rows.
 * @param string                                          $format    'pct' (integer %) | 'time' (ms → fmt_time).
 * @param string                                          $empty_msg Optional empty-state copy.
 * @param string                                          $note      Optional footnote (e.g. retention caveat).
 * @return void
 */
function snt_analytics_render_percentiles( $title, $rows, $format = 'pct', $empty_msg = '', $note = '' ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div><div class="inside inside-flush"><div class="sn-an-panel sn-an-pctl">';
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		$msg = ( '' !== $empty_msg ) ? $empty_msg : 'No ' . strtolower( $title ) . ' data in this range yet.';
		echo '<p class="sn-an-empty">' . esc_html( $msg ) . '</p></div></div></div>';
		return;
	}
	echo '<div class="sn-an-pctl-row">';
	foreach ( $rows as $r ) {
		$label = strtoupper( (string) ( $r['label'] ?? '' ) );
		$value = (float) ( $r['value'] ?? 0 );
		$disp  = ( 'time' === $format ) ? snt_analytics_fmt_time( $value ) : ( (int) round( $value ) . '%' );
		echo '<div class="sn-an-pctl-chip">';
		echo '<span class="sn-an-pctl-k">' . esc_html( $label ) . '</span>';
		echo '<span class="sn-an-pctl-v num">' . esc_html( $disp ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
	if ( '' !== $note ) {
		echo '<p class="sn-an-foot">' . esc_html( $note ) . '</p>';
	}
	echo '</div></div></div>';
}

/**
 * Render the cross-tab drill-down panel: "Top pages · <DimLabel> = <value>" with
 * a Clear link, populated by sn_analytics_drilldown(). $rows null/empty → empty
 * state (rejected value / no data / unconfigured AE). Native wp-admin, no brutalist.
 *
 * @param string                                                    $dim   A SN_ANALYTICS_DIM_COLUMNS key (for the label).
 * @param string                                                    $value The drilled value.
 * @param array<int,array{path:string,views:int,visits:int}>|null   $rows  Top pages, or null.
 * @param string                                                    $note  Optional footnote (e.g. retention caveat).
 * @return void
 */
function snt_analytics_render_drilldown_panel( $dim, $value, $rows, $note = '' ) {
	$labels = array(
		'referrer' => 'Referrer', 'country' => 'Country', 'device' => 'Device', 'browser' => 'Browser',
		'os' => 'OS', 'region' => 'Region', 'city' => 'City', 'network' => 'Network',
		'colo' => 'Edge location', 'protocol' => 'Protocol', 'tls' => 'TLS',
	);
	$label = isset( $labels[ $dim ] ) ? $labels[ $dim ] : ucfirst( (string) $dim );
	$clear = remove_query_arg( 'sn_drill', add_query_arg( array() ) );

	echo '<div class="postbox sn-an-drill"><div class="postbox-header"><h2 class="hndle"><span>'
		. esc_html( 'Top pages · ' . $label . ' = ' . (string) $value ) . '</span></h2></div>'
		. '<div class="inside sn-an-table-inside">';
	echo '<p class="sn-an-subh sn-an-subh--panel"><a href="' . esc_url( $clear ) . '">&larr; Clear drill-down</a>'
		. ( '' !== $note ? ' · <span class="sn-an-foot">' . esc_html( $note ) . '</span>' : '' ) . '</p>';

	if ( ! is_array( $rows ) || empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No pages for this segment in this range (or it needs live Analytics Engine data).</p></div></div>';
		return;
	}

	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">Page</th>'
		. '<th scope="col" class="manage-column num">Views</th>'
		. '<th scope="col" class="manage-column num">Visits</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td class="column-primary" data-colname="Page">' . esc_html( (string) ( $r['path'] ?? '' ) ) . '</td>'
			. '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) ( $r['views'] ?? 0 ) ) ) . '</td>'
			. '<td class="num" data-colname="Visits">' . esc_html( number_format_i18n( (int) ( $r['visits'] ?? 0 ) ) ) . '</td></tr>';
	}
	echo '</tbody></table></div></div>';
}

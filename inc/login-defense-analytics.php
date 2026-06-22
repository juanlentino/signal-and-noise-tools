<?php
/**
 * Login defense analytics: the Analytics-dashboard Login defense view (registered
 * in SN_ANALYTICS_VIEWS, dispatched by snt_analytics_render_dashboard) + its
 * login-specific renderers. Reads the sn_login_guard AE dataset via the query
 * builders in inc/login-defense.php. Reuses the existing .sn-kpi / .sn-spark CSS
 * vocabulary but with login-appropriate labels (the shared analytics renderers
 * hardcode pageview semantics). Read-only; no enforcement.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KPI cards: Checked / Blocked / Block rate / Networks. Mirrors the .sn-kpi-row
 * markup of snt_analytics_render_cards() with login labels.
 */
function sn_login_defense_render_kpi_cards( $k ) {
	$cards = array(
		array( 'l' => __( 'Checked (7d)', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['checked'] ?? 0 ) ), 'promoted' => true, 'sub' => __( 'seen', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Blocked', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['blocked'] ?? 0 ) ), 'promoted' => true, 'sub' => __( 'denied', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Block rate', 'signal-and-noise-tools' ), 'n' => (int) ( $k['block_rate'] ?? 0 ) . '%', 'sub' => __( 'of checks', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Networks', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['networks'] ?? 0 ) ), 'sub' => __( 'distinct', 'signal-and-noise-tools' ) ),
	);
	echo '<div class="sn-kpi-row">';
	foreach ( $cards as $c ) {
		echo '<div class="sn-kpi' . ( ! empty( $c['promoted'] ) ? ' sn-kpi-promoted' : '' ) . '">';
		echo '<p class="sn-kpi-label">' . esc_html( $c['l'] ) . '</p>';
		echo '<p class="sn-kpi-value">' . esc_html( $c['n'] ) . '</p>';
		// Flat delta slot — login KPIs have no period-over-period delta; the slot keeps
		// the card structure identical to snt_analytics_render_cards() (a labelled sub-line).
		echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html( $c['sub'] ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
}

/**
 * Daily blocked-trend sparkline. Mirrors snt_analytics_render_trend()'s SVG band
 * (reusing snt_analytics_smooth_path when available, else a straight polyline)
 * with login labels. $series = [{day,views}] ascending; views = blocked count.
 */
function sn_login_defense_render_trend_chart( $series ) {
	if ( ! is_array( $series ) || count( $series ) < 2 ) {
		return;
	}
	$n    = count( $series );
	$max  = 1;
	$peak = 0;
	foreach ( $series as $r ) {
		$v    = (int) ( $r['views'] ?? 0 );
		$max  = max( $max, $v );
		$peak = max( $peak, $v );
	}
	$w    = 600.0;
	$top  = 8.0;
	$base = 78.0;
	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px   = array();
	$py   = array();
	foreach ( array_values( $series ) as $i => $r ) {
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( (int) ( $r['views'] ?? 0 ) / $max ) * ( $base - $top ), 2 );
	}
	if ( function_exists( 'snt_analytics_smooth_path' ) ) {
		$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	} else {
		$pts    = array();
		foreach ( $px as $i => $x ) { $pts[] = $x . ',' . $py[ $i ]; }
		$line_d = 'M ' . implode( ' L ', $pts );
	}
	// Area = the line dropped to the baseline and closed (parity with snt_analytics_render_trend).
	$last_x = $px[ $n - 1 ];
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $last_x . ',' . $base . ' Z';

	echo '<div class="sn-overview-trend">';
	echo '<div class="sn-trend-head"><span class="sn-trend-title">' . esc_html__( 'Blocked per day', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-trend-meta">' . esc_html( sprintf( /* translators: %s peak blocked count */ __( 'peak %s', 'signal-and-noise-tools' ), number_format_i18n( $peak ) ) ) . '</span></div>';
	echo '<div class="sn-spark-wrap">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- coords esc_attr'd, static SVG chrome.
	echo '<svg class="sn-spark" viewBox="0 0 600 84" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( __( 'Daily blocked trend', 'signal-and-noise-tools' ) ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG gradient def, no dynamic values.
	echo '<defs><linearGradient id="snSparkFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2271b1" stop-opacity="0.16"/><stop offset="55%" stop-color="#2271b1" stop-opacity="0.04"/><stop offset="100%" stop-color="#2271b1" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#snSparkFill)" stroke="none"/>';
	echo '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	echo '</svg></div>';
	echo '<div class="sn-spark-axis"><span>' . esc_html( (string) $series[0]['day'] ) . '</span><span>' . esc_html( (string) end( $series )['day'] ) . '</span></div>';
	echo '</div>';
}

/**
 * Ranked top-N table (attacker networks / countries). $rows = [{k,v}].
 */
function sn_login_defense_render_top_table( $title, $col, $rows ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	if ( ! $rows ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html__( 'No blocks recorded yet.', 'signal-and-noise-tools' ) . '</p></div></div>';
		return;
	}
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th scope="col" class="manage-column column-primary">' . esc_html( $col ) . '</th>';
	echo '<th scope="col" class="manage-column num">' . esc_html__( 'Blocked', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td class="column-primary"><strong>' . esc_html( (string) ( $r['k'] ?? '' ) ) . '</strong></td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) ( $r['v'] ?? 0 ) ) ) . '</td></tr>';
	}
	echo '</tbody></table></div></div>';
}

/**
 * The Analytics-dashboard Login defense view. Dormant-gated (mirrors
 * snt_edge_render_view); own inline range control (own URL param, generic date
 * math); assembles KPIs + trend + decision breakdown + threat tables.
 * Registered in SN_ANALYTICS_VIEWS and dispatched by snt_analytics_render_dashboard().
 */
function sn_login_defense_view_render() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		echo '<div class="postbox"><div class="inside"><p class="sn-an-empty">'
			. esc_html__( 'Connect Cloudflare Analytics (Account ID + token) in the Analytics tab to see login-defense analytics.', 'signal-and-noise-tools' )
			. '</p></div></div>';
		return;
	}

	$allowed = array( 7, 30, 90 );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display range, no state change.
	$days = isset( $_GET['sn_lg_range'] ) ? (int) $_GET['sn_lg_range'] : 7;
	if ( ! in_array( $days, $allowed, true ) ) {
		$days = 7;
	}

	$base = remove_query_arg( array( 'sn_lg_range' ) );
	echo '<div class="sn-toolbar">';
	foreach ( $allowed as $r ) {
		$cls = ( $r === $days ) ? 'button button-primary' : 'button';
		echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( add_query_arg( array( 'sn_lg_range' => $r ), $base ) ) . '">' . (int) $r . 'd</a> ';
	}
	echo '</div>';

	$dec              = sn_analytics_query( sn_login_defense_decisions_sql( $days ) ) ?: array();
	$kpis             = sn_login_defense_kpis_from_rows( $dec );
	$net              = sn_analytics_query( sn_login_defense_networks_sql( $days ) ) ?: array();
	$kpis['networks'] = (int) ( $net[0]['networks'] ?? 0 );

	// Fuse the KPI strip + blocked-trend into ONE "Overview" postbox, identical to the
	// shared dashboard chrome (render_dashboard wraps the other views' cards+trend this way).
	echo '<div class="postbox sn-overview"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Overview', 'signal-and-noise-tools' ) . '</span></h2></div>';
	echo '<div class="inside inside-flush sn-overview-inside">';
	sn_login_defense_render_kpi_cards( $kpis );
	sn_login_defense_render_trend_chart( sn_login_defense_trend_series( sn_analytics_query( sn_login_defense_trend_sql( $days ) ) ?: array() ) );
	echo '</div></div>';

	echo '<p class="sn-an-breakdown">';
	foreach ( array( 'block', 'pass', 'bypass', 'killswitch' ) as $d ) {
		echo '<span class="sn-pill">' . esc_html( $d ) . ' '
			. esc_html( number_format_i18n( (int) ( $kpis['breakdown'][ $d ] ?? 0 ) ) ) . '</span> ';
	}
	echo '</p>';

	$asn  = sn_analytics_query( sn_login_defense_top_asn_sql( $days, 10 ) ) ?: array();
	$ctry = sn_analytics_query( sn_login_defense_top_country_sql( $days, 10 ) ) ?: array();
	sn_login_defense_render_top_table(
		__( 'Top attacker networks', 'signal-and-noise-tools' ),
		__( 'Network (ASN)', 'signal-and-noise-tools' ),
		array_map(
			function ( $r ) { return array( 'k' => $r['asorg'] ?? '', 'v' => $r['hits'] ?? 0 ); },
			$asn
		)
	);
	sn_login_defense_render_top_table(
		__( 'Top attacker countries', 'signal-and-noise-tools' ),
		__( 'Country', 'signal-and-noise-tools' ),
		array_map(
			function ( $r ) { return array( 'k' => $r['country'] ?? '', 'v' => $r['hits'] ?? 0 ); },
			$ctry
		)
	);
}

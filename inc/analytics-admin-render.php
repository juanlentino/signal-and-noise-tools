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
 * Range picker + class segmented control (GET links preserving the route).
 *
 * @param int    $range Active window.
 * @param string $class Active class.
 */
function snt_analytics_render_controls( $range, $class ) {
	$base = admin_url( 'admin.php?page=sn-monitoring&tab=monitoring&sub=analytics' );
	echo '<div class="sn-an-controls">';

	echo '<span class="sn-an-seg">';
	foreach ( SN_ANALYTICS_RANGES as $r ) {
		$url    = add_query_arg( array( 'sn_range' => $r, 'sn_class' => $class ), $base );
		$active = ( (int) $r === (int) $range ) ? 'is-active' : '';
		echo '<a class="' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $r . 'd' ) . '</a>';
	}
	echo '</span>';

	$labels = array( 'human' => 'Human', 'suspect' => 'Suspect', 'bot' => 'Bot' );
	echo '<span class="sn-an-seg">';
	foreach ( $labels as $key => $label ) {
		$url    = add_query_arg( array( 'sn_range' => $range, 'sn_class' => $key ), $base );
		$active = ( $key === $class ) ? 'is-active' : '';
		echo '<a class="' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</span>';

	echo '</div>';
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
	echo '<p class="sn-an-sep">Showing <strong>' . esc_html( $class ) . '</strong> traffic';
	if ( $auto > 0 ) {
		echo ' · ' . esc_html( number_format_i18n( $auto ) ) . ' automated filtered ('
			. esc_html( number_format_i18n( $bot ) ) . ' bot · '
			. esc_html( number_format_i18n( $suspect ) ) . ' suspect)';
	}
	echo '</p>';
}

/**
 * Bar strip of per-day views (heights relative to the series max).
 *
 * @param array $series [{day,views,visits}] ascending.
 */
function snt_analytics_render_trend( $series ) {
	if ( empty( $series ) ) {
		return;
	}
	$max = 1;
	foreach ( $series as $row ) {
		$max = max( $max, (int) $row['views'] );
	}
	echo '<div class="sn-an-trend" role="img" aria-label="' . esc_attr__( 'Daily views trend', 'signal-and-noise-tools' ) . '">';
	foreach ( $series as $row ) {
		$pct = (int) round( ( (int) $row['views'] / $max ) * 100 );
		echo '<span class="bar" style="height:' . esc_attr( max( 2, $pct ) ) . '%" title="'
			. esc_attr( $row['day'] . ': ' . number_format_i18n( (int) $row['views'] ) . ' views' ) . '"></span>';
	}
	echo '</div>';
}

/**
 * The 5 stat cards: Now, Views, Visits, Avg scroll, Avg time.
 *
 * @param int|null $now    Realtime visitor count.
 * @param array    $totals {views,visits,scroll_avg,time_avg}
 */
function snt_analytics_render_cards( $now, $totals ) {
	$cards = array(
		array( 'l' => 'Now',        'n' => ( null === $now ? '—' : number_format_i18n( (int) $now ) ) ),
		array( 'l' => 'Views',      'n' => number_format_i18n( (int) ( $totals['views'] ?? 0 ) ) ),
		array( 'l' => 'Visits',     'n' => number_format_i18n( (int) ( $totals['visits'] ?? 0 ) ) ),
		array( 'l' => 'Avg scroll', 'n' => (int) round( (float) ( $totals['scroll_avg'] ?? 0 ) ) . '%' ),
		array( 'l' => 'Avg time',   'n' => snt_analytics_fmt_time( (float) ( $totals['time_avg'] ?? 0 ) ) ),
	);
	echo '<div class="sn-an-cards">';
	foreach ( $cards as $c ) {
		echo '<div class="sn-an-card"><div class="n">' . esc_html( $c['n'] ) . '</div><div class="l">' . esc_html( $c['l'] ) . '</div></div>';
	}
	echo '</div>';
}

/**
 * Top-pages panel (path + views + visits + scroll + time).
 *
 * @param array $paths [{path,views,visits,scroll_avg,time_avg}]
 */
function snt_analytics_render_paths_table( $paths ) {
	echo '<div class="sn-an-panel"><h3>Top pages</h3>';
	if ( empty( $paths ) ) {
		echo '<p class="sn-an-empty">No page views in this range.</p></div>';
		return;
	}
	echo '<table class="sn-an-table"><thead><tr><th>Path</th><th class="num">Views</th><th class="num">Visits</th><th class="num">Scroll</th><th class="num">Time</th></tr></thead><tbody>';
	foreach ( $paths as $r ) {
		echo '<tr><td>' . esc_html( (string) $r['path'] ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td>'
			. '<td class="num">' . esc_html( (int) round( (float) $r['scroll_avg'] ) . '%' ) . '</td>'
			. '<td class="num">' . esc_html( snt_analytics_fmt_time( (float) $r['time_avg'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * A dimension breakdown panel (value + views + visits).
 *
 * @param string $title
 * @param array  $rows  [{value,views,visits}]
 * @param string $empty Empty-state copy.
 */
function snt_analytics_render_dim_table( $title, $rows, $empty ) {
	echo '<div class="sn-an-panel"><h3>' . esc_html( $title ) . '</h3>';
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty">' . esc_html( $empty ) . '</p></div>';
		return;
	}
	echo '<table class="sn-an-table"><thead><tr><th>' . esc_html( $title ) . '</th><th class="num">Views</th><th class="num">Visits</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td>' . esc_html( (string) $r['value'] ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

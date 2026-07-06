<?php
/**
 * Signal & Noise — Analytics distribution/spread panels: referrer-category bars,
 * scroll/time distribution bands, the hour×day activity heatmap (with its
 * accessible companion table), and the p50/p75/p90 percentile chips. Native
 * wp-admin markup via the panel primitive. Extracted from
 * analytics-admin-render.php (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';        // panel chrome + empty-fold collector
require_once __DIR__ . '/analytics-render-helpers.php'; // snt_analytics_fmt_time (percentiles 'time' format)

/**
 * Referrer-source category panel: Search / Social / Direct / Other as labelled
 * percentage bars (folded from the referrer dimension in inc/analytics-derived.php).
 *
 * @param array $cats [{category,label,views,visits}]
 */
function snt_analytics_render_referrer_categories( $cats ) {
	// v8.5.0: retitled from 'Traffic sources' — it sat directly under the
	// 'Top sources' table in the new side column and the near-duplicate
	// titles read as the same panel twice (approved mockup: 'Referrer
	// categories'). Chips compaction is CSS-only.
	snt_an_panel_open( __( 'Referrer categories', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside inside-flush' ) );
	echo '<div class="sn-an-panel sn-an-refcats sn-an-refcats--chips">';
	$total = 0;
	foreach ( (array) $cats as $c ) {
		$total += (int) ( $c['views'] ?? 0 );
	}
	if ( $total <= 0 ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No referrer data in this range yet.</p></div>';
		snt_an_panel_close();
		return;
	}
	echo '<div class="sn-an-refcats-bars">';
	foreach ( (array) $cats as $c ) {
		$v   = (int) ( $c['views'] ?? 0 );
		$pct = (int) round( $v / $total * 100 );
		echo '<div class="sn-an-refcat" title="' . esc_attr( (string) ( $c['label'] ?? '' ) . ': ' . number_format_i18n( $v ) . ' views · ' . number_format_i18n( (int) ( $c['visits'] ?? 0 ) ) . ' visits' ) . '">';
		echo '<div class="sn-an-refcat-h"><span>' . esc_html( (string) ( $c['label'] ?? '' ) ) . '</span>'
			. '<span class="num">' . esc_html( number_format_i18n( $v ) . ' · ' . $pct . '%' ) . '</span></div>';
		echo '<div class="sn-an-refcat-bar"><span style="width:' . esc_attr( max( 1, $pct ) ) . '%"></span></div>';
		echo '</div>';
	}
	echo '</div></div>';
	snt_an_panel_close();
}

/**
 * Distribution panel (scroll-depth or time-on-page bands) as horizontal bars
 * scaled to the peak band. Bands come pre-ordered + zero-filled from
 * sn_analytics_distribution().
 *
 * @param string $title
 * @param array  $rows  [{label,views}]
 */
function snt_analytics_render_distribution( $title, $rows, $empty_msg = '', $wide_labels = false ) {
	$max = 0;
	foreach ( (array) $rows as $r ) {
		$max = max( $max, (int) ( $r['views'] ?? 0 ) );
	}
	if ( $max <= 0 ) {
		snt_an_note_empty( $title );
		return;
	}
	snt_an_panel_open( $title, array( 'inside_class' => 'inside inside-flush' ) );
	echo '<div class="sn-an-panel sn-an-dist' . ( $wide_labels ? ' sn-an-dist--wide' : '' ) . '">';
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
	echo '</div></div>';
	snt_an_panel_close();
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
	snt_an_panel_open( __( 'Activity by hour (UTC)', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside inside-flush' ) );
	echo '<div class="sn-an-panel sn-an-heatmap-panel">';
	if ( $max <= 0 || empty( $grid ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No hourly data in this range yet.</p></div>';
		snt_an_panel_close();
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

	echo '</div>'; // close .sn-an-panel
	snt_an_panel_close();
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
	snt_an_panel_open( $title, array( 'inside_class' => 'inside inside-flush' ) );
	echo '<div class="sn-an-panel sn-an-pctl">';
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		$msg = ( '' !== $empty_msg ) ? $empty_msg : 'No ' . strtolower( $title ) . ' data in this range yet.';
		echo '<p class="sn-an-empty">' . esc_html( $msg ) . '</p></div>';
		snt_an_panel_close();
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
	echo '</div>';
	snt_an_panel_close();
}

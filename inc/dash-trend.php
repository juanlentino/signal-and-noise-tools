<?php
/**
 * Signal & Noise — the 30-day trend chart.
 *
 * Split out of inc/dash-console.php in v11.30.0: that file had grown past the
 * ~150-line ceiling this project holds itself to, and these renderers have no
 * dependency on the screen's composition.
 *
 * @package SignalNoiseTools
 * @since 11.29.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The 30-day trend as a real chart.
 *
 * NOT snt_analytics_sparkline() stretched. That helper is a 72x18 inline mark
 * for a table cell; blown up to full width it is a bare line with no baseline
 * to read against. The stage chart the mockup approved has two grid lines, an
 * area fill and an emphasised endpoint — the same treatment as the Analytics
 * Overview chart, at the size the stage gives it.
 *
 * @since 11.29.1
 * @param array<int,array<string,mixed>> $series [{day,views}]
 * @return void
 */
function sn_dash_render_trend( array $series ) {
	$series = array_values( $series );
	$n      = count( $series );
	if ( $n < 2 ) {
		// One point is not a trend. Rendering a flat line would assert a shape
		// the data cannot support.
		return;
	}

	$max = 1;
	foreach ( $series as $row ) {
		$max = max( $max, (int) ( $row['views'] ?? 0 ) );
	}

	$w    = 600.0;
	$top  = 8.0;
	$base = 88.0;
	$step = $w / ( $n - 1 );

	$pts = array();
	foreach ( $series as $i => $row ) {
		$x     = round( $i * $step, 2 );
		$y     = round( $base - ( (int) ( $row['views'] ?? 0 ) / $max ) * ( $base - $top ), 2 );
		$pts[] = $x . ',' . $y;
	}
	$line = 'M ' . implode( ' L ', $pts );
	$area = $line . ' L ' . round( ( $n - 1 ) * $step, 2 ) . ',' . $base . ' L 0,' . $base . ' Z';
	$last = explode( ',', $pts[ $n - 1 ] );

	echo '<section class="sn-stage__trend">';
	echo '<h2 class="sn-stage__head">' . esc_html__( 'Views · 30 days', 'signal-and-noise-tools' ) . '</h2>';
	echo '<svg class="sn-trend" viewBox="0 0 600 96" preserveAspectRatio="none" role="img" aria-label="'
		. esc_attr__( 'Views over the last 30 days', 'signal-and-noise-tools' ) . '">';
	echo '<line x1="0" y1="28" x2="600" y2="28" class="sn-trend__grid" />';
	echo '<line x1="0" y1="58" x2="600" y2="58" class="sn-trend__grid" />';
	echo '<path d="' . esc_attr( $area ) . '" class="sn-trend__area" />';
	echo '<path d="' . esc_attr( $line ) . '" class="sn-trend__line" />';
	echo '<circle cx="' . esc_attr( $last[0] ) . '" cy="' . esc_attr( $last[1] ) . '" r="3.5" class="sn-trend__end" />';
	echo '</svg>';
	echo '</section>';
}

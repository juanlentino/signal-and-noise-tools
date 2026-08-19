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

	// Peak and latest are rendered as HTML beside the plot, never as SVG <text>:
	// the chart stretches with preserveAspectRatio="none", which would distort
	// every glyph inside it. A 30-day plot whose maximum you cannot read off is
	// a decoration of a number, not a reading of one.
	$latest = (int) ( $series[ $n - 1 ]['views'] ?? 0 );
	$first  = (string) ( $series[0]['day'] ?? '' );
	$lastd  = (string) ( $series[ $n - 1 ]['day'] ?? '' );

	echo '<section class="sn-stage__trend">';

	echo '<header class="sn-card__head">';
	echo '<span class="sn-card__eyebrow">' . esc_html__( 'Views · 30 days', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-card__meta">';
	/* translators: %s the most recent day's views */
	echo '<b>' . esc_html( number_format_i18n( $latest ) ) . '</b> ' . esc_html__( 'latest', 'signal-and-noise-tools' );
	echo '<i class="sn-card__sep" aria-hidden="true"></i>';
	/* translators: %s the highest daily views in the window */
	echo esc_html__( 'peak', 'signal-and-noise-tools' ) . ' <b>' . esc_html( number_format_i18n( $max ) ) . '</b>';
	echo '</span>';
	echo '</header>';

	echo '<div class="sn-trend-plot">';
	echo '<svg class="sn-trend" viewBox="0 0 600 96" preserveAspectRatio="none" role="img" aria-label="'
		. esc_attr(
			sprintf(
				/* translators: 1: latest views, 2: peak views */
				__( 'Views over the last 30 days. Latest %1$s, peak %2$s.', 'signal-and-noise-tools' ),
				number_format_i18n( $latest ),
				number_format_i18n( $max )
			)
		) . '">';
	echo '<defs><linearGradient id="sn-trend-fill" x1="0" y1="0" x2="0" y2="1">';
	echo '<stop offset="0%" class="sn-trend__stop-a" /><stop offset="100%" class="sn-trend__stop-b" />';
	echo '</linearGradient></defs>';
	echo '<line x1="0" y1="28" x2="600" y2="28" class="sn-trend__grid" />';
	echo '<line x1="0" y1="58" x2="600" y2="58" class="sn-trend__grid" />';
	echo '<path d="' . esc_attr( $area ) . '" class="sn-trend__area" />';
	echo '<path d="' . esc_attr( $line ) . '" class="sn-trend__line" />';
	echo '<circle cx="' . esc_attr( $last[0] ) . '" cy="' . esc_attr( $last[1] ) . '" r="3.5" class="sn-trend__end" />';
	echo '</svg>';
	echo '</div>';

	echo '<footer class="sn-trend__axis">';
	echo '<span>' . esc_html( '' !== $first ? $first : __( '30 days ago', 'signal-and-noise-tools' ) ) . '</span>';
	echo '<span>' . esc_html( '' !== $lastd ? $lastd : __( 'today', 'signal-and-noise-tools' ) ) . '</span>';
	echo '</footer>';

	echo '</section>';
}

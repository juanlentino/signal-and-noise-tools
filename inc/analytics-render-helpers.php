<?php
/**
 * Signal & Noise — Analytics render primitives shared across the panel partials.
 * Two pure, IO-free helpers the domain renderers lean on: a duration formatter
 * and the Catmull-Rom → bézier smoother behind every trend chart. Extracted from
 * the former analytics-admin-render.php monolith (v8.9.x) so each domain file can
 * require just this instead of the whole render surface.
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

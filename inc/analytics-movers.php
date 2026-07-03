<?php
/**
 * Signal & Noise Tools — Analytics "Movers" (v8.5.0).
 *
 * Top posts by views delta, current window vs the prior window of equal
 * length — the owner's daily "which posts moved" question, answered on the
 * landing so it stops costing a tab switch. Zero new storage: two
 * sn_analytics_top_paths() reads diffed in memory, 15-min transient.
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uncached movers computation. Paths present in either window count; a path
 * that dropped out entirely is a negative mover (its loss is the story).
 * Zero-delta paths are noise, not movers.
 *
 * @param string $from  Window start (Y-m-d).
 * @param string $to    Window end (Y-m-d).
 * @param string $class Traffic class (follows the page filter).
 * @param int    $limit Rows returned.
 * @return array[] Rows: { path, views, delta }, ranked by |delta| desc.
 */
function sn_analytics_movers_uncached( $from, $to, $class = 'human', $limit = 3 ) {
	list( $pfrom, $pto ) = sn_analytics_prior_window( $from, $to );
	$cur = sn_analytics_top_paths( $from, $to, $class, 50 );
	$pri = sn_analytics_top_paths( $pfrom, $pto, $class, 50 );

	$prior_views = array();
	foreach ( (array) $pri as $row ) {
		$prior_views[ (string) $row['path'] ] = (int) $row['views'];
	}

	$out = array();
	foreach ( (array) $cur as $row ) {
		$path  = (string) $row['path'];
		$delta = (int) $row['views'] - ( $prior_views[ $path ] ?? 0 );
		unset( $prior_views[ $path ] );
		if ( 0 !== $delta ) {
			$out[] = array( 'path' => $path, 'views' => (int) $row['views'], 'delta' => $delta );
		}
	}
	foreach ( $prior_views as $path => $views ) {
		if ( $views > 0 ) {
			$out[] = array( 'path' => (string) $path, 'views' => 0, 'delta' => -$views );
		}
	}

	usort( $out, function ( $a, $b ) {
		return abs( $b['delta'] ) <=> abs( $a['delta'] );
	} );

	return array_slice( $out, 0, max( 1, (int) $limit ) );
}

/**
 * Cached movers (15 min — realtime enough for a glance tile, cheap on the
 * rollup table; the transient is cache data, exactly what transients are for
 * per docs/WORDPRESS-REFERENCE.md §3 — flush-volatility is fine here).
 *
 * @param string $from  Window start (Y-m-d).
 * @param string $to    Window end (Y-m-d).
 * @param string $class Traffic class.
 * @param int    $limit Rows returned.
 * @return array[] See sn_analytics_movers_uncached().
 */
function sn_analytics_movers( $from, $to, $class = 'human', $limit = 3 ) {
	$key    = 'sn_an_movers_' . md5( $from . '|' . $to . '|' . $class . '|' . (int) $limit );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$out = sn_analytics_movers_uncached( $from, $to, $class, $limit );
	set_transient( $key, $out, 15 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * The rail "Movers" tile (v8.5.0 landing). Up to five rows: path, current
 * views (muted), signed delta — the rail stretches to the Overview's height,
 * so the tile fills what used to be blank (owner: "no blank spaces").
 * Links to the Posts tab for the deep dive (trajectory / catalog / velocity).
 *
 * @param string $from  Window start (Y-m-d).
 * @param string $to    Window end (Y-m-d).
 * @param string $class Traffic class (follows the page filter).
 */
function snt_analytics_render_movers_tile( $from, $to, $class ) {
	$movers = sn_analytics_movers( $from, $to, $class, 5 );

	snt_an_panel_open( __( 'Movers', 'signal-and-noise-tools' ), array(
		'panel_class' => 'sn-an-rail-tile sn-an-movers',
		'header_meta' => esc_html__( 'vs prior period', 'signal-and-noise-tools' ),
	) );
	if ( empty( $movers ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html__( 'No movement in this range yet.', 'signal-and-noise-tools' ) . '</p>';
	} else {
		echo '<ul class="sn-an-movers-list">';
		foreach ( $movers as $m ) {
			$delta = (int) $m['delta'];
			$sign  = $delta > 0 ? '+' : '';
			$cls   = $delta > 0 ? 'sn-an-delta-up' : 'sn-an-delta-down';
			echo '<li><span class="sn-an-mover-path">' . esc_html( (string) $m['path'] ) . '</span>'
				. '<span class="sn-an-mover-nums"><span class="sn-an-mover-views">' . esc_html( number_format_i18n( (int) $m['views'] ) ) . '</span>'
				. '<span class="' . esc_attr( $cls ) . '">' . esc_html( $sign . $delta ) . '</span></span></li>';
		}
		echo '</ul>';
		echo '<a class="sn-an-mover-more" href="' . esc_url( admin_url( 'index.php?page=sn-analytics&sn_view=posts' ) ) . '">'
			. esc_html__( 'Posts view', 'signal-and-noise-tools' ) . ' &rarr;</a>';
	}
	snt_an_panel_close();
}

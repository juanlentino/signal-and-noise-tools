<?php
/**
 * Field Core Web Vitals, the live half: the cached read, the ability behind
 * sn-metrics' analytics_vitals section, and the per-device rows the
 * Engagement view paints on both twins (classic and S&N Analytics).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One dimension's rows over the window, both generations stitched. Null when
 * a read failed or a half reached the row cap (a partial distribution).
 *
 * @param string   $dim   'device' | 'path'.
 * @param string   $from  Y-m-d.
 * @param string   $to    Y-m-d.
 * @param string   $class Traffic class.
 * @param string[] $paths Canonical paths.
 * @return array|null
 */
function sn_analytics_vitals_rows( $dim, $from, $to, $class, array $paths = array() ) {
	if ( 'path' === $dim && array() === $paths ) {
		return array();
	}
	list( $lo, $hi ) = sn_analytics_local_day_bounds_utc( $from, $to );
	$sets            = sn_analytics_stitched_rows(
		static fn( $source, $range ) => sn_analytics_vitals_sql( $dim, $from, $to, $class, $paths, $source, $range ),
		substr( $lo, 0, 10 ),
		$hi
	);
	$cap = defined( 'SN_ANALYTICS_AE_ROW_CAP' ) ? SN_ANALYTICS_AE_ROW_CAP : 10000;
	if ( null === $sets || array() !== array_filter( $sets, static fn( $s ) => count( $s ) >= $cap ) ) {
		return null;
	}
	return array_merge( ...$sets );
}

/**
 * The vitals payload for a window: thresholds, site-wide, per device, and per
 * top path. Cached 15 minutes (a failure 5). Null when a read failed.
 * $limit 0 is the devices-only read the Engagement twins use: no top-paths
 * query and no path query, so a path read cannot blank the device rows.
 *
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @param string $class Traffic class.
 * @param int    $limit Top paths (by views in the window); 0 for none.
 * @return array|null
 */
function sn_analytics_vitals( $from, $to, $class = 'human', $limit = 10 ) {
	$limit = max( 0, min( 50, (int) $limit ) );
	list( $lo, $hi ) = sn_analytics_local_day_bounds_utc( $from, $to );
	$key    = 'sn_vitals_' . md5( $from . '|' . $to . '|' . $class . '|' . $limit . sn_analytics_read_key( substr( $lo, 0, 10 ), $hi ) );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : null;
	}
	$paths   = $limit > 0 ? sn_analytics_vitals_top_paths( $from, $to, $class, $limit ) : array();
	$devices = null === $paths ? null : sn_analytics_vitals_rows( 'device', $from, $to, $class );
	$by_path = null === $devices ? null : sn_analytics_vitals_rows( 'path', $from, $to, $class, $paths );
	if ( null === $by_path ) {
		set_transient( $key, '', 5 * MINUTE_IN_SECONDS );
		return null;
	}
	$thresholds = array();
	foreach ( sn_analytics_vitals_metrics() as $name => $m ) {
		$thresholds[ $name ] = array( 'good_max' => $m['good'] / $m['div'], 'poor_over' => $m['poor'] / $m['div'], 'unit' => $m['unit'] );
	}
	$out = array(
		'thresholds'  => $thresholds,
		'min_samples' => SNT_VITALS_MIN_SAMPLES,
		'basis'       => 'field measurements from the theme beacon, sample-weighted; p75 is the figure Google assesses a page on',
		'site'        => sn_analytics_vitals_fold( $devices, array( 'all' ), static fn( $k ) => 'all' )['all'],
		'devices'     => sn_analytics_vitals_fold( $devices, array( 'mobile', 'desktop' ), static fn( $k ) => in_array( $k, array( 'mobile', 'desktop' ), true ) ? $k : 'unknown' ),
		'paths'       => array(),
		'paths_from'  => 'the top paths by views in the window',
	);
	foreach ( sn_analytics_vitals_fold( $by_path, $paths, 'sn_analytics_canonical_path' ) as $path => $cells ) {
		$out['paths'][] = array( 'path' => (string) $path ) + $cells;
	}
	set_transient( $key, $out, 15 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * The top paths the per-path cells cover, canonical and allowlisted (a path
 * the SQL would refuse is not listed as "not measured"). Null when the
 * top-paths read failed: an empty list after a database error is not "no
 * pages".
 *
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @param string $class Traffic class.
 * @param int    $limit Top paths.
 * @return string[]|null
 */
function sn_analytics_vitals_top_paths( $from, $to, $class, $limit ) {
	$top = sn_analytics_top_paths( $from, $to, $class, $limit );
	if ( isset( $GLOBALS['wpdb'] ) && '' !== (string) ( $GLOBALS['wpdb']->last_error ?? '' ) ) {
		return null;
	}
	$paths = array_map( static fn( $r ) => sn_analytics_canonical_path( (string) $r['path'] ), (array) $top );
	return array_values( array_unique( array_filter( $paths, 'sn_analytics_vitals_path_ok' ) ) );
}

/** Ability execute: signal-noise/get-analytics-vitals. */
function snt_ability_get_analytics_vitals( $input ) {
	$a = snt_metrics_args( $input );
	if ( is_wp_error( $a ) ) {
		return $a;
	}
	list( $win, $class, $limit ) = $a;
	$v = sn_analytics_vitals( $win['from'], $win['to'], $class, min( 50, $limit ) );
	if ( null === $v ) {
		return new WP_Error( 'snt_metrics_read', 'The Core Web Vitals read failed.' );
	}
	return $win + array( 'class' => $class ) + $v;
}

/**
 * One vital as display text: "2.1 s" or "0.08", plus the small-sample label,
 * or "not measured". PURE.
 *
 * @param array  $cell   sn_analytics_vitals_cell().
 * @param string $metric lcp | inp | cls.
 * @return string
 */
function sn_analytics_vitals_text( array $cell, $metric ) {
	if ( null === $cell['p75'] ) {
		return __( 'not measured', 'signal-and-noise-tools' );
	}
	$x   = (float) $cell['p75'];
	$val = 'cls' === $metric ? number_format_i18n( $x, 2 ) : ( 'lcp' === $metric ? number_format_i18n( $x / 1000, 1 ) . ' s' : number_format_i18n( $x ) . ' ms' );
	$val .= ', ' . sprintf( /* translators: %s: share of good measurements. */ __( '%s%% good', 'signal-and-noise-tools' ), (string) $cell['good'] );
	/* translators: %d: measurements behind the figure. */
	return $cell['small_sample'] ? $val . ' ' . sprintf( __( '(small sample: %d)', 'signal-and-noise-tools' ), (int) $cell['samples'] ) : $val;
}

/**
 * The per-device rows both Engagement twins paint: device, then LCP, INP and
 * CLS at p75. Null when the read failed. PURE given the payload.
 *
 * @param array|null $v sn_analytics_vitals().
 * @return array<int,array{device:string,lcp:string,inp:string,cls:string}>|null
 */
function sn_analytics_vitals_device_rows( $v ) {
	if ( ! is_array( $v ) ) {
		return null;
	}
	$labels = array( 'mobile' => __( 'Mobile', 'signal-and-noise-tools' ), 'desktop' => __( 'Desktop', 'signal-and-noise-tools' ), 'unknown' => __( 'Unknown device', 'signal-and-noise-tools' ) );
	$rows   = array();
	foreach ( (array) $v['devices'] as $d => $cells ) {
		if ( 'unknown' === $d && 0 === array_sum( array_column( $cells, 'samples' ) ) ) {
			continue;
		}
		$row = array( 'device' => $labels[ $d ] ?? (string) $d );
		foreach ( array( 'lcp', 'inp', 'cls' ) as $m ) {
			$row[ $m ] = sn_analytics_vitals_text( $cells[ $m ], $m );
		}
		$rows[] = $row;
	}
	return $rows;
}

/**
 * The sentences both Engagement twins print around the per-device rows, so
 * the classic page and S&N Analytics say the same thing.
 *
 * @return array{title:string,failed:string,note:string}
 */
function sn_analytics_vitals_copy() {
	return array(
		'title'  => __( 'Core Web Vitals at p75, by device', 'signal-and-noise-tools' ),
		'failed' => __( 'The field measurements by device could not be read.', 'signal-and-noise-tools' ),
		/* translators: %d: the small-sample floor. */
		'note'   => sprintf( __( 'p75 is the figure Google assesses a page on; the share is the measurements in the good band. A device with fewer than %d measurements is labeled a small sample.', 'signal-and-noise-tools' ), SNT_VITALS_MIN_SAMPLES ),
	);
}

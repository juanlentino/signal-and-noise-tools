<?php
/**
 * Signal & Noise — derived analytics rollup: time-of-day heatmap + scroll/time
 * distributions (v5.4.0).
 *
 * A companion to inc/analytics-dims.php. Where the dims table carries top-N
 * value breakdowns, this table carries the two *derived* views the dashboard
 * needs that the day-keyed path rollup throws away: hour-of-day (the daily
 * rollup collapses to whole days) and the scroll/time *distribution* (the path
 * rollup keeps only averages). One table, keyed (day, metric, bucket, class):
 *
 *   metric='hour'    bucket='00'..'23'        — pv counts per hour-of-day
 *   metric='scroll'  bucket='b0'..'b3'        — pageviews per scroll-depth band
 *   metric='time'    bucket='b0'..'b4'        — pageviews per time-on-page band
 *   metric='botscore' bucket='b0'..'b2'       — pageviews per CF bot-score band (1–99)
 *
 * Fed by the SAME rollup cron as dims (one extra AE query per metric — no new
 * hook). Every builder uses ONLY the AE primitives v5.3.0 already proves work:
 * formatDateTime() (the dims rollup uses it for the day bucket) for the hour,
 * and sum(if(...)) (the sumIf lineage) for the distribution bands — NOT the
 * unvalidated toHour()/toDayOfWeek()/quantile*() the design first sketched. A
 * failed AE query returns null and the metric is simply skipped, so an
 * unsupported function degrades to an empty panel, never a fatal.
 *
 * Day-of-week is derived at READ time from each row's UTC `day`, so the table
 * stores only (day, hour) and the 7×24 heatmap grid is assembled in PHP.
 *
 * Dormant until AE creds are configured (sn_analytics_query() → null), exactly
 * like the path + dims rollups: the empty table is created but never written.
 *
 * @package SignalNoiseTools
 * @since 5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_BUCKETS_TABLE          = 'sn_analytics_buckets';
const SN_ANALYTICS_BUCKETS_DB_VERSION     = '1';
const SN_ANALYTICS_BUCKETS_DB_VERSION_OPT = 'sn_analytics_buckets_db_version';

/**
 * The distribution metrics: their source event + double column + display-labelled
 * bands. Single source of truth shared by the SQL builder (band edges), the
 * run_rollup melt (band count), the read accessor (key → label), and the
 * renderer. A band with `hi => null` is open-ended (>= lo, no upper bound).
 *
 * @return array<string, array{event:string, col:string, label:string, buckets:array<int,array{label:string, lo:int, hi:?int}>}>
 */
function sn_analytics_buckets_metrics() {
	return array(
		'scroll' => array(
			'event'   => 'sc',
			'col'     => 'double1',
			'label'   => 'Scroll depth',
			'buckets' => array(
				array( 'label' => '0–25%',   'lo' => 0,  'hi' => 25 ),
				array( 'label' => '25–50%',  'lo' => 25, 'hi' => 50 ),
				array( 'label' => '50–75%',  'lo' => 50, 'hi' => 75 ),
				array( 'label' => '75–100%', 'lo' => 75, 'hi' => null ),
			),
		),
		'time' => array(
			'event'   => 'tm',
			'col'     => 'double2',
			'label'   => 'Time on page',
			'buckets' => array(
				array( 'label' => '0–10s',  'lo' => 0,      'hi' => 10000 ),
				array( 'label' => '10–30s', 'lo' => 10000,  'hi' => 30000 ),
				array( 'label' => '30–60s', 'lo' => 30000,  'hi' => 60000 ),
				array( 'label' => '1–3m',   'lo' => 60000,  'hi' => 180000 ),
				array( 'label' => '3m+',    'lo' => 180000, 'hi' => null ),
			),
		),
		'botscore' => array(
			'event'   => 'pv',
			'col'     => 'double3',
			'label'   => 'Bot confidence',
			// double3 is Cloudflare's bot-management score (1–99), or -1 when Bot
			// Management is absent on the zone. lo=1 on the first band excludes the
			// -1/0 sentinels, so only real scores are bucketed. This is the FIRST
			// query of double3 + the first blob1='pv' distribution — a new AE query
			// (live-validation gated). It reuses dist_sql's proven sum(if()) form.
			'buckets' => array(
				array( 'label' => '1–30',  'lo' => 1,  'hi' => 31 ),
				array( 'label' => '31–60', 'lo' => 31, 'hi' => 61 ),
				array( 'label' => '61–99', 'lo' => 61, 'hi' => null ),
			),
		),
		'rtt' => array(
			'event'   => 'pv',
			'col'     => 'double4',
			'label'   => 'Connection RTT',
			// double4 = clientTcpRtt (ms), captured by worker v1.7.0. It is 0 when
			// absent — HTTP/3 / QUIC requests carry no TCP RTT — so lo=1 on the first
			// band excludes the 0-sentinel and only real TCP round-trips are bucketed
			// (the distribution is TCP-only by construction). Reuses the proven sum(if())
			// form; live-validation gated like botscore.
			'buckets' => array(
				array( 'label' => '1–50ms',    'lo' => 1,   'hi' => 50 ),
				array( 'label' => '50–100ms',  'lo' => 50,  'hi' => 100 ),
				array( 'label' => '100–200ms', 'lo' => 100, 'hi' => 200 ),
				array( 'label' => '200–500ms', 'lo' => 200, 'hi' => 500 ),
				array( 'label' => '500ms+',    'lo' => 500, 'hi' => null ),
			),
		),
		// Field Core Web Vitals (v6.28.0). Each metric is its OWN event (vl/vi/vc,
		// sent by the theme beacon v10.14.0 via web-vitals), all carrying the value in
		// double7 (worker v1.8.0). The event filter isolates each metric, so the bands
		// run from lo=0 with NO sentinel — CLS=0 (a perfect, zero-shift page) counts as
		// Good. Bands are Google's good / needs-improvement / poor thresholds, so the
		// distribution reads directly as "% of page-loads in each CWV band".
		'lcp' => array(
			'event'   => 'vl',
			'col'     => 'double7',
			'label'   => 'LCP (field)',
			'buckets' => array(
				array( 'label' => 'Good (<2.5s)',       'lo' => 0,    'hi' => 2500 ),
				array( 'label' => 'Needs work (2.5–4s)', 'lo' => 2500, 'hi' => 4000 ),
				array( 'label' => 'Poor (>4s)',          'lo' => 4000, 'hi' => null ),
			),
		),
		'inp' => array(
			'event'   => 'vi',
			'col'     => 'double7',
			'label'   => 'INP (field)',
			'buckets' => array(
				array( 'label' => 'Good (<200ms)',         'lo' => 0,   'hi' => 200 ),
				array( 'label' => 'Needs work (200–500ms)', 'lo' => 200, 'hi' => 500 ),
				array( 'label' => 'Poor (>500ms)',          'lo' => 500, 'hi' => null ),
			),
		),
		'cls' => array(
			'event'   => 'vc',
			'col'     => 'double7', // CLS ×1000 (0.10 → 100), so it stores as an integer
			'label'   => 'CLS (field)',
			'buckets' => array(
				array( 'label' => 'Good (<0.10)',        'lo' => 0,   'hi' => 100 ),
				array( 'label' => 'Needs work (0.10–0.25)', 'lo' => 100, 'hi' => 250 ),
				array( 'label' => 'Poor (>0.25)',         'lo' => 250, 'hi' => null ),
			),
		),
	);
}

/** A metric is storable if it's 'hour' or one of the configured distribution metrics. */
function sn_analytics_buckets_valid_metric( $metric ) {
	return 'hour' === $metric || isset( sn_analytics_buckets_metrics()[ $metric ] );
}

/**
 * dbDelta CREATE TABLE. `metric`/`bucket`/`class` are VARCHAR(10); with `day`
 * DATE (3B) the composite UNIQUE key is ~123 bytes — well inside InnoDB's
 * 767-byte prefix. Buckets are a small fixed vocabulary (24 hours + ≤5 bands
 * per metric × 3 classes × N days) so the table is slow-growing, no prune.
 *
 * @return string CREATE TABLE statement.
 */
function sn_analytics_buckets_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_BUCKETS_TABLE;
	$charset = $wpdb->get_charset_collate();

	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		metric VARCHAR(10) NOT NULL,
		bucket VARCHAR(10) NOT NULL,
		class VARCHAR(10) NOT NULL DEFAULT 'human',
		views INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_metric_bucket_class (day, metric, bucket, class)
	) {$charset};";
}

/** Create the table via dbDelta. Brand-new dormant table — no migration path. */
function sn_analytics_buckets_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_analytics_buckets_schema_sql() );
	update_option( SN_ANALYTICS_BUCKETS_DB_VERSION_OPT, SN_ANALYTICS_BUCKETS_DB_VERSION );
}

/** One autoloaded-option compare per request; install runs only on the delta. */
function sn_analytics_buckets_maybe_install() {
	if ( get_option( SN_ANALYTICS_BUCKETS_DB_VERSION_OPT ) !== SN_ANALYTICS_BUCKETS_DB_VERSION ) {
		sn_analytics_buckets_install();
	}
}
add_action( 'init', 'sn_analytics_buckets_maybe_install' );

/**
 * AE SQL: pageviews per (day, hour-of-day, class) over the trailing window.
 * Hour comes from formatDateTime(timestamp,'%H') — the same proven function the
 * dims/path rollups use for the day bucket — never the unvalidated toHour().
 * $days is integer-cast + floored (defence in depth; callers pass a constant).
 *
 * @param int $days Trailing window in days.
 * @return string AE SQL.
 */
function sn_analytics_buckets_hour_sql( $days ) {
	$days = max( 1, (int) $days );

	return implode( ' ', array(
		"SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day,",
		"formatDateTime(timestamp, '%H') AS bucket,",
		'blob7 AS class,',
		'sum(_sample_interval) AS views',
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE blob1 = 'pv' AND timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)",
		'GROUP BY day, bucket, class',
	) );
}

/**
 * AE SQL: a wide per-(day,class) row with one sum(if()) column per distribution
 * band. sum(if(col >= lo AND col < hi, _sample_interval, 0)) — the documented
 * sumIf lineage, NOT quantile*(). A band whose `hi` is null is open-ended
 * (>= lo only). $event/$col are sanitised defensively (callers pass constants
 * from sn_analytics_buckets_metrics()); $days is integer-cast.
 *
 * @param string $event   Event-type filter ('sc' | 'tm').
 * @param string $col     Double column to bucket ('double1' | 'double2').
 * @param array  $buckets Band defs [{lo,hi}] from sn_analytics_buckets_metrics().
 * @param int    $days    Trailing window in days.
 * @return string AE SQL.
 */
function sn_analytics_buckets_dist_sql( $event, $col, $buckets, $days ) {
	$days  = max( 1, (int) $days );
	$event = preg_replace( '/[^a-z]/', '', (string) $event );
	$col   = preg_replace( '/[^a-z0-9]/', '', (string) $col );

	$selects = array();
	foreach ( $buckets as $i => $b ) {
		$lo = (int) $b['lo'];
		if ( null === $b['hi'] ) {
			$selects[] = "sum(if({$col} >= {$lo}, _sample_interval, 0)) AS b{$i}";
		} else {
			$hi        = (int) $b['hi'];
			$selects[] = "sum(if({$col} >= {$lo} AND {$col} < {$hi}, _sample_interval, 0)) AS b{$i}";
		}
	}

	return implode( ' ', array(
		"SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day,",
		'blob7 AS class,',
		implode( ', ', $selects ),
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE blob1 = '{$event}' AND timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)",
		'GROUP BY day, class',
	) );
}

/**
 * UPSERT bucket rows (day/metric/bucket/class/views). Malformed rows are skipped:
 * a YYYY-MM-DD day, a known metric, a non-empty bucket, and a known class are
 * required. Batched per 100.
 *
 * @param array $rows
 * @return int Rows written.
 */
function sn_analytics_buckets_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}

	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day    = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$metric = isset( $r['metric'] ) ? (string) $r['metric'] : '';
		$bucket = isset( $r['bucket'] ) ? trim( (string) $r['bucket'] ) : '';
		$class  = isset( $r['class'] ) && '' !== (string) $r['class'] ? (string) $r['class'] : 'human';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || '' === $bucket ) {
			continue;
		}
		if ( ! sn_analytics_buckets_valid_metric( $metric ) || ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
			continue;
		}
		$clean[] = array(
			'day'    => $day,
			'metric' => $metric,
			'bucket' => substr( $bucket, 0, 10 ),
			'class'  => $class,
			'views'  => max( 0, (int) round( (float) ( $r['views'] ?? 0 ) ) ),
		);
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_BUCKETS_TABLE;
	$written = 0;

	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			$placeholders[] = '(%s, %s, %s, %s, %d)';
			array_push( $values, $c['day'], $c['metric'], $c['bucket'], $c['class'], $c['views'] );
		}
		$sql = "INSERT INTO {$table} (day, metric, bucket, class, views) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE views=VALUES(views)';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a static INSERT ... VALUES template with a generated %s/%d placeholder group per row; $table is $wpdb->prefix + a plugin constant and every value is bound via prepare().
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false !== $result ) {
			$written += count( $chunk );
		}
	}

	return $written;
}

/**
 * Roll the hour heatmap + the scroll/time distributions: one AE query for the
 * hour metric, one per distribution metric (each a wide row melted into one row
 * per band), all merged into a single UPSERT. Called from sn_analytics_run_rollup().
 * No-ops when AE isn't configured; a per-metric query failure (null) is skipped.
 */
function sn_analytics_buckets_run_rollup() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return;
	}
	if ( ! sn_analytics_config() ) {
		return;
	}

	$rows = array();

	// 1. Hour-of-day — already in (day, bucket, class, views) shape.
	$hour = sn_analytics_query( sn_analytics_buckets_hour_sql( SN_ANALYTICS_ROLLUP_WINDOW_DAYS ) );
	if ( is_array( $hour ) ) {
		foreach ( $hour as $hr ) {
			if ( ! is_array( $hr ) ) {
				continue;
			}
			$rows[] = array(
				'day'    => $hr['day'] ?? '',
				'metric' => 'hour',
				'bucket' => (string) ( $hr['bucket'] ?? '' ),
				'class'  => $hr['class'] ?? 'human',
				'views'  => $hr['views'] ?? 0,
			);
		}
	}

	// 2. Distributions — melt each wide (day,class,b0..bN) row into per-band rows.
	foreach ( sn_analytics_buckets_metrics() as $metric => $m ) {
		$wide = sn_analytics_query( sn_analytics_buckets_dist_sql( $m['event'], $m['col'], $m['buckets'], SN_ANALYTICS_ROLLUP_WINDOW_DAYS ) );
		if ( ! is_array( $wide ) ) {
			continue;
		}
		foreach ( $wide as $wr ) {
			if ( ! is_array( $wr ) ) {
				continue;
			}
			foreach ( $m['buckets'] as $i => $b ) {
				$rows[] = array(
					'day'    => $wr['day'] ?? '',
					'metric' => $metric,
					'bucket' => 'b' . $i,
					'class'  => $wr['class'] ?? 'human',
					'views'  => $wr[ 'b' . $i ] ?? 0,
				);
			}
		}
	}

	if ( ! empty( $rows ) ) {
		sn_analytics_buckets_upsert( $rows );
	}
}

/**
 * Read accessor: the 7×24 hour-of-day × day-of-week heatmap grid for a class.
 * Day-of-week (1=Mon..7=Sun, ISO) is derived from each row's UTC day so the
 * table needn't store it. Returns a zero-filled grid + the peak cell value
 * (the renderer scales intensity against it). Never makes a network call.
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class (default 'human').
 * @return array{grid:array<int,array<int,int>>, max:int}
 */
function sn_analytics_hour_dow_grid( $from, $to, $class = 'human' ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_BUCKETS_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, bucket, views
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND metric = 'hour' AND class = %s",
		(string) $from,
		(string) $to,
		$class
	), ARRAY_A );

	$grid = array();
	for ( $d = 1; $d <= 7; $d++ ) {
		$grid[ $d ] = array_fill( 0, 24, 0 );
	}
	$max = 0;

	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$day  = (string) ( $r['day'] ?? '' );
			$hour = (int) ( $r['bucket'] ?? -1 );
			if ( $hour < 0 || $hour > 23 ) {
				continue;
			}
			$ts = strtotime( $day . ' 00:00:00 UTC' );
			if ( ! $ts ) {
				continue;
			}
			$dow                  = (int) gmdate( 'N', $ts ); // 1=Mon..7=Sun
			$grid[ $dow ][ $hour ] += (int) ( $r['views'] ?? 0 );
			if ( $grid[ $dow ][ $hour ] > $max ) {
				$max = $grid[ $dow ][ $hour ];
			}
		}
	}

	return array( 'grid' => $grid, 'max' => $max );
}

/**
 * Read accessor: the distribution for one metric ('scroll' | 'time' | 'botscore') as an
 * ordered, zero-filled [{label, views}] list (bands the config defines but the
 * data lacks still appear, at 0). Maps the stored bX key → the config label.
 *
 * @param string $metric 'scroll' | 'time' | 'botscore'.
 * @param string $from   Inclusive start day, YYYY-MM-DD.
 * @param string $to     Inclusive end day, YYYY-MM-DD.
 * @param string $class  Traffic class (default 'human').
 * @return array<int, array{label:string, views:int}>
 */
function sn_analytics_distribution( $metric, $from, $to, $class = 'human' ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$metrics = sn_analytics_buckets_metrics();
	if ( ! isset( $metrics[ $metric ] ) ) {
		return array();
	}

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_BUCKETS_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT bucket, views
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND metric = %s AND class = %s",
		(string) $from,
		(string) $to,
		$metric,
		$class
	), ARRAY_A );

	$sums = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$k          = (string) ( $r['bucket'] ?? '' );
			$sums[ $k ] = ( $sums[ $k ] ?? 0 ) + (int) ( $r['views'] ?? 0 );
		}
	}

	$out = array();
	foreach ( $metrics[ $metric ]['buckets'] as $i => $b ) {
		$out[] = array(
			'label' => (string) $b['label'],
			'views' => (int) ( $sums[ 'b' . $i ] ?? 0 ),
		);
	}
	return $out;
}

<?php
/**
 * Signal & Noise — first-party analytics dimension breakdowns (P3).
 *
 * The path-keyed daily rollup (inc/analytics-rollup.php) carries per-page
 * engagement; this companion table carries the visit-attribute breakdowns the
 * dashboard needs — referrer host, country, device — keyed (day, dim, value,
 * class). Fed by the SAME rollup cron (one extra AE query per dim). No Worker
 * change: blob3/blob4/blob5 are already written by the edge worker.
 *
 * Dormant until AE creds are configured (sn_analytics_query() → null), exactly
 * like the path rollup: the empty table is created but never written.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_DIMS_TABLE          = 'sn_analytics_dims';
const SN_ANALYTICS_DIMS_DB_VERSION     = '1';
const SN_ANALYTICS_DIMS_DB_VERSION_OPT = 'sn_analytics_dims_db_version';

// The dimensions this table aggregates, mapped to their AE blob columns.
// blob3/4/5 ship since v5.0.1; blob8–15 are the edge dimensions added by the
// analytics worker v1.1.0 (browser/os via UA parse, the rest from request.cf).
// The map is the single wiring point: sn_analytics_dims_run_rollup() iterates
// every key (one AE query per dim) and sn_analytics_top_dimension() is
// dim-agnostic, so a new entry here lights up both the rollup and the read path.
// The dims table keys on (day, dim, value, class), so new dims add rows, not
// columns — no schema or DB-version change.
const SN_ANALYTICS_DIM_COLUMNS = array(
	'referrer' => 'blob3',
	'country'  => 'blob4',
	'device'   => 'blob5',
	'browser'  => 'blob8',
	'os'       => 'blob9',
	'region'   => 'blob10',
	'city'     => 'blob11',
	'network'  => 'blob12',
	'colo'     => 'blob13',
	'protocol' => 'blob14',
	'tls'      => 'blob15',
	'timezone' => 'blob19', // v6.27.0: visitor IANA timezone (worker v1.7.0)
);

/**
 * dbDelta CREATE TABLE for the dimension breakdowns.
 *
 * `value` is VARCHAR(160); with `day` DATE (3B), `dim` VARCHAR(10) (40B),
 * `value` (640B) and `class` VARCHAR(10) (40B) the composite UNIQUE key is 723
 * bytes — inside InnoDB's 767-byte prefix. Over-long values truncate at write.
 *
 * @return string CREATE TABLE statement.
 */
function sn_analytics_dims_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_DIMS_TABLE;
	$charset = $wpdb->get_charset_collate();

	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		dim VARCHAR(10) NOT NULL,
		value VARCHAR(160) NOT NULL,
		class VARCHAR(10) NOT NULL DEFAULT 'human',
		views INT UNSIGNED NOT NULL DEFAULT 0,
		visits INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_dim_value_class (day, dim, value, class)
	) {$charset};";
}

/**
 * Create the table via dbDelta. Brand-new dormant table — no migration path.
 */
function sn_analytics_dims_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_analytics_dims_schema_sql() );
	update_option( SN_ANALYTICS_DIMS_DB_VERSION_OPT, SN_ANALYTICS_DIMS_DB_VERSION );
}

/**
 * One autoloaded-option compare per request; install runs only on the delta.
 */
function sn_analytics_dims_maybe_install() {
	if ( get_option( SN_ANALYTICS_DIMS_DB_VERSION_OPT ) !== SN_ANALYTICS_DIMS_DB_VERSION ) {
		sn_analytics_dims_install();
	}
}
// NOTE: wired into the plugin loader (signal-and-noise-tools.php) in a later task; until then this module is loaded only by its CLI test.
add_action( 'init', 'sn_analytics_dims_maybe_install' );

/**
 * AE SQL aggregating the trailing $days into per-day-per-value-per-class rows
 * for ONE dimension. Returns '' for an unknown dim (caller issues no query).
 * $days is integer-cast + floored (defence in depth — callers pass a constant).
 *
 * @param string $dim  One of array_keys( SN_ANALYTICS_DIM_COLUMNS ).
 * @param int    $days Trailing window in days.
 * @return string AE SQL, or '' if $dim is unknown.
 */
function sn_analytics_dims_rollup_sql( $dim, $days ) {
	if ( ! isset( SN_ANALYTICS_DIM_COLUMNS[ $dim ] ) ) {
		return '';
	}
	$col  = SN_ANALYTICS_DIM_COLUMNS[ $dim ];
	$days = max( 1, (int) $days );

	// pv-only window: dimensions describe pageviews, so filtering to `pv` events
	// in the WHERE lets both aggregates use AE's documented forms — sum() over the
	// (now exclusively pv) sample interval, and count(DISTINCT <column>) on a bare
	// column. AE rejects count(DISTINCT <expression>) (the prior count(DISTINCT
	// if(...)) was undocumented) and count(*)/count(<arg>) ("COUNT() function must
	// have 0 arguments"); both are avoided here. Semantically identical to the
	// prior sumIf(pv)+count(DISTINCT if(pv)) form.
	return implode( ' ', array(
		"SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day,",
		"{$col} AS value,",
		'blob7 AS class,',
		'sum(_sample_interval) AS views,',
		'count(DISTINCT index1) AS visits',
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE blob1 = 'pv' AND timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)",
		'GROUP BY day, value, class',
		'ORDER BY day DESC, views DESC',
	) );
}

/**
 * UPSERT dimension rows (each carrying day/dim/value/class/views/visits) into
 * the dims table. Malformed rows are skipped: a YYYY-MM-DD day, a known dim, and
 * a known class are required. A blank value becomes '(direct)' for referrer /
 * '(unknown)' otherwise; values truncate to 160 chars. Batched per 100.
 *
 * @param array $rows
 * @return int Rows written.
 */
function sn_analytics_dims_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}

	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day   = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$dim   = isset( $r['dim'] ) ? (string) $r['dim'] : '';
		$class = isset( $r['class'] ) && '' !== (string) $r['class'] ? (string) $r['class'] : 'human';
		$value = isset( $r['value'] ) ? trim( (string) $r['value'] ) : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			continue;
		}
		if ( ! isset( SN_ANALYTICS_DIM_COLUMNS[ $dim ] ) || ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
			continue;
		}
		if ( '' === $value ) {
			$value = ( 'referrer' === $dim ) ? '(direct)' : '(unknown)';
		}
		$clean[] = array(
			'day'    => $day,
			'dim'    => $dim,
			'value'  => substr( $value, 0, 160 ),
			'class'  => $class,
			'views'  => max( 0, (int) round( (float) ( $r['views'] ?? 0 ) ) ),
			'visits' => max( 0, (int) round( (float) ( $r['visits'] ?? 0 ) ) ),
		);
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_DIMS_TABLE;
	$written = 0;

	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			$placeholders[] = '(%s, %s, %s, %s, %d, %d)';
			array_push( $values, $c['day'], $c['dim'], $c['value'], $c['class'], $c['views'], $c['visits'] );
		}
		$sql = "INSERT INTO {$table} (day, dim, value, class, views, visits) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE views=VALUES(views), visits=VALUES(visits)';

		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false !== $result ) {
			$written += count( $chunk );
		}
	}

	return $written;
}

/**
 * Roll all three dimensions: one AE query per dim, tag each row with its dim,
 * merge, and UPSERT in one batch. Called from sn_analytics_run_rollup(). No-ops
 * when AE isn't configured; a per-dim query failure (null) is skipped, not fatal.
 */
function sn_analytics_dims_run_rollup() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return;
	}
	if ( ! sn_analytics_config() ) {
		return;
	}

	$all = array();
	foreach ( array_keys( SN_ANALYTICS_DIM_COLUMNS ) as $dim ) {
		$rows = sn_analytics_query( sn_analytics_dims_rollup_sql( $dim, SN_ANALYTICS_ROLLUP_WINDOW_DAYS ) );
		if ( ! is_array( $rows ) ) {
			continue;
		}
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$row['dim'] = $dim;
				$all[]      = $row;
			}
		}
	}

	if ( ! empty( $all ) ) {
		sn_analytics_dims_upsert( $all );
	}
}

/**
 * Read accessor: top values for ONE dimension across an inclusive [$from,$to]
 * day range, filtered to a single class (default human), ordered by views.
 *
 * @param string $dim   'referrer' | 'country' | 'device'.
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class (default 'human').
 * @param int    $limit Max rows (1..500).
 * @return array<int, array{value:string, views:int, visits:int}>
 */
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) {
	if ( ! isset( SN_ANALYTICS_DIM_COLUMNS[ $dim ] ) ) {
		return array();
	}
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$limit = max( 1, min( 500, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DIMS_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT value, SUM(views) AS views, SUM(visits) AS visits
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND dim = %s AND class = %s
		 GROUP BY value
		 ORDER BY views DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		$dim,
		$class,
		$limit
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'value'  => (string) $r['value'],
				'views'  => (int) $r['views'],
				'visits' => (int) $r['visits'],
			);
		}
	}
	return $out;
}

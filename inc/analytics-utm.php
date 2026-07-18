<?php
/**
 * Signal & Noise — first-party UTM campaign attribution (read + durable rollup).
 *
 * The edge worker (v1.12.0) captures the five named utm_* params a visitor lands
 * with and packs them into the AE row's last free blob (blob20) as
 * `source␟medium␟campaign␟term␟content` (␟ = U+001F), '' for the ~99% of
 * pageviews with no campaign tag. This module rolls that packed column into a
 * durable per-day Source/Medium + Campaign table so the dashboard has fast,
 * >90-day history.
 *
 * Unlike the generic dims table (one dim = one blob), blob20 is a PACKED tuple,
 * so this is a dedicated module: it groups by the raw packed string in AE (no
 * JOINs, no untrusted split functions there — see the AE SQL dialect notes) and
 * splits it in PHP at write time. The wide (source, medium, campaign) tuple is
 * keyed by a sha1 `sig` so the UNIQUE index stays well inside InnoDB's prefix
 * limit. term/content are captured at the edge but not surfaced yet (YAGNI).
 *
 * Dormant until AE creds are configured (sn_analytics_query() → null), exactly
 * like the path + dims rollups: the empty table is created but never written.
 *
 * @package SignalNoiseTools
 * @since 9.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_UTM_TABLE          = 'sn_analytics_utm';
const SN_ANALYTICS_UTM_DB_VERSION     = '1';
const SN_ANALYTICS_UTM_DB_VERSION_OPT = 'sn_analytics_utm_db_version';
const SN_ANALYTICS_UTM_SEP            = "\x1f"; // MUST match the worker's packed-UTM field separator (U+001F).

/**
 * Split the worker's packed blob20 into exactly five fields
 * [source, medium, campaign, term, content]. Missing trailing fields pad to ''
 * so callers can index positionally without bounds checks.
 *
 * @param string $packed The raw blob20 value.
 * @return array{0:string,1:string,2:string,3:string,4:string}
 */
function sn_analytics_utm_split( $packed ) {
	$parts = explode( SN_ANALYTICS_UTM_SEP, (string) $packed );
	$out   = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$out[] = isset( $parts[ $i ] ) ? $parts[ $i ] : '';
	}
	return $out;
}

/**
 * dbDelta CREATE TABLE for the UTM breakdown. The (source, medium, campaign)
 * tuple is wide, so the UNIQUE constraint is a 40-char sha1 `sig` over
 * day|source|medium|campaign|class — the indexed key stays 40 bytes regardless
 * of value length, sidestepping InnoDB's 767-byte prefix limit that caps the
 * generic dims table's `value` at 160.
 *
 * @return string CREATE TABLE statement.
 */
function sn_analytics_utm_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_UTM_TABLE;
	$charset = $wpdb->get_charset_collate();

	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		source VARCHAR(128) NOT NULL DEFAULT '',
		medium VARCHAR(128) NOT NULL DEFAULT '',
		campaign VARCHAR(128) NOT NULL DEFAULT '',
		class VARCHAR(10) NOT NULL DEFAULT 'human',
		views INT UNSIGNED NOT NULL DEFAULT 0,
		visits INT UNSIGNED NOT NULL DEFAULT 0,
		sig CHAR(40) NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY sig (sig)
	) {$charset};";
}

/**
 * Create the table via dbDelta. Brand-new dormant table — no migration path.
 */
function sn_analytics_utm_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_analytics_utm_schema_sql() );
	update_option( SN_ANALYTICS_UTM_DB_VERSION_OPT, SN_ANALYTICS_UTM_DB_VERSION );
}

/**
 * One autoloaded-option compare per request; install runs only on the delta.
 */
function sn_analytics_utm_maybe_install() {
	if ( get_option( SN_ANALYTICS_UTM_DB_VERSION_OPT ) !== SN_ANALYTICS_UTM_DB_VERSION ) {
		sn_analytics_utm_install();
	}
}
add_action( 'init', 'sn_analytics_utm_maybe_install' );

/**
 * AE SQL aggregating the trailing $days of campaign pageviews into
 * per-day-per-packed-tuple-per-class rows. Mirrors the dims rollup's proven,
 * live-verified forms (formatDateTime(toStartOfDay(...)), sum(_sample_interval),
 * count(DISTINCT index1)); the only additions are selecting the packed blob20 and
 * the `blob20 != ''` filter that drops the overwhelming majority of pageviews
 * with no campaign tag. No JOINs and no split functions run in AE — the packed
 * tuple is split in PHP by sn_analytics_utm_upsert().
 *
 * @param int $days Trailing window in days.
 * @return string AE SQL.
 */
function sn_analytics_utm_rollup_sql( $days ) {
	$days = max( 1, (int) $days );

	return implode( ' ', array(
		"SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day,",
		'blob20 AS packed,',
		'blob7 AS class,',
		'sum(_sample_interval) AS views,',
		'count(DISTINCT index1) AS visits',
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE blob1 = 'pv' AND blob20 != '' AND timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)",
		'GROUP BY day, packed, class',
		'ORDER BY day DESC, views DESC',
	) );
}

/**
 * UPSERT packed-UTM rows (each carrying day/packed/class/views/visits) into the
 * utm table. The packed value is split here; empty source/medium/campaign fields
 * normalize to '(none)'. Rows are skipped when the day isn't YYYY-MM-DD, the
 * class is unknown, or the tuple carries no campaign signal at all (every field
 * empty). Each surviving tuple gets a sha1 `sig` for the UNIQUE key. Batched per
 * 100 to stay under MySQL's 65,535-placeholder limit.
 *
 * @param array $rows
 * @return int Rows written.
 */
function sn_analytics_utm_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}

	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day   = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$class = isset( $r['class'] ) && '' !== (string) $r['class'] ? (string) $r['class'] : 'human';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			continue;
		}
		if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
			continue;
		}

		$parts = sn_analytics_utm_split( $r['packed'] ?? '' );
		$norm  = static function ( $v ) {
			$v = substr( trim( (string) $v ), 0, 128 );
			return '' === $v ? '(none)' : $v;
		};
		$source   = $norm( $parts[0] );
		$medium   = $norm( $parts[1] );
		$campaign = $norm( $parts[2] );
		// An all-empty packed tuple carries no campaign signal — the worker should
		// never emit one (blob20 is '' in that case), but guard defensively.
		if ( '(none)' === $source && '(none)' === $medium && '(none)' === $campaign ) {
			continue;
		}

		$sig = sha1( $day . '|' . $source . '|' . $medium . '|' . $campaign . '|' . $class );

		$clean[] = array(
			'day'      => $day,
			'source'   => $source,
			'medium'   => $medium,
			'campaign' => $campaign,
			'class'    => $class,
			'views'    => max( 0, (int) round( (float) ( $r['views'] ?? 0 ) ) ),
			'visits'   => max( 0, (int) round( (float) ( $r['visits'] ?? 0 ) ) ),
			'sig'      => $sig,
		);
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_UTM_TABLE;
	$written = 0;

	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			$placeholders[] = '(%s, %s, %s, %s, %s, %d, %d, %s)';
			array_push( $values, $c['day'], $c['source'], $c['medium'], $c['campaign'], $c['class'], $c['views'], $c['visits'], $c['sig'] );
		}
		$sql = "INSERT INTO {$table} (day, source, medium, campaign, class, views, visits, sig) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE views=VALUES(views), visits=VALUES(visits)';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a static INSERT ... VALUES template with a generated %s/%d placeholder group per row; $table is $wpdb->prefix + a plugin constant and every value is bound via prepare().
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false !== $result ) {
			$written += count( $chunk );
		}
	}

	return $written;
}

/**
 * Roll the packed UTM column: one AE query, split each packed tuple in PHP, and
 * UPSERT. Called from sn_analytics_run_rollup(). No-ops when AE isn't configured;
 * a query failure (null) is skipped, not fatal.
 */
function sn_analytics_utm_run_rollup() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return;
	}
	if ( ! sn_analytics_config() ) {
		return;
	}

	$rows = sn_analytics_query( sn_analytics_utm_rollup_sql( SN_ANALYTICS_ROLLUP_WINDOW_DAYS ) );
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return;
	}
	sn_analytics_utm_upsert( $rows );
}

/**
 * Read accessor: top campaigns across an inclusive [$from,$to] day range,
 * filtered to a single class (default human), ordered by views.
 *
 * Contract (v9.68.1): null = the read FAILED ($wpdb->last_error set — never
 * served as an empty window); [] = an empty window, which is an ANSWER.
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class (default 'human').
 * @param int    $limit Max rows (1..500).
 * @return array<int, array{value:string, views:int, visits:int}>|null
 */
function sn_analytics_top_utm_campaigns( $from, $to, $class = 'human', $limit = 25 ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$limit = max( 1, min( 500, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_UTM_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT campaign AS value, SUM(views) AS views, SUM(visits) AS visits
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY campaign
		 ORDER BY views DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		$class,
		$limit
	), ARRAY_A );

	// v9.68.1 failure honesty: a FAILED query is [] + $wpdb->last_error set
	// (flush-per-query, so it reflects THIS read) — never an empty window.
	if ( ! is_array( $results ) || '' !== (string) $wpdb->last_error ) {
		return null;
	}

	$out = array();
	foreach ( $results as $r ) {
		$out[] = array(
			'value'  => (string) $r['value'],
			'views'  => (int) $r['views'],
			'visits' => (int) $r['visits'],
		);
	}
	return $out;
}

/**
 * Read accessor: top Source/Medium pairs (the classic acquisition report),
 * across an inclusive [$from,$to] day range, filtered to a single class, ordered
 * by views. Each row carries the raw source + medium plus a composed
 * "source / medium" label for the table.
 *
 * Contract (v9.68.1): null = the read FAILED ($wpdb->last_error set — never
 * served as an empty window); [] = an empty window, which is an ANSWER.
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class (default 'human').
 * @param int    $limit Max rows (1..500).
 * @return array<int, array{value:string, source:string, medium:string, views:int, visits:int}>|null
 */
function sn_analytics_top_utm_sources( $from, $to, $class = 'human', $limit = 25 ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$limit = max( 1, min( 500, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_UTM_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT source, medium, SUM(views) AS views, SUM(visits) AS visits
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY source, medium
		 ORDER BY views DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		$class,
		$limit
	), ARRAY_A );

	// v9.68.1 failure honesty: a FAILED query is [] + $wpdb->last_error set —
	// never an empty window.
	if ( ! is_array( $results ) || '' !== (string) $wpdb->last_error ) {
		return null;
	}

	$out = array();
	foreach ( $results as $r ) {
		$source = (string) $r['source'];
		$medium = (string) $r['medium'];
		$out[]  = array(
			'value'  => $source . ' / ' . $medium,
			'source' => $source,
			'medium' => $medium,
			'views'  => (int) $r['views'],
			'visits' => (int) $r['visits'],
		);
	}
	return $out;
}

/**
 * Per-bucket view series for a set of UTM values, in ONE batched query (avoids an
 * N+1 across rows) — the trend sparklines beside the Campaigns / Source-Medium
 * tables, matching the referrer sources' treatment. $mode picks which value the
 * series keys on: 'campaign' → the campaign column; 'source_medium' → the same
 * "source / medium" label the read accessor composes. Returns value => [{day,views}].
 *
 * Contract (v9.68.1): null = the read FAILED ($wpdb->last_error set); [] = no
 * series (including an empty $values set, which issues no query at all).
 *
 * @param string   $mode        'campaign' | 'source_medium'.
 * @param string[] $values      Already-trusted top-N values (from the read accessor).
 * @param string   $from        Inclusive start day, YYYY-MM-DD.
 * @param string   $to          Inclusive end day, YYYY-MM-DD.
 * @param string   $class       Traffic class (default 'human').
 * @param string   $granularity 'day' | 'week'.
 * @return array<string, array<int, array{day:string, views:int}>>|null
 */
function sn_analytics_utm_series( $mode, $values, $from, $to, $class = 'human', $granularity = 'day' ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$values = array_values( array_filter( (array) $values, 'is_string' ) );
	if ( empty( $values ) ) {
		return array();
	}
	$value_expr = ( 'source_medium' === $mode ) ? "CONCAT(source, ' / ', medium)" : 'campaign';
	$expr       = function_exists( 'sn_analytics_bucket_expr' ) ? sn_analytics_bucket_expr( $granularity ) : 'day';

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_UTM_TABLE;

	$in_ph = implode( ',', array_fill( 0, count( $values ), '%s' ) );
	$args  = array_merge( array( (string) $from, (string) $to, $class ), $values );
	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $expr + $value_expr are hardcoded SQL fragments (bucket expr / a fixed column or CONCAT); $table is $wpdb->prefix + a plugin constant; every user value is bound via prepare().
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT {$expr} AS day, {$value_expr} AS value, SUM(views) AS views
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s AND {$value_expr} IN ({$in_ph})
		 GROUP BY {$expr}, value
		 ORDER BY day ASC",
		$args
	), ARRAY_A );

	// v9.68.1 failure honesty: a FAILED query is [] + $wpdb->last_error set —
	// never an empty series map.
	if ( ! is_array( $results ) || '' !== (string) $wpdb->last_error ) {
		return null;
	}

	$map = array();
	foreach ( $results as $r ) {
		$v           = (string) $r['value'];
		$map[ $v ][] = array( 'day' => (string) $r['day'], 'views' => (int) $r['views'] );
	}
	return $map;
}

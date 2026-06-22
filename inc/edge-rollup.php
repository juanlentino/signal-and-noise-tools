<?php
/**
 * Signal & Noise — edge-analytics durable storage + daily GraphQL rollup.
 *
 * The companion to inc/edge-analytics.php (the GraphQL client). Two tables mirror
 * the AE dims/daily split:
 *   - sn_edge_daily — one EXACT row per UTC day (httpRequests1dGroups): requests,
 *     cache, bandwidth, threats, pageViews, and the 2xx/3xx/4xx/5xx status buckets.
 *   - sn_edge_dims  — (day, dim, value) breakdowns: country (from the daily map),
 *     plus today's sampled colo + threat detail.
 *
 * A daily WP-Cron poll re-pulls the trailing ~13 months of 1dGroups (exact,
 * idempotent overwrite — the first run back-fills) and a trailing adaptive snapshot
 * (24h by default, clamped to the node's discovered retention) of the two adaptive
 * datasets (sampling-corrected, attributed to "today"). Dormant until the GraphQL
 * client is configured; a failed query is skipped, never fatal.
 *
 * @package SignalNoiseTools
 * @since 6.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_EDGE_DAILY_TABLE      = 'sn_edge_daily';
const SN_EDGE_DIMS_TABLE       = 'sn_edge_dims';
const SN_EDGE_DB_VERSION       = '1';
const SN_EDGE_DB_VERSION_OPT   = 'sn_edge_db_version';
const SN_EDGE_BACKFILL_DAYS    = 395; // ~13 months — inside httpRequests1dGroups retention.
const SN_EDGE_ROLLUP_HOOK      = 'sn_edge_rollup_cron';

/** dbDelta CREATE for the exact daily totals (one row per day). */
function sn_edge_daily_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_EDGE_DAILY_TABLE;
	$charset = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
		cached_requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
		bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
		cached_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
		threats BIGINT UNSIGNED NOT NULL DEFAULT 0,
		page_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
		status_2xx BIGINT UNSIGNED NOT NULL DEFAULT 0,
		status_3xx BIGINT UNSIGNED NOT NULL DEFAULT 0,
		status_4xx BIGINT UNSIGNED NOT NULL DEFAULT 0,
		status_5xx BIGINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day (day)
	) {$charset};";
}

/** dbDelta CREATE for the breakdown rows (country / colo / threat). */
function sn_edge_dims_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$charset = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		dim VARCHAR(16) NOT NULL,
		value VARCHAR(160) NOT NULL,
		requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
		bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_dim_value (day, dim, value)
	) {$charset};";
}

function sn_edge_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_edge_daily_schema_sql() );
	dbDelta( sn_edge_dims_schema_sql() );
	update_option( SN_EDGE_DB_VERSION_OPT, SN_EDGE_DB_VERSION );
}

function sn_edge_maybe_install() {
	if ( get_option( SN_EDGE_DB_VERSION_OPT ) !== SN_EDGE_DB_VERSION ) {
		sn_edge_install();
	}
}
add_action( 'init', 'sn_edge_maybe_install' );

/** Daily cron: re-pull + upsert. WP passes no args → today defaults to now (UTC). */
add_action( SN_EDGE_ROLLUP_HOOK, 'sn_edge_run_rollup' );
add_action( 'init', 'sn_edge_maybe_schedule' );
function sn_edge_maybe_schedule() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_EDGE_ROLLUP_HOOK ) ) {
		wp_schedule_event( time(), 'daily', SN_EDGE_ROLLUP_HOOK );
	}
}

/** UPSERT exact daily rows. @return int rows written. */
function sn_edge_daily_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DAILY_TABLE;
	$cols  = array( 'requests', 'cached_requests', 'bytes', 'cached_bytes', 'threats', 'page_views', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx' );
	$ph    = array();
	$vals  = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || empty( $r['day'] ) ) {
			continue;
		}
		$ph[]   = '(%s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)';
		$vals[] = (string) $r['day'];
		foreach ( $cols as $c ) {
			$vals[] = max( 0, (int) ( $r[ $c ] ?? 0 ) );
		}
	}
	if ( empty( $ph ) ) {
		return 0;
	}
	$updates = array();
	foreach ( $cols as $c ) {
		$updates[] = "{$c}=VALUES({$c})";
	}
	$sql = "INSERT INTO {$table} (day, " . implode( ', ', $cols ) . ') VALUES '
		. implode( ', ', $ph ) . ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
	return false === $wpdb->query( $wpdb->prepare( $sql, $vals ) ) ? 0 : count( $ph );
}

/** UPSERT breakdown rows (day, dim, value, requests, bytes). @return int rows written. */
function sn_edge_dims_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$ph    = array();
	$vals  = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || empty( $r['day'] ) || '' === (string) ( $r['value'] ?? '' ) ) {
			continue;
		}
		$ph[]   = '(%s, %s, %s, %d, %d)';
		array_push( $vals, (string) $r['day'], (string) $r['dim'], substr( (string) $r['value'], 0, 160 ), max( 0, (int) ( $r['requests'] ?? 0 ) ), max( 0, (int) ( $r['bytes'] ?? 0 ) ) );
	}
	if ( empty( $ph ) ) {
		return 0;
	}
	$sql = "INSERT INTO {$table} (day, dim, value, requests, bytes) VALUES "
		. implode( ', ', $ph ) . ' ON DUPLICATE KEY UPDATE requests=VALUES(requests), bytes=VALUES(bytes)';
	return false === $wpdb->query( $wpdb->prepare( $sql, $vals ) ) ? 0 : count( $ph );
}

/** Bucket an HTTP status code to its class column key (2xx..5xx); others ignored. */
function sn_edge_status_bucket( $status ) {
	$c = (int) floor( (int) $status / 100 );
	return ( $c >= 2 && $c <= 5 ) ? 'status_' . $c . 'xx' : '';
}

/**
 * Daily rollup: pull the exact 1dGroups window + the trailing adaptive snapshot
 * (24h, clamped to the node's discovered retention), parse, and upsert. Dormant when
 * unconfigured; per-dataset failure (null) is skipped.
 *
 * @param string|null $today YYYY-MM-DD reference day (defaults to now, UTC).
 */
function sn_edge_run_rollup( $today = null ) {
	if ( ! function_exists( 'sn_edge_config' ) || ! sn_edge_config() ) {
		return;
	}
	$today = $today && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $today ) ? (string) $today : gmdate( 'Y-m-d' );
	$today_ts = strtotime( $today . ' 00:00:00 UTC' );
	$from_day = gmdate( 'Y-m-d', $today_ts - SN_EDGE_BACKFILL_DAYS * DAY_IN_SECONDS );

	// Adaptive snapshot width: a trailing 24h by default, but never wider than the
	// dataset's REAL retention — discovered at runtime from the settings node's
	// notOlderThan (Cloudflare publishes no fixed Free-tier number). On the common
	// case (retention ≥ 24h) this is a no-op; it only shrinks the window if a node
	// happens to retain less than a day, so we never request data it cannot return.
	$window    = DAY_IN_SECONDS;
	$retention = function_exists( 'sn_edge_adaptive_retention' ) ? sn_edge_adaptive_retention() : null;
	if ( is_int( $retention ) && $retention > 0 && $retention < $window ) {
		$window = $retention;
	}
	$since = gmdate( 'Y-m-d\TH:i:s\Z', $today_ts - $window );

	$daily_rows = array();
	$dim_rows   = array();

	// 1. Exact daily (httpRequests1dGroups).
	$zone = sn_edge_query( sn_edge_daily_query(), array( 'from' => $from_day, 'to' => $today ) );
	if ( is_array( $zone ) && is_array( $zone['httpRequests1dGroups'] ?? null ) ) {
		foreach ( $zone['httpRequests1dGroups'] as $g ) {
			$day = (string) ( $g['dimensions']['date'] ?? '' );
			if ( '' === $day ) {
				continue;
			}
			$sum = is_array( $g['sum'] ?? null ) ? $g['sum'] : array();
			$row = array(
				'day'             => $day,
				'requests'        => (int) ( $sum['requests'] ?? 0 ),
				'cached_requests' => (int) ( $sum['cachedRequests'] ?? 0 ),
				'bytes'           => (int) ( $sum['bytes'] ?? 0 ),
				'cached_bytes'    => (int) ( $sum['cachedBytes'] ?? 0 ),
				'threats'         => (int) ( $sum['threats'] ?? 0 ),
				'page_views'      => (int) ( $sum['pageViews'] ?? 0 ),
				'status_2xx'      => 0,
				'status_3xx'      => 0,
				'status_4xx'      => 0,
				'status_5xx'      => 0,
			);
			foreach ( (array) ( $sum['responseStatusMap'] ?? array() ) as $sm ) {
				$bucket = sn_edge_status_bucket( $sm['edgeResponseStatus'] ?? 0 );
				if ( '' !== $bucket ) {
					$row[ $bucket ] += (int) ( $sm['requests'] ?? 0 );
				}
			}
			$daily_rows[] = $row;
			foreach ( (array) ( $sum['countryMap'] ?? array() ) as $cm ) {
				$dim_rows[] = array( 'day' => $day, 'dim' => 'country', 'value' => (string) ( $cm['clientCountryName'] ?? '' ), 'requests' => (int) ( $cm['requests'] ?? 0 ), 'bytes' => (int) ( $cm['bytes'] ?? 0 ) );
			}
		}
	}

	// 2. Threats (firewallEventsAdaptiveGroups) — sampled, trailing snapshot → today.
	$zone = sn_edge_query( sn_edge_firewall_query(), array( 'from' => $since ) );
	if ( is_array( $zone ) && is_array( $zone['firewallEventsAdaptiveGroups'] ?? null ) ) {
		foreach ( $zone['firewallEventsAdaptiveGroups'] as $g ) {
			$action = (string) ( $g['dimensions']['action'] ?? '' );
			if ( '' === $action ) {
				continue;
			}
			$dim_rows[] = array( 'day' => $today, 'dim' => 'threat', 'value' => $action, 'requests' => sn_edge_corrected( $g ), 'bytes' => 0 );
		}
	}

	// 3. Per-colo (httpRequestsAdaptiveGroups) — sampled, trailing snapshot → today.
	$zone = sn_edge_query( sn_edge_colo_query(), array( 'from' => $since ) );
	if ( is_array( $zone ) && is_array( $zone['httpRequestsAdaptiveGroups'] ?? null ) ) {
		foreach ( $zone['httpRequestsAdaptiveGroups'] as $g ) {
			$colo = (string) ( $g['dimensions']['coloCode'] ?? '' );
			if ( '' === $colo ) {
				continue;
			}
			$si  = max( 1.0, (float) ( $g['avg']['sampleInterval'] ?? 1 ) );
			$dim_rows[] = array( 'day' => $today, 'dim' => 'colo', 'value' => $colo, 'requests' => sn_edge_corrected( $g ), 'bytes' => (int) round( (int) ( $g['sum']['edgeResponseBytes'] ?? 0 ) * $si ) );
		}
	}

	// 4. Attack-surface pressure (httpRequestsAdaptiveGroups, aliased doors+probes) —
	// sampled, trailing snapshot → today. Marginalize the 5-dim door rows into atk_* keys.
	$zone = sn_edge_query( sn_edge_attack_query(), array( 'from' => $since ) );
	if ( is_array( $zone ) ) {
		$marg = array(); // dim => value => corrected sum
		foreach ( (array) ( $zone['doors'] ?? array() ) as $g ) {
			$req = sn_edge_corrected( $g );
			$d   = is_array( $g['dimensions'] ?? null ) ? $g['dimensions'] : array();
			$asn = (string) ( $d['clientASNDescription'] ?? '' );
			if ( '' === $asn ) {
				$an  = (string) ( $d['clientAsn'] ?? '' );
				$asn = '' !== $an ? 'AS' . $an : '';
			}
			$pairs = array(
				'atk_door'    => (string) ( $d['clientRequestPath'] ?? '' ),
				'atk_country' => (string) ( $d['clientCountryName'] ?? '' ),
				'atk_asn'     => $asn,
				'atk_status'  => (string) ( $d['edgeResponseStatus'] ?? '' ),
				'atk_method'  => (string) ( $d['clientRequestHTTPMethodName'] ?? '' ),
			);
			foreach ( $pairs as $dim => $val ) {
				if ( '' === $val ) {
					continue;
				}
				$marg[ $dim ][ $val ] = ( $marg[ $dim ][ $val ] ?? 0 ) + $req;
			}
		}
		foreach ( (array) ( $zone['probes'] ?? array() ) as $g ) {
			$path = (string) ( $g['dimensions']['clientRequestPath'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			$marg['atk_path'][ $path ] = ( $marg['atk_path'][ $path ] ?? 0 ) + sn_edge_corrected( $g );
		}
		foreach ( $marg as $dim => $vals ) {
			foreach ( $vals as $val => $req ) {
				$dim_rows[] = array( 'day' => $today, 'dim' => $dim, 'value' => (string) $val, 'requests' => (int) $req, 'bytes' => 0 );
			}
		}
	}

	if ( ! empty( $daily_rows ) ) {
		sn_edge_daily_upsert( $daily_rows );
	}
	if ( ! empty( $dim_rows ) ) {
		sn_edge_dims_upsert( $dim_rows );
	}
}

/**
 * Summed edge totals over [from,to] + derived cache-hit% and error% (4xx+5xx).
 *
 * @return array scalar sums + cache_hit_pct + error_pct.
 */
function sn_edge_range_totals( $from, $to ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DAILY_TABLE;
	$cols  = array( 'requests', 'cached_requests', 'bytes', 'cached_bytes', 'threats', 'page_views', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx' );
	$select = array();
	foreach ( $cols as $c ) {
		$select[] = "SUM({$c}) AS {$c}";
	}
	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT ' . implode( ', ', $select ) . " FROM {$table} WHERE day >= %s AND day <= %s",
		(string) $from,
		(string) $to
	), ARRAY_A );

	$out = array();
	foreach ( $cols as $c ) {
		$out[ $c ] = (int) ( is_array( $row ) ? ( $row[ $c ] ?? 0 ) : 0 );
	}
	$req                  = max( 0, $out['requests'] );
	$out['cache_hit_pct'] = $req > 0 ? (int) round( $out['cached_requests'] / $req * 100 ) : 0;
	$out['error_pct']     = $req > 0 ? (int) round( ( $out['status_4xx'] + $out['status_5xx'] ) / $req * 100 ) : 0;
	return $out;
}

/** [{day, requests}] daily request series over [from,to], ascending. */
function sn_edge_daily_series( $from, $to ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DAILY_TABLE;
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, requests FROM {$table} WHERE day >= %s AND day <= %s ORDER BY day ASC",
		(string) $from,
		(string) $to
	), ARRAY_A );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[] = array( 'day' => (string) ( $r['day'] ?? '' ), 'requests' => (int) ( $r['requests'] ?? 0 ) );
	}
	return $out;
}

/** Top breakdown values for one dim (country|colo|threat) over [from,to]. */
function sn_edge_top_dim( $dim, $from, $to, $limit = 10 ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$limit = max( 1, min( 500, (int) $limit ) );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT value, SUM(requests) AS requests, SUM(bytes) AS bytes
		 FROM {$table} WHERE day >= %s AND day <= %s AND dim = %s
		 GROUP BY value ORDER BY requests DESC LIMIT %d",
		(string) $from,
		(string) $to,
		(string) $dim,
		$limit
	), ARRAY_A );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[] = array( 'value' => (string) ( $r['value'] ?? '' ), 'requests' => (int) ( $r['requests'] ?? 0 ), 'bytes' => (int) ( $r['bytes'] ?? 0 ) );
	}
	return $out;
}

/**
 * The headline reconciliation: edge HTML pageviews (every client) vs the beacon's
 * human pageviews (JS-executing humans) → the machine / no-JS traffic the beacon
 * never saw. Clamped at 0 (a sampled beacon window can momentarily over-count).
 *
 * @return array{edge:int, human:int, machine:int, machine_pct:int}
 */
function sn_edge_machine_split( $from, $to ) {
	$edge  = (int) ( sn_edge_range_totals( $from, $to )['page_views'] ?? 0 );
	$human = function_exists( 'sn_analytics_range_totals' )
		? (int) ( sn_analytics_range_totals( $from, $to, 'human' )['views'] ?? 0 )
		: 0;
	$machine = max( 0, $edge - $human );
	return array(
		'edge'        => $edge,
		'human'       => $human,
		'machine'     => $machine,
		'machine_pct' => $edge > 0 ? (int) round( $machine / $edge * 100 ) : 0,
	);
}

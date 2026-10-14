<?php
/**
 * Signal & Noise: edge-analytics rollup, the daily rollup run and the totals reads (the run, the snapshot-dims clear, the range totals, the daily series, the top dim).
 *
 * Split from inc/edge-rollup.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily rollup: pull the exact 1dGroups window + the adaptive snapshot of the
 * COMPLETE previous day (clamped to the node's discovered retention), parse,
 * and upsert. Dormant when unconfigured; per-dataset failure (null) is skipped.
 *
 * The snapshot is [today 00:00 − 24h, today 00:00) and is stored under
 * YESTERDAY. It used to run to `now` and be stored under today, so two
 * consecutive days overlapped by [yesterday 00:00, yesterday's run time] —
 * a day's threat / colo / atk_ / err_ rows covered ~38h and a week's
 * sn_edge_top_dim() counted most events twice (#1203).
 *
 * @param string|null $today YYYY-MM-DD reference day (defaults to now, UTC).
 */
function sn_edge_run_rollup( $today = null, $adaptive_only = false ) {
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
	$until = gmdate( 'Y-m-d\TH:i:s\Z', $today_ts );
	$snap  = gmdate( 'Y-m-d', $today_ts - DAY_IN_SECONDS ); // the day the snapshot covers

	$daily_rows = array();
	$dim_rows   = array();

	// 1. Exact daily (httpRequests1dGroups). Skipped by the 17.9.1 repair,
	// which re-rolls only the sampled snapshots and must not re-pull thirteen
	// months of exact history once per day it repairs.
	$zone = $adaptive_only ? null : sn_edge_query( sn_edge_daily_query(), array( 'from' => $from_day, 'to' => $today ) );
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

	// 2. Threats (firewallEventsAdaptiveGroups) — sampled, previous-day snapshot → yesterday.
	$zone = sn_edge_query( sn_edge_firewall_query(), array( 'from' => $since, 'to' => $until ) );
	if ( is_array( $zone ) && is_array( $zone['firewallEventsAdaptiveGroups'] ?? null ) ) {
		foreach ( $zone['firewallEventsAdaptiveGroups'] as $g ) {
			$action = (string) ( $g['dimensions']['action'] ?? '' );
			if ( '' === $action ) {
				continue;
			}
			$dim_rows[] = array( 'day' => $snap, 'dim' => 'threat', 'value' => $action, 'requests' => sn_edge_corrected( $g ), 'bytes' => 0 );
		}
	}

	// 3. Per-colo (httpRequestsAdaptiveGroups) — sampled, previous-day snapshot → yesterday.
	$zone = sn_edge_query( sn_edge_colo_query(), array( 'from' => $since, 'to' => $until ) );
	if ( is_array( $zone ) && is_array( $zone['httpRequestsAdaptiveGroups'] ?? null ) ) {
		foreach ( $zone['httpRequestsAdaptiveGroups'] as $g ) {
			$colo = (string) ( $g['dimensions']['coloCode'] ?? '' );
			if ( '' === $colo ) {
				continue;
			}
			// A grouped sum is already Cloudflare's estimate (see sn_edge_corrected()).
			$dim_rows[] = array( 'day' => $snap, 'dim' => 'colo', 'value' => $colo, 'requests' => sn_edge_corrected( $g ), 'bytes' => (int) ( $g['sum']['edgeResponseBytes'] ?? 0 ) );
		}
	}

	// 4. Attack-surface pressure (httpRequestsAdaptiveGroups, aliased doors+probes) —
	// sampled, previous-day snapshot → yesterday. Marginalize the 5-dim door rows into atk_* keys.
	$zone = sn_edge_query( sn_edge_attack_query(), array( 'from' => $since, 'to' => $until ) );
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
				$dim_rows[] = array( 'day' => $snap, 'dim' => $dim, 'value' => (string) $val, 'requests' => (int) $req, 'bytes' => 0 );
			}
		}
	}

	// 5. Server-error pressure (#1002). Its OWN query, so an unknown field here
	// cannot take the doors/probes collection down with it, and a failure leaves
	// the rest of this rollup intact.
	//
	// Two dimensions, because the counts alone answer the wrong question:
	//   err_path   which URLs failed
	//   err_source WHO answered — `edge=503 origin=503` is the origin (or the
	//              cache in front of it) failing; `edge=503 origin=-` is
	//              Cloudflare or a Worker answering by itself.
	// That second one is the datum eight external reproduction attempts could
	// not produce, which is the whole reason this section exists.
	// 17.9.3: a refused errors query used to leave the day silently empty;
	// its reason is kept, so the readers can say "not read" instead of "none".
	$err_q = '';
	$errz  = sn_edge_query( sn_edge_errors_query(), array( 'from' => $since, 'to' => $until ), $err_q );
	update_option( SN_EDGE_ERRORS_QUERY_OPT, array( 'at' => time(), 'day' => $snap, 'error' => (string) $err_q ), false );
	sn_edge_errors_mark_read( $snap, (string) $err_q );
	if ( is_array( $errz ) ) {
		foreach ( sn_edge_errors_dims( (array) ( $errz['errors'] ?? array() ) ) as $dim => $vals ) {
			foreach ( $vals as $val => $req ) {
				$dim_rows[] = array( 'day' => $snap, 'dim' => $dim, 'value' => (string) $val, 'requests' => (int) $req, 'bytes' => 0 );
			}
		}
	}

	if ( ! empty( $daily_rows ) ) {
		sn_edge_daily_upsert( $daily_rows );
	}
	if ( ! empty( $dim_rows ) ) {
		if ( $adaptive_only ) {
			sn_edge_clear_snapshot_dims( $snap, array_unique( array_column( $dim_rows, 'dim' ) ) );
		}
		sn_edge_dims_upsert( $dim_rows );
	}
}

/**
 * 17.9.1 repair only: drop a day's rows for the dims a re-fetch just returned,
 * so a value that fell out of the new top-N does not keep its inflated count.
 * Only dims present in the re-fetch are cleared: a sub-query that failed
 * leaves its dim as it was rather than empty.
 *
 * @param string   $day  YYYY-MM-DD.
 * @param string[] $dims Dims the re-fetch returned rows for.
 * @return void
 */
function sn_edge_clear_snapshot_dims( $day, array $dims ) {
	global $wpdb;
	$dims = array_values( array_filter( array_map( 'strval', $dims ), static function ( $d ) { return '' !== $d && 'country' !== $d; } ) );
	if ( ! $wpdb || array() === $dims ) {
		return;
	}
	$table = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$in    = implode( ', ', array_fill( 0, count( $dims ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- table name from the prefix + a constant; values prepared.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day = %s AND dim IN ({$in})", array_merge( array( (string) $day ), $dims ) ) );
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


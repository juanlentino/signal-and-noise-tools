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
 * datasets (Cloudflare's own sampled estimates, taken as reported since 17.9.1). Dormant until the GraphQL
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
const SN_EDGE_RESAMPLE_HOOK    = 'sn_edge_resample_repair'; // 17.9.1: one-shot.
const SN_EDGE_HONEST_FROM_OPT  = 'sn_edge_honest_from';      // First day the sampled dims count honestly.
const SN_EDGE_RESAMPLE_LOCK    = 'sn_edge_resample_running'; // 17.9.2: one repair in flight.
const SN_EDGE_ERRORS_QUERY_OPT = 'sn_edge_errors_query_last'; // 17.9.3: the errors query's last outcome.
const SN_EDGE_ERRORS_READ_OPT  = 'sn_edge_errors_read_days';  // 18.3.0: day => '' (read) or the refusal, per rollup run.

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

/**
 * 17.9.1: the one-shot repair of the sampled dims (threat, colo, atk_*, err_*),
 * which were counted twice over (see sn_edge_corrected()). Every day the
 * adaptive dataset still retains is re-rolled with the fixed arithmetic; the
 * upsert replaces a row's values, so the re-roll overwrites the inflated ones.
 * Days older than the retention cannot be recomputed (the per-row sample
 * intervals are gone), so they stay as stored and SN_EDGE_HONEST_FROM_OPT
 * records where the honest counts begin, rather than deleting history.
 * Scheduled once, from init, on the first request after the upgrade.
 */
function sn_edge_resample_repair() {
	if ( false !== get_option( SN_EDGE_HONEST_FROM_OPT, false ) || ! function_exists( 'sn_edge_config' ) || ! sn_edge_config() ) {
		return;
	}
	// 17.9.2: WP-Cron removes a single event from the queue BEFORE running it,
	// and a re-roll of a month takes longer than a minute, so the init hook
	// below saw "not done, nothing queued" mid-run and queued a second repair,
	// every minute until the first finished (measured 2026-09-23). The lock
	// is what the init hook reads; it expires, so a run that dies is retried.
	set_transient( SN_EDGE_RESAMPLE_LOCK, time(), 30 * MINUTE_IN_SECONDS );
	$days  = function_exists( 'sn_edge_adaptive_retention' ) ? (int) floor( (int) sn_edge_adaptive_retention() / DAY_IN_SECONDS ) : 0;
	$days  = max( 1, min( 31, $days ) );
	$today = strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' );
	for ( $d = 0; $d < $days; $d++ ) {
		sn_edge_run_rollup( gmdate( 'Y-m-d', $today - $d * DAY_IN_SECONDS ), true );
	}
	update_option( SN_EDGE_HONEST_FROM_OPT, gmdate( 'Y-m-d', $today - $days * DAY_IN_SECONDS ), false );
	delete_transient( SN_EDGE_RESAMPLE_LOCK );
}
add_action( SN_EDGE_RESAMPLE_HOOK, 'sn_edge_resample_repair' );
/**
 * Queue the repair once: not when it is done, not while one is running
 * (SN_EDGE_RESAMPLE_LOCK), not when one is already queued.
 *
 * @return bool Whether an event was queued.
 */
function sn_edge_resample_maybe_schedule() {
	if ( false !== get_option( SN_EDGE_HONEST_FROM_OPT, false ) || false !== get_transient( SN_EDGE_RESAMPLE_LOCK ) || ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( SN_EDGE_RESAMPLE_HOOK ) ) {
		return false;
	}
	wp_schedule_single_event( time() + 60, SN_EDGE_RESAMPLE_HOOK );
	return true;
}
add_action( 'init', 'sn_edge_resample_maybe_schedule' );

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
	// Drop malformed rows first, then CHUNK. sn_edge_run_rollup() re-pulls
	// SN_EDGE_BACKFILL_DAYS days of countryMap + adaptive threat/colo/attack dims on
	// EVERY run, so this row set is unbounded — one INSERT of the whole set (5
	// placeholders each) can blow past MySQL's 65,535-placeholder statement limit,
	// making $wpdb->query() return false → 0 rows written → and, re-pulled each run,
	// never recovering. 100 rows/chunk (500 placeholders) matches every sibling upsert.
	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || empty( $r['day'] ) || '' === (string) ( $r['value'] ?? '' ) ) {
			continue;
		}
		$clean[] = $r;
	}
	if ( empty( $clean ) ) {
		return 0;
	}
	global $wpdb;
	$table   = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$written = 0;
	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$ph   = array();
		$vals = array();
		foreach ( $chunk as $r ) {
			$ph[]   = '(%s, %s, %s, %d, %d)';
			array_push( $vals, (string) $r['day'], (string) $r['dim'], mb_substr( (string) $r['value'], 0, 160, 'UTF-8' ), max( 0, (int) ( $r['requests'] ?? 0 ) ), max( 0, (int) ( $r['bytes'] ?? 0 ) ) );
		}
		$sql = "INSERT INTO {$table} (day, dim, value, requests, bytes) VALUES "
			. implode( ', ', $ph ) . ' ON DUPLICATE KEY UPDATE requests=VALUES(requests), bytes=VALUES(bytes)';
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL -- $sql is a static INSERT ... VALUES template with a generated %s/%d placeholder group per row; $table is $wpdb->prefix + a plugin constant and every value is bound via prepare().
		if ( false !== $wpdb->query( $wpdb->prepare( $sql, $vals ) ) ) {
			$written += count( $ph );
		}
	}
	return $written;
}

/** Bucket an HTTP status code to its class column key (2xx..5xx); others ignored. */
function sn_edge_status_bucket( $status ) {
	$c = (int) floor( (int) $status / 100 );
	return ( $c >= 2 && $c <= 5 ) ? 'status_' . $c . 'xx' : '';
}

/**
 * Map 5xx rows onto the two dimensions worth storing (#1002).
 *
 * PURE, and separate from the rollup, because the rollup can only be exercised
 * through a stubbed database — the first version of this lived inline and every
 * assertion about it read zero rows, which looked like a broken feature and was
 * a test reading the wrong side of a DB stub.
 *
 *   err_path    which URLs failed
 *   err_source  WHO answered. `edge=503 origin=503` is the origin, or the cache
 *               in front of it, failing. `edge=503 origin=-` is Cloudflare or a
 *               Worker answering by itself. That distinction is the one datum
 *               eight external reproduction attempts could not produce.
 *
 * An absent origin status renders as `-`, never 0: it means nothing upstream
 * answered, and a 0 in a column of status codes reads as a status code.
 *
 * @since 13.96.3
 * @param array $rows GraphQL `errors` groups.
 * @return array<string,array<string,int>> dim => value => corrected requests.
 */
function sn_edge_errors_dims( array $rows ) {
	$out = array();
	foreach ( $rows as $g ) {
		$d    = is_array( $g['dimensions'] ?? null ) ? $g['dimensions'] : array();
		$path = (string) ( $d['clientRequestPath'] ?? '' );
		if ( '' === $path ) {
			continue;
		}
		$req   = function_exists( 'sn_edge_corrected' ) ? sn_edge_corrected( $g ) : (int) ( $g['count'] ?? 0 );
		$edge  = (int) ( $d['edgeResponseStatus'] ?? 0 );
		$orig  = isset( $d['originResponseStatus'] ) && (int) $d['originResponseStatus'] > 0
			? (string) (int) $d['originResponseStatus']
			: '-';
		$cache = (string) ( $d['cacheStatus'] ?? '' );
		$from  = (string) ( $d['requestSource'] ?? '' );

		$out['err_path'][ $path ] = ( $out['err_path'][ $path ] ?? 0 ) + $req;
		$src = ( '' !== $from ? 'src=' . $from . ' ' : '' ) . 'edge=' . $edge . ' origin=' . $orig . ( '' !== $cache ? ' cache=' . $cache : '' );
		$out['err_source'][ $src ] = ( $out['err_source'][ $src ] ?? 0 ) + $req;
	}

	return $out;
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

/**
 * 17.8.1 (#1002): the 5xx rows read back. sn_edge_errors_dims() has stored
 * which paths failed and who answered since 13.96.3, and nothing read them:
 * sn_edge_top_dim() was only ever asked for country, colo and threat. This
 * is the one reader the Edge panels and `cloudflare-status` share.
 *
 * @param int         $days  Window, in days, ending today (UTC).
 * @param string|null $today YYYY-MM-DD reference day; defaults to now, UTC.
 * @return array{from:string,to:string,total:int,paths:array,sources:array}
 */
function sn_edge_errors_reading( $days = 7, $today = null ) {
	$to   = null === $today ? gmdate( 'Y-m-d' ) : (string) $today;
	$from = gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - max( 0, (int) $days - 1 ) * DAY_IN_SECONDS );
	return sn_edge_errors_range( $from, $to );
}

/**
 * The same reading over an explicit inclusive [$from,$to] (YYYY-MM-DD), for a
 * panel that already has its own range.
 *
 * @param string $from First day.
 * @param string $to   Last day.
 * @return array{from:string,to:string,total:int,paths:array,sources:array,days:array,asked_by:array}
 */
function sn_edge_errors_range( $from, $to ) {
	$from    = (string) $from;
	$to      = (string) $to;
	$paths   = sn_edge_top_dim( 'err_path', $from, $to, 10 );
	$sources = array();
	foreach ( sn_edge_top_dim( 'err_source', $from, $to, 10 ) as $row ) {
		$row['label'] = sn_edge_error_source_label( $row['value'] );
		$sources[]    = $row;
	}
	// 18.2.0: one row per day, so the day a filter changed is visible instead
	// of averaged into the week, and the week's total is the full sum rather
	// than the top ten sources' share of it.
	$days     = function_exists( 'sn_edge_errors_days' ) ? sn_edge_errors_days( $from, $to ) : array();
	// 18.3.0: each day says whether it was read, so a 0 is never ambiguous.
	$days     = sn_edge_errors_days_annotate(
		$days,
		function_exists( 'get_option' ) ? (array) get_option( SN_EDGE_ERRORS_READ_OPT, array() ) : array(),
		gmdate( 'Y-m-d' )
	);
	$asked_by = sn_edge_errors_asked_by_totals( $days );
	return array(
		'from'        => $from,
		'to'          => $to,
		// 17.9.1: sampled rows before this day were counted twice over.
		'honest_from' => function_exists( 'get_option' ) ? (string) get_option( SN_EDGE_HONEST_FROM_OPT, '' ) : '',
		// 17.9.3: the errors query's last outcome; a non-empty error means the
		// window below was NOT read, which is not the same as no errors.
		'query'       => function_exists( 'get_option' ) ? get_option( SN_EDGE_ERRORS_QUERY_OPT, null ) : null,
		'total'       => $days ? (int) array_sum( array_column( $days, 'total' ) ) : (int) array_sum( array_column( $sources, 'requests' ) ),
		'paths'       => $paths,
		'sources'     => $sources,
		'days'        => $days,
		'asked_by'    => $asked_by,
	);
}

/**
 * Who ASKED for a stored 5xx (18.2.0), from the err_source value's `src=`
 * prefix: `visitor` (eyeball), `worker` (a Worker's subrequest), `other`
 * (any other Cloudflare request source) or `unrecorded`. Unrecorded means the
 * row was stored before the errors query carried requestSource (17.9.3), so
 * in a window spanning that change it is the pre-filter leftover, not a class
 * of traffic.
 *
 * @param string $value A stored err_source value.
 * @return string visitor|worker|other|unrecorded
 */
function sn_edge_error_asker( $value ) {
	if ( ! preg_match( '/^src=(\S+) /', (string) $value, $s ) ) {
		return 'unrecorded';
	}
	if ( 'eyeball' === $s[1] ) {
		return 'visitor';
	}
	return 0 === strpos( $s[1], 'edgeWorker' ) ? 'worker' : 'other';
}

/**
 * Shape err_source rows into one entry per day of [$from,$to] (18.2.0). PURE:
 * the reader below only fetches. Every day in the range is present, zero-
 * filled, so a day with no stored 5xx reads 0 instead of vanishing.
 *
 * @param array  $rows Rows of {day, value, requests}.
 * @param string $from First day, YYYY-MM-DD.
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array<int,array{day:string,total:int,visitor:int,worker:int,other:int,unrecorded:int}>
 */
function sn_edge_errors_days_shape( array $rows, $from, $to ) {
	$out   = array();
	$start = strtotime( (string) $from . ' UTC' );
	$end   = strtotime( (string) $to . ' UTC' );
	if ( false === $start || false === $end || $end < $start || ( $end - $start ) > 400 * DAY_IN_SECONDS ) {
		return array();
	}
	for ( $t = $start; $t <= $end; $t += DAY_IN_SECONDS ) {
		$day         = gmdate( 'Y-m-d', $t );
		$out[ $day ] = array( 'day' => $day, 'total' => 0, 'visitor' => 0, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 );
	}
	foreach ( $rows as $r ) {
		$day = (string) ( $r['day'] ?? '' );
		if ( ! isset( $out[ $day ] ) ) {
			continue;
		}
		$n                      = (int) ( $r['requests'] ?? 0 );
		$out[ $day ]['total']  += $n;
		$out[ $day ][ sn_edge_error_asker( (string) ( $r['value'] ?? '' ) ) ] += $n;
	}
	return array_values( $out );
}

/**
 * The window's asked-by totals, summed from the day rows (18.2.0).
 *
 * @param array $days Output of sn_edge_errors_days_shape().
 * @return array{visitor:int,worker:int,other:int,unrecorded:int}
 */
function sn_edge_errors_asked_by_totals( array $days ) {
	$out = array( 'visitor' => 0, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 );
	foreach ( $days as $d ) {
		foreach ( $out as $k => $v ) {
			$out[ $k ] = $v + (int) ( $d[ $k ] ?? 0 );
		}
	}
	return $out;
}

/**
 * Record that the errors query ran for $day (18.3.0): '' when it was read,
 * the refusal otherwise. SN_EDGE_ERRORS_QUERY_OPT keeps only the LAST run,
 * which cannot say whether an older day in the window was ever read; this
 * map can. Capped at the newest 30 days.
 *
 * @param string $day   YYYY-MM-DD the snapshot covers.
 * @param string $error '' on success, the query's refusal otherwise.
 * @return void
 */
function sn_edge_errors_mark_read( $day, $error ) {
	$map                    = (array) get_option( SN_EDGE_ERRORS_READ_OPT, array() );
	$map[ (string) $day ] = (string) $error;
	ksort( $map );
	update_option( SN_EDGE_ERRORS_READ_OPT, array_slice( $map, -30, null, true ), false );
}

/**
 * Stamp each day row with whether it was read (18.3.0). PURE.
 *
 *   read       the errors query ran for that day and answered
 *   failed     it ran and was refused: the day was NOT read, a 0 means nothing
 *   pending    no run has covered it yet (today, or yesterday before the daily
 *              rollup): a 0 means "not yet", never "clean"
 *   untracked  older than this bookkeeping (stored before 18.3.0)
 *
 * @param array  $days  Output of sn_edge_errors_days_shape().
 * @param array  $read  SN_EDGE_ERRORS_READ_OPT: day => '' or error.
 * @param string $today YYYY-MM-DD (UTC).
 * @return array The same rows, each with `read`.
 */
function sn_edge_errors_days_annotate( array $days, array $read, $today ) {
	$yesterday = gmdate( 'Y-m-d', strtotime( (string) $today . ' UTC' ) - DAY_IN_SECONDS );
	foreach ( $days as $i => $d ) {
		$day = (string) ( $d['day'] ?? '' );
		if ( array_key_exists( $day, $read ) ) {
			$days[ $i ]['read'] = '' === (string) $read[ $day ] ? 'read' : 'failed';
		} elseif ( $day >= $yesterday ) {
			$days[ $i ]['read'] = 'pending';
		} else {
			$days[ $i ]['read'] = 'untracked';
		}
	}
	return $days;
}

/**
 * Read the stored err_source rows per day and shape them (18.2.0).
 *
 * @param string $from First day, YYYY-MM-DD.
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array See sn_edge_errors_days_shape().
 */
function sn_edge_errors_days( $from, $to ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, value, SUM(requests) AS requests
		 FROM {$table} WHERE day >= %s AND day <= %s AND dim = %s
		 GROUP BY day, value",
		(string) $from,
		(string) $to,
		'err_source'
	), ARRAY_A );
	return sn_edge_errors_days_shape( (array) $rows, $from, $to );
}

/**
 * `edge=503 origin=503 cache=miss` in words. The distinction is the one
 * #1002 could not get from outside: an origin status means the origin (or
 * the cache in front of it) failed; `origin=-` means nothing upstream
 * answered, so Cloudflare or a Worker produced the error by itself.
 *
 * @param string $value A stored err_source value.
 * @return string
 */
function sn_edge_error_source_label( $value ) {
	$who = '';
	if ( preg_match( '/^src=(\S+) /', (string) $value, $s ) ) {
		$who   = ', ' . ( 'eyeball' === $s[1] ? __( 'asked by a visitor', 'signal-and-noise-tools' ) : ( 0 === strpos( $s[1], 'edgeWorker' ) ? __( 'asked by a Worker', 'signal-and-noise-tools' ) : sprintf( /* translators: %s: Cloudflare request source. */ __( 'asked by %s', 'signal-and-noise-tools' ), $s[1] ) ) );
		$value = substr( (string) $value, strlen( $s[0] ) );
	}
	if ( ! preg_match( '/^edge=(\d+) origin=(\d+|-)/', (string) $value, $m ) ) {
		return (string) $value . $who;
	}
	if ( '-' === $m[2] ) {
		/* translators: %s: HTTP status Cloudflare returned. */
		return sprintf( __( '%s from Cloudflare itself (the origin never answered)', 'signal-and-noise-tools' ), $m[1] ) . $who;
	}
	if ( $m[1] === $m[2] ) {
		/* translators: %s: HTTP status the origin returned. */
		return sprintf( __( '%s from the origin', 'signal-and-noise-tools' ), $m[2] ) . $who;
	}
	/* translators: 1: status Cloudflare returned, 2: status the origin returned. */
	return sprintf( __( '%1$s at the edge, origin said %2$s', 'signal-and-noise-tools' ), $m[1], $m[2] ) . $who;
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

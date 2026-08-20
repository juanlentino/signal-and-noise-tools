<?php
/**
 * Signal & Noise — dashboard read accessors over the path rollup table.
 *
 * Aggregation cuts the Analytics admin tab + the re-pointed dashboard widgets
 * need: top pages (views-weighted engagement), range totals (the stat cards),
 * and the per-day series (the trend strip). Kept separate from
 * inc/analytics-rollup.php (which owns the table + cron) to keep each file small.
 *
 * All read the durable wp_sn_analytics_daily table — never AE — so they never
 * block a render and return [] / zeros while the table is dormant (no creds).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Pure derive layer (Phase A spec §4) — zero WP calls, function_exists-guarded,
// so this require is safe both under the plugin loader (already loaded) and in
// the standalone CLI test harness (loads only this file).
require_once __DIR__ . '/analytics-derive.php';

// Backfill discontinuity marker (Phase A spec §8): set by the owner-run
// trailing-≤90d re-roll (Task 6) to the first day whose rows carry the exact
// v5 metrics. Read by the summary path so a mixed legacy range can say WHY its
// exact fields are null. Null (option unset) until the backfill has run.
const SN_ANALYTICS_EXACT_SINCE_OPT = 'sn_analytics_exact_metrics_since';

/**
 * Top pages over [$from,$to] for one class, with views-weighted scroll/time.
 *
 * @return array<int, array{path:string, views:int, visits:int, scroll_avg:float, time_avg:float}>
 */
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$limit = max( 1, min( 500, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	// A trailing slash is a spelling, not a page: group by the CANONICAL path
	// so the ORDER BY and LIMIT below rank and truncate the merged figure.
	$path_expr = sn_analytics_canonical_path_sql( 'path' );

	$results = $wpdb->get_results( $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $path_expr is a constant expression over a literal column name; no input reaches it.
		"SELECT {$path_expr} AS path,
		        SUM(views)  AS views,
		        SUM(visits) AS visits,
		        SUM(scroll_avg * views) / NULLIF(SUM(views), 0) AS scroll_avg,
		        SUM(time_avg  * views) / NULLIF(SUM(views), 0) AS time_avg
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY {$path_expr}
		 ORDER BY views DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		$class,
		$limit
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'path'       => (string) $r['path'],
				'views'      => (int) $r['views'],
				'visits'     => (int) $r['visits'],
				'scroll_avg' => (float) $r['scroll_avg'],
				'time_avg'   => (float) $r['time_avg'],
			);
		}
	}
	return $out;
}

/**
 * Range totals for the stat cards + the get-analytics-summary ability: summed
 * views/visits + views-weighted scroll/time across all paths for one class,
 * MERGED (Phase A spec §4) with the honest derived vocabulary — every
 * sn_analytics_derive_metrics() field plus `exact_metrics_since` — beside the
 * kept-deprecated legacy quartet (views/visits/scroll_avg/time_avg, untouched).
 *
 * Range aggregation is honest by construction: views, the four engagement
 * sums/counts, `pageview_visits`, and `visits` (≡ unique visitor-DAYS) are all
 * per-day additive units, so daily rows SUM across the range and the derive
 * layer runs ONCE on the totals. Mixed-range rule: if ANY row in range lacks
 * the v5 sums (legacy, pre-backfill), the exact engagement + gated fields are
 * null for the whole range — SQL SUM() skips NULLs, and an honest null beats a
 * silently partial denominator; `exact_metrics_since` says why. An EMPTY range
 * is an ANSWER (zero traffic): real 0 counts, null ratios (never invent a rate
 * from nothing). Day-boundary parity: this layer adds NO date math — it
 * filters the stored `day` keys (rolled by the site-local-else-UTC boundary
 * the rollup used) as inclusive Y-m-d strings, so it can never disagree with
 * the rollup's boundary; callers own computing [$from,$to] in that same zone.
 *
 * Request-scope memo (D5 §5 perf): the header region
 * (inc/analytics-header-region.php:38), the insights band
 * (inc/analytics-insights.php:75), and the Dashboard-home widget all pull the
 * SAME [$from,$to,$class] window once per page load (audit E§3.8) — cache it
 * per request so that costs one read, not three. $refresh is the re-prime
 * seam (the D2 sn_analytics_recommendations( true ) idiom) for callers that
 * must force a fresh read within the same request (e.g. CLI/tests).
 *
 * @param bool $refresh Bypass and re-prime the memo for this key.
 * @return array{
 *     views:int, visits:int, scroll_avg:float, time_avg:float,
 *     unique_visitor_days:int|null, pageview_visits:int|null,
 *     viewless_visits:int|null, view_visit_ratio:float|null,
 *     pageviews_per_visitor_day:float|null,
 *     scroll_avg_per_view:float|null, time_avg_per_view:float|null,
 *     scroll_avg_per_visit:float|null, time_avg_per_visit:float|null,
 *     integrity_violation:bool, exact_metrics_since:string|null
 * }
 */
function sn_analytics_range_totals( $from, $to, $class = 'human', $refresh = false ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}

	static $memo = array();
	$key = $from . '|' . $to . '|' . $class;
	if ( ! $refresh && isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	// COUNT(col) counts non-null rows only — exact_rows/gated_rows vs
	// COUNT(*) is how the mixed-range rule detects legacy (pre-v5) rows.
	$row = $wpdb->get_results( $wpdb->prepare(
		"SELECT SUM(views)  AS views,
		        SUM(visits) AS visits,
		        SUM(scroll_avg * views) / NULLIF(SUM(views), 0) AS scroll_avg,
		        SUM(time_avg  * views) / NULLIF(SUM(views), 0) AS time_avg,
		        SUM(scroll_sum)        AS scroll_sum,
		        SUM(scroll_events)     AS scroll_events,
		        SUM(time_sum)          AS time_sum,
		        SUM(time_events)       AS time_events,
		        SUM(pageview_visits)   AS pageview_visits,
		        COUNT(*)               AS row_count,
		        COUNT(scroll_sum)      AS exact_rows,
		        COUNT(pageview_visits) AS gated_rows
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s",
		(string) $from,
		(string) $to,
		$class
	), ARRAY_A );

	// Transport failure is NOT an answer (the realtime-zero-vs-null rule, read
	// side): a FAILED $wpdb read leaves last_error set and an EMPTY result —
	// indistinguishable from a real zero-traffic range without this check. On
	// failure the NEW honest fields must all read null ("never measured"),
	// never fabricated measured zeros; the legacy quartet keeps its
	// long-standing zero shape (back-compat, deliberately unaltered). isset()
	// keeps sibling test harnesses with minimal wpdb stubs warning-free; the
	// real wpdb always declares last_error (reset per query).
	$read_failed = isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error;
	if ( $read_failed ) {
		error_log( sprintf(
			'[sn-analytics] range totals read failed for %s..%s class %s (%s) serving null derived fields (a transport failure is NOT an answer)',
			(string) $from,
			(string) $to,
			$class,
			(string) $wpdb->last_error
		) );
	}

	$r = ( ! $read_failed && is_array( $row ) && isset( $row[0] ) && is_array( $row[0] ) ) ? $row[0] : array();

	// Kept-deprecated legacy quartet — semantics UNALTERED (spec §4: nothing
	// removed, nothing silently redefined). `?? 0` is correct HERE: these map
	// NOT NULL columns, so a SQL NULL only ever means "zero rows in range" —
	// and on a failed read the quartet has ALWAYS read zeros (kept as-is).
	$legacy = array(
		'views'      => (int) ( $r['views'] ?? 0 ),
		'visits'     => (int) ( $r['visits'] ?? 0 ),
		'scroll_avg' => (float) ( $r['scroll_avg'] ?? 0 ),
		'time_avg'   => (float) ( $r['time_avg'] ?? 0 ),
	);

	$input   = sn_analytics_range_derive_input( $r, $legacy['views'], $legacy['visits'], $read_failed );
	$derived = sn_analytics_derive_metrics( $input );
	if ( ! $read_failed ) {
		// Guard skipped on a failed read: with every input unknown there is no
		// verdict to check and no payload worth recording (no alert churn).
		sn_analytics_read_integrity_guard( $from, $to, $class, $input, $derived );
	}

	$memo[ $key ] = array_merge( $legacy, $derived, array(
		'exact_metrics_since' => sn_analytics_exact_metrics_since(),
	) );
	return $memo[ $key ];
}

/**
 * Build the sn_analytics_derive_metrics() input from the extended range-totals
 * row — the null-discipline gate for range aggregation.
 *
 *   - Zero rows: an empty range is an ANSWER (zero traffic) — every count is a
 *     REAL 0; the derive layer nulls the ratios (÷0). Never null a real 0.
 *   - Mixed range (any row with NULL scroll_sum — legacy, pre-backfill): the
 *     exact engagement + gated fields are null for the WHOLE range. SQL SUM()
 *     skips NULLs, so the transported sums exist but are silently PARTIAL —
 *     honest null beats a partial denominator. Never 0 a null.
 *   - Gated-partial (engagement sums complete but some row's pageview_visits
 *     is NULL — a gated-query-failed day): same rule, per family — the gated
 *     fields null while exact engagement survives.
 *   - FAILED read ($read_failed): a transport failure is NOT an answer — every
 *     input is returned ABSENT (including views/visits, so the new vocabulary
 *     never launders the legacy back-compat zeros into confident
 *     unique_visitor_days/ratio values the read never measured) and the derive
 *     layer nulls every derived field. Never the zero-rows branch's real 0s.
 *
 * @param array $r           Extended totals row (may be empty on a failed read).
 * @param int   $views       Coerced legacy views total.
 * @param int   $visits      Coerced legacy visits total (≡ unique visitor-days).
 * @param bool  $read_failed The $wpdb read errored (last_error was set).
 * @return array Derive-layer input (rollup column spellings).
 */
function sn_analytics_range_derive_input( $r, $views, $visits, $read_failed = false ) {
	if ( $read_failed ) {
		return array(); // every key absent ≡ "never measured" → all-null derive.
	}

	$rows  = (int) ( $r['row_count'] ?? 0 );
	$exact = (int) ( $r['exact_rows'] ?? 0 );
	$gated = (int) ( $r['gated_rows'] ?? 0 );

	$input = array(
		'views'  => $views,
		'visits' => $visits,
	);

	if ( 0 === $rows ) {
		return array_merge( $input, array(
			'pageview_visits' => 0,
			'scroll_sum'      => 0.0,
			'scroll_events'   => 0,
			'time_sum'        => 0.0,
			'time_events'     => 0,
		) );
	}

	$all_exact = ( $exact === $rows );
	$all_gated = ( $gated === $rows );

	// The transported sums pass through raw (wpdb numeric strings are fine —
	// the derive layer normalizes); the gates above decide null vs value.
	return array_merge( $input, array(
		'scroll_sum'      => $all_exact ? ( $r['scroll_sum'] ?? null ) : null,
		'scroll_events'   => $all_exact ? ( $r['scroll_events'] ?? null ) : null,
		'time_sum'        => $all_exact ? ( $r['time_sum'] ?? null ) : null,
		'time_events'     => $all_exact ? ( $r['time_events'] ?? null ) : null,
		'pageview_visits' => ( $all_exact && $all_gated ) ? ( $r['pageview_visits'] ?? null ) : null,
	) );
}

/**
 * Read-side defensive integrity guard (Phase A spec §5) — mirrors the rollup
 * guard: a human range with views < pageview_visits (both known) is
 * arithmetically impossible, so surface it via error_log + the SAME
 * sn_analytics_integrity_alert option, surfaced since v9.65.0 by the
 * Content-Health scan's analytics_integrity check
 * (inc/health-analytics-integrity.php; before that the option had NO reader) —
 * and still serve the values UN-clamped. The alarm is the feature. Idempotent:
 * the same violation (timestamp aside) never churns the option on repeat reads.
 *
 * @param string $from    Range start (Y-m-d).
 * @param string $to      Range end (Y-m-d).
 * @param string $class   Traffic class (side effect fires for 'human' only,
 *                        matching the rollup guard's gate; the response still
 *                        REPORTS integrity_violation honestly for any class).
 * @param array  $input   Derive input (the range totals fed to the verdict).
 * @param array  $derived Derive output (carries integrity_violation).
 */
function sn_analytics_read_integrity_guard( $from, $to, $class, $input, $derived ) {
	if ( 'human' !== $class || true !== ( $derived['integrity_violation'] ?? false ) ) {
		return;
	}

	// The option constant lives in inc/analytics-rollup.php (loaded before this
	// file in production); the fallback literal keeps this module loadable
	// standalone in the CLI test harness.
	$opt     = defined( 'SN_ANALYTICS_INTEGRITY_ALERT_OPT' ) ? SN_ANALYTICS_INTEGRITY_ALERT_OPT : 'sn_analytics_integrity_alert';
	$payload = array(
		'time'            => time(),
		'scope'           => 'read-range',
		'from'            => (string) $from,
		'to'              => (string) $to,
		'class'           => (string) $class,
		'views'           => (int) $input['views'],
		'pageview_visits' => (int) $input['pageview_visits'],
	);

	$existing = get_option( $opt );
	if ( is_array( $existing ) ) {
		$prev = $existing;
		$next = $payload;
		unset( $prev['time'], $next['time'] );
		ksort( $prev );
		ksort( $next );
		if ( $prev === $next ) {
			return; // same violation already recorded — don't churn the option.
		}
	}

	error_log( sprintf(
		'[sn-analytics] integrity violation: views < pageview_visits for range %s..%s class %s (%d < %d): read-side defensive guard, values served unmodified',
		$payload['from'],
		$payload['to'],
		$payload['class'],
		$payload['views'],
		$payload['pageview_visits']
	) );
	update_option( $opt, $payload, false );
}

/**
 * The exact-metrics discontinuity date (Phase A spec §8), or null.
 *
 * Null means "the trailing-≤90d backfill has not run" — callers must render
 * that as unknown, never as a fabricated date. A malformed option value is
 * treated the same (fail toward honesty).
 *
 * @return string|null Y-m-d, or null when unset/malformed.
 */
function sn_analytics_exact_metrics_since() {
	$since = get_option( SN_ANALYTICS_EXACT_SINCE_OPT );
	return ( is_string( $since ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) ? $since : null;
}

/**
 * Pick a time-bucket granularity for a window length.
 * Ranges ≤90d render daily; longer ranges roll up to ISO weeks so the trend
 * strip stays legible (≤~52 bars/year) and fast.
 *
 * @param int $days
 * @return string 'day' | 'week'
 */
function sn_analytics_granularity( $days ) {
	return ( (int) $days > 90 ) ? 'week' : 'day';
}

/**
 * SQL expression that maps a `day` DATE to its bucket key.
 * Week granularity floors to the ISO Monday (WEEKDAY: Mon=0). The returned
 * fragment is a fixed literal — never user data — so it is safe to interpolate.
 *
 * @param string $granularity 'day' | 'week'
 * @return string
 */
function sn_analytics_bucket_expr( $granularity ) {
	return ( 'week' === $granularity )
		? 'DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)'
		: 'day';
}

/**
 * Per-day (or per-week) views/visits for one class, ascending — the trend strip.
 *
 * Request-scope memo (D5 §5 perf): sn_analytics_signal_anomalies() and
 * sn_analytics_signal_forecasts() (inc/analytics-signals.php) both pull the
 * SAME [$from,$to,$class,'day'] trailing-baseline window once per page load
 * (QM: 2 reads per page) — cache it per request so that costs one read, not
 * two. $refresh is the re-prime seam (the D2 sn_analytics_recommendations( true )
 * idiom, mirrored in sn_analytics_range_totals()) for callers that must force
 * a fresh read within the same request (e.g. CLI/tests).
 *
 * @param string $granularity 'day' | 'week'  Week granularity floors each day
 *                             to the ISO Monday (DATE_SUB … WEEKDAY) so weekly
 *                             bars line up naturally. Use sn_analytics_granularity()
 *                             to pick the right value for a given date range.
 * @param bool   $refresh     Bypass and re-prime the memo for this key.
 * @return array<int, array{day:string, views:int, visits:int}>
 */
function sn_analytics_daily_series( $from, $to, $class = 'human', $granularity = 'day', $refresh = false ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}

	static $memo = array();
	$key = $from . '|' . $to . '|' . $class . '|' . $granularity;
	if ( ! $refresh && isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}

	$expr = sn_analytics_bucket_expr( $granularity );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $expr is a hardcoded SQL fragment ("day" or a fixed DATE_SUB() expression) from sn_analytics_bucket_expr(); $table is $wpdb->prefix + a plugin constant; every user value is bound via prepare().
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT {$expr} AS day, SUM(views) AS views, SUM(visits) AS visits
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY {$expr}
		 ORDER BY day ASC",
		(string) $from,
		(string) $to,
		$class
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'day'    => (string) $r['day'],
				'views'  => (int) $r['views'],
				'visits' => (int) $r['visits'],
			);
		}
	}
	$memo[ $key ] = $out;
	return $memo[ $key ];
}

/**
 * Per-bucket view series for a set of dimension values, in ONE batched query
 * (avoids an N+1 across table rows). Returns value => [{day,views}, …].
 *
 * Contract (v9.68.1): null = the read FAILED ($wpdb->last_error set); [] = no
 * series (including an empty $values set, which issues no query at all).
 *
 * @param string   $dim
 * @param string[] $values      already-trusted top-N dimension values
 * @param string   $granularity 'day' | 'week'
 * @return array<string, array<int, array{day:string, views:int}>>|null
 */
function sn_analytics_dimension_series( $dim, $values, $from, $to, $class = 'human', $granularity = 'day' ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$values = array_values( array_filter( (array) $values, 'is_string' ) );
	if ( empty( $values ) ) {
		return array();
	}
	$expr = sn_analytics_bucket_expr( $granularity );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DIMS_TABLE;

	$in_ph   = implode( ',', array_fill( 0, count( $values ), '%s' ) );
	$args    = array_merge( array( (string) $from, (string) $to, $dim, $class ), $values );
	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $expr is a hardcoded SQL fragment ("day" or a fixed DATE_SUB() expression) from sn_analytics_bucket_expr(); $table is $wpdb->prefix + a plugin constant; every user value is bound via prepare().
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT {$expr} AS day, value, SUM(views) AS views
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND dim = %s AND class = %s AND value IN ({$in_ph})
		 GROUP BY {$expr}, value
		 ORDER BY day ASC",
		$args
	), ARRAY_A );

	// v9.68.1 failure honesty: a FAILED query is [] + $wpdb->last_error set
	// (flush-per-query, so it reflects THIS read) — never an empty series map.
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

const SN_ANALYTICS_LOWENGAGE_SCROLL    = 25;     // page-weighted scroll % below this …
const SN_ANALYTICS_LOWENGAGE_TIME_MS   = 10000;  // … AND time below this (ms) …
const SN_ANALYTICS_LOWENGAGE_MIN_VIEWS = 20;     // … on pages with at least this many views.

/**
 * Pages with real traffic but weak engagement (low scroll AND low dwell) —
 * "pages losing readers". Page-weighted averages match range_totals/top_paths.
 *
 * @return array<int, array{path:string, views:int, scroll_avg:float, time_avg:float}>
 */
function sn_analytics_low_engagement_paths( $from, $to, $class = 'human', $limit = 15 ) {
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}
	$limit = max( 1, min( 200, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	// Same rule as top_paths: the HAVING thresholds and the LIMIT must see one
	// page, not two spellings each carrying half its views — a split page can
	// fall under the min-views floor that neither half alone clears.
	$path_expr = sn_analytics_canonical_path_sql( 'path' );

	$results = $wpdb->get_results( $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $path_expr is a constant expression over a literal column name; no input reaches it.
		"SELECT {$path_expr} AS path,
		        SUM(views) AS views,
		        SUM(scroll_avg * views) / NULLIF(SUM(views), 0) AS scroll_avg,
		        SUM(time_avg  * views) / NULLIF(SUM(views), 0) AS time_avg
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 GROUP BY {$path_expr}
		 HAVING views >= %d
		        AND scroll_avg < %d
		        AND time_avg  < %d
		 ORDER BY views DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		$class,
		SN_ANALYTICS_LOWENGAGE_MIN_VIEWS,
		SN_ANALYTICS_LOWENGAGE_SCROLL,
		SN_ANALYTICS_LOWENGAGE_TIME_MS,
		$limit
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'path'       => (string) $r['path'],
				'views'      => (int) $r['views'],
				'scroll_avg' => (float) $r['scroll_avg'],
				'time_avg'   => (float) $r['time_avg'],
			);
		}
	}
	return $out;
}

/**
 * Earliest day present in the durable rollup — the lower bound for the
 * "All-time" range. Cached for an hour (the table only grows by one day/run).
 *
 * @return string Y-m-d (today if the table is empty).
 */
function sn_analytics_min_day() {
	$cached = get_transient( 'sn_analytics_min_day' );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}
	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	$min   = $wpdb->get_var( "SELECT MIN(day) FROM {$table}" );
	$min   = ( is_string( $min ) && '' !== $min ) ? $min : gmdate( 'Y-m-d' );
	set_transient( 'sn_analytics_min_day', $min, HOUR_IN_SECONDS );
	return $min;
}

/**
 * Per-bucket bot share over the window (durable — no AE). Sums views across all
 * classes per bucket and exposes bot_pct for the Quality-tab trend line.
 *
 * @param string $from        YYYY-MM-DD start of window (inclusive).
 * @param string $to          YYYY-MM-DD end of window (inclusive).
 * @param string $granularity 'day' | 'week'
 * @return array<int, array{day:string, total:int, bot:int, bot_pct:int}>
 */
function sn_analytics_class_series( $from, $to, $granularity = 'day' ) {
	$expr = sn_analytics_bucket_expr( $granularity );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $expr is a hardcoded SQL fragment ("day" or a fixed DATE_SUB() expression) from sn_analytics_bucket_expr(); $table is $wpdb->prefix + a plugin constant; every user value is bound via prepare().
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT {$expr} AS day, class, SUM(views) AS views
		 FROM {$table}
		 WHERE day >= %s AND day <= %s
		 GROUP BY {$expr}, class
		 ORDER BY day ASC",
		(string) $from,
		(string) $to
	), ARRAY_A );

	$acc = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$d = (string) $r['day'];
			if ( ! isset( $acc[ $d ] ) ) {
				$acc[ $d ] = array( 'day' => $d, 'total' => 0, 'bot' => 0 );
			}
			$v                  = (int) $r['views'];
			$acc[ $d ]['total'] += $v;
			if ( 'bot' === (string) $r['class'] ) {
				$acc[ $d ]['bot'] += $v;
			}
		}
	}
	$out = array();
	foreach ( $acc as $row ) {
		$row['bot_pct'] = ( $row['total'] > 0 ) ? (int) round( $row['bot'] / $row['total'] * 100 ) : 0;
		$out[]          = $row;
	}
	return $out;
}

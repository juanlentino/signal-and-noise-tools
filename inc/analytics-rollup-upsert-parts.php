<?php
/**
 * Signal & Noise Tools: Analytics Rollup, the upsert into the daily table.
 *
 * Split from inc/analytics-rollup.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UPSERT AE result rows into the daily table.
 *
 * Validates + normalizes each row (a YYYY-MM-DD `day` and a non-empty `path`
 * are required; malformed rows are skipped, not written), then issues a single
 * batched INSERT ... ON DUPLICATE KEY UPDATE per chunk of 100. Re-running over
 * the same (day, path) overwrites the aggregate — exactly what a recomputed
 * partial day needs.
 *
 * @param array $rows AE rows keyed by the SELECT aliases (day/path/views/...).
 * @return int Number of rows written.
 */
function sn_analytics_rollup_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}

	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day   = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$path  = isset( $r['path'] ) ? (string) $r['path'] : '';
		$class = isset( $r['class'] ) && '' !== (string) $r['class'] ? (string) $r['class'] : 'human';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || '' === $path ) {
			continue;
		}
		if ( sn_analytics_is_excluded_path( $path ) ) {
			continue; // admin/login paths are never human pageviews — ingestion guard
		}
		if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
			continue; // defensive: never store an unexpected class
		}

		// The five v5 nullable columns: absent ≡ null ≡ "never measured" (kept
		// null through to a literal SQL NULL bind), read via array_key_exists —
		// NEVER the legacy `?? 0`, which would fabricate a measurement. A real
		// transported 0 (incl. AE's UInt64-as-string "0") stays a real 0.
		// $scroll_sum_raw is the TRANSPORTED milestone-point sum (a full-depth
		// view contributes 25+50+75+100 = 250): since v9.66.0 it feeds ONLY the
		// weighted legacy scroll_avg — the STORED scroll_sum column is re-based
		// below to the depth identity 25 × scroll_events (the sum of per-view
		// max depths; see inc/analytics-derive.php's module header).
		$scroll_sum_raw  = sn_analytics_rollup_nullable_num( $r, 'scroll_sum' );
		$scroll_events   = sn_analytics_rollup_nullable_num( $r, 'scroll_events' );
		$time_sum        = sn_analytics_rollup_nullable_num( $r, 'time_sum' );
		$time_events     = sn_analytics_rollup_nullable_num( $r, 'time_events' );
		$pageview_visits = sn_analytics_rollup_nullable_num( $r, 'pageview_visits' );

		// Normalized event count first: the stored scroll_sum derives from THIS
		// value so the 25×scroll_events invariant holds exactly row-by-row.
		// Unknown events ⇒ unknown identity ⇒ NULL — a present raw sum alone is
		// NEVER stored (it would reintroduce the shipped-113% milestone-point
		// unit). The step constant lives in inc/analytics-derive.php (loaded in
		// production; the literal fallback keeps this module standalone-testable).
		$scroll_events_n = null === $scroll_events ? null : max( 0, (int) round( (float) $scroll_events ) );
		$scroll_step     = defined( 'SN_ANALYTICS_SCROLL_MILESTONE_STEP' ) ? SN_ANALYTICS_SCROLL_MILESTONE_STEP : 25;

		$c = array(
			'day'             => $day,
			'path'            => substr( $path, 0, 180 ),
			'class'           => $class,
			'views'           => max( 0, (int) round( (float) ( $r['views'] ?? 0 ) ) ),
			'visits'          => max( 0, (int) round( (float) ( $r['visits'] ?? 0 ) ) ),
			// Legacy avgs switch to the weighted ratio sum/events when both are
			// known (identical to avgIf at sample interval 1, correct under
			// sampling); legacy passthrough otherwise. scroll_avg deliberately
			// weights the RAW milestone-point sum — its per-event-mean semantics
			// are unchanged by the v6 re-base. See the helper for the 0-events
			// (division-by-zero) rule.
			'scroll_avg'      => sn_analytics_rollup_legacy_avg( $scroll_sum_raw, $scroll_events, $r['scroll_avg'] ?? 0 ),
			'time_avg'        => sn_analytics_rollup_legacy_avg( $time_sum, $time_events, $r['time_avg'] ?? 0 ),
			// v9.66.0 (schema v6): stored in TRUE depth units — 25 × scroll_events
			// (the exact sum of per-view max depths) or NULL, never the raw
			// milestone-point sum. time_sum is genuinely event-summed ms — untouched.
			'scroll_sum'      => null === $scroll_events_n ? null : (float) ( $scroll_step * $scroll_events_n ),
			'scroll_events'   => $scroll_events_n,
			'time_sum'        => null === $time_sum ? null : max( 0.0, (float) $time_sum ),
			'time_events'     => null === $time_events ? null : max( 0, (int) round( (float) $time_events ) ),
			'pageview_visits' => null === $pageview_visits ? null : max( 0, (int) round( (float) $pageview_visits ) ),
		);

		// Never-invert guard (spec §5, human class): views ≥ pageview_visits
		// holds by construction, so an inversion is a genuine rollup/sampling
		// bug. Surface it — error_log + a timestamped option the Health scan
		// reads — and STILL write the row un-clamped. The alarm is the feature;
		// a clamp or skip would silently serve corrupt arithmetic as clean.
		if ( 'human' === $c['class'] && null !== $c['pageview_visits'] && $c['views'] < $c['pageview_visits'] ) {
			error_log( sprintf(
				'[sn-analytics] integrity violation: views < pageview_visits for %s %s (%d < %d): row written unmodified',
				$c['day'],
				$c['path'],
				$c['views'],
				$c['pageview_visits']
			) );
			update_option( SN_ANALYTICS_INTEGRITY_ALERT_OPT, array(
				'time'            => time(),
				'day'             => $c['day'],
				'path'            => $c['path'],
				'class'           => $c['class'],
				'views'           => $c['views'],
				'pageview_visits' => $c['pageview_visits'],
			), false );
		}

		$clean[] = $c;
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	$written = 0;

	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			// FLOAT columns bind as %s carrying a number_format()'d string,
			// NOT %f. %f routes through $wpdb->prepare()'s vsprintf(), which honours
			// LC_NUMERIC — under a comma-decimal server locale (de_DE, pt_BR, …) it
			// would emit "1,50" and corrupt the SQL. number_format( …, '.', '' )
			// forces a '.' decimal and empty thousands separator regardless of
			// locale, and MySQL coerces the quoted numeric string into the FLOAT column.
			$tuple = '(%s, %s, %s, %d, %d, %s, %s';
			array_push(
				$values,
				$c['day'],
				$c['path'],
				$c['class'],
				$c['views'],
				$c['visits'],
				number_format( (float) $c['scroll_avg'], 2, '.', '' ),
				number_format( (float) $c['time_avg'], 2, '.', '' )
			);
			// The five v5 nullable columns: null binds a LITERAL SQL NULL (no
			// placeholder, no value) so "never measured" survives the write;
			// known values bind %s (4dp dot-decimal, same locale rule) for the
			// FLOAT sums and %d for the INT counts.
			foreach ( array(
				'scroll_sum'      => 'float',
				'scroll_events'   => 'int',
				'time_sum'        => 'float',
				'time_events'     => 'int',
				'pageview_visits' => 'int',
			) as $col => $type ) {
				if ( null === $c[ $col ] ) {
					$tuple .= ', NULL';
				} elseif ( 'float' === $type ) {
					$tuple   .= ', %s';
					$values[] = number_format( (float) $c[ $col ], 4, '.', '' );
				} else {
					$tuple   .= ', %d';
					$values[] = (int) $c[ $col ];
				}
			}
			$placeholders[] = $tuple . ')';
		}
		$sql = "INSERT INTO {$table} (day, path, class, views, visits, scroll_avg, time_avg, scroll_sum, scroll_events, time_sum, time_events, pageview_visits) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE views=VALUES(views), visits=VALUES(visits), scroll_avg=VALUES(scroll_avg), time_avg=VALUES(time_avg),'
			. ' scroll_sum=VALUES(scroll_sum), scroll_events=VALUES(scroll_events), time_sum=VALUES(time_sum), time_events=VALUES(time_events), pageview_visits=VALUES(pageview_visits)';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL -- $sql is a static INSERT ... VALUES template with a generated %s/%d placeholder group per row; $table is $wpdb->prefix + a plugin constant and every value is bound via prepare().
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false === $result && function_exists( 'sn_analytics_rollup_chunk_failed' ) ) {
			sn_analytics_rollup_chunk_failed( true ); // any failed chunk rolls back a day replace.
		}
		if ( false !== $result ) {
			$written += count( $chunk );
		}
	}

	return $written;
}


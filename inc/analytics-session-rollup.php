<?php
/**
 * Signal & Noise — durable per-day visit-quality rollup (v8.8.0).
 *
 * A nightly WP-Cron snapshot of within-day visit quality (visits, bounce %,
 * pages/visit, median duration) per traffic class, for long-term trend lines
 * beyond AE's ~90-day raw retention. Funnels/paths are NOT rolled up (they need
 * event-level detail) — they stay on the live raw window.
 *
 * @package SignalNoiseTools
 * @since 8.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SESSION_ROLLUP_TABLE          = 'sn_session_daily';
const SN_SESSION_ROLLUP_DB_VERSION     = '1';
const SN_SESSION_ROLLUP_DB_VERSION_OPT = 'sn_session_daily_db_version';
const SN_SESSION_ROLLUP_HOOK           = 'sn_session_rollup_daily';

/**
 * CREATE TABLE for the daily visit-quality rollup.
 *
 * @return string
 */
function sn_session_rollup_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_SESSION_ROLLUP_TABLE;
	$charset = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		class VARCHAR(10) NOT NULL DEFAULT 'human',
		visits INT UNSIGNED NOT NULL DEFAULT 0,
		bounce_pct FLOAT NOT NULL DEFAULT 0,
		ppv FLOAT NOT NULL DEFAULT 0,
		median_dur INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_class (day, class)
	) {$charset};";
}

/**
 * Install/upgrade the table on version change.
 */
function sn_session_rollup_maybe_install() {
	if ( get_option( SN_SESSION_ROLLUP_DB_VERSION_OPT ) === SN_SESSION_ROLLUP_DB_VERSION ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( sn_session_rollup_schema_sql() );
	update_option( SN_SESSION_ROLLUP_DB_VERSION_OPT, SN_SESSION_ROLLUP_DB_VERSION );
}
add_action( 'init', 'sn_session_rollup_maybe_install' );

/**
 * Schedule the daily rollup cron.
 */
function sn_session_rollup_schedule() {
	if ( ! wp_next_scheduled( SN_SESSION_ROLLUP_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SN_SESSION_ROLLUP_HOOK );
	}
}
add_action( 'init', 'sn_session_rollup_schedule' );

/**
 * Normalize AE-derived rollup rows into typed, validated records.
 *
 * @param array $rows Rows with keys day, class, visits, bounce_pct, ppv, median_dur.
 * @return array Clean records ready to upsert.
 */
function sn_session_rollup_normalize( $rows ) {
	$allowed = defined( 'SN_ANALYTICS_CLASSES' ) ? SN_ANALYTICS_CLASSES : array( 'human', 'suspect', 'bot' );
	$clean   = array();
	foreach ( (array) $rows as $r ) {
		$day   = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$class = isset( $r['class'] ) && '' !== (string) $r['class'] ? (string) $r['class'] : 'human';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! in_array( $class, $allowed, true ) ) {
			continue;
		}
		$clean[] = array(
			'day'        => $day,
			'class'      => $class,
			'visits'     => max( 0, (int) round( (float) ( $r['visits'] ?? 0 ) ) ),
			'bounce_pct' => round( (float) ( $r['bounce_pct'] ?? 0 ), 2 ),
			'ppv'        => round( (float) ( $r['ppv'] ?? 0 ), 2 ),
			'median_dur' => max( 0, (int) round( (float) ( $r['median_dur'] ?? 0 ) ) ),
		);
	}
	return $clean;
}

/**
 * Derive durable exit-page rows from pv-gated visit summaries (v9.66.0).
 *
 * The bridge the exit half of inc/analytics-pageroles.php waited for since
 * v6.10.0: role='exit' had NO live source (true live exit needs the session
 * model — which has existed since v8.8.0 and computes every visit's exit in
 * sn_visit_summary()). Each visit ends on exactly ONE page (its last
 * pageview), so a path's nightly exit count is simultaneously its exit
 * pageviews and its exiting visits: views == visits == count. Note the unit:
 * "visits" here are gap-split within-day SESSIONS (the engine's unit), not
 * the distinct visitor-days the entry feed counts — summaries carry no
 * visitor hash, and for exits the session is the honest unit anyway.
 *
 * Day-key convention (resolved, not invented): the session engine buckets at
 * UTC midnight (the caller passes gmdate('Y-m-d')), and the pageroles table's
 * OWN live feed — the entry rollup, sn_analytics_pageroles_rollup_sql() —
 * also buckets at UTC midnight (toStartOfDay(timestamp) with NO timezone
 * arg; unlike wp_sn_analytics_daily, pageroles never migrated to the
 * site-local day). Engine day == pageroles day, so the caller's $day passes
 * straight through — no conversion, no third convention.
 *
 * Pure (no WP calls): blank exits (pageview-less groups — sn_visit_summary()
 * returns '' when a group has no pageview) and malformed summaries are
 * skipped. No summaries → no rows → the caller writes NOTHING for the day
 * (an absent day is "not measured", never a fabricated zero-row).
 *
 * @since 9.66.0
 * @param array  $summaries Pv-gated visit summaries (sn_pageview_visits() output).
 * @param string $day       UTC day key (Y-m-d) — the engine's bucket.
 * @return array<int, array{day:string, role:string, path:string, views:int, visits:int}>
 */
function sn_session_exit_page_rows( array $summaries, $day ) {
	$counts = array();
	foreach ( $summaries as $s ) {
		$exit = is_array( $s ) ? trim( (string) ( $s['exit'] ?? '' ) ) : '';
		if ( '' === $exit ) {
			continue; // pageview-less group or malformed summary — never an exit.
		}
		$counts[ $exit ] = (int) ( $counts[ $exit ] ?? 0 ) + 1;
	}

	$rows = array();
	foreach ( $counts as $path => $n ) {
		$rows[] = array(
			'day'    => (string) $day,
			'role'   => 'exit',
			'path'   => (string) $path,
			'views'  => $n,
			'visits' => $n,
		);
	}
	return $rows;
}

/**
 * Compute yesterday's per-class visit-quality and upsert it.
 *
 * v9.66.0: the human-class pass ALSO bridges exit pages into the durable
 * pageroles table (see sn_session_exit_page_rows) — human-only because
 * pageroles has no class column (entry/exit are human-only by design,
 * consistent with the entry feed and the Plausible history). Path truncation
 * (190) and 100-row chunking are the upsert's job (sn_analytics_pageroles_upsert).
 */
function sn_session_rollup_run() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return;
	}
	$day     = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	$allowed = defined( 'SN_ANALYTICS_CLASSES' ) ? SN_ANALYTICS_CLASSES : array( 'human', 'suspect', 'bot' );
	$records = array();
	foreach ( $allowed as $class ) {
		$data = sn_analytics_fetch_session_events( $day, $day, $class );
		if ( empty( $data['configured'] ) ) {
			continue;
		}
		// A "visit" requires >= 1 pageview. Filter pageview-less groups (RSS srv:1
		// 'ce' polls, orphan scroll/timing beacons) BEFORE aggregating — exactly as
		// the interactive Visits view does (inc/analytics-view-sessions.php). Without
		// this the durable table's bounce / ppv / median disagree with the live view
		// for the same window (a lone RSS reader gap-splits into phantom visits).
		$visits    = function_exists( 'sn_pageview_visits' ) ? sn_pageview_visits( $data['summaries'] ) : $data['summaries'];
		$m         = sn_session_metrics( $visits );
		$records[] = array(
			'day'        => $day,
			'class'      => $class,
			'visits'     => $m['visits'],
			'bounce_pct' => $m['bounce_rate'] * 100,
			'ppv'        => $m['pages_per_visit'],
			'median_dur' => $m['median_duration'],
		);
		// v9.66.0 exit bridge: derive per-path exit counts from the SAME pv-gated
		// visit set and upsert them into pageroles (role='exit'). Zero rows →
		// nothing written (absent day, never zero-rows); the nightly re-run of a
		// complete UTC day recomputes and overwrites idempotently (ON DUPLICATE
		// KEY in the upsert). function_exists-guarded like every cross-module wire.
		if ( 'human' === $class && function_exists( 'sn_analytics_pageroles_upsert' ) ) {
			$exit_rows = sn_session_exit_page_rows( $visits, $day );
			if ( ! empty( $exit_rows ) ) {
				sn_analytics_pageroles_upsert( $exit_rows );
			}
		}
	}
	$clean = sn_session_rollup_normalize( $records );
	if ( ! empty( $clean ) ) {
		sn_session_rollup_upsert( $clean );
	}
}
add_action( SN_SESSION_ROLLUP_HOOK, 'sn_session_rollup_run' );

/**
 * Read per-day session-quality rows back from the durable rollup table.
 *
 * The read half the table waited for since v8.8.0 (until v9.65.0 the nightly
 * writer had NO consumer). Day keys follow the WRITER's convention exactly:
 * sn_session_rollup_run() buckets by gmdate('Y-m-d') — a UTC day string —
 * matching the Visits view's "resets at UTC midnight" window and the UTC
 * snt_analytics_range_dates() bounds callers pass in.
 *
 * Null discipline (realtime-zero-vs-null): absent days stay ABSENT from the
 * result (never fabricated 0-rows — a night the cron skipped is "not
 * measured", not "zero sessions"); an EMPTY result set is a real answer ([]);
 * a FAILED query or invalid input returns null ("don't know"). Real wpdb
 * reports a FAILED query as [] WITH $wpdb->last_error set (get_results()
 * yields null only when prepare() failed and the query string was falsy), so
 * the accessor consults last_error after the read — otherwise a missing/
 * corrupt table would be indistinguishable from an honest empty window and
 * failure would be served as an answer. wpdb transports every selected
 * column as a numeric STRING — the rows are deliberately re-typed here so
 * consumers get the writer's types back.
 *
 * @since 9.65.0
 * @param string $from  Window start (Y-m-d, UTC day — the writer's key).
 * @param string $to    Window end (Y-m-d, inclusive).
 * @param string $class Traffic class (human|suspect|bot).
 * @return array|null Day-ascending list of typed rows
 *                    {day:string, visits:int, bounce_pct:float, ppv:float,
 *                    median_dur:int}, [] when no days rolled up in the
 *                    window, or null on invalid input / a failed query.
 */
function sn_session_rollup_read( $from, $to, $class ) {
	global $wpdb;
	$from    = trim( (string) $from );
	$to      = trim( (string) $to );
	$class   = (string) $class;
	$allowed = defined( 'SN_ANALYTICS_CLASSES' ) ? SN_ANALYTICS_CLASSES : array( 'human', 'suspect', 'bot' );
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from )
		|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to )
		|| ! in_array( $class, $allowed, true ) ) {
		return null;
	}

	$table = $wpdb->prefix . SN_SESSION_ROLLUP_TABLE;
	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- static SELECT template; $table is $wpdb->prefix + a plugin constant and every value binds via prepare(); reads the plugin-owned rollup table (no core API exists for it).
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, visits, bounce_pct, ppv, median_dur FROM {$table} WHERE day >= %s AND day <= %s AND class = %s ORDER BY day ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		$from,
		$to,
		$class
	), ARRAY_A );
	if ( ! is_array( $rows ) ) {
		return null; // falsy query (prepare() failed) — the only shape wpdb reports as null.
	}
	if ( '' !== (string) $wpdb->last_error ) {
		// A FAILED query (missing/corrupt table) comes back as [] with
		// last_error set — unknown is not an empty window.
		return null;
	}

	$out = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		// A malformed row (a selected column missing) is DROPPED, never padded
		// with fabricated 0s (array_key_exists — absent is not null is not 0).
		foreach ( array( 'day', 'visits', 'bounce_pct', 'ppv', 'median_dur' ) as $k ) {
			if ( ! array_key_exists( $k, $r ) ) {
				continue 2;
			}
		}
		$out[] = array(
			'day'        => (string) $r['day'],
			'visits'     => max( 0, (int) $r['visits'] ),
			'bounce_pct' => (float) $r['bounce_pct'],
			'ppv'        => (float) $r['ppv'],
			'median_dur' => max( 0, (int) $r['median_dur'] ),
		);
	}
	return $out;
}

/**
 * Batch INSERT ... ON DUPLICATE KEY UPDATE the clean records.
 *
 * @param array $clean Records from sn_session_rollup_normalize().
 * @return int Rows written.
 */
function sn_session_rollup_upsert( $clean ) {
	global $wpdb;
	$table   = $wpdb->prefix . SN_SESSION_ROLLUP_TABLE;
	$written = 0;
	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			// bounce_pct / ppv bind as %s carrying number_format()'d strings, NOT %f:
			// %f routes through $wpdb->prepare()'s vsprintf() (LC_NUMERIC-sensitive),
			// so a comma-decimal server locale (de_DE, pt_BR, …) would emit "1,75"
			// and corrupt the SQL. number_format( …, '.', '' ) forces a dot decimal
			// regardless of locale; MySQL coerces the quoted string into the FLOAT column.
			$placeholders[] = '(%s, %s, %d, %s, %s, %d)';
			array_push(
				$values,
				$c['day'],
				$c['class'],
				$c['visits'],
				number_format( (float) $c['bounce_pct'], 2, '.', '' ),
				number_format( (float) $c['ppv'], 2, '.', '' ),
				$c['median_dur']
			);
		}
		$sql = "INSERT INTO {$table} (day, class, visits, bounce_pct, ppv, median_dur) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE visits=VALUES(visits), bounce_pct=VALUES(bounce_pct), ppv=VALUES(ppv), median_dur=VALUES(median_dur)';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL -- $sql is a static INSERT ... VALUES template with a generated %s/%d placeholder group per row; $table is $wpdb->prefix + a plugin constant and every value is bound via prepare().
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false !== $result ) {
			$written += count( $chunk );
		}
	}
	return $written;
}

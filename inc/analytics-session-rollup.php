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
 * Compute yesterday's per-class visit-quality and upsert it.
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
		$m         = sn_session_metrics( $data['summaries'] );
		$records[] = array(
			'day'        => $day,
			'class'      => $class,
			'visits'     => $m['visits'],
			'bounce_pct' => $m['bounce_rate'] * 100,
			'ppv'        => $m['pages_per_visit'],
			'median_dur' => $m['median_duration'],
		);
	}
	$clean = sn_session_rollup_normalize( $records );
	if ( ! empty( $clean ) ) {
		sn_session_rollup_upsert( $clean );
	}
}
add_action( SN_SESSION_ROLLUP_HOOK, 'sn_session_rollup_run' );

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
			// bounce_pct / ppv bind as %s carrying a number_format()'d string, NOT
			// %f. %f routes through $wpdb->prepare()'s vsprintf(), which honours
			// LC_NUMERIC — under a comma-decimal server locale (de_DE, pt_BR, …) it
			// would emit "1,50" and corrupt the SQL. number_format( …, '.', '' )
			// forces a '.' decimal and an empty thousands separator regardless of
			// locale, and MySQL coerces the quoted numeric string into the FLOAT column.
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

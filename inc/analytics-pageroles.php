<?php
/**
 * Signal & Noise — durable entry/exit page-roles table (analytics queue 04).
 *
 * One table, two roles:
 *   role='entry' — landing pages: a pageview whose referrer (blob3) is external
 *                  or direct. Fed live by a daily AE rollup (see Task 2) wired
 *                  into the existing rollup cron, AND back-filled from Plausible
 *                  CSV history via inc/analytics-import.php (type entry_pages).
 *   role='exit'  — last page of a visit. NO live source (true live exit needs a
 *                  session id, which breaks cookieless — deferred). Historical
 *                  only, back-filled from Plausible CSV (type exit_pages).
 *
 * Entry/exit are HUMAN-ONLY (no traffic-class column): the dashboard class pill
 * does not apply, consistent with the Events tab and the human-only Plausible
 * history, so live + historical merge cleanly into one report.
 *
 * Install pattern mirrors inc/analytics-events.php exactly: constants → schema_sql
 * → install → version-gated maybe_install (init) → idempotent ON DUPLICATE KEY
 * upsert → read accessors.
 *
 * @package SignalNoiseTools
 * @since 6.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_PAGEROLES_TABLE          = 'sn_analytics_page_roles';
const SN_ANALYTICS_PAGEROLES_DB_VERSION     = '1';
const SN_ANALYTICS_PAGEROLES_DB_VERSION_OPT = 'sn_analytics_page_roles_db_version';

// The two roles this table stores. Used to validate upsert rows.
const SN_ANALYTICS_PAGEROLES_ROLES = array( 'entry', 'exit' );

/**
 * dbDelta CREATE TABLE for the page-roles aggregate.
 *
 * `day` DATE (3B) + `role` VARCHAR(8) (32B) + `path` VARCHAR(190) (760B) →
 * composite UNIQUE key 795 bytes under utf8mb4, inside InnoDB's 3072-byte
 * large-prefix limit (default since MySQL 5.7 / MariaDB 10.3). Paths over 190
 * chars truncate at write.
 *
 * @return string CREATE TABLE statement.
 */
function sn_analytics_pageroles_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_PAGEROLES_TABLE;
	$charset = $wpdb->get_charset_collate();

	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		role VARCHAR(8) NOT NULL,
		path VARCHAR(190) NOT NULL,
		views INT UNSIGNED NOT NULL DEFAULT 0,
		visits INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_role_path (day, role, path)
	) {$charset};";
}

/**
 * Create the table via dbDelta. Brand-new dormant table — no migration path.
 */
function sn_analytics_pageroles_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_analytics_pageroles_schema_sql() );
	update_option( SN_ANALYTICS_PAGEROLES_DB_VERSION_OPT, SN_ANALYTICS_PAGEROLES_DB_VERSION );
}

/**
 * One autoloaded-option compare per request; install runs only on the delta.
 */
function sn_analytics_pageroles_maybe_install() {
	if ( get_option( SN_ANALYTICS_PAGEROLES_DB_VERSION_OPT ) !== SN_ANALYTICS_PAGEROLES_DB_VERSION ) {
		sn_analytics_pageroles_install();
	}
}
add_action( 'init', 'sn_analytics_pageroles_maybe_install' );

/**
 * UPSERT page-role rows (each carrying day/role/path/views/visits). Rows with a
 * malformed day (not YYYY-MM-DD), a role outside {entry,exit}, or a blank path
 * are skipped. Paths truncate to 190 chars. Batched per 100.
 *
 * @param array $rows Array of assoc arrays with keys: day, role, path, views, visits.
 * @return int Rows written.
 */
function sn_analytics_pageroles_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}

	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day  = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$role = isset( $r['role'] ) ? trim( (string) $r['role'] ) : '';
		$path = isset( $r['path'] ) ? trim( (string) $r['path'] ) : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			continue;
		}
		if ( ! in_array( $role, SN_ANALYTICS_PAGEROLES_ROLES, true ) || '' === $path ) {
			continue;
		}
		$clean[] = array(
			'day'    => $day,
			'role'   => $role,
			'path'   => substr( $path, 0, 190 ),
			'views'  => max( 0, (int) round( (float) ( $r['views'] ?? 0 ) ) ),
			'visits' => max( 0, (int) round( (float) ( $r['visits'] ?? 0 ) ) ),
		);
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_PAGEROLES_TABLE;
	$written = 0;

	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			$placeholders[] = '(%s, %s, %s, %d, %d)';
			array_push( $values, $c['day'], $c['role'], $c['path'], $c['views'], $c['visits'] );
		}
		$sql = "INSERT INTO {$table} (day, role, path, views, visits) VALUES "
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
 * Read accessor: top paths for one $role across an inclusive [$from,$to] day
 * range, ordered by views descending.
 *
 * @param string $role  'entry' | 'exit'.
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param int    $limit Max rows (1..500).
 * @return array<int, array{path:string, views:int, visits:int}>
 */
function sn_analytics_pageroles_top( $role, $from, $to, $limit = 25 ) {
	if ( ! in_array( $role, SN_ANALYTICS_PAGEROLES_ROLES, true ) ) {
		return array();
	}
	$limit = max( 1, min( 500, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_PAGEROLES_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT path, SUM(views) AS views, SUM(visits) AS visits
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND role = %s
		 GROUP BY path
		 ORDER BY views DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		(string) $role,
		$limit
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'path'   => (string) $r['path'],
				'views'  => (int) $r['views'],
				'visits' => (int) $r['visits'],
			);
		}
	}
	return $out;
}

/**
 * Top entry (landing) pages — convenience wrapper over sn_analytics_pageroles_top().
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param int    $limit Max rows (1..500), default 25.
 * @return array<int, array{path:string, views:int, visits:int}>
 */
function sn_analytics_top_entry_pages( $from, $to, $limit = 25 ) {
	return sn_analytics_pageroles_top( 'entry', $from, $to, $limit );
}

/**
 * Top exit pages — convenience wrapper over sn_analytics_pageroles_top().
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param int    $limit Max rows (1..500), default 25.
 * @return array<int, array{path:string, views:int, visits:int}>
 */
function sn_analytics_top_exit_pages( $from, $to, $limit = 25 ) {
	return sn_analytics_pageroles_top( 'exit', $from, $to, $limit );
}

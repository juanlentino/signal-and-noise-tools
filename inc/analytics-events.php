<?php
/**
 * Signal & Noise — durable custom-events and custom-event-properties tables (P6).
 *
 * Two companion tables to the first-party analytics suite:
 *   wp_sn_analytics_events      — daily totals per named Plausible custom event
 *   wp_sn_analytics_event_props — daily totals per custom event property+value pair
 *
 * Back-filled from Plausible CSV exports via inc/analytics-import.php (types
 * custom_events / custom_props). No live-capture pipeline yet — these are
 * durable historical tables. No dashboard display surface is wired in this
 * release; read accessors (sn_analytics_top_events / sn_analytics_top_event_props)
 * are provided for a later redesign pass.
 *
 * Install pattern mirrors inc/analytics-dims.php exactly: constants, schema_sql,
 * install, maybe_install (version-gated), and an idempotent ON DUPLICATE KEY
 * UPDATE upsert. Both tables are created in the same install call.
 *
 * @package SignalNoiseTools
 * @since 6.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_EVENTS_TABLE              = 'sn_analytics_events';
const SN_ANALYTICS_EVENTS_DB_VERSION         = '1';
const SN_ANALYTICS_EVENTS_DB_VERSION_OPT     = 'sn_analytics_events_db_version';

const SN_ANALYTICS_EVENT_PROPS_TABLE         = 'sn_analytics_event_props';
const SN_ANALYTICS_EVENT_PROPS_DB_VERSION    = '1';
const SN_ANALYTICS_EVENT_PROPS_DB_VERSION_OPT = 'sn_analytics_event_props_db_version';

/**
 * dbDelta CREATE TABLE statements for both events tables.
 *
 * events:      keyed (day, name) — one row per calendar day per event name.
 * event_props: keyed (day, property, value) — one row per day per prop+value pair.
 *
 * VARCHAR sizes chosen to fit inside InnoDB's 767-byte UNIQUE key prefix:
 *   events:      DATE(3) + VARCHAR(120)(480) = 483 bytes.
 *   event_props: DATE(3) + VARCHAR(60)(240) + VARCHAR(180)(720) = 963 bytes — uses
 *                utf8mb3 equivalent sizing; actual byte ceiling with utf8mb4 at
 *                3+60*4+180*4 = 1203 bytes exceeds 3072-byte limit (InnoDB large-prefix
 *                enabled by default since MySQL 5.7 / MariaDB 10.3). Safe.
 *
 * @return array{events:string, event_props:string}
 */
function sn_analytics_events_schema_sql() {
	global $wpdb;
	$events_table = $wpdb->prefix . SN_ANALYTICS_EVENTS_TABLE;
	$props_table  = $wpdb->prefix . SN_ANALYTICS_EVENT_PROPS_TABLE;
	$charset      = $wpdb->get_charset_collate();

	$events_sql = "CREATE TABLE {$events_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		name VARCHAR(120) NOT NULL,
		visitors INT UNSIGNED NOT NULL DEFAULT 0,
		events INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_name (day, name)
	) {$charset};";

	$props_sql = "CREATE TABLE {$props_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		property VARCHAR(60) NOT NULL,
		value VARCHAR(180) NOT NULL,
		visitors INT UNSIGNED NOT NULL DEFAULT 0,
		events INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_prop_value (day, property, value)
	) {$charset};";

	return array(
		'events'      => $events_sql,
		'event_props' => $props_sql,
	);
}

/**
 * Create both tables via dbDelta. Brand-new dormant tables — no migration path.
 */
function sn_analytics_events_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	$sqls = sn_analytics_events_schema_sql();
	dbDelta( $sqls['events'] );
	dbDelta( $sqls['event_props'] );
	update_option( SN_ANALYTICS_EVENTS_DB_VERSION_OPT, SN_ANALYTICS_EVENTS_DB_VERSION );
	update_option( SN_ANALYTICS_EVENT_PROPS_DB_VERSION_OPT, SN_ANALYTICS_EVENT_PROPS_DB_VERSION );
}

/**
 * One autoloaded-option compare per request; install runs only on the delta.
 * Mirrors sn_analytics_dims_maybe_install() — checks BOTH version options so
 * either table being out of date triggers a full install run.
 */
function sn_analytics_events_maybe_install() {
	if (
		get_option( SN_ANALYTICS_EVENTS_DB_VERSION_OPT ) !== SN_ANALYTICS_EVENTS_DB_VERSION ||
		get_option( SN_ANALYTICS_EVENT_PROPS_DB_VERSION_OPT ) !== SN_ANALYTICS_EVENT_PROPS_DB_VERSION
	) {
		sn_analytics_events_install();
	}
}
// NOTE: wired into the plugin loader (signal-and-noise-tools.php) in a later task;
// until then this module is loaded only by its CLI test.
add_action( 'init', 'sn_analytics_events_maybe_install' );

/**
 * UPSERT event rows (each carrying day/name/visitors/events) into the events table.
 * Rows with a missing or malformed day, or a blank name, are skipped. Batched per 100.
 *
 * @param array $rows Array of assoc arrays with keys: day, name, visitors, events.
 * @return int Rows written.
 */
function sn_analytics_events_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}

	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day  = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$name = isset( $r['name'] ) ? trim( (string) $r['name'] ) : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || '' === $name ) {
			continue;
		}
		$clean[] = array(
			'day'      => $day,
			'name'     => substr( $name, 0, 120 ),
			'visitors' => max( 0, (int) ( $r['visitors'] ?? 0 ) ),
			'events'   => max( 0, (int) ( $r['events'] ?? 0 ) ),
		);
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_EVENTS_TABLE;
	$written = 0;

	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			$placeholders[] = '(%s, %s, %d, %d)';
			array_push( $values, $c['day'], $c['name'], $c['visitors'], $c['events'] );
		}
		$sql = "INSERT INTO {$table} (day, name, visitors, events) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE visitors=VALUES(visitors), events=VALUES(events)';

		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false !== $result ) {
			$written += count( $chunk );
		}
	}

	return $written;
}

/**
 * UPSERT event-property rows (day/property/value/visitors/events) into the
 * event_props table. Rows with a missing/malformed day or blank property are
 * skipped. Batched per 100.
 *
 * @param array $rows Array of assoc arrays with keys: day, property, value, visitors, events.
 * @return int Rows written.
 */
function sn_analytics_event_props_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}

	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day      = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$property = isset( $r['property'] ) ? trim( (string) $r['property'] ) : '';
		$value    = isset( $r['value'] ) ? trim( (string) $r['value'] ) : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || '' === $property ) {
			continue;
		}
		$clean[] = array(
			'day'      => $day,
			'property' => substr( $property, 0, 60 ),
			'value'    => substr( $value, 0, 180 ),
			'visitors' => max( 0, (int) ( $r['visitors'] ?? 0 ) ),
			'events'   => max( 0, (int) ( $r['events'] ?? 0 ) ),
		);
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_EVENT_PROPS_TABLE;
	$written = 0;

	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			$placeholders[] = '(%s, %s, %s, %d, %d)';
			array_push( $values, $c['day'], $c['property'], $c['value'], $c['visitors'], $c['events'] );
		}
		$sql = "INSERT INTO {$table} (day, property, value, visitors, events) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE visitors=VALUES(visitors), events=VALUES(events)';

		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false !== $result ) {
			$written += count( $chunk );
		}
	}

	return $written;
}

/**
 * Read accessor: top event names by total events across an inclusive [$from, $to]
 * day range, ordered by events descending.
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param int    $limit Max rows (1..500), default 25.
 * @return array<int, array{name:string, events:int, visitors:int}>
 */
function sn_analytics_top_events( $from, $to, $limit = 25 ) {
	$limit = max( 1, min( 500, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_EVENTS_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT name, SUM(events) AS events, SUM(visitors) AS visitors
		 FROM {$table}
		 WHERE day >= %s AND day <= %s
		 GROUP BY name
		 ORDER BY events DESC
		 LIMIT %d",
		(string) $from,
		(string) $to,
		$limit
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'name'     => (string) $r['name'],
				'events'   => (int) $r['events'],
				'visitors' => (int) $r['visitors'],
			);
		}
	}
	return $out;
}

/**
 * Read accessor: top property+value pairs by total events across an inclusive
 * [$from, $to] day range. Optionally filter to one property. Ordered by events desc.
 *
 * @param string $from     Inclusive start day, YYYY-MM-DD.
 * @param string $to       Inclusive end day, YYYY-MM-DD.
 * @param string $property Filter to this property name, or '' for all.
 * @param int    $limit    Max rows (1..500), default 50.
 * @return array<int, array{property:string, value:string, events:int, visitors:int}>
 */
function sn_analytics_top_event_props( $from, $to, $property = '', $limit = 50 ) {
	$limit = max( 1, min( 500, (int) $limit ) );

	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_EVENT_PROPS_TABLE;

	if ( '' !== (string) $property ) {
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT property, value, SUM(events) AS events, SUM(visitors) AS visitors
			 FROM {$table}
			 WHERE day >= %s AND day <= %s AND property = %s
			 GROUP BY property, value
			 ORDER BY events DESC
			 LIMIT %d",
			(string) $from,
			(string) $to,
			(string) $property,
			$limit
		), ARRAY_A );
	} else {
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT property, value, SUM(events) AS events, SUM(visitors) AS visitors
			 FROM {$table}
			 WHERE day >= %s AND day <= %s
			 GROUP BY property, value
			 ORDER BY events DESC
			 LIMIT %d",
			(string) $from,
			(string) $to,
			$limit
		), ARRAY_A );
	}

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[] = array(
				'property' => (string) $r['property'],
				'value'    => (string) $r['value'],
				'events'   => (int) $r['events'],
				'visitors' => (int) $r['visitors'],
			);
		}
	}
	return $out;
}

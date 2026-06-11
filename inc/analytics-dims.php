<?php
/**
 * Signal & Noise — first-party analytics dimension breakdowns (P3).
 *
 * The path-keyed daily rollup (inc/analytics-rollup.php) carries per-page
 * engagement; this companion table carries the visit-attribute breakdowns the
 * dashboard needs — referrer host, country, device — keyed (day, dim, value,
 * class). Fed by the SAME rollup cron (one extra AE query per dim). No Worker
 * change: blob3/blob4/blob5 are already written by the edge worker.
 *
 * Dormant until AE creds are configured (sn_analytics_query() → null), exactly
 * like the path rollup: the empty table is created but never written.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_DIMS_TABLE          = 'sn_analytics_dims';
const SN_ANALYTICS_DIMS_DB_VERSION     = '1';
const SN_ANALYTICS_DIMS_DB_VERSION_OPT = 'sn_analytics_dims_db_version';

// The dimensions this table aggregates, mapped to their AE blob columns.
const SN_ANALYTICS_DIM_COLUMNS = array(
	'referrer' => 'blob3',
	'country'  => 'blob4',
	'device'   => 'blob5',
);

/**
 * dbDelta CREATE TABLE for the dimension breakdowns.
 *
 * `value` is VARCHAR(160); with `day` DATE (3B), `dim` VARCHAR(10) (40B),
 * `value` (640B) and `class` VARCHAR(10) (40B) the composite UNIQUE key is 723
 * bytes — inside InnoDB's 767-byte prefix. Over-long values truncate at write.
 *
 * @return string CREATE TABLE statement.
 */
function sn_analytics_dims_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_DIMS_TABLE;
	$charset = $wpdb->get_charset_collate();

	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		dim VARCHAR(10) NOT NULL,
		value VARCHAR(160) NOT NULL,
		class VARCHAR(10) NOT NULL DEFAULT 'human',
		views INT UNSIGNED NOT NULL DEFAULT 0,
		visits INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY day_dim_value_class (day, dim, value, class)
	) {$charset};";
}

/**
 * Create the table via dbDelta. Brand-new dormant table — no migration path.
 */
function sn_analytics_dims_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_analytics_dims_schema_sql() );
	update_option( SN_ANALYTICS_DIMS_DB_VERSION_OPT, SN_ANALYTICS_DIMS_DB_VERSION );
}

/**
 * One autoloaded-option compare per request; install runs only on the delta.
 */
function sn_analytics_dims_maybe_install() {
	if ( get_option( SN_ANALYTICS_DIMS_DB_VERSION_OPT ) !== SN_ANALYTICS_DIMS_DB_VERSION ) {
		sn_analytics_dims_install();
	}
}
// NOTE: wired into the plugin loader (signal-and-noise-tools.php) in a later task; until then this module is loaded only by its CLI test.
add_action( 'init', 'sn_analytics_dims_maybe_install' );

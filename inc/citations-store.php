<?php
/**
 * Signal & Noise — the verified citation graph: storage.
 *
 * One row per (source, target) pair, keyed on a hash of the NORMALISED pair so a
 * re-ping updates rather than duplicates.
 *
 * `last_checked_gmt` is NULLABLE ON PURPOSE. NULL means never measured; a
 * datetime means measured. Had it defaulted to the row's creation time, "we have
 * not looked yet" and "we looked and found nothing" would collapse into the same
 * value and no amount of careful rendering downstream could separate them again.
 * That is the zero-vs-null rule, applied in the schema where it cannot be lost.
 *
 * @package SignalNoiseTools
 * @since 11.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CIT_TABLE          = 'sn_citations';
const SN_CIT_DB_VERSION     = '1';
const SN_CIT_DB_VERSION_OPT = 'sn_citations_db_version';

/** @return string The prefixed table name. */
function sn_cit_table() {
	global $wpdb;
	return $wpdb->prefix . SN_CIT_TABLE;
}

/**
 * Stable identity for a (source, target) pair. Hashing the normalised pair means
 * https://x.com/a and https://X.com/a/ are the SAME citation, not two. Pure.
 *
 * @param string $source
 * @param string $target
 * @return string 40-char sha1, or '' when either URL is unusable.
 */
function sn_cit_pair_hash( $source, $target ) {
	$s = sn_cit_normalize_url( $source );
	$t = sn_cit_normalize_url( $target );
	if ( '' === $s || '' === $t ) {
		return '';
	}
	return sha1( $s . "\n" . $t );
}

function sn_cit_install() {
	global $wpdb;
	$table   = sn_cit_table();
	$charset = $wpdb->get_charset_collate();

	// last_checked_gmt is NULL DEFAULT NULL: never-measured is a distinct answer.
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		pair_hash CHAR(40) NOT NULL,
		source_url VARCHAR(500) NOT NULL DEFAULT '',
		target_url VARCHAR(500) NOT NULL DEFAULT '',
		target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		source_title VARCHAR(255) NOT NULL DEFAULT '',
		tier VARCHAR(20) NOT NULL DEFAULT 'unverified',
		first_seen_gmt DATETIME NOT NULL,
		last_checked_gmt DATETIME NULL DEFAULT NULL,
		last_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY pair_hash (pair_hash),
		KEY target_post_id (target_post_id),
		KEY tier (tier)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( SN_CIT_DB_VERSION_OPT, SN_CIT_DB_VERSION );
}

/** Install on init so the table exists before the endpoint can be hit. */
function sn_cit_maybe_install() {
	if ( get_option( SN_CIT_DB_VERSION_OPT ) !== SN_CIT_DB_VERSION ) {
		sn_cit_install();
	}
}

/**
 * Record an inbound claim. A claim is NOT a verdict: the row lands as
 * `unverified` with a NULL check time, and only the verifier may promote it.
 *
 * @param string $source
 * @param string $target
 * @param int    $post_id
 * @return string 'created' | 'exists' | 'invalid'
 */
function sn_cit_record( $source, $target, $post_id = 0 ) {
	global $wpdb;
	$hash = sn_cit_pair_hash( $source, $target );
	if ( '' === $hash ) {
		return 'invalid';
	}
	$table    = sn_cit_table();
	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE pair_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $existing ) {
		return 'exists';
	}
	$wpdb->insert(
		$table,
		array(
			'pair_hash'      => $hash,
			'source_url'     => substr( sn_cit_normalize_url( $source ), 0, 500 ),
			'target_url'     => substr( sn_cit_normalize_url( $target ), 0, 500 ),
			'target_post_id' => (int) $post_id,
			'tier'           => 'unverified',
			'first_seen_gmt' => gmdate( 'Y-m-d H:i:s' ),
		)
	);
	return 'created';
}

/**
 * Write a verdict. The ONLY place tier is persisted.
 *
 * @param int    $id
 * @param string $tier   One of SN_CIT_TIERS.
 * @param int    $status HTTP status observed, 0 when no response.
 * @param string $title  Source page title, best effort.
 * @return bool
 */
function sn_cit_update_verdict( $id, $tier, $status = 0, $title = '' ) {
	global $wpdb;
	if ( ! in_array( $tier, SN_CIT_TIERS, true ) ) {
		return false;
	}
	return (bool) $wpdb->update(
		sn_cit_table(),
		array(
			'tier'             => $tier,
			'last_status'      => (int) $status,
			'source_title'     => substr( (string) $title, 0, 255 ),
			'last_checked_gmt' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'id' => (int) $id )
	);
}

/**
 * Citations for one note. `$public_only` applies the tier gate, so a caller
 * cannot accidentally render an `asserted` claim as a citation.
 *
 * @param int  $post_id
 * @param bool $public_only
 * @return array<int,object>
 */
function sn_cit_for_post( $post_id, $public_only = true ) {
	global $wpdb;
	$table = sn_cit_table();
	if ( $public_only ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE target_post_id = %d AND tier IN ( 'verified', 'unattributed' ) ORDER BY first_seen_gmt DESC", (int) $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE target_post_id = %d ORDER BY first_seen_gmt DESC", (int) $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	return is_array( $rows ) ? $rows : array();
}

/**
 * Rows due for a check: never checked, or checked longer ago than the window.
 * Ordered never-checked first so a new claim is adjudicated before an old one is
 * re-adjudicated.
 *
 * @param int $limit
 * @param int $stale_days
 * @return array<int,object>
 */
function sn_cit_due_for_check( $limit = 10, $stale_days = 7 ) {
	global $wpdb;
	$table = sn_cit_table();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a
	// constant-derived identifier from sn_cit_table(); the VALUES are placeholders.
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table}
			  WHERE last_checked_gmt IS NULL
			     OR last_checked_gmt < ( UTC_TIMESTAMP() - INTERVAL %d DAY )
			  ORDER BY ( last_checked_gmt IS NOT NULL ), first_seen_gmt ASC
			  LIMIT %d",
			(int) $stale_days,
			(int) $limit
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return is_array( $rows ) ? $rows : array();
}

/**
 * Tier counts, plus `never_checked` as its own figure. Every declared tier is
 * present with an explicit 0 — a tier missing from the readout would be
 * indistinguishable from a tier nobody has measured.
 *
 * @return array<string,int>
 */
function sn_cit_counts() {
	global $wpdb;
	$table = sn_cit_table();
	$out   = array_fill_keys( SN_CIT_TIERS, 0 );
	$rows  = $wpdb->get_results( "SELECT tier, COUNT(*) AS n FROM {$table} GROUP BY tier" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	foreach ( (array) $rows as $r ) {
		if ( isset( $out[ $r->tier ] ) ) {
			$out[ $r->tier ] = (int) $r->n;
		}
	}
	$out['never_checked'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE last_checked_gmt IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	return $out;
}

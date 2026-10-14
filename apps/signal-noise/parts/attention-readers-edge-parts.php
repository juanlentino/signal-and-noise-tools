<?php
/**
 * Signal & Noise app, the Attention queue's readers: the edge reader, its purge check, and the citations reader.
 *
 * A pure move from parts/attention-readers.php (house line cap, 2026-10-14), required at its end in source order.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Notes whose last edge probe found a stale render.
 *
 * The probe log is a twenty-row SITE-WIDE buffer, newest first by insertion,
 * so this measures the last twenty saves and never the site: a note with no
 * row here was evicted, not verified. Only the newest row per post counts —
 * an older stale verdict a later fresh one replaced is history.
 *
 * The two filters are the summary's own, applied at READ time for its reason
 * (inc/cloudflare-purge-verify.php:330-348): a row from before the detector
 * fix measured with a broken instrument, and a manual zone purge is the
 * operator moving the diagnostic they were reading.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_edge() {
	if ( ! defined( 'SN_CF_PROBE_LOG_OPT' ) || ! defined( 'SN_CF_PROBE_ALGO' ) || ! function_exists( 'get_option' ) ) {
		return attention_read();
	}
	try {
		$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
		if ( ! is_array( $log ) ) {
			return attention_unreadable();
		}
		$now    = attention_now();
		$door   = attention_door( 'sn-connections', 'cloudflare' );
		$seen   = array();
		$rows   = array();
		$newest = '';
		// 19.2.0: a full zone purge LATER than a stale reading replaced
		// everything that reading saw, so the verdict is history. The margin
		// keeps the probe's own escalation (seconds after the reading) from
		// retiring it: only a purge the reading did not trigger counts.
		// ponytail: this retires on a purge, not on a re-probe; a live re-check
		// per row is the upgrade if a zone purge ever stops clearing the edge.
		$zone_purge = defined( 'SN_CF_LAST_ZONE_PURGE_OPT' ) ? (int) get_option( SN_CF_LAST_ZONE_PURGE_OPT, 0 ) : 0;
		// 2026-10-13: a whole-site tag purge (a probe's escalation, a save) or a
		// zone invalidate (an update) refreshes every page's HTML too, so it
		// retires the reading the same way. The margin keeps a probe's own
		// escalation from retiring its row when it lands at once. One that was
		// throttled and confirmed on a retry minutes later does retire it: that
		// purge did refresh every page after the reading, and the probe log
		// still keeps the stale row.
		$zone_purge = max( $zone_purge, defined( 'SN_CF_LAST_HTML_REFRESH_OPT' ) ? (int) get_option( SN_CF_LAST_HTML_REFRESH_OPT, 0 ) : 0 );
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) || (int) ( $entry['algo'] ?? 1 ) < SN_CF_PROBE_ALGO ) {
				continue;
			}
			if ( 'manual_zone_purge' === (string) ( $entry['source'] ?? '' ) ) {
				continue;
			}
			$pid = (int) ( $entry['post_id'] ?? 0 );
			if ( $pid <= 0 || isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true; // Newest first: the first row for a post is its verdict.
			if ( 'stale' !== (string) ( $entry['result'] ?? '' ) ) {
				continue;
			}
			$time     = (int) ( $entry['time'] ?? 0 );
			if ( attention_edge_superseded( $time, $zone_purge ) ) {
				continue;
			}
			$stamp    = attention_stamp( $time );
			$headline = function_exists( 'snt_cf_freshness_headline' )
				? (string) \snt_cf_freshness_headline( 'stale' )
				: __( 'Edge served a stale render', 'signal-and-noise-tools' );
			$phrase   = function_exists( 'snt_cf_freshness_phrase' )
				? (string) \snt_cf_freshness_phrase( 'stale', $time, $now )
				: '';
			$subtitle = '' !== $phrase ? $headline . ', ' . $phrase : $headline;
			// The shared words (whole-site tag or zone); a row with no field is a zone purge.
			$esc = function_exists( 'snt_cf_probe_escalation_sentence' )
				? (string) \snt_cf_probe_escalation_sentence( $entry )
				: ( ! empty( $entry['escalated'] ) ? __( 'A zone purge was forced.', 'signal-and-noise-tools' ) : '' );
			if ( '' !== $esc ) {
				$subtitle .= ' ' . $esc;
			}
			$rows[] = attention_row( array(
				'kind'       => 'edge',
				'key'        => (string) $pid,
				'title'      => attention_post_title( $pid, (string) ( $entry['url'] ?? '' ) ),
				'subtitle'   => $subtitle,
				'tone'       => 'warning',
				'stamp'      => $stamp,
				'source'     => __( 'The purge verification log (newest twenty, site-wide)', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Cloudflare in S&N Dashboard', 'signal-and-noise-tools' ),
				'post_id'    => $pid,
			) );
			if ( $stamp > $newest ) {
				$newest = $stamp;
			}
		}
		// A purge Cloudflare refused: the automation failed, which is the one
		// time the cache earns a row without a stale reading behind it.
		$failure = function_exists( 'sn_cf_purge_failure' ) ? \sn_cf_purge_failure() : null;
		if ( is_array( $failure ) ) {
			$stamp  = attention_stamp( (int) ( $failure['time'] ?? 0 ) );
			$rows[] = attention_row( array(
				'kind'       => 'edge',
				'key'        => 'purge-failure',
				'title'      => __( 'Cache refresh refused', 'signal-and-noise-tools' ),
				/* translators: %d: the HTTP status Cloudflare answered with. */
				'subtitle'   => sprintf( __( 'Cloudflare did not accept a cache refresh (HTTP %d)', 'signal-and-noise-tools' ), (int) ( $failure['http'] ?? 0 ) ),
				'tone'       => 'warning',
				'stamp'      => $stamp,
				'source'     => __( 'The last purge request Cloudflare refused', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Cloudflare in S&N Dashboard', 'signal-and-noise-tools' ),
			) );
			if ( $stamp > $newest ) {
				$newest = $stamp;
			}
		}
		return attention_read( $rows, $newest );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Did a full zone purge come after this stale reading, by more than the
 * probe's own escalation? PURE.
 *
 * @param int $reading_time When the stale verdict was recorded.
 * @param int $zone_purge   When the last full zone purge ran (0: never).
 * @return bool
 */
function attention_edge_superseded( $reading_time, $zone_purge ) {
	return (int) $zone_purge > 0 && (int) $zone_purge - (int) $reading_time > ATTENTION_EDGE_ESCALATION_S;
}

/**
 * Citations nobody has checked, and citations whose check has gone stale.
 *
 * Two figures, never one: `never_checked` is a row nobody has looked at and
 * "due" is a row whose look has aged out, and the store keeps them apart
 * deliberately (a tier missing from a readout would be indistinguishable from
 * a tier nobody measured). The due read is bounded, so the row says "or more"
 * when it hits its bound rather than reporting the bound as the total.
 *
 * The two SQL reads are exactly where the sandbox's SQLite refuses, which is
 * the unreadable row's first real case -- and NEITHER STORE FUNCTION THROWS.
 * A failed wpdb query returns []/null with `$wpdb->last_error` set, so
 * sn_cit_counts() hands back a full set of zeros and sn_cit_due_for_check() an
 * empty array: a broken store reads exactly like a clean one. The house
 * pattern is to consult last_error around the call (inc/sn-scan-telemetry.php:
 * 255-270, inc/ml-cadence.php:240-255), and to CLEAR it first -- an error left
 * behind by an unrelated earlier query would otherwise condemn this reading.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_citations() {
	if ( ! function_exists( 'sn_cit_counts' ) || ! function_exists( 'sn_cit_due_for_check' ) ) {
		return attention_read();
	}
	try {
		global $wpdb;
		$watched = is_object( $wpdb ) && property_exists( $wpdb, 'last_error' );
		if ( $watched ) {
			$wpdb->last_error = '';
		}
		$counts = \sn_cit_counts();
		$due    = \sn_cit_due_for_check( ATTENTION_CITATIONS_DUE_CAP, ATTENTION_CITATIONS_STALE_DAYS );
		if ( ! is_array( $counts ) || ! is_array( $due ) ) {
			return attention_unreadable();
		}
		// Zeros with an error behind them are not a reading. Zeros with an
		// EMPTY last_error are an honest, measured nothing.
		if ( $watched && '' !== (string) $wpdb->last_error ) {
			return attention_unreadable();
		}
		// A live query: the stamp is when it was read, not when the rows were
		// written -- the table itself carries no reading time.
		$stamp  = attention_stamp( attention_now() );
		$door   = attention_door( 'sn-tools', 'citations' );
		$label  = __( 'Open Citations in S&N Dashboard', 'signal-and-noise-tools' );
		$source = __( 'The citations table, read just now', 'signal-and-noise-tools' );
		$rows   = array();
		$never  = (int) ( $counts['never_checked'] ?? 0 );
		if ( $never > 0 ) {
			$rows[] = attention_row( array(
				'kind'       => 'citations',
				'key'        => 'never-checked',
				'title'      => __( 'Citations never checked', 'signal-and-noise-tools' ),
				/* translators: %d: a count of citation rows. */
				'subtitle'   => sprintf( _n( '%d citation has never been checked', '%d citations have never been checked', $never, 'signal-and-noise-tools' ), $never ),
				'tone'       => 'warning',
				'stamp'      => $stamp,
				'source'     => $source,
				'door'       => $door,
				'door_label' => $label,
			) );
		}
		$due_n = count( $due );
		if ( $due_n > 0 ) {
			$rows[] = attention_row( array(
				'kind'       => 'citations',
				'key'        => 'due',
				'title'      => __( 'Citations due for a check', 'signal-and-noise-tools' ),
				'subtitle'   => $due_n >= ATTENTION_CITATIONS_DUE_CAP
					/* translators: 1: a bounded count. 2: the staleness window in days. */
					? sprintf( __( '%1$d or more citations are due for a check (unchecked, or checked over %2$d days ago)', 'signal-and-noise-tools' ), $due_n, ATTENTION_CITATIONS_STALE_DAYS )
					/* translators: 1: a count. 2: the staleness window in days. */
					: sprintf( _n( '%1$d citation is due for a check (unchecked, or checked over %2$d days ago)', '%1$d citations are due for a check (unchecked, or checked over %2$d days ago)', $due_n, 'signal-and-noise-tools' ), $due_n, ATTENTION_CITATIONS_STALE_DAYS ),
				// A due date, not a failure: the verifier drains ten an hour.
				'tone'       => 'neutral',
				'stamp'      => $stamp,
				'source'     => $source,
				'door'       => $door,
				'door_label' => $label,
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}


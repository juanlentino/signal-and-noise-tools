<?php
/**
 * Signal & Noise Tools: sn_apply change.type "change_class_backfill"
 * (2026-10-17). Labels versions minted before change.change_class existed,
 * through sn_prov_set_change_class() and nothing else: the label sits beside
 * the signed payload, so no payload, hash or anchor moves.
 *
 * The plan, per listed post: the chain versions (v0 genesis excluded) whose
 * committed_at falls on one of payload.window_dates. Chains store
 * committed_at as ISO-8601 UTC ('Y-m-d\TH:i:s\Z', sn_prov_record()), so a
 * window date is a UTC calendar day. Exactly one candidate plans; zero or
 * more than one refuses the whole call, naming every candidate.
 *
 * The audit cross-check is day-level by necessity: the rw audit log keeps
 * redacted scalars only, and an sn_apply row carries neither the post nor the
 * version it minted. A row reports whether any applied sn_apply write landed
 * on its day, so a label with no write behind it is flagged, not refused.
 *
 * PUBLISH-ONLY (a label on an existing version has no revision to stage). The
 * fingerprint binds the planned rows, the roadmap_board idiom: a dry run
 * reports it as gates.fingerprint.observed, an apply requires it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payload shape problems, each a sentence. Empty when the payload is usable.
 *
 * @param array $payload
 * @return string[]
 */
function snt_sn_apply_backfill_payload_problems( array $payload ) {
	$problems = array();
	$dates    = $payload['window_dates'] ?? null;
	if ( ! is_array( $dates ) || array() === $dates || count( $dates ) > 7 ) {
		$problems[] = 'payload.window_dates must list 1 to 7 UTC dates (YYYY-MM-DD).';
	} else {
		foreach ( $dates as $d ) {
			$p = is_string( $d ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ? $m : null;
			if ( ! $p || ! checkdate( (int) $p[2], (int) $p[3], (int) $p[1] ) ) {
				$problems[] = sprintf( 'payload.window_dates: "%s" is not a YYYY-MM-DD date.', is_scalar( $d ) ? (string) $d : gettype( $d ) );
			}
		}
	}
	$labels = $payload['labels'] ?? null;
	if ( ! is_array( $labels ) || array() === $labels || count( $labels ) > 200 ) {
		$problems[] = 'payload.labels must list 1 to 200 {post_id, change_class} items.';
		return $problems;
	}
	$seen = array();
	foreach ( array_values( $labels ) as $i => $label ) {
		$pid   = is_array( $label ) ? (int) ( $label['post_id'] ?? 0 ) : 0;
		$class = is_array( $label ) ? ( $label['change_class'] ?? '' ) : '';
		if ( $pid <= 0 ) {
			$problems[] = sprintf( 'payload.labels[%d]: post_id must be a positive integer.', $i );
		} elseif ( isset( $seen[ $pid ] ) ) {
			$problems[] = sprintf( 'payload.labels[%d]: post %d is listed twice.', $i, $pid );
		}
		$seen[ $pid ] = true;
		if ( ! sn_prov_change_class_storable( $class ) ) {
			$problems[] = sprintf( 'payload.labels[%d]: change_class "%s" is not storable; use one of reading_aid, correction, copy, claim.', $i, is_scalar( $class ) ? (string) $class : gettype( $class ) );
		}
	}
	return $problems;
}

/**
 * The plan: one row per listed post with exactly one window version, and a
 * refused entry (with every candidate) for each post with zero or several.
 *
 * @param array $payload A payload with no shape problems.
 * @return array{rows:array,refused:array}
 */
function snt_sn_apply_backfill_plan( array $payload ) {
	$dates   = array_map( 'strval', (array) $payload['window_dates'] );
	$days    = snt_sn_apply_backfill_write_days( $dates );
	$rows    = array();
	$refused = array();
	foreach ( (array) $payload['labels'] as $label ) {
		$pid        = (int) $label['post_id'];
		$candidates = array();
		foreach ( sn_prov_get_chain( $pid ) as $entry ) {
			$at = (string) ( $entry['committed_at'] ?? '' );
			if ( (int) ( $entry['version'] ?? 0 ) >= 1 && in_array( substr( $at, 0, 10 ), $dates, true ) ) {
				$candidates[] = array( 'version' => (int) $entry['version'], 'minted_at' => $at, 'entry' => $entry );
			}
		}
		if ( 1 !== count( $candidates ) ) {
			$refused[] = array(
				'post_id'    => $pid,
				'title'      => (string) get_the_title( $pid ),
				'candidates' => array_map( function ( $c ) {
					return array( 'version' => $c['version'], 'minted_at' => $c['minted_at'] );
				}, $candidates ),
			);
			continue;
		}
		$day    = substr( $candidates[0]['minted_at'], 0, 10 );
		$rows[] = array(
			'post_id'        => $pid,
			'title'          => (string) get_the_title( $pid ),
			'version'        => $candidates[0]['version'],
			'minted_at'      => $candidates[0]['minted_at'],
			'current_class'  => snt_prov_change_class_of( $candidates[0]['entry'] ),
			'proposed_class' => (string) $label['change_class'],
			'audit_match'    => null === $days ? 'audit_unavailable' : ( isset( $days[ $day ] ) ? 'sn_apply_write_same_day' : 'no_sn_apply_write_that_day' ),
		);
	}
	usort( $rows, function ( $a, $b ) {
		return $a['post_id'] <=> $b['post_id'];
	} );
	return array( 'rows' => $rows, 'refused' => $refused );
}

/**
 * The fingerprint over the planned rows: which version, and what its label
 * is and will be. A label that moves after the observe makes it stale.
 *
 * @param array $rows
 * @return string
 */
function snt_sn_apply_backfill_fingerprint( array $rows ) {
	$bound = array_map( function ( $r ) {
		return array( $r['post_id'], $r['version'], $r['minted_at'], $r['current_class'], $r['proposed_class'] );
	}, $rows );
	return hash( 'sha256', (string) wp_json_encode( $bound ) );
}

/**
 * The window days on which an applied (not dry-run) sn_apply write landed,
 * as a set keyed by UTC date, or null when the audit log is not readable.
 * Backfill calls are excluded: they label, they never mint.
 *
 * @param string[] $dates
 * @return array<string,bool>|null
 */
function snt_sn_apply_backfill_write_days( array $dates ) {
	if ( ! function_exists( 'sn_mcp_rw_audit_get_blob' ) ) {
		return null;
	}
	$days = array();
	foreach ( (array) ( sn_mcp_rw_audit_get_blob()['rows'] ?? array() ) as $row ) {
		$a   = (array) ( $row['args_redacted'] ?? array() );
		$day = gmdate( 'Y-m-d', (int) ( $row['ts'] ?? 0 ) );
		if ( 'signal-noise/sn-apply' === ( $row['slug'] ?? '' ) && 'ok' === ( $row['outcome'] ?? '' ) && true === ( $a['applied'] ?? null )
			&& false === ( $a['dry_run'] ?? null ) && 'change_class_backfill' !== ( $a['change_type'] ?? '' ) && in_array( $day, $dates, true ) ) {
			$days[ $day ] = true;
		}
	}
	return $days;
}

/**
 * The anchor-status change_classes totals, optionally with planned rows
 * applied on top (the would-be totals a dry run reports).
 *
 * @param array $rows Rows to apply; empty for the current totals.
 * @return array<string,int>
 */
function snt_sn_apply_backfill_totals( array $rows = array() ) {
	$totals = function_exists( 'snt_prov_anchor_overview' ) ? (array) snt_prov_anchor_overview()['change_classes'] : array();
	foreach ( $rows as $r ) {
		$from = '' === $r['current_class'] ? 'unclassified' : $r['current_class'];
		if ( isset( $totals[ $from ], $totals[ $r['proposed_class'] ] ) ) {
			$totals[ $from ]--;
			$totals[ $r['proposed_class'] ]++;
		}
	}
	return $totals;
}

require_once __DIR__ . '/change-class-backfill-parts.php';

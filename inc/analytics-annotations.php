<?php
/**
 * Signal & Noise Tools — Analytics annotation resolvers (Release 1).
 *
 * One pure function per eligible panel: it receives the data the panel already
 * fetched and returns a one-sentence "read" or null. Zero new AE queries, zero
 * AI, fully deterministic (and therefore unit-testable). Thresholds are the
 * SN_ANNOTATION_* constants below, tuned conservative so quiet ranges stay silent.
 *
 * Rendered by snt_an_annotation() (inc/analytics-panels.php).
 *
 * @package SignalNoiseTools
 * @since 9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Movers: need at least this many movers to read a direction, and this share of
// them must point one way for the skew to be worth stating.
const SN_ANNOTATION_MOVERS_MIN  = 3;
const SN_ANNOTATION_MOVERS_SKEW = 0.6;

// Anomalies: this many pages of one divergence type before it is worth a line.
const SN_ANNOTATION_ANOMALY_MIN = 2;

// Lifecycle: below this catalogue size a census is not meaningful; at or above
// this refresh-candidate count the read fires.
const SN_ANNOTATION_LIFECYCLE_MIN_TOTAL      = 8;
const SN_ANNOTATION_LIFECYCLE_MIN_CANDIDATES = 3;

// Overview: views must move at least this percent for a volume/engagement
// divergence to be worth calling out.
const SN_ANNOTATION_OVERVIEW_VIEWS_PCT = 15;

/**
 * Movers read: state the direction of movement when it clearly skews one way.
 * Uses only { path, views, delta }, with no post age (age would need a per-path
 * query, breaking the zero-new-query rule). Null on mixed or thin movement.
 *
 * @param array $movers [ { path, views, delta } ] from sn_analytics_movers().
 * @return string|null
 */
function sn_annotation_movers( $movers ) {
	$movers = is_array( $movers ) ? $movers : array();
	$total  = count( $movers );
	if ( $total < SN_ANNOTATION_MOVERS_MIN ) {
		return null;
	}
	$up   = 0;
	$down = 0;
	foreach ( $movers as $m ) {
		$d = (int) ( $m['delta'] ?? 0 );
		if ( $d > 0 ) {
			++$up;
		} elseif ( $d < 0 ) {
			++$down;
		}
	}
	if ( $down >= $up && $down / $total >= SN_ANNOTATION_MOVERS_SKEW ) {
		return sprintf(
			/* translators: 1: count of declining pages, 2: total movers */
			__( 'Movement skews down: %1$d of %2$d movers lost views.', 'signal-and-noise-tools' ),
			$down,
			$total
		);
	}
	if ( $up > $down && $up / $total >= SN_ANNOTATION_MOVERS_SKEW ) {
		return sprintf(
			/* translators: 1: count of rising pages, 2: total movers */
			__( 'Movement skews up: %1$d of %2$d movers gained views.', 'signal-and-noise-tools' ),
			$up,
			$total
		);
	}
	return null;
}

/**
 * Anomalies read: summarize the divergence rows by type (skim / stall). Null
 * below the per-type threshold.
 *
 * @param array $anom { divergence:[ { type } ], outliers:[] } from sn_analytics_engagement_anomalies().
 * @return string|null
 */
function sn_annotation_anomalies( $anom ) {
	$div   = ( is_array( $anom ) && isset( $anom['divergence'] ) && is_array( $anom['divergence'] ) ) ? $anom['divergence'] : array();
	$skim  = 0;
	$stall = 0;
	foreach ( $div as $d ) {
		$t = (string) ( $d['type'] ?? '' );
		if ( 'skim' === $t ) {
			++$skim;
		} elseif ( 'stall' === $t ) {
			++$stall;
		}
	}
	$parts = array();
	if ( $skim >= SN_ANNOTATION_ANOMALY_MIN ) {
		/* translators: %d is the number of skimmed pages */
		$parts[] = sprintf( __( '%d pages skimmed: deep scroll, fast leave.', 'signal-and-noise-tools' ), $skim );
	}
	if ( $stall >= SN_ANNOTATION_ANOMALY_MIN ) {
		/* translators: %d is the number of stalled pages */
		$parts[] = sprintf( __( '%d pages stalled: long dwell, low scroll.', 'signal-and-noise-tools' ), $stall );
	}
	return empty( $parts ) ? null : implode( ' ', $parts );
}

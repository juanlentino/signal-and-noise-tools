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

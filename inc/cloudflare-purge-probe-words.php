<?php
/**
 * Signal & Noise Tools: the words every surface uses for a probe's escalation.
 *
 * 2026-10-13: a stale post-save probe purges the theme's whole-site cache tag
 * (every page, feed and machine file; assets stay cached) and falls back to a
 * zone purge only when Cloudflare refuses the tag call. Rows written before
 * that carry no `escalation` field and were zone purges. The classic page, the
 * native leaf and Attention read these, so the three say the same thing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which escalation a probe row ran: 'tag', 'zone' or '' (none).
 *
 * @param array $row A probe log row.
 * @return string
 */
function snt_cf_probe_escalation( array $row ) {
	if ( 'stale' !== (string) ( $row['result'] ?? '' ) || empty( $row['escalated'] ) ) {
		return '';
	}
	// No field: a row from before 2026-10-13, when every escalation was a zone purge.
	return 'tag' === (string) ( $row['escalation'] ?? 'zone' ) ? 'tag' : 'zone';
}

/**
 * The suffix a stale row's Result carries, or ''.
 *
 * @param array $row A probe log row.
 * @return string
 */
function snt_cf_probe_escalation_label( array $row ) {
	$how = snt_cf_probe_escalation( $row );
	if ( 'tag' === $how ) {
		return __( '→ whole-site tag purge', 'signal-and-noise-tools' );
	}
	return 'zone' === $how ? __( '→ zone purge', 'signal-and-noise-tools' ) : '';
}

/**
 * The sentence Attention adds to a stale row that escalated, or ''.
 *
 * @param array $row A probe log row.
 * @return string
 */
function snt_cf_probe_escalation_sentence( array $row ) {
	$how = snt_cf_probe_escalation( $row );
	if ( 'tag' === $how ) {
		return __( 'The whole-site cache tag was purged.', 'signal-and-noise-tools' );
	}
	return 'zone' === $how ? __( 'A zone purge was forced.', 'signal-and-noise-tools' ) : '';
}

/**
 * The Post-purge probes box's description, the same on both surfaces.
 *
 * @param int $delay Seconds between a purge and its probe.
 * @return string
 */
function snt_cf_probe_intro( $delay ) {
	return sprintf(
		/* translators: %d: seconds between a purge and its probe */
		__( 'Each row is one check of the page a reader would actually get, %d seconds after its purge. A stale row escalated at the time: it purged the whole-site cache tag (every page, feed and machine file; images, styles and scripts stay cached), or the whole zone when Cloudflare refused the tag call or the row is from before October 13, 2026. So it records a purge that needed a second attempt, not a page still stale now.', 'signal-and-noise-tools' ),
		(int) $delay
	);
}

<?php
/**
 * Signal & Noise — Content Health check: cadence deviations (v10.22.0).
 *
 * Thin adapter over snt_ml_cadence_flags() (inc/ml-cadence.php): each flag —
 * a publishing rhythm or a recorded cron hook whose current gap z-scores at
 * three sigmas or more against its own history — becomes one finding. Rides
 * the 24h health scan, so the count reaches the Health tab, the desktop
 * health widget, and the attention badge from the CACHED scan (the badge's
 * never-computes contract holds).
 *
 * @package SignalNoiseTools
 * @since 10.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the cadence scan and pack it as a health check.
 *
 * @return array The sn_health_pack_check envelope.
 */
function sn_health_check_ml_cadence() {
	$label    = __( 'Cadence deviations', 'signal-and-noise-tools' );
	$fix_hint = __( 'A rhythm broke: the current gap is a statistical outlier against its own history. For a cron hook, check it still schedules and its last runs succeeded; for publishing, this is just the site noticing a quiet spell.', 'signal-and-noise-tools' );

	if ( ! function_exists( 'snt_ml_cadence_flags' ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'The cadence module is not loaded, so nothing was scanned.' ); // Defensive; module loads with the plugin.
	}

	$env      = snt_ml_cadence_flags();
	$findings = array();
	foreach ( (array) ( is_array( $env ) ? ( $env['flags'] ?? array() ) : array() ) as $flag ) {
		if ( ! is_array( $flag ) || ! isset( $flag['subject'], $flag['z'] ) ) {
			continue; // Malformed row: skip, never fabricate.
		}
		// A views flag speaks in COUNTS: its numbers are weekly view totals,
		// and humanizing 105 views as "105 seconds" would be a category error
		// wearing the right units' clothes.
		if ( 'views' === (string) ( $flag['kind'] ?? '' ) ) {
			$note = sprintf(
				/* translators: 1: z-score, 2: typical weekly views, 3: this week's views. */
				__( 'z %1$s · a typical week reads ~%2$s views · this week %3$s', 'signal-and-noise-tools' ),
				sprintf( '%.2f', (float) $flag['z'] ),
				number_format_i18n( (int) ( $flag['expected_views'] ?? 0 ) ),
				number_format_i18n( (int) ( $flag['current_views'] ?? 0 ) )
			);
		} else {
			$note = sprintf(
				/* translators: 1: z-score, 2: expected gap, 3: current gap. */
				__( 'z %1$s · expected gap ~%2$s · current %3$s', 'signal-and-noise-tools' ),
				sprintf( '%.2f', (float) $flag['z'] ),
				snt_ml_cadence_human_gap( (float) ( $flag['expected_gap'] ?? $flag['ewma'] ?? 0 ) ),
				snt_ml_cadence_human_gap( (float) ( $flag['current_gap'] ?? 0 ) )
			);
		}
		$findings[] = array(
			'subject_label' => (string) $flag['subject'],
			'note'          => $note,
			'edit_url'      => '',
		);
	}

	// The module computes cron_skipped / views_skipped for exactly this reason
	// and the adapter used to read only `flags`. A section that could not be
	// read is named; the tally only honours it when there are no findings.
	$unread = array();
	if ( ! empty( $env['cron_skipped'] ) ) {
		$unread[] = 'cron history';
	}
	if ( ! empty( $env['views_skipped'] ) ) {
		$unread[] = 'views rollup';
	}
	$skipped = array() === $unread ? null : sprintf( 'The %s could not be read, so this scan is partial. The check retries on the next scan.', implode( ' and the ', $unread ) );

	return sn_health_pack_check( $label, $findings, $fix_hint, $skipped );
}

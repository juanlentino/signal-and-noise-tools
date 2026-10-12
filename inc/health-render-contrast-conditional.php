<?php
/**
 * Signal & Noise Tools, Health tab: the contrast report's placement-dependent
 * pairings (classic renderer).
 *
 * Moved out of inc/health-render-contrast.php on 2026-10-11 (that file was
 * over the house line cap) when the table stopped capping silently: it cut at
 * SN_HEALTH_CONTRAST_USAGE_MAX_ROWS with no line saying so, and the live site
 * carries hundreds of these rows. The remainder is counted now, the same line
 * S&N Home's Integrity > Reports paints
 * (apps/sn-dashboard/parts/leaves/tools-reports-usage.php).
 *
 * @package SignalNoiseTools
 * @since 10.90.0 (function), moved 2026-10-11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Components that clear the page background but would fail on another surface
 * this design system actually paints.
 *
 * DELIBERATELY NOT IN THE HEADLINE COUNT. A component with no background of its
 * own has a contrast that depends on where it is placed, and no stylesheet scan
 * can say where that is: counting these as live defects would repeat, one tier
 * down, exactly the overclaim that made the arithmetic table misleading.
 *
 * They are still worth showing: the provenance chip's `muted` state passes on
 * white at 4.83:1 and fails on the served asphalt at 3.66:1, which is the shape
 * of thing that ships unnoticed for months. So: collapsed, counted, and worded
 * as a conditional. The rows arrive sorted worst-first, so the cap cuts the
 * safe tail.
 *
 * @param array $conditional Rows from the usage scan.
 * @since 10.90.0
 */
function sn_health_render_contrast_conditional( $conditional ) {
	if ( empty( $conditional ) ) {
		return;
	}

	echo '<details class="sn-health-contrast-conditional sn-disclosure">';
	echo '<summary>';
	printf(
		/* translators: %d: conditional pairing count */
		esc_html__( '%d placement-dependent pairing(s): pass on the page background, would fail on another surface the design system paints', 'signal-and-noise-tools' ),
		count( $conditional )
	);
	echo '</summary>';

	echo '<p class="sn-field-helper">' . esc_html__( 'These components set no background of their own, so where they land decides whether they pass. Not counted above, because a stylesheet cannot say where they land.', 'signal-and-noise-tools' ) . '</p>';

	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped snt-mt-half"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Selector', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Would be', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-90px">' . esc_html__( 'Ratio', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Palette', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	$visible = array_slice( $conditional, 0, SN_HEALTH_CONTRAST_USAGE_MAX_ROWS );
	foreach ( $visible as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) ( $row['selector'] ?? '' ) ) . '</code>';
		echo '<br><span class="sn-field-helper">' . esc_html( (string) ( $row['source'] ?? '' ) ) . '</span></td>';
		echo '<td><code>' . esc_html( (string) ( $row['pair'] ?? '' ) ) . '</code></td>';
		echo '<td>' . esc_html( number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) ) . ':1</td>';
		echo '<td>' . esc_html( (string) ( $row['palette'] ?? '' ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';

	// Never a silent cap: the rows past it are counted, not dropped.
	$hidden = count( $conditional ) - count( $visible );
	if ( $hidden > 0 ) {
		echo '<p class="sn-field-helper">';
		printf(
			/* translators: %s: placement-dependent pairings not listed */
			esc_html__( '+%s more placement-dependent pairings, sorted worst-first; the tail is the safe end.', 'signal-and-noise-tools' ),
			esc_html( number_format_i18n( $hidden ) )
		);
		echo '</p>';
	}
	echo '</details>';
}

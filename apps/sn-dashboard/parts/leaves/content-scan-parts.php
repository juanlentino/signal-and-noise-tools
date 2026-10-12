<?php
/**
 * S&N Dashboard: Content > Pattern Adoption and Block Migrations, the layout
 * both scanners share (2026-10-12 audit).
 *
 * Each leaf was one narrow box (the count, the intro, the scan button) capped
 * beside two thirds of empty window, its counts read as current with no age.
 * Now: the status box (count, when it scanned, the scan button) beside what
 * the scanner looks for, then the queue or the clean note at full width. The
 * sentences are inc/content-scans-shared.php's, which the classic twins print.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Both scanners' layout: the status box (the count, when
 * the scan ran, the scan button) beside what it looks for, then the queue
 * or the clean note at full width. 2026-10-12 audit: one narrow box left two
 * thirds of the window empty, and the counts read as current with no age.
 *
 * @param string     $heading The scanner.
 * @param array|null $scan    The cached scan, null before the first.
 * @param string     $badge   The count pill ('' before the first scan).
 * @param string     $form    The scan form.
 * @param string     $about   What it looks for (escaped prose).
 * @param string     $result  The queue or the clean note ('' before the first scan).
 * @return string
 */
function content_scan_layout( $heading, $scan, $badge, $form, $about, $result ) {
	$age    = is_array( $scan ) && ! empty( $scan['scanned_at'] )
		? sprintf( \snt_kit_esc( \snt_content_scan_age_format() ), \snt_kit_relative_time( (int) $scan['scanned_at'] ) )
		: \snt_kit_esc( is_array( $scan ) ? __( 'Scanned (the time was not recorded).', 'signal-and-noise-tools' ) : __( 'Not scanned yet.', 'signal-and-noise-tools' ) );
	$status = \snt_kit_section( $heading, $badge . '<p class="snt-prose">' . $age . '</p>' . $form );
	$what   = \snt_kit_section( __( 'What it looks for', 'signal-and-noise-tools' ), '<p class="snt-prose">' . $about . '</p>' );
	return \snt_kit_grid( array( $status, $what ) ) . ( '' !== $result ? \snt_kit_section( __( 'Candidates', 'signal-and-noise-tools' ), $result ) : '' );
}

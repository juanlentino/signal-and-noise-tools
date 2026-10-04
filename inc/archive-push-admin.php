<?php
/**
 * Signal & Noise Tools: the Internet Archive fieldset on the classic
 * Tools > Provenance page (21.1.0), the twin of
 * apps/sn-dashboard/parts/leaves/tools-provenance-archive.php. Same state,
 * same one button, through the same handler (archive_push_existing).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paint the fieldset; nothing when the push module is not loaded.
 *
 * @return void
 */
function sn_archive_existing_render_fieldset() {
	if ( ! function_exists( 'sn_archive_existing_status_line' ) || ! function_exists( 'snt_watch_ripe_archive_push' ) || ! function_exists( 'sn_admin_post_url' ) ) {
		return;
	}
	$watch   = snt_watch_ripe_archive_push( array(), time() );
	$run     = (array) get_option( SN_ARCHIVE_EXISTING_OPT, array() );
	$pending = count( sn_archive_existing_pending() );
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Internet Archive', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'A note is pushed to the Internet Archive on its first publish, so a dated outside copy exists from day one. An accepted request is not a confirmed capture; the crawl runs later.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . esc_html( ucfirst( $watch['note'] ) ) . '</p>';
	echo '<p><strong>' . esc_html__( 'Older notes:', 'signal-and-noise-tools' ) . '</strong> ' . esc_html( sn_archive_existing_status_line() ) . '</p>';
	if ( $pending > 0 && 'running' !== ( $run['state'] ?? '' ) && null !== sn_archive_push_keys() ) {
		echo '<form method="post" action="' . esc_url( sn_admin_post_url( 'archive_push_existing' ) ) . '">';
		echo '<input type="hidden" name="action" value="sn_archive_push_existing" />';
		echo '<button type="submit" class="button button-primary">' . esc_html(
			'halted' === ( $run['state'] ?? '' )
				? __( 'Resume', 'signal-and-noise-tools' )
				/* translators: %s: number of notes. */
				: sprintf( __( 'Push %s older notes', 'signal-and-noise-tools' ), number_format_i18n( $pending ) )
		) . '</button></form>';
		echo '<p class="sn-field-helper">' . esc_html__( 'About one note every five to ten minutes, oldest first. Any refusal halts the run until you resume it.', 'signal-and-noise-tools' ) . '</p>';
	}
	echo '</div>';
}

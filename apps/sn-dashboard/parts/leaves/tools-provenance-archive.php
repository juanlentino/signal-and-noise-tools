<?php
/**
 * Tools > Provenance: the Internet Archive section (21.1.0). What the push
 * module holds (configured, the last push, the failures not since accepted)
 * and the one owner-started run over notes that predate the keys.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The section, '' when the push module is not loaded.
 *
 * @return string
 */
function provenance_archive_html() {
	if ( ! function_exists( 'sn_archive_existing_status_line' ) || ! function_exists( 'snt_watch_ripe_archive_push' ) ) {
		return '';
	}
	$watch      = \snt_watch_ripe_archive_push( array(), time() );
	$run        = (array) get_option( SN_ARCHIVE_EXISTING_OPT, array() );
	$pending    = count( \sn_archive_existing_pending() );
	$configured = null !== \sn_archive_push_keys();
	// Not configured is neither a pass nor a finding (the watch says so): a
	// neutral notice, said once. It was a green notice, then the same
	// sentence again as the "Older notes" line (2026-10-12 audit). A failed
	// push outranks it in the notice, so then the line stays: it is the only
	// place that says the keys are gone.
	$inner = '<p class="snt-prose">' . \snt_kit_esc( __( 'A note is pushed to the Internet Archive on its first publish, so a dated outside copy exists from day one. An accepted request is not a confirmed capture; the crawl runs later.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_notice( $watch['ripe'] ? 'warn' : ( $configured ? 'ok' : 'info' ), \snt_kit_esc( ucfirst( $watch['note'] ) ) )
		. ( $configured || $watch['ripe'] ? '<p class="snt-prose"><strong>' . \snt_kit_esc( __( 'Older notes:', 'signal-and-noise-tools' ) ) . '</strong> ' . \snt_kit_esc( \sn_archive_existing_status_line() ) . '</p>' : '' );
	if ( $pending > 0 && 'running' !== ( $run['state'] ?? '' ) && $configured ) {
		$inner .= provenance_post_action(
			'archive_push_existing',
			'halted' === ( $run['state'] ?? '' )
				? __( 'Resume', 'signal-and-noise-tools' )
				/* translators: %s: number of notes. */
				: sprintf( __( 'Push %s older notes', 'signal-and-noise-tools' ), number_format_i18n( $pending ) )
		) . '<p class="snt-hint">' . \snt_kit_esc( __( 'About one note every five to ten minutes, oldest first. Any refusal halts the run until you resume it.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	return \snt_kit_section( __( 'Internet Archive', 'signal-and-noise-tools' ), $inner );
}

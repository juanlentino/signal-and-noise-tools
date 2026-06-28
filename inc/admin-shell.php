<?php
/**
 * Signal & Noise Tools — two-column admin shell (full-width equal columns).
 *
 * A reusable layout primitive for admin sub-tabs that pair a work column
 * (forms, primary actions) with a secondary readout column (status box,
 * metrics, spend). Both columns share the FULL content width equally — the
 * same auto-fit treatment as .sn-2up on the Analytics tab — and collapse to
 * one column (DOM order, so the readout reads after the main) when the content
 * area is narrow.
 *
 * Echo-style, deliberately. Every SN admin renderer echoes its markup
 * inline (escaped at each output sink), so the shell is emitted as three
 * static-literal echoes — open / rail / close — and the existing render
 * functions flow straight into the correct column with no buffering.
 * Nothing re-echoes a composed HTML string, which would trip WPCS
 * EscapeOutput; the only dynamic value is the rail's aria-label, which is
 * esc_attr()'d.
 *
 * Full-width since v6.44.1. It was a capped 820px main + a fixed 300px sticky
 * rail (v6.42.0), which left the layout left-aligned with dead space on a real
 * monitor and squeezed the rail so its tables wrapped their headers. The grid
 * lives in assets/admin.css (.sn-shell); the function names keep "rail" for the
 * second column for backward compatibility, though it is now an equal column.
 *
 * Usage (the caller gates current_user_can BEFORE opening the shell):
 *
 *   sn_admin_shell_open();
 *   // … main column echoes: intro, primary forms, primary tables …
 *   sn_admin_shell_rail( 'Scan status' );
 *   // … rail echoes: status box, readouts, secondary actions …
 *   sn_admin_shell_close();
 *
 * Contract: between open() and close() the caller MUST NOT return early or
 * the wrapper divs go unbalanced. Convert any early-return into a
 * conditional so control always reaches close().
 *
 * @package SignalNoiseTools
 * @since 6.42.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Open the two-column shell and the main column.
 *
 * @return void
 */
function sn_admin_shell_open() {
	echo '<div class="sn-shell"><div class="sn-shell__main">';
}

/**
 * Close the main column and open the sticky right rail.
 *
 * @param string $aria_label Accessible name for the rail landmark.
 * @return void
 */
function sn_admin_shell_rail( $aria_label = 'Summary' ) {
	echo '</div><aside class="sn-shell__rail" aria-label="' . esc_attr( $aria_label ) . '">';
}

/**
 * Close the right rail and the shell.
 *
 * @return void
 */
function sn_admin_shell_close() {
	echo '</aside></div>';
}

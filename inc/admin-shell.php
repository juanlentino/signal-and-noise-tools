<?php
/**
 * Signal & Noise Tools — two-column admin shell (capped main + fixed rail).
 *
 * A reusable layout primitive for admin sub-tabs that carry a passive,
 * read-only readout (status box, metrics, spend) which wastes the full
 * 820px content width when stacked. It moves that readout into a fixed
 * 300px sticky right rail beside the capped main column, reclaiming the
 * horizontal space the single-column stack left empty.
 *
 * Echo-style, deliberately. Every SN admin renderer echoes its markup
 * inline (escaped at each output sink), so the shell is emitted as three
 * static-literal echoes — open / rail / close — and the existing render
 * functions flow straight into the correct column with no buffering.
 * Nothing re-echoes a composed HTML string, which would trip WPCS
 * EscapeOutput; the only dynamic value is the rail's aria-label, which is
 * esc_attr()'d.
 *
 * The asymmetry (a FIXED rail beside a fluid-CAPPED main) is intentional.
 * The earlier fluid two-column .sn-2col split was collapsed to a single
 * column in v3.8.5 because two fluid columns both stayed cramped at every
 * viewport. A fixed rail can never starve the main, and the main keeps the
 * exact 820px cap every other tab already uses.
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

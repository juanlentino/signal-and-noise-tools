<?php
/**
 * Signal & Noise Tools — two-column admin shell (full-width, asymmetric).
 *
 * A reusable layout primitive for admin sub-tabs that pair a primary work column
 * (forms, primary actions) with a narrower secondary readout column (status box,
 * compact metrics). Full-width, asymmetric ~62/38 — WordPress's own normal/side
 * dashboard proportion (main wider than side), collapsing to one column (DOM
 * order, so the readout reads after the main) when the content area is narrow.
 * RULE: wide content (data tables) belongs in the MAIN column — the narrower
 * side holds only compact readouts/status.
 *
 * Echo-style, deliberately. Every SN admin renderer echoes its markup
 * inline (escaped at each output sink), so the shell is emitted as three
 * static-literal echoes — open / rail / close — and the existing render
 * functions flow straight into the correct column with no buffering.
 * Nothing re-echoes a composed HTML string, which would trip WPCS
 * EscapeOutput; the only dynamic value is the rail's aria-label, which is
 * esc_attr()'d.
 *
 * Full-width since v6.44.1; asymmetric since v6.45.0 (was a capped 820px main +
 * fixed 300px rail in v6.42.0, then equal columns in v6.44.1). The grid lives in
 * assets/admin.css (.sn-shell); the function names keep "rail" for the second
 * column for backward compatibility.
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

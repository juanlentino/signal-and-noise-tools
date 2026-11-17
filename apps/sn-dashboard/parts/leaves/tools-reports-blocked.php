<?php
/**
 * S&N Dashboard, Integrity > Reports: the contact form's blocked attempts,
 * the native twin of snt_fs_tally_render() (inc/forms-blocked-tally.php).
 * Same rows from the same reader; counts only. Shown with or without a scan.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The box, or '' when the tally module is not loaded.
 *
 * @return string
 */
function tools_reports_blocked_html() {
	if ( ! function_exists( 'snt_fs_blocked_daily' ) || ! defined( 'SNT_FS_TALLY_GUARDS' ) ) {
		return '';
	}
	$all  = \snt_fs_blocked_daily();
	$rows = array_slice( $all, 0, TOOLS_REPORTS_ROWS );
	$lead = __( 'Submissions the form refused, counted per day by the guard that caught them (Rule is the blocklist and the content rules). Counts only: no message, name, email or address is kept. Days with none have no row.', 'signal-and-noise-tools' );
	if ( ! $rows ) {
		return \snt_kit_section( __( 'Contact form: blocked attempts', 'signal-and-noise-tools' ), '<p class="snt-prose">' . \snt_kit_esc( __( 'No blocked attempt recorded yet.', 'signal-and-noise-tools' ) ) . '</p>', $lead );
	}
	$cols = array( array( 'key' => 'day', 'label' => __( 'Day', 'signal-and-noise-tools' ) ) );
	foreach ( \SNT_FS_TALLY_GUARDS as $key => $label ) {
		$cols[] = array( 'key' => $key, 'label' => $label, 'align' => 'end' );
	}
	$more = count( $all ) > count( $rows ) ? sprintf( '+%d more days', count( $all ) - count( $rows ) ) : '';
	return \snt_kit_section( __( 'Contact form: blocked attempts', 'signal-and-noise-tools' ), \snt_kit_table( $cols, $rows, tools_reports_ledger_opts( $more ) ), $lead );
}

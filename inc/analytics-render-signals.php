<?php
/**
 * Quality tab: the beacon bot-signals panel (observe-only). "Likely automated"
 * beside human, then each signal's fire rate per cohort. Native wp-admin table
 * via the panel primitive. Data: sn_bot_signals_stored() (the nightly reading).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';

/**
 * Render the panel.
 *
 * @param array|null $r Stored readout, or null when nothing is measured yet.
 */
function snt_analytics_render_bot_signals( $r ) {
	$title = __( 'Bot signals (observe-only)', 'signal-and-noise-tools' );
	if ( ! is_array( $r ) || empty( $r['cohorts'] ) ) {
		snt_an_note_empty( $title, __( 'Not measured yet: the reading runs with the nightly rollup once the worker and theme releases send the signals.', 'signal-and-noise-tools' ) );
		return;
	}
	$labels = array(
		'relay'      => __( 'Relay readers (Safari via Private Relay)', 'signal-and-noise-tools' ),
		'intent'     => __( 'Intent visitors (download, contact, verify)', 'signal-and-noise-tools' ),
		'stored_bot' => __( 'Stored bots that ran the script', 'signal-and-noise-tools' ),
		'over_cap'   => __( 'Over-cap visitor-days', 'signal-and-noise-tools' ),
		'hosting'    => __( 'Hosting suspects', 'signal-and-noise-tools' ),
	);
	$h = (array) ( $r['human'] ?? array() );
	snt_an_panel_open( $title, array( 'inside_class' => 'inside inside-flush' ) );
	echo '<div class="sn-an-panel">';
	echo '<p>' . esc_html( sprintf(
		/* translators: 1: likely automated count, 2: human visitor-days, 3: window days */
		__( 'Likely automated: %1$s of %2$s human visitor-days in the last %3$s days. Not subtracted from any count.', 'signal-and-noise-tools' ),
		number_format_i18n( (int) ( $h['likely_automated'] ?? 0 ) ),
		number_format_i18n( (int) ( $h['visitor_days'] ?? 0 ) ),
		number_format_i18n( (int) ( $r['window_days'] ?? 0 ) )
	) ) . '</p>';
	if ( ! empty( $r['truncated'] ) ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'The visitor-day list hit its row limit; rates cover the first rows only.', 'signal-and-noise-tools' ) . '</p>';
	}
	echo '<table class="sn-an-table widefat striped"><thead><tr><th scope="col" class="manage-column column-primary">' . esc_html__( 'Cohort', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="num">' . esc_html__( 'Visitor-days', 'signal-and-noise-tools' ) . '</th>';
	foreach ( SNT_BOT_SIGNAL_BITS as $name ) {
		echo '<th scope="col" class="num">' . esc_html( str_replace( '_', ' ', $name ) ) . '</th>';
	}
	echo '<th scope="col" class="num">' . esc_html__( 'Likely automated', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $labels as $c => $label ) {
		$row = (array) ( $r['cohorts'][ $c ] ?? array() );
		echo '<tr><td class="column-primary">' . esc_html( $label ) . '</td><td class="num">' . esc_html( number_format_i18n( (int) ( $row['n'] ?? 0 ) ) ) . '</td>';
		foreach ( SNT_BOT_SIGNAL_BITS as $name ) {
			$rate = $row['rate'][ $name ] ?? null;
			echo '<td class="num" data-colname="' . esc_attr( $name ) . '">' . esc_html( null === $rate ? '–' : number_format_i18n( (float) $rate, 1 ) . '%' ) . '</td>';
		}
		echo '<td class="num">' . esc_html( number_format_i18n( (int) ( $row['likely_automated'] ?? 0 ) ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p class="description">' . esc_html( sprintf(
		/* translators: 1: days carrying signals, 2: measured-at date */
		__( '%1$s days carry signals. A signal worth keeping reads near 0%% on relay readers and intent visitors and fires on the last three rows. Expected false positive: a phone reader who finishes a short page without scrolling or touching sends a time event with no input, so no input fires; the relay readers\' no-input rate is that human baseline, not a bot rate. Measured %2$s.', 'signal-and-noise-tools' ),
		number_format_i18n( (int) ( $r['days_present'] ?? 0 ) ),
		gmdate( 'Y-m-d H:i', (int) ( $r['measured_at'] ?? 0 ) ) . ' UTC'
	) ) . '</p>';
	echo '</div>';
	snt_an_panel_close();
}

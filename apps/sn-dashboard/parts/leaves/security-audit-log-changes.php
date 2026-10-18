<?php
/**
 * S&N Dashboard: Security > Audit log, the account and site changes.
 *
 * 2026-10-18. The same finding, cells, pill and store cap as the classic tab
 * (inc/audit-log-admin-changes.php); a change involving administrator access
 * carries the warn badge.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The changes table, newest first, the store cap as the section's context.
 *
 * @param array<int,array<string,mixed>> $rows snt_audit_get_changes_impl( 30 ).
 * @return string
 */
function audit_log_changes_html( array $rows ) {
	$heading = __( 'Account and site changes (last 30 days)', 'signal-and-noise-tools' );
	if ( array() === $rows || ! function_exists( 'snt_audit_log_change_cells' ) ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( __( 'No account or setting changes recorded in this window.', 'signal-and-noise-tools' ) ) . '</p>' );
	}
	$data = array();
	foreach ( $rows as $row ) {
		$c = snt_audit_log_change_cells( $row );
		$data[] = array(
			'formatted' => $c['formatted'],
			'change'    => $c['high']
				? array( 'html' => \snt_kit_esc( $c['change'] ) . ' ' . \snt_kit_badge( 'warn', snt_audit_log_change_pill_text() ), 'text' => $c['change'] )
				: $c['change'],
			'subject'   => $c['subject'],
			'actor'     => $c['actor'],
		);
	}
	$table = \snt_kit_table(
		array(
			array( 'key' => 'formatted', 'label' => __( 'When', 'signal-and-noise-tools' ) ),
			array( 'key' => 'change', 'label' => __( 'Change', 'signal-and-noise-tools' ) ),
			array( 'key' => 'subject', 'label' => __( 'Account or item', 'signal-and-noise-tools' ) ),
			array( 'key' => 'actor', 'label' => __( 'By', 'signal-and-noise-tools' ) ),
		),
		$data
	);
	return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( snt_audit_log_changes_finding( $rows ) ) . '</p>' . $table, snt_audit_log_changes_store_text() );
}

<?php
/**
 * Signal & Noise Tools: the Audit log's account and site changes.
 *
 * 2026-10-18. The words both twins print (the finding, the cells, the store
 * cap) live here; the S&N Home leaf (apps/sn-dashboard/parts/leaves/
 * security-audit-log-changes.php) paints the same helpers. A change that
 * grants administrator access leads the finding and carries the warn pill.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The finding over the changes: how many, and how many touch administrator
 * access, the newest of those first.
 *
 * @param array<int,array<string,mixed>> $rows snt_audit_get_changes_impl(), newest first.
 * @return string '' when there are none.
 */
function snt_audit_log_changes_finding( array $rows ) {
	if ( array() === $rows ) {
		return '';
	}
	// Refused app-password sign-ins are listed but are not changes.
	$rows = array_values( array_filter( $rows, 'snt_audit_is_change_row' ) );
	if ( array() === $rows ) {
		return __( 'No account or site change in the window; the rows below are refused app password sign-ins, the first of each reason on a day.', 'signal-and-noise-tools' );
	}
	$total = count( $rows );
	/* translators: %s: number of changes. */
	$all  = sprintf( _n( '%s change', '%s changes', $total, 'signal-and-noise-tools' ), number_format_i18n( $total ) );
	$high = array_values( array_filter( $rows, static function ( $r ) { return ! empty( $r['high'] ); } ) );
	if ( array() === $high ) {
		/* translators: 1: "N changes", 2: newest change time. */
		return sprintf( __( '%1$s in the window, the newest at %2$s; none involves administrator access.', 'signal-and-noise-tools' ), $all, (string) ( $rows[0]['formatted'] ?? '' ) );
	}
	return sprintf(
		/* translators: 1: "N changes", 2: changes involving administrator access, 3: the newest of those. */
		_n( '%1$s in the window; %2$s involves administrator access, the newest at %3$s.', '%1$s in the window; %2$s involve administrator access, the newest at %3$s.', count( $high ), 'signal-and-noise-tools' ),
		$all,
		number_format_i18n( count( $high ) ),
		(string) ( $high[0]['formatted'] ?? '' )
	);
}

/**
 * One change as both twins print it.
 *
 * @param array<string,mixed> $row A snt_audit_get_changes_impl() row.
 * @return array{formatted:string,change:string,subject:string,actor:string,high:bool}
 */
function snt_audit_log_change_cells( array $row ) {
	$detail = (string) ( $row['detail'] ?? '' );
	return array(
		'formatted' => (string) ( $row['formatted'] ?? '' ),
		'change'    => snt_audit_change_label( (string) ( $row['kind'] ?? '' ) ),
		'subject'   => (string) ( $row['subject'] ?? '' ) . ( '' !== $detail ? ' (' . $detail . ')' : '' ),
		'actor'     => '' !== (string) ( $row['actor'] ?? '' ) ? (string) $row['actor'] : __( 'no one signed in', 'signal-and-noise-tools' ),
		'high'      => ! empty( $row['high'] ),
	);
}

/**
 * The pill on a change that involves administrator access.
 *
 * @return string
 */
function snt_audit_log_change_pill_text() {
	return __( 'admin access', 'signal-and-noise-tools' );
}

/**
 * The store's ceiling, named under the table on both twins.
 *
 * @return string
 */
function snt_audit_log_changes_store_text() {
	/* translators: %s: the storage cap on change rows. */
	return sprintf( __( 'The store keeps at most %s changes, dropping the oldest first.', 'signal-and-noise-tools' ), number_format_i18n( SN_AUDIT_CHANGES_CAP ) );
}

/**
 * Render the changes table on the classic tab.
 *
 * @param array<int,array<string,mixed>> $rows snt_audit_get_changes_impl( 30 ).
 * @return void
 */
function snt_audit_log_render_changes_table( $rows ) {
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">' . esc_html__( 'Account and site changes (last 30 days)', 'signal-and-noise-tools' ) . '</h2>';
	if ( empty( $rows ) ) {
		echo '<p class="sn-prose">' . esc_html__( 'No account or setting changes recorded in this window.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	echo '<p class="sn-prose">' . esc_html( snt_audit_log_changes_finding( $rows ) ) . '</p>';
	echo '<div class="snt-scroll-table"><table class="widefat sn-audit-changes">';
	echo '<thead><tr><th scope="col">' . esc_html__( 'When', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Change', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Account or item', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'By', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$c    = snt_audit_log_change_cells( $row );
		$pill = $c['high'] ? ' <span class="sn-pill sn-pill--warn">' . esc_html( snt_audit_log_change_pill_text() ) . '</span>' : '';
		echo '<tr><td><code>' . esc_html( $c['formatted'] ) . '</code></td><td>' . esc_html( $c['change'] ) . $pill . '</td><td>' . esc_html( $c['subject'] ) . '</td><td>' . esc_html( $c['actor'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pill is built from esc_html() above.
	}
	echo '</tbody></table></div>';
	echo '<p class="sn-prose">' . esc_html( snt_audit_log_changes_store_text() ) . '</p>';
	echo '</div>';
}

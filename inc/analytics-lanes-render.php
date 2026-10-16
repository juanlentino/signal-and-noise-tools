<?php
/**
 * SN Traffic Lanes: the classic Integrity › Lanes leaf. Its native twin is
 * apps/sn-dashboard/parts/leaves/tools-lanes.php; both paint
 * sn_lanes_view() (inc/analytics-lanes-view.php), which reads only the
 * lanes table.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One view-model table as a wp-admin table.
 *
 * @param array $t Table from sn_lanes_view().
 * @return void
 */
function sn_lanes_render_table( array $t ) {
	$fold = (string) ( $t['fold'] ?? '' );
	if ( '' !== $fold ) {
		echo '<details class="sn-disclosure"><summary>' . esc_html( (string) $t['heading'] . ': ' . $fold ) . '</summary>';
		sn_lanes_render_table( array( 'fold' => '' ) + $t );
		echo '</details>';
		return;
	}
	echo '<h2 class="sn-section-h">' . esc_html( (string) $t['heading'] ) . '</h2>';
	echo '<p class="sn-field-helper">' . esc_html( (string) $t['description'] ) . '</p>';
	if ( empty( $t['rows'] ) ) {
		echo '<p class="sn-field-helper">' . esc_html__( 'Nothing stored yet.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	echo '<div class="snt-scroll-table"><table class="widefat striped snt-mt-half"><thead><tr>';
	foreach ( $t['columns'] as $c ) {
		echo '<th scope="col">' . esc_html( (string) $c['label'] ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $t['rows'] as $row ) {
		echo '<tr>';
		foreach ( $t['columns'] as $c ) {
			$v = $row[ $c['key'] ] ?? '';
			echo '<td>' . esc_html( is_int( $v ) ? number_format_i18n( $v ) : (string) $v ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * The classic leaf's body (its named wrapper, sn_admin_render_lanes_section(),
 * is in inc/admin-render-sections.php with the other leaves).
 *
 * @param array|null $view Test seam; null reads the table.
 * @return void
 */
function sn_lanes_render_section( $view = null ) {
	$view = is_array( $view ) ? $view : sn_lanes_view( sn_lanes_report_data() );
	$kind = 'warning' === $view['alert']['kind'] ? 'notice-warning' : 'notice-info';
	echo '<div class="notice ' . esc_attr( $kind ) . ' inline"><p><strong>' . esc_html( (string) $view['alert']['text'] ) . '</strong></p></div>';
	echo '<ul class="sn-prose">';
	foreach ( $view['notes'] as $note ) {
		echo '<li>' . esc_html( (string) $note ) . '</li>';
	}
	echo '</ul>';
	foreach ( $view['tables'] as $t ) {
		sn_lanes_render_table( $t );
	}
	echo '<details class="sn-disclosure"><summary>' . esc_html( 'How the lanes are decided: ' . (string) $view['reference']['summary'] ) . '</summary><ul class="sn-prose">';
	foreach ( $view['reference']['lines'] as $line ) {
		echo '<li>' . esc_html( (string) $line ) . '</li>';
	}
	echo '</ul></details>';
}

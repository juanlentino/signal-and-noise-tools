<?php
/**
 * Signal & Noise Tools, Machine Readers: "How each count is known", classic.
 *
 * The classic twin of the native leaf's block
 * (apps/sn-dashboard/parts/leaves/monitoring-machine-readers-basis.php): the
 * same rows (snt_mr_basis_display()), in the house widefat tables. Every cell
 * escaped at the sink.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One split table: a label column, an optional first-party column, the split.
 *
 * @param string $caption Caption.
 * @param string $head    Label column heading.
 * @param array  $rows    snt_mr_basis_display() rows.
 * @param bool   $fp      Whether to add the first-party column.
 * @return string
 */
function snt_mr_render_basis_table( $caption, $head, array $rows, $fp = false ) {
	$heads = array( $head => '' );
	if ( $fp ) {
		$heads[ __( 'This site’s own tools', 'signal-and-noise-tools' ) ] = 'num';
	}
	$heads += array(
		__( 'Claimed (user agent)', 'signal-and-noise-tools' ) => 'num',
		__( 'Verified (Cloudflare)', 'signal-and-noise-tools' ) => 'num',
		__( 'Verified for its purpose', 'signal-and-noise-tools' ) => 'num',
		__( 'Unverified (claim only)', 'signal-and-noise-tools' ) => 'num',
		__( 'Unverifiable (not measured)', 'signal-and-noise-tools' ) => 'num',
	);
	$out = snt_mr_table_open( $caption, $heads );
	foreach ( $rows as $r ) {
		$out .= '<tr><td class="column-primary"><strong>' . esc_html( (string) $r['label'] ) . '</strong></td>';
		if ( $fp ) {
			$out .= '<td class="num">' . esc_html( snt_mr_basis_count( $r['first_party'] ?? 0 ) ) . '</td>';
		}
		foreach ( array( 'claimed', 'verified', 'verified_matched', 'unverified', 'unverifiable' ) as $k ) {
			$out .= '<td class="num">' . esc_html( snt_mr_basis_count( $r[ $k ] ?? null ) ) . '</td>';
		}
		$out .= '</tr>';
	}
	return $out . '</tbody></table>';
}

/**
 * The block: legend, the two AI-training figures, purposes, families, and the
 * control tokens credited to no vendor.
 *
 * @param array $rows Aggregate rows.
 * @return string
 */
function snt_mr_render_basis( $rows ) {
	if ( ! function_exists( 'snt_mr_basis_display' ) ) {
		return '';
	}
	$d   = snt_mr_basis_display( (array) $rows );
	$out = '<div class="sn-mr-basis"><h4>' . esc_html__( 'How each count is known', 'signal-and-noise-tools' ) . '</h4>';
	$out .= '<p class="sn-an-settings-help">' . esc_html( snt_mr_basis_legend( $d['since'] ) ) . '</p>';
	$out .= snt_mr_render_basis_table( __( 'AI-training reads, by evidence.', 'signal-and-noise-tools' ), __( 'Figure', 'signal-and-noise-tools' ), $d['ai'] );
	$out .= null === $d['purposes']
		? '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'Purposes not measured: the deployed sensor predates the taxonomy.', 'signal-and-noise-tools' ) . '</p>'
		: snt_mr_render_basis_table( __( 'Reads by purpose, by evidence (third parties only).', 'signal-and-noise-tools' ), __( 'Purpose', 'signal-and-noise-tools' ), $d['purposes'] );
	$out .= snt_mr_render_basis_table( __( 'Reads by crawler family, by evidence.', 'signal-and-noise-tools' ), __( 'Family', 'signal-and-noise-tools' ), $d['families'], true );
	$out .= '<p class="sn-an-settings-help">' . esc_html__( 'Robots.txt control tokens presented as a user agent are credited to no vendor: Google-Extended and Applebot-Extended are names a vendor reads in robots.txt, not crawlers of their own.', 'signal-and-noise-tools' ) . '</p>';
	if ( array() === $d['impersonations'] ) {
		return $out . '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No control token was presented in this window.', 'signal-and-noise-tools' ) . '</p></div>';
	}
	$out .= snt_mr_table_open( __( 'Control tokens presented as a user agent.', 'signal-and-noise-tools' ), array(
		__( 'Agent', 'signal-and-noise-tools' )        => '',
		__( 'Token', 'signal-and-noise-tools' )        => '',
		__( 'Reads', 'signal-and-noise-tools' )        => 'num',
		__( 'Top networks', 'signal-and-noise-tools' ) => '',
	) );
	foreach ( $d['impersonations'] as $i ) {
		$out .= '<tr><td class="column-primary"><code>' . esc_html( (string) $i['agent'] ) . '</code></td><td>' . esc_html( (string) $i['token'] ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $i['hits'] ) ) . '</td><td>' . esc_html( snt_mr_basis_networks( $i ) ) . '</td></tr>';
	}
	return $out . '</tbody></table></div>';
}

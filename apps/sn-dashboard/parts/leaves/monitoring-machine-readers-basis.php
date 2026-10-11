<?php
/**
 * S&N Dashboard, Machine Readers: "How each count is known", from the kit.
 *
 * The native twin of inc/machine-readers-render-basis.php: the same rows
 * (snt_mr_basis_display()), painted with snt_kit_table.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One split table.
 *
 * @param string $head Label column heading.
 * @param array  $rows snt_mr_basis_display() rows.
 * @param bool   $fp   Whether to add the first-party column.
 * @return string
 */
function machine_readers_basis_table_html( $head, array $rows, $fp = false ) {
	$cols = array( array( 'key' => 'label', 'label' => $head ) );
	if ( $fp ) {
		$cols[] = array( 'key' => 'first_party', 'label' => __( 'This site’s own tools', 'signal-and-noise-tools' ), 'align' => 'end' );
	}
	foreach ( array(
		'claimed'          => __( 'Claimed (user agent)', 'signal-and-noise-tools' ),
		'verified'         => __( 'Verified (Cloudflare)', 'signal-and-noise-tools' ),
		'verified_matched' => __( 'Verified for its purpose', 'signal-and-noise-tools' ),
		'unverified'       => __( 'Unverified (claim only)', 'signal-and-noise-tools' ),
		'unverifiable'     => __( 'Unverifiable (not measured)', 'signal-and-noise-tools' ),
	) as $key => $label ) {
		$cols[] = array( 'key' => $key, 'label' => $label, 'align' => 'end' );
	}
	$out = array();
	foreach ( $rows as $r ) {
		$row = array( 'label' => (string) $r['label'] );
		if ( $fp ) {
			$row['first_party'] = \snt_mr_basis_count( $r['first_party'] ?? 0 );
		}
		foreach ( array( 'claimed', 'verified', 'verified_matched', 'unverified', 'unverifiable' ) as $k ) {
			$row[ $k ] = \snt_mr_basis_count( $r[ $k ] ?? null );
		}
		$out[] = $row;
	}
	return \snt_kit_table( $cols, $out, array( 'stack_on_phone' => true ) );
}

/**
 * The block: legend, the two AI-training figures, purposes, families, and the
 * control tokens credited to no vendor.
 *
 * @param array $rows Aggregate rows.
 * @return string
 */
function machine_readers_basis_html( array $rows ) {
	if ( ! function_exists( 'snt_mr_basis_display' ) ) {
		return '';
	}
	$d   = \snt_mr_basis_display( $rows );
	$out = '<p class="snt-hint">' . \snt_kit_esc( \snt_mr_basis_legend( $d['since'] ) ) . '</p>';
	$out .= machine_readers_basis_table_html( __( 'AI-training reads', 'signal-and-noise-tools' ), $d['ai'] );
	$out .= null === $d['purposes']
		? '<p class="snt-hint">' . \snt_kit_esc( __( 'Purposes not measured: the deployed sensor predates the taxonomy.', 'signal-and-noise-tools' ) ) . '</p>'
		: machine_readers_basis_table_html( __( 'Purpose (third parties)', 'signal-and-noise-tools' ), $d['purposes'] );
	$out .= machine_readers_basis_table_html( __( 'Family', 'signal-and-noise-tools' ), $d['families'], true );
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Robots.txt control tokens presented as a user agent are credited to no vendor: Google-Extended and Applebot-Extended are names a vendor reads in robots.txt, not crawlers of their own.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( array() === $d['impersonations'] ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'No control token was presented in this window.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$imp = array();
		foreach ( $d['impersonations'] as $i ) {
			$imp[] = array( 'agent' => (string) $i['agent'], 'token' => (string) $i['token'], 'reads' => number_format_i18n( (int) $i['hits'] ), 'networks' => \snt_mr_basis_networks( $i ) );
		}
		$out .= \snt_kit_table(
			array(
				array( 'key' => 'agent', 'label' => __( 'Agent', 'signal-and-noise-tools' ) ),
				array( 'key' => 'token', 'label' => __( 'Token', 'signal-and-noise-tools' ) ),
				array( 'key' => 'reads', 'label' => __( 'Reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
				array( 'key' => 'networks', 'label' => __( 'Top networks', 'signal-and-noise-tools' ) ),
			),
			$imp,
			array( 'stack_on_phone' => true )
		);
	}
	return \snt_kit_section( __( 'How each count is known', 'signal-and-noise-tools' ), $out );
}

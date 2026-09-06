<?php
/**
 * S&N Analytics — table and histogram helpers the view painters share.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A dimension / leaderboard table. Optional drill buttons under the grid.
 *
 * @param string                              $title     Section heading.
 * @param array<int,array<string,mixed>>|null $rows      Classic rows.
 * @param string                              $empty     Empty copy.
 * @param string                              $drill_dim Drill dimension, or ''.
 * @return string
 */
function dim_table( $title, $rows, $empty, $drill_dim = '' ) {
	if ( null === $rows ) {
		return \snt_kit_section( (string) $title, \snt_kit_notice( 'warn', __( 'This reading could not be loaded.', 'signal-and-noise-tools' ) ) );
	}
	$table_rows = array();
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$table_rows[] = array(
			'value'  => (string) ( $row['value'] ?? $row['label'] ?? $row['name'] ?? $row['path'] ?? $row['k'] ?? $row['title'] ?? '' ),
			'views'  => num( $row['views'] ?? $row['events'] ?? $row['v'] ?? $row['hits'] ?? 0 ),
			'visits' => isset( $row['visits'] ) || isset( $row['visitors'] ) ? num( $row['visits'] ?? $row['visitors'] ?? 0 ) : '',
		);
	}
	if ( array() === $table_rows ) {
		return \snt_kit_section( (string) $title, \snt_kit_empty( '', (string) $empty ) );
	}
	$columns = array(
		array( 'key' => 'value', 'label' => (string) $title ),
		array( 'key' => 'views', 'label' => __( 'Views', 'signal-and-noise-tools' ), 'align' => 'end' ),
	);
	if ( '' !== (string) $table_rows[0]['visits'] ) {
		$columns[] = array( 'key' => 'visits', 'label' => __( 'Visits', 'signal-and-noise-tools' ), 'align' => 'end' );
	}
	$inner = \snt_kit_table( $columns, $table_rows, array( 'empty' => (string) $empty ) );
	if ( '' !== (string) $drill_dim ) {
		$links = '';
		foreach ( array_slice( $table_rows, 0, 8 ) as $row ) {
			if ( '' === $row['value'] ) {
				continue;
			}
			$links .= pick( $row['value'], 'drill', $drill_dim . ':' . $row['value'], false );
		}
		$inner .= '' !== $links ? '<div class="snt-toolbar__group">' . $links . '</div>' : '';
	}
	return \snt_kit_section( (string) $title, $inner );
}

/**
 * Daily/weekly views as `<os-histogram>`.
 *
 * @param array<int,array<string,mixed>> $series  Rows with day + views.
 * @param string                         $heading Chart heading.
 * @return string
 */
function daily_histogram( array $series, $heading ) {
	$series = array_values( $series );
	if ( count( $series ) < 2 ) {
		return '';
	}
	$columns = array();
	foreach ( $series as $row ) {
		$columns[] = array( (int) ( $row['views'] ?? $row['visits'] ?? 0 ) );
	}
	$first = strtotime( (string) ( $series[0]['day'] ?? '' ) . ' 00:00:00 UTC' );
	$last  = strtotime( (string) ( $series[ count( $series ) - 1 ]['day'] ?? '' ) . ' 00:00:00 UTC' );
	return \snt_kit_histogram(
		array( array( 'key' => 'views', 'label' => (string) $heading, 'tone' => 'accent' ) ),
		$columns,
		array(
			'heading' => (string) $heading,
			'start'   => false !== $first ? $first : null,
			'end'     => false !== $last ? $last + ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ) : null,
			'height'  => 140,
		)
	);
}

/**
 * A labelled distribution (scroll / time / CWV bands).
 *
 * @param string                         $title Heading.
 * @param array<int,array<string,mixed>> $rows  Rows.
 * @param string                         $empty Empty copy.
 * @return string
 */
function distribution_table( $title, $rows, $empty ) {
	$table_rows = array();
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$table_rows[] = array(
			'band'  => (string) ( $row['label'] ?? $row['value'] ?? $row['band'] ?? '' ),
			'views' => num( $row['views'] ?? 0 ),
		);
	}
	if ( array() === $table_rows ) {
		return \snt_kit_section( (string) $title, \snt_kit_empty( '', (string) $empty ) );
	}
	return \snt_kit_section(
		(string) $title,
		\snt_kit_table(
			array(
				array( 'key' => 'band', 'label' => (string) $title ),
				array( 'key' => 'views', 'label' => __( 'Views', 'signal-and-noise-tools' ), 'align' => 'end' ),
			),
			$table_rows,
			array( 'empty' => (string) $empty )
		)
	);
}

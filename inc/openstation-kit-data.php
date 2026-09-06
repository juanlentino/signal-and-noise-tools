<?php
/**
 * Signal & Noise Tools — the kit's data elements, painted from PHP.
 *
 * `<os-table>` takes its rows as PROPERTIES, so a server view feeds it through
 * `os-prop-columns` / `os-prop-data` (the runtime assigns the parsed JSON after
 * every paint). `<os-histogram>` reads `series` / `columns` as JSON attributes.
 * The two list shapes the Dashboard's ops wall uses stay semantic HTML on the
 * shell's tokens, as the shell's own Station Home paints its lists.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `<os-table>` fed from markup. A column is `key` plus optional `label`,
 * `align` (start|center|end), `filter` (text|select), `sortable`, `stack`.
 *
 * @param array<int,array<string,mixed>> $columns Column descriptors.
 * @param array<int,array<string,mixed>> $rows    Row objects keyed by column key.
 * @param array<string,mixed>            $opts    empty, striped, hover, compact, bordered, sticky_header, selectable, class, id.
 * @return string
 */
function snt_kit_table( array $columns, array $rows, array $opts = array() ) {
	$cols = array();
	foreach ( $columns as $column ) {
		if ( is_string( $column ) ) {
			$column = array( 'key' => $column, 'label' => $column );
		}
		if ( ! is_array( $column ) || '' === (string) ( $column['key'] ?? '' ) ) {
			continue;
		}
		$cols[] = array_intersect_key( $column, array_flip( array( 'key', 'label', 'align', 'filter', 'sortable', 'stack', 'width' ) ) );
	}
	return snt_kit_tag(
		'os-table',
		array(
			'id'              => $opts['id'] ?? null,
			'class'           => $opts['class'] ?? null,
			'os-prop-columns' => $cols,
			'os-prop-data'    => array_values( $rows ),
			'striped'         => (bool) ( $opts['striped'] ?? true ),
			'hover'           => (bool) ( $opts['hover'] ?? true ),
			'compact'         => (bool) ( $opts['compact'] ?? true ),
			'bordered'        => (bool) ( $opts['bordered'] ?? false ),
			'sticky-header'   => (bool) ( $opts['sticky_header'] ?? false ),
			'selectable'      => $opts['selectable'] ?? null,
			'empty'           => (string) ( $opts['empty'] ?? __( 'Nothing to show.', 'signal-and-noise-tools' ) ),
		)
	);
}

/**
 * `<os-histogram>`: stacked buckets, oldest first, one count per series.
 *
 * @param array<int,array{key:string,label?:string,tone?:string}> $series  Stack layers, bottom first.
 * @param array<int,array<int,int>>                                $columns One inner array per bucket.
 * @param array<string,mixed>                                      $opts    heading, start, end (unix seconds), legend, height, empty, class.
 * @return string
 */
function snt_kit_histogram( array $series, array $columns, array $opts = array() ) {
	return snt_kit_tag(
		'os-histogram',
		array(
			'class'   => $opts['class'] ?? null,
			'heading' => (string) ( $opts['heading'] ?? '' ),
			'series'  => array_values( $series ),
			'columns' => array_values( $columns ),
			'start'   => isset( $opts['start'] ) ? (string) (int) $opts['start'] : null,
			'end'     => isset( $opts['end'] ) ? (string) (int) $opts['end'] : null,
			'legend'  => (bool) ( $opts['legend'] ?? false ),
			'height'  => isset( $opts['height'] ) ? (string) (int) $opts['height'] : null,
			'empty'   => (string) ( $opts['empty'] ?? __( 'No data in the window.', 'signal-and-noise-tools' ) ),
		)
	);
}

/**
 * A label/value list, the ops-wall row: `label`, `value`, optional `href`
 * (external link), `dot` (a status dot: err|unknown|ok), `go` (a `snt_kit_go()`
 * target array: tab, sub, anchor) and `tone`.
 *
 * @param array<int,array<string,mixed>> $rows Rows.
 * @param array<string,mixed>            $opts class, empty.
 * @return string
 */
function snt_kit_list( array $rows, array $opts = array() ) {
	if ( empty( $rows ) ) {
		return snt_kit_tag( 'p', array( 'class' => 'snt-list__empty' ), snt_kit_esc( (string) ( $opts['empty'] ?? '' ) ) );
	}
	$items = '';
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$label = snt_kit_esc( (string) ( $row['label'] ?? '' ) );
		$dot   = (string) ( $row['dot'] ?? '' );
		$inner = '' !== $dot ? snt_kit_tag( 'span', array( 'class' => 'snt-dot snt-dot--' . $dot, 'aria-hidden' => 'true' ) ) : '';
		if ( isset( $row['go'] ) && is_array( $row['go'] ) && function_exists( 'snt_kit_go' ) ) {
			$inner .= snt_kit_go( (string) ( $row['label'] ?? '' ), $row['go'], array( 'class' => 'snt-list__label', 'variant' => 'link' ) );
		} elseif ( '' !== (string) ( $row['href'] ?? '' ) ) {
			$inner .= snt_kit_tag( 'a', array( 'class' => 'snt-list__label', 'href' => (string) $row['href'], 'target' => '_blank', 'rel' => 'noopener noreferrer' ), $label );
		} else {
			$inner .= snt_kit_tag( 'span', array( 'class' => 'snt-list__label' ), $label );
		}
		$inner .= snt_kit_tag( 'span', array( 'class' => 'snt-list__value', 'data-tone' => isset( $row['tone'] ) ? snt_kit_tone( (string) $row['tone'] ) : null ), snt_kit_esc( (string) ( $row['value'] ?? '' ) ) );
		$items .= snt_kit_tag( 'li', array( 'class' => 'snt-list__row' ), $inner );
	}
	return snt_kit_tag( 'ul', array( 'class' => trim( 'snt-list ' . (string) ( $opts['class'] ?? '' ) ) ), $items );
}

/**
 * A facts list (`<dl>`): rows of `label`, `value` (HTML allowed when `html` is true), optional `tone`.
 *
 * @param array<int,array<string,mixed>> $rows Rows.
 * @return string
 */
function snt_kit_kv( array $rows ) {
	$out = '';
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$value = ! empty( $row['html'] ) ? (string) ( $row['value'] ?? '' ) : snt_kit_esc( (string) ( $row['value'] ?? '' ) );
		$out  .= snt_kit_tag( 'div', array( 'class' => 'snt-kv__row' ),
			snt_kit_tag( 'dt', array( 'class' => 'snt-kv__k' ), snt_kit_esc( (string) ( $row['label'] ?? '' ) ) )
			. snt_kit_tag( 'dd', array( 'class' => 'snt-kv__v', 'data-tone' => isset( $row['tone'] ) ? snt_kit_tone( (string) $row['tone'] ) : null ), $value )
		);
	}
	return snt_kit_tag( 'dl', array( 'class' => 'snt-kv' ), $out );
}

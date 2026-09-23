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
 * 17.9.0 (#1624): a cell value may be `[ 'html' => ..., 'text' => ... ]` for a
 * control, a link, a badge or code the cell must hold as markup. It becomes a
 * slot cell (OpenStation 1.1.11, upstream #874): the data carries
 * `{ slot, text }` and the markup rides as a light-DOM child with that slot,
 * where the runtime's action delegation and the classic scripts still reach
 * it. The slot name is minted per row and column, so it is unique across the
 * table as the component requires; `text` is what the column sorts and
 * filters on. A row's optional `_key` names its slots by identity instead of
 * position (see below). `stack_on_phone` marks the table for
 * assets/os-kit-stack.js.
 *
 * @param array<int,array<string,mixed>> $columns Column descriptors.
 * @param array<int,array<string,mixed>> $rows    Row objects keyed by column key.
 * @param array<string,mixed>            $opts    empty, striped, hover, compact, bordered, sticky_header, selectable, class, id, stack_on_phone.
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
	$data    = array();
	$slotted = '';
	$seen    = array();
	foreach ( array_values( $rows ) as $i => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		// A stable `_key` names the row's slots by identity, not position, and
		// becomes the slot cell's os-key: a morph then follows a row that moved
		// (a dismissal shifts every row after it) instead of renaming its cells.
		$rkey = isset( $row['_key'] ) ? 'r' . substr( md5( (string) $row['_key'] ), 0, 12 ) : 'c' . $i;
		unset( $row['_key'] );
		// Two rows sharing a key would mint one slot name twice, and the
		// second row would render blank (the first <slot> takes every match).
		if ( isset( $seen[ $rkey ] ) ) {
			$rkey .= '-' . $i;
		}
		$seen[ $rkey ] = true;
		foreach ( $row as $key => $cell ) {
			if ( ! is_array( $cell ) || ! array_key_exists( 'html', $cell ) ) {
				continue;
			}
			$slot        = $rkey . '-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $key );
			$row[ $key ] = array( 'slot' => $slot, 'text' => (string) ( $cell['text'] ?? '' ) );
			$slotted    .= snt_kit_tag( 'div', array( 'slot' => $slot, 'class' => 'snt-cell', 'os-key' => $slot ), (string) $cell['html'] );
		}
		$data[] = $row;
	}
	return snt_kit_tag(
		'os-table',
		array(
			'id'              => $opts['id'] ?? null,
			'class'           => $opts['class'] ?? null,
			'os-prop-columns' => $cols,
			'os-prop-data'    => $data,
			'striped'         => (bool) ( $opts['striped'] ?? true ),
			'hover'           => (bool) ( $opts['hover'] ?? true ),
			'compact'         => (bool) ( $opts['compact'] ?? true ),
			'bordered'        => (bool) ( $opts['bordered'] ?? false ),
			'sticky-header'   => (bool) ( $opts['sticky_header'] ?? false ),
			'selectable'      => $opts['selectable'] ?? null,
			'empty'           => (string) ( $opts['empty'] ?? __( 'Nothing to show.', 'signal-and-noise-tools' ) ),
			'data-snt-stack-on-phone' => empty( $opts['stack_on_phone'] ) ? null : true,
		),
		$slotted
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
			// 14.7.5: through snt_kit_link(), so a same-origin href is a door
			// (a window) and only another origin opens a tab.
			$inner .= function_exists( 'snt_kit_link' )
				? str_replace( 'class="snt-link"', 'class="snt-list__label"', snt_kit_link( (string) ( $row['label'] ?? '' ), (string) $row['href'] ) )
				: snt_kit_tag( 'a', array( 'class' => 'snt-list__label', 'href' => (string) $row['href'], 'target' => '_blank', 'rel' => 'noopener noreferrer' ), $label );
		} else {
			$inner .= snt_kit_tag( 'span', array( 'class' => 'snt-list__label' ), $label );
		}
		// 14.9.1: an optional title carries the sentence a figure-sized value
		// cannot; the row never widens for it.
		$inner .= snt_kit_tag( 'span', array( 'class' => 'snt-list__value', 'data-tone' => isset( $row['tone'] ) ? snt_kit_tone( (string) $row['tone'] ) : null, 'title' => '' !== (string) ( $row['title'] ?? '' ) ? (string) $row['title'] : null ), snt_kit_esc( (string) ( $row['value'] ?? '' ) ) );
		$items .= snt_kit_tag( 'li', array( 'class' => 'snt-list__row' ), $inner );
	}
	return snt_kit_tag( 'ul', array( 'class' => trim( 'snt-list ' . (string) ( $opts['class'] ?? '' ) ) ), $items );
}

/**
 * A facts list: rows of `label`, `value` (HTML allowed when `html` is true), optional `tone`.
 *
 * 17.9.0: painted by the kit's own `<os-facts>` / `<os-fact>` (OpenStation
 * 1.1.11, upstream #889) instead of a hand-rolled `<dl class="snt-kv">`.
 * The list is still a real `<dl>` (in the component's shadow root, with each
 * row `display: contents` so its `<dt>`/`<dd>` belong to it), so screen
 * readers read the same thing. The label is the row's `label` attribute; the
 * value stays in the light DOM inside `.snt-kv__v`, which is the hook the
 * tone colour, the inline-code wrap and the provenance leaf's rules read.
 * `<os-facts>` has no tone of its own (no upstream painter used one), so the
 * tone rides the value span exactly as it rode the `<dd>`.
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
		$out  .= snt_kit_tag(
			'os-fact',
			array( 'label' => (string) ( $row['label'] ?? '' ) ),
			snt_kit_tag( 'span', array( 'class' => 'snt-kv__v', 'data-tone' => isset( $row['tone'] ) ? snt_kit_tone( (string) $row['tone'] ) : null ), $value )
		);
	}
	return snt_kit_tag( 'os-facts', array( 'class' => 'snt-kv' ), $out );
}

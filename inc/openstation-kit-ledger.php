<?php
/**
 * Signal & Noise Tools: the ledger, a finding, a capped table, a "+N more" line.
 *
 * The no-fold shape (2026-10-11, owner rule: no collapsible sections, and not
 * a plain hint over a bare table either). A list leads with ONE sentence that
 * states what the rows show, built from the data by the caller; the kit table
 * holds the first `$cap` rows; anything past the cap is counted out loud,
 * never dropped silently. The 17.4.1 de-folds (the Cloudflare probes ledger,
 * the TF-IDF ledger) are the shape this generalizes. Required by
 * inc/openstation-kit-data.php, so every surface that has the table has this.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "+N more" line under a capped list, or '' when nothing was cut.
 *
 * @param int    $more   Rows not shown.
 * @param string $format sprintf format taking the formatted count as `%s`;
 *                       '' for the default sentence.
 * @return string
 */
function snt_kit_more( $more, $format = '' ) {
	$more = (int) $more;
	if ( $more <= 0 ) {
		return '';
	}
	$format = '' !== (string) $format
		? (string) $format
		/* translators: %s: rows not shown. */
		: __( '+%s more; the list is capped, not complete.', 'signal-and-noise-tools' );
	return '<p class="snt-hint snt-ledger-more">' . snt_kit_esc( sprintf( $format, number_format_i18n( $more ) ) ) . '</p>';
}

/**
 * The id a row's own action carries, so a Cancel can land focus back on it.
 *
 * @param string $key The row's identity (a path, a section key).
 * @return string
 */
function snt_kit_row_anchor( $key ) {
	return 'snt-row-' . substr( md5( (string) $key ), 0, 12 );
}

/**
 * A row's one action, wrapped in its anchor: the wrapper carries the row's
 * id and `data-snt-focus`, and assets/os-host.js focuses the button inside
 * when a `go` anchors there (a Cancel returning to the row it left).
 *
 * @param string $key         The row's identity.
 * @param string $action_html The painted button (or the badge an open row shows).
 * @return string
 */
function snt_kit_row_action( $key, $action_html ) {
	return snt_kit_tag( 'span', array( 'id' => snt_kit_row_anchor( $key ), 'data-snt-focus' => true ), (string) $action_html );
}

/**
 * A kit table split at the row being edited, so its editor opens UNDER the
 * row (2026-10-12, owner: "it's weird to click in 'edit' and something else
 * opens"): the head holds rows 0..$at with row $at marked selected (os-table's
 * own `is-selected`: a tint and an accent bar down its leading edge), the
 * tail the rows after it under a repeated header ('' when none). An $at
 * outside the rows returns the whole table as the head.
 *
 * @param array<int,array<string,mixed>> $columns As snt_kit_table().
 * @param array<int,array<string,mixed>> $rows    The rows, in display order.
 * @param int                            $at      The edited row's index, -1 for none.
 * @param array<string,mixed>            $opts    As snt_kit_table().
 * @return array{0:string,1:string} Head and tail.
 */
function snt_kit_table_split( array $columns, array $rows, $at, array $opts = array() ) {
	$rows = array_values( $rows );
	$at   = (int) $at;
	if ( $at < 0 || $at >= count( $rows ) ) {
		return array( snt_kit_table( $columns, $rows, $opts ), '' );
	}
	// snt_kit_table() takes no selection; the property rides on the tag (the runtime assigns every os-prop-*).
	$head = substr_replace( snt_kit_table( $columns, array_slice( $rows, 0, $at + 1 ), $opts ), snt_kit_attr( array( 'os-prop-selection' => array( $at ) ) ), strlen( '<os-table' ), 0 );
	$tail = array_slice( $rows, $at + 1 );
	return array( $head, array() === $tail ? '' : snt_kit_table( $columns, $tail, $opts ) );
}

/**
 * A list whose rows open an editor in place: the section (its heading, its
 * lead, the rows down to the open one), then the editor, then the rest of the
 * rows and the "+N more" line in a section of their own. With no row open
 * (or the open one not listed) it is one section, and any editor follows it.
 * Every table shows all its rows (`snt-table-full`: no inner scroller).
 *
 * @param array<string,mixed> $list   heading, lead (HTML), columns, rows, at (the open row's index), more (HTML), description, attrs, table (snt_kit_table() options).
 * @param string              $editor The open row's editor, '' when none.
 * @return string
 */
function snt_kit_edit_list( array $list, $editor ) {
	$rows = array_values( (array) ( $list['rows'] ?? array() ) );
	$at   = '' !== (string) $editor ? (int) ( $list['at'] ?? -1 ) : -1;
	$open = $at >= 0 && $at < count( $rows );
	$more = (string) ( $list['more'] ?? '' );
	$opts = array_merge( array( 'stack_on_phone' => true, 'class' => 'snt-table-full' ), (array) ( $list['table'] ?? array() ) );
	list( $head, $tail ) = snt_kit_table_split( (array) ( $list['columns'] ?? array() ), $rows, $open ? $at : -1, $opts );
	$out = snt_kit_section( (string) ( $list['heading'] ?? '' ), (string) ( $list['lead'] ?? '' ) . $head . ( $open ? '' : $more ), (string) ( $list['description'] ?? '' ), (array) ( $list['attrs'] ?? array() ) );
	if ( ! $open ) {
		return $out . (string) $editor;
	}
	return $out . $editor . ( '' !== $tail . $more ? snt_kit_section( '', $tail . $more ) : '' );
}

/**
 * A finding sentence, the first `$cap` rows as a kit table, and the "+N more"
 * line for the rest.
 *
 * @param string                         $finding One sentence built from the data ('' paints none).
 * @param array<int,array<string,mixed>> $columns As snt_kit_table().
 * @param array<int,array<string,mixed>> $rows    Every row, in display order.
 * @param int                            $cap     Rows to paint.
 * @param array<string,mixed>            $opts    As snt_kit_table(), plus `more` (a snt_kit_more() format).
 * @return string
 */
function snt_kit_ledger( $finding, array $columns, array $rows, $cap, array $opts = array() ) {
	$rows = array_values( $rows );
	$cap  = max( 1, (int) $cap );
	$out  = '' !== (string) $finding ? '<p class="snt-prose">' . snt_kit_esc( $finding ) . '</p>' : '';
	return $out
		. snt_kit_table( $columns, array_slice( $rows, 0, $cap ), $opts )
		. snt_kit_more( count( $rows ) - $cap, (string) ( $opts['more'] ?? '' ) );
}

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
	return '<p class="snt-hint snt-more">' . snt_kit_esc( sprintf( $format, number_format_i18n( $more ) ) ) . '</p>';
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

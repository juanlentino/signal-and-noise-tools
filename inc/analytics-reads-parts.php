<?php
/**
 * Reads (inc/analytics-reads.php): the summary field, its schema, the
 * ability text and the Overview card, from the store's window reader.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The summary's reads field: the window's reads for the class asked. PURE given the store. */
function sn_analytics_reads_figure( $from, $to, $class = 'human', $store = null ) {
	$w = sn_analytics_reads_window( $from, $to, $class, null, $store );
	unset( $w['by_day'] );
	return $w + array( 'definition' => SN_ANALYTICS_READS_DEFINITION );
}

/** Its schema, one function for both doors. PURE. */
function sn_analytics_reads_schema() {
	$i = array( 'type' => 'integer' );
	return array(
		'type'       => 'object',
		'properties' => array( 'reads' => array( 'type' => array( 'integer', 'null' ) ), 'days' => $i, 'measured_days' => $i, 'partial' => array( 'type' => 'boolean' ), 'definition' => array( 'type' => 'string' ) ),
	);
}

/** The Overview strip's Reads card. PURE. */
function snt_analytics_reads_card( $fig ) {
	$fig = is_array( $fig ) ? $fig : array( 'reads' => null );
	$sub = null === ( $fig['reads'] ?? null )
		? __( 'not measured for this range yet; the nightly store fills it', 'signal-and-noise-tools' )
		: ( ! empty( $fig['partial'] )
			/* translators: 1: site days measured, 2: site days in the range. */
			? sprintf( __( '%1$d of %2$d days measured', 'signal-and-noise-tools' ), (int) $fig['measured_days'], (int) $fig['days'] )
			: __( '10 s, half the page, or a click', 'signal-and-noise-tools' ) );
	return array(
		'l'        => __( 'Reads', 'signal-and-noise-tools' ),
		'n'        => null === ( $fig['reads'] ?? null ) ? __( 'not measured', 'signal-and-noise-tools' ) : number_format_i18n( (int) $fig['reads'] ),
		'sub'      => $sub,
		'promoted' => true,
	);
}

/** The ability text for the field, shared by both doors. */
function sn_analytics_reads_description() {
	return '`reads`: proven reading for the class asked, the headline figure. ' . SN_ANALYTICS_READS_DEFINITION . ' Counted over site days (America/New_York) from a nightly store kept as far back as Analytics Engine holds (about 90 days): reads is null when no day of the range is stored (not measured, never 0); measured_days counts the stored days and partial says the range holds days that were not measured. ';
}

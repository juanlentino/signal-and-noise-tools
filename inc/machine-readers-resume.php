<?php
/**
 * Signal & Noise Tools: the résumé on the Machine readers view. Which crawlers
 * and agents read the résumé, and in which format (the sensor's `resume`
 * surface and blob17 resume_format, rights-signals #100), beside the people
 * who downloaded its PDF (the North Star's résumé downloads). Rows in, HTML
 * out; both twins paint it through inc/analytics-machine-views.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Readers a format row names before it stops. */
const SN_MR_RESUME_AGENTS = 3;

/**
 * The résumé's formats in reading order, value => label.
 *
 * @return array<string,string>
 */
function snt_mr_resume_formats() {
	return array(
		'html' => __( 'HTML page', 'signal-and-noise-tools' ),
		'md'   => __( 'Markdown', 'signal-and-noise-tools' ),
		'json' => __( 'JSON Resume', 'signal-and-noise-tools' ),
		'pdf'  => __( 'PDF', 'signal-and-noise-tools' ),
		'vcf'  => __( 'vCard', 'signal-and-noise-tools' ),
		'twin' => __( 'Page JSON', 'signal-and-noise-tools' ),
	);
}

/**
 * Credited reads per résumé format, with the readers behind each. This site's
 * own tools, control tokens and impostors move nothing, as on every card.
 *
 * @param array $rows Normalized aggregate rows.
 * @return array<string,array{reads:int,agents:array<string,int>}> Formats with reads, in snt_mr_resume_formats() order.
 */
function snt_mr_resume_reads( array $rows ) {
	$by = array();
	foreach ( snt_mr_credited_reader_rows( $rows ) as $r ) {
		$fmt = (string) ( $r['resume_format'] ?? '' );
		if ( '' === $fmt ) {
			continue;
		}
		$who  = '' !== (string) ( $r['agent'] ?? '' ) ? (string) $r['agent'] : (string) ( $r['family'] ?? '' );
		$hits = max( 0, (int) ( $r['hits'] ?? 0 ) );
		$by[ $fmt ]['reads']          = ( $by[ $fmt ]['reads'] ?? 0 ) + $hits;
		$by[ $fmt ]['agents'][ $who ] = ( $by[ $fmt ]['agents'][ $who ] ?? 0 ) + $hits;
	}
	$out = array();
	foreach ( array_keys( snt_mr_resume_formats() ) as $fmt ) {
		if ( isset( $by[ $fmt ] ) ) {
			arsort( $by[ $fmt ]['agents'] );
			$out[ $fmt ] = $by[ $fmt ];
		}
	}
	return $out;
}

/**
 * The Résumé panel: machine reads per format, then the people line.
 *
 * @param array    $rows    Normalized aggregate rows.
 * @param int|null $people  Visitor-days that downloaded the PDF, last 7 days; null when not measured.
 * @param string   $surface kit|classic.
 * @return string
 */
function snt_mr_paint_resume( array $rows, $people, $surface ) {
	$labels = snt_mr_resume_formats();
	$table  = array();
	foreach ( snt_mr_resume_reads( $rows ) as $fmt => $f ) {
		$who = array();
		foreach ( array_slice( $f['agents'], 0, SN_MR_RESUME_AGENTS, true ) as $a => $n ) {
			$who[] = $a . ' (' . number_format_i18n( $n ) . ')';
		}
		$table[] = array( 'format' => $labels[ $fmt ], 'reads' => number_format_i18n( $f['reads'] ), 'who' => implode( ', ', $who ) );
	}
	$machines = $table
		? snt_ev_table( $surface, array( 'format' => __( 'Format', 'signal-and-noise-tools' ), 'reads' => __( 'Machine reads', 'signal-and-noise-tools' ), 'who' => __( 'Top readers', 'signal-and-noise-tools' ) ), $table, 0, __( 'Résumé', 'signal-and-noise-tools' ) )
		: snt_ev_lead( $surface, __( 'No reader fetched the résumé in this window.', 'signal-and-noise-tools' ) );
	$humans = null === $people
		? __( 'People who downloaded the PDF: not measured.', 'signal-and-noise-tools' )
		/* translators: %s: visitor-days. */
		: sprintf( _n( '%s person downloaded the PDF in the last 7 days (visitor-days, from the page).', '%s people downloaded the PDF in the last 7 days (visitor-days, from the page).', (int) $people, 'signal-and-noise-tools' ), number_format_i18n( (int) $people ) );
	$note = __( 'The page links only the PDF. The other formats are offered to machines, and a direct fetch runs no beacon, so people reading them are not counted.', 'signal-and-noise-tools' );
	return snt_ev_box( $surface, __( 'Résumé', 'signal-and-noise-tools' ), $machines . snt_ev_lead( $surface, $humans ) . snt_ev_lead( $surface, $note ) );
}

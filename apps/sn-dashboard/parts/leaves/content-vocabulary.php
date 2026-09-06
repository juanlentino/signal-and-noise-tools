<?php
/**
 * S&N Dashboard — Content → Vocabulary, painted from the kit.
 *
 * The classic leaf (inc/ml-drift-admin.php, `snt_ml_drift_render_section()`
 * behind the `sn_admin_render_drift_section()` delegator) is a pure readout of
 * `snt_ml_drift_report()`: the year ledger, then per adjacent-year pair either
 * the thin verdict, the held-still sentence, or the four movement lists. No
 * form, no action, no query read. Same reader, same states, same numbers; the
 * kit's parts instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The year ledger: one stat per year, the size every verdict is judged against.
 *
 * @param array<int,array{year:int,docs:int}> $years Ascending years.
 * @return string
 */
function vocabulary_years_html( array $years ) {
	$out = '';
	foreach ( $years as $y ) {
		if ( ! is_array( $y ) ) {
			continue;
		}
		$docs = (int) ( $y['docs'] ?? 0 );
		$out .= \snt_kit_stat( (string) $docs, (string) (int) ( $y['year'] ?? 0 ), __( 'notes', 'signal-and-noise-tools' ) );
	}
	return '<div class="snt-stats">' . $out . '</div>';
}

/**
 * One movement list as a column: a term/share table, or the em-dash when the
 * pair spoke and this direction had nothing.
 *
 * @param string $title Column heading (already translated).
 * @param array  $rows  Kernel rows ({term, delta}|{term, after}|{term, before}).
 * @param string $kind  'delta' | 'after' | 'before' — which share to print.
 * @return string
 */
function vocabulary_list_html( $title, array $rows, $kind ) {
	if ( array() === $rows ) {
		$inner = '<p class="snt-list__empty">' . \snt_kit_esc( '—' ) . '</p>';
	} else {
		$data = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$share  = 'delta' === $kind ? (float) ( $row['delta'] ?? 0 ) : (float) ( $row[ $kind ] ?? 0 );
			$data[] = array(
				'term'  => (string) ( $row['term'] ?? '' ),
				'share' => 'delta' === $kind ? sprintf( '%+.0f%%', $share * 100 ) : sprintf( '%.0f%%', $share * 100 ),
			);
		}
		$inner = \snt_kit_table(
			array(
				array( 'key' => 'term', 'label' => __( 'Term', 'signal-and-noise-tools' ) ),
				array( 'key' => 'share', 'label' => 'delta' === $kind ? __( 'Change', 'signal-and-noise-tools' ) : __( 'Share', 'signal-and-noise-tools' ), 'align' => 'end' ),
			),
			$data,
			array( 'hover' => false )
		);
	}
	return '<section class="snt-col"><h4 class="snt-col__h">' . \snt_kit_esc( $title ) . '</h4>' . $inner . '</section>';
}

/**
 * One adjacent-year pair: its own section, in one of three states.
 *
 * @param array<string,mixed> $pair from, to + the kernel drift envelope.
 * @return string
 */
function vocabulary_pair_html( array $pair ) {
	$heading = sprintf( '%d → %d', (int) ( $pair['from'] ?? 0 ), (int) ( $pair['to'] ?? 0 ) );
	if ( 'thin' === (string) ( $pair['verdict'] ?? '' ) ) {
		// A distinct state, never "no movement": one side was too small to speak.
		$text = sprintf(
			/* translators: 1: earlier-year note count, 2: later-year note count, 3: minimum. */
			__( 'Too few notes to speak (%1$d and %2$d; the mirror needs %3$d on each side). Not the same as "no drift" — this pair was not measured.', 'signal-and-noise-tools' ),
			(int) ( $pair['docs']['before'] ?? 0 ),
			(int) ( $pair['docs']['after'] ?? 0 ),
			(int) SNT_ML_DRIFT_MIN_DOCS
		);
		return \snt_kit_section( $heading, \snt_kit_notice( 'warn', \snt_kit_badge( 'warn', __( 'Not measured', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( $text ) ) );
	}
	$lists = array(
		'risen'    => array( __( 'Rose', 'signal-and-noise-tools' ), 'delta' ),
		'fallen'   => array( __( 'Fell', 'signal-and-noise-tools' ), 'delta' ),
		'entered'  => array( __( 'Entered', 'signal-and-noise-tools' ), 'after' ),
		'silenced' => array( __( 'Went silent', 'signal-and-noise-tools' ), 'before' ),
	);
	$still = true;
	foreach ( array_keys( $lists ) as $key ) {
		$still = $still && array() === (array) ( $pair[ $key ] ?? array() );
	}
	if ( $still ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( __( 'The vocabulary held still across this pair.', 'signal-and-noise-tools' ) ) . '</p>' );
	}
	$cols = '';
	foreach ( $lists as $key => $spec ) {
		$cols .= vocabulary_list_html( $spec[0], (array) ( $pair[ $key ] ?? array() ), $spec[1] );
	}
	return \snt_kit_section( $heading, '<div class="snt-cols">' . $cols . '</div>' );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_vocabulary( array $ctx ) {
	unset( $ctx );
	$heading = __( 'Vocabulary drift', 'signal-and-noise-tools' );
	$intro   = __( 'How the published corpus\'s vocabulary moved between years: the share of notes each term appears in, compared per adjacent-year pair. Pure corpus statistics — no AI call, no reader data, and shown only here: this mirror faces the writer, never a model.', 'signal-and-noise-tools' );

	if ( ! function_exists( 'snt_ml_drift_report' ) ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'The drift module is not loaded.', 'signal-and-noise-tools' ) ), $intro );
	}
	$report = (array) \snt_ml_drift_report();
	$years  = (array) ( $report['years'] ?? array() );
	$pairs  = (array) ( $report['pairs'] ?? array() );

	if ( array() === $years ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'No published notes yet — the mirror has nothing to reflect.', 'signal-and-noise-tools' ) ), $intro );
	}
	// The year ledger first: every verdict below is judged against these sizes.
	$inner = vocabulary_years_html( $years );
	if ( array() === $pairs ) {
		$inner .= \snt_kit_empty( __( 'Only one year holds published notes so far — drift needs two to compare.', 'signal-and-noise-tools' ) );
	}
	$out = \snt_kit_section( $heading, $inner, $intro );
	foreach ( $pairs as $pair ) {
		if ( is_array( $pair ) ) {
			$out .= vocabulary_pair_html( $pair );
		}
	}
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/vocabulary'] = __NAMESPACE__ . '\\paint_content_vocabulary';
		return $painters;
	}
);

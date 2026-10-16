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
	$out = array();
	foreach ( $years as $y ) {
		if ( ! is_array( $y ) ) {
			continue;
		}
		$docs = (int) ( $y['docs'] ?? 0 );
		$out[] = \snt_kit_stat( (string) $docs, (string) (int) ( $y['year'] ?? 0 ), __( 'notes', 'signal-and-noise-tools' ) );
	}
	return \snt_kit_grid( $out, 160, 10 );
}

/**
 * One movement list as a column: a term/share table, or "None" when the pair
 * spoke and this direction had nothing (snt_ml_drift_share_text() and
 * snt_ml_drift_copy(), the classic twin's words).
 *
 * @param string $title Column heading (already translated).
 * @param array  $rows  Kernel rows ({term, before, after, delta}|{term, after}|{term, before}).
 * @param string $kind  'delta' | 'after' | 'before': which share to print.
 * @return string
 */
function vocabulary_list_html( $title, array $rows, $kind ) {
	$data = array();
	foreach ( $rows as $row ) {
		if ( is_array( $row ) ) {
			$data[] = array( 'term' => (string) ( $row['term'] ?? '' ), 'share' => \snt_ml_drift_share_text( $row, $kind ) );
		}
	}
	$inner = array() === $data
		? '<p class="snt-list__empty">' . \snt_kit_esc( \snt_ml_drift_copy( 'none' ) ) . '</p>'
		: \snt_kit_table(
			array(
				array( 'key' => 'term', 'label' => __( 'Term', 'signal-and-noise-tools' ) ),
				array( 'key' => 'share', 'label' => 'delta' === $kind ? __( 'Change', 'signal-and-noise-tools' ) : __( 'Share', 'signal-and-noise-tools' ), 'align' => 'end' ),
			),
			$data,
			array( 'hover' => false )
		);
	return \snt_kit_tag( 'os-card', array(), '<h4 class="snt-col__h">' . \snt_kit_esc( $title ) . '</h4>' . $inner );
}

/**
 * Whether a pair paints as one short line (thin, or held still): those share
 * a row three at a time instead of each spanning the window.
 *
 * @param array<string,mixed> $pair The kernel's pair envelope.
 * @return bool
 */
function vocabulary_pair_is_short( array $pair ) {
	if ( 'thin' === (string) ( $pair['verdict'] ?? '' ) ) {
		return true;
	}
	foreach ( array( 'risen', 'fallen', 'entered', 'silenced' ) as $key ) {
		if ( array() !== (array) ( $pair[ $key ] ?? array() ) ) {
			return false;
		}
	}
	return true;
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
		return \snt_kit_section( $heading, \snt_kit_notice( 'warn', \snt_kit_badge( 'warn', __( 'Not measured', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( \snt_ml_drift_thin_text( $pair ) ) ) );
	}
	if ( vocabulary_pair_is_short( $pair ) ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( \snt_ml_drift_copy( 'still' ) ) . '</p>' );
	}
	$lists = array(
		'risen'    => array( __( 'Rose', 'signal-and-noise-tools' ), 'delta' ),
		'fallen'   => array( __( 'Fell', 'signal-and-noise-tools' ), 'delta' ),
		'entered'  => array( __( 'Entered', 'signal-and-noise-tools' ), 'after' ),
		'silenced' => array( __( 'Went silent', 'signal-and-noise-tools' ), 'before' ),
	);
	$boxes = array();
	foreach ( $lists as $key => $spec ) {
		$boxes[] = vocabulary_list_html( $spec[0], (array) ( $pair[ $key ] ?? array() ), $spec[1] );
	}
	// 2026-10-12 audit: one row of four, as the classic's four-column grid
	// (two rows of two at about 1,300px). 200px keeps a term and its two
	// shares legible and fits four in a window of about 900px.
	return \snt_kit_section( $heading, \snt_kit_grid( $boxes, 200 ) );
}

/**
 * The pairs: a moved pair spans the window; consecutive short ones (thin, or
 * held still) share a row three at a time (2026-10-16, owner: three across
 * wherever three belong together; a run of two still shares its row).
 *
 * @param array $pairs The kernel's pairs.
 * @return string
 */
function vocabulary_pairs_html( array $pairs ) {
	$out = '';
	$run = array();
	foreach ( $pairs as $pair ) {
		if ( ! is_array( $pair ) ) {
			continue;
		}
		if ( vocabulary_pair_is_short( $pair ) ) {
			$run[] = vocabulary_pair_html( $pair );
			if ( 3 === count( $run ) ) {
				$out .= \snt_kit_grid( $run );
				$run  = array();
			}
			continue;
		}
		$out .= \snt_kit_grid( $run ) . vocabulary_pair_html( $pair );
		$run  = array();
	}
	return $out . \snt_kit_grid( $run );
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
	$intro   = function_exists( 'snt_ml_drift_copy' ) ? \snt_ml_drift_copy( 'intro' ) : '';

	if ( ! function_exists( 'snt_ml_drift_report' ) ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'The drift module is not loaded.', 'signal-and-noise-tools' ) ), $intro );
	}
	$report = (array) \snt_ml_drift_report();
	$years  = (array) ( $report['years'] ?? array() );
	$pairs  = (array) ( $report['pairs'] ?? array() );

	if ( array() === $years ) {
		return \snt_kit_section( $heading, \snt_kit_empty( \snt_ml_drift_copy( 'empty' ) ), $intro );
	}
	// The year ledger first: every verdict below is judged against these sizes.
	$ledger = \snt_kit_section( $heading, vocabulary_years_html( $years ), $intro );
	if ( array() === $pairs ) {
		// 2026-10-12 audit: one year was one small box beside an empty window;
		// the reason there is no drift yet pairs with the ledger.
		return \snt_kit_grid( array( $ledger, \snt_kit_section( __( 'Year over year', 'signal-and-noise-tools' ), \snt_kit_empty( \snt_ml_drift_copy( 'one_year' ) ) ) ) );
	}
	return $ledger . vocabulary_pairs_html( $pairs );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/vocabulary'] = __NAMESPACE__ . '\\paint_content_vocabulary';
		return $painters;
	}
);

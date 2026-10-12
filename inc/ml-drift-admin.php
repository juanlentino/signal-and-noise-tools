<?php
/**
 * Signal & Noise Tools — Vocabulary drift admin (Content → Vocabulary).
 *
 * Renders the corpus-drift mirror (R4 4A): per adjacent-year pair, which terms
 * rose, fell, entered, or went silent in the published corpus's vocabulary —
 * document share, computed on demand by inc/ml-drift.php over the pure kernel.
 * The FOURTH content read surface, beside Tags / Pattern Adoption / Block
 * Migrations (the v10.46.0 reunion); structurally it mirrors
 * inc/pattern-adoption-admin.php's leaf shape but needs no scan button and no
 * queue — the mirror recomputes on render and proposes nothing.
 *
 * A THIN pair renders as its own state, visually distinct from "no movement":
 * "too few notes to speak" and "the vocabulary held still" are different
 * findings, and collapsing them is the confident-0.00 failure the kernel's
 * thin verdict exists to prevent.
 *
 * Writer-facing ONLY. No ability, no remote twin, no public surface —
 * tests/ml-drift.php pins the absences.
 *
 * @package SignalNoiseTools
 * @since 11.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The words both Vocabulary surfaces print (2026-10-12 audit: em dashes in
 * the copy, an empty list painted as a lone dash a screen reader announces
 * as "em dash", and the change printed as a percent when it is a difference of
 * shares: 10% to 22% of notes read "+12%").
 *
 * @param string $which intro | empty | one_year | still.
 * @return string
 */
function snt_ml_drift_copy( $which ) {
	$copy = array(
		'intro'    => __( 'How the published corpus\'s vocabulary moved between years: the share of notes each term appears in, compared per adjacent-year pair. Pure corpus statistics (no AI call, no reader data), shown only here: this mirror faces the writer, never a model.', 'signal-and-noise-tools' ),
		'empty'    => __( 'No published notes yet: the mirror has nothing to reflect.', 'signal-and-noise-tools' ),
		'one_year' => __( 'Only one year holds published notes so far; drift needs two to compare.', 'signal-and-noise-tools' ),
		'still'    => __( 'The vocabulary held still across this pair.', 'signal-and-noise-tools' ),
		'none'     => __( 'None', 'signal-and-noise-tools' ),
	);
	return $copy[ (string) $which ] ?? '';
}

/**
 * The thin pair's sentence: both sizes and the floor.
 *
 * @param array $pair The kernel's pair envelope.
 * @return string
 */
function snt_ml_drift_thin_text( array $pair ) {
	return sprintf(
		/* translators: 1: earlier-year note count, 2: later-year note count, 3: minimum. */
		__( 'Too few notes to speak (%1$d and %2$d; the mirror needs %3$d on each side). Not the same as "no drift": this pair was not measured.', 'signal-and-noise-tools' ),
		(int) ( $pair['docs']['before'] ?? 0 ),
		(int) ( $pair['docs']['after'] ?? 0 ),
		defined( 'SNT_ML_DRIFT_MIN_DOCS' ) ? (int) SNT_ML_DRIFT_MIN_DOCS : 0
	);
}

/**
 * One row's share as both surfaces print it: a moved term as its two shares
 * ("10% → 22%", to one decimal when whole percents would read the same), an
 * entered or silenced term as its one share ("40%").
 *
 * @param array  $row  Kernel row ({term, before, after, delta}|{term, after}|{term, before}).
 * @param string $kind 'delta' | 'after' | 'before'.
 * @return string
 */
function snt_ml_drift_share_text( array $row, $kind ) {
	$pct = static fn( $share, $dec ) => number_format( (float) $share * 100, $dec ) . '%';
	if ( 'delta' !== $kind ) {
		return $pct( $row[ $kind ] ?? 0, 0 );
	}
	if ( ! isset( $row['before'], $row['after'] ) ) {
		// A row without its two shares: the difference, in points.
		return sprintf( '%+.0f pts', (float) ( $row['delta'] ?? 0 ) * 100 );
	}
	$dec = round( (float) $row['before'] * 100 ) === round( (float) $row['after'] * 100 ) ? 1 : 0;
	return $pct( $row['before'], $dec ) . ' → ' . $pct( $row['after'], $dec );
}

/**
 * Render one movement list as a compact table column.
 *
 * @param string $title Column heading (already translated).
 * @param array  $rows  Kernel rows ({term, delta}|{term, after}|{term, before}).
 * @param string $kind  'delta' | 'after' | 'before': which share to print.
 * @return void
 */
function sn_admin_drift_render_list( $title, array $rows, $kind ) {
	echo '<div class="sn-drift-col">';
	echo '<h4>' . esc_html( $title ) . '</h4>';
	if ( array() === $rows ) {
		// An empty list is a real answer here (the pair spoke; this direction
		// had nothing), said in a word, not a fabricated row.
		echo '<p class="sn-drift-none">' . esc_html( snt_ml_drift_copy( 'none' ) ) . '</p></div>';
		return;
	}
	echo '<table class="widefat striped sn-drift-table"><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr><td>' . esc_html( (string) $row['term'] ) . '</td>';
		echo '<td class="sn-drift-share">' . esc_html( snt_ml_drift_share_text( (array) $row, $kind ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Render the Vocabulary leaf (hooked on the sn_admin_drift_tab delegator in
 * inc/admin-render-sections.php, matching the three sibling scanners' wiring).
 *
 * @return void
 */
function snt_ml_drift_render_section() {
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Vocabulary drift', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html( snt_ml_drift_copy( 'intro' ) ) . '</p>';

	if ( ! function_exists( 'snt_ml_drift_report' ) ) {
		echo '<p>' . esc_html__( 'The drift module is not loaded.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	$report = snt_ml_drift_report();

	if ( array() === $report['years'] ) {
		echo '<p>' . esc_html( snt_ml_drift_copy( 'empty' ) ) . '</p></div>';
		return;
	}

	// The year ledger first: every verdict below is judged against these sizes.
	echo '<p class="sn-drift-years">';
	$year_bits = array();
	foreach ( $report['years'] as $y ) {
		/* translators: 1: year, 2: note count. */
		$year_bits[] = sprintf( __( '%1$d: %2$d notes', 'signal-and-noise-tools' ), (int) $y['year'], (int) $y['docs'] );
	}
	echo esc_html( implode( ' · ', $year_bits ) );
	echo '</p>';

	if ( array() === $report['pairs'] ) {
		echo '<p>' . esc_html( snt_ml_drift_copy( 'one_year' ) ) . '</p></div>';
		return;
	}

	foreach ( $report['pairs'] as $pair ) {
		echo '<div class="sn-drift-pair">';
		echo '<h3>' . esc_html( sprintf( '%d → %d', (int) $pair['from'], (int) $pair['to'] ) ) . '</h3>';

		if ( 'thin' === $pair['verdict'] ) {
			// A distinct state, never "no movement": one side was too small to
			// speak. Name the sizes so the writer sees WHY, and the floor so
			// the refusal is legible rather than mysterious.
			echo '<p class="sn-drift-thin">' . esc_html( snt_ml_drift_thin_text( $pair ) ) . '</p></div>';
			continue;
		}

		$still = array() === $pair['risen'] && array() === $pair['fallen']
			&& array() === $pair['entered'] && array() === $pair['silenced'];
		if ( $still ) {
			echo '<p>' . esc_html( snt_ml_drift_copy( 'still' ) ) . '</p></div>';
			continue;
		}

		echo '<div class="sn-drift-grid">';
		sn_admin_drift_render_list( __( 'Rose', 'signal-and-noise-tools' ), $pair['risen'], 'delta' );
		sn_admin_drift_render_list( __( 'Fell', 'signal-and-noise-tools' ), $pair['fallen'], 'delta' );
		sn_admin_drift_render_list( __( 'Entered', 'signal-and-noise-tools' ), $pair['entered'], 'after' );
		sn_admin_drift_render_list( __( 'Went silent', 'signal-and-noise-tools' ), $pair['silenced'], 'before' );
		echo '</div></div>';
	}

	echo '</div>';
}
add_action( 'sn_admin_drift_tab', 'snt_ml_drift_render_section' );

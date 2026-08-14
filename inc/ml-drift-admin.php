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
 * Render one movement list as a compact table column.
 *
 * @param string $title Column heading (already translated).
 * @param array  $rows  Kernel rows ({term, delta}|{term, after}|{term, before}).
 * @param string $kind  'delta' | 'after' | 'before' — which share to print.
 * @return void
 */
function sn_admin_drift_render_list( $title, array $rows, $kind ) {
	echo '<div class="sn-drift-col">';
	echo '<h4>' . esc_html( $title ) . '</h4>';
	if ( array() === $rows ) {
		// An empty list is a real answer here (the pair spoke; this direction
		// had nothing) — the em-dash idiom, not a fabricated row.
		echo '<p class="sn-drift-none">&#8212;</p></div>';
		return;
	}
	echo '<table class="widefat striped sn-drift-table"><tbody>';
	foreach ( $rows as $row ) {
		$share = 'delta' === $kind ? (float) $row['delta'] : (float) $row[ $kind ];
		$text  = 'delta' === $kind
			? sprintf( '%+.0f%%', $share * 100 )
			: sprintf( '%.0f%%', $share * 100 );
		echo '<tr><td>' . esc_html( (string) $row['term'] ) . '</td>';
		echo '<td class="sn-drift-share">' . esc_html( $text ) . '</td></tr>';
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
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'How the published corpus\'s vocabulary moved between years: the share of notes each term appears in, compared per adjacent-year pair. Pure corpus statistics — no AI call, no reader data, and shown only here: this mirror faces the writer, never a model.', 'signal-and-noise-tools' ) . '</p>';

	if ( ! function_exists( 'snt_ml_drift_report' ) ) {
		echo '<p>' . esc_html__( 'The drift module is not loaded.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	$report = snt_ml_drift_report();

	if ( array() === $report['years'] ) {
		echo '<p>' . esc_html__( 'No published notes yet — the mirror has nothing to reflect.', 'signal-and-noise-tools' ) . '</p></div>';
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
		echo '<p>' . esc_html__( 'Only one year holds published notes so far — drift needs two to compare.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}

	foreach ( $report['pairs'] as $pair ) {
		echo '<div class="sn-drift-pair">';
		echo '<h3>' . esc_html( sprintf( '%d → %d', (int) $pair['from'], (int) $pair['to'] ) ) . '</h3>';

		if ( 'thin' === $pair['verdict'] ) {
			// A distinct state, never "no movement": one side was too small to
			// speak. Name the sizes so the writer sees WHY, and the floor so
			// the refusal is legible rather than mysterious.
			echo '<p class="sn-drift-thin">' . esc_html( sprintf(
				/* translators: 1: earlier-year note count, 2: later-year note count, 3: minimum. */
				__( 'Too few notes to speak (%1$d and %2$d; the mirror needs %3$d on each side). Not the same as "no drift" — this pair was not measured.', 'signal-and-noise-tools' ),
				(int) $pair['docs']['before'],
				(int) $pair['docs']['after'],
				SNT_ML_DRIFT_MIN_DOCS
			) ) . '</p></div>';
			continue;
		}

		$still = array() === $pair['risen'] && array() === $pair['fallen']
			&& array() === $pair['entered'] && array() === $pair['silenced'];
		if ( $still ) {
			echo '<p>' . esc_html__( 'The vocabulary held still across this pair.', 'signal-and-noise-tools' ) . '</p></div>';
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

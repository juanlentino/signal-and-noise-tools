<?php
/**
 * Signal & Noise — the ops wall renderer (detail columns).
 *
 * Split out of inc/dash-console.php in v11.30.0: that file had grown past the
 * ~150-line ceiling this project holds itself to, and these renderers have no
 * dependency on the screen's composition.
 *
 * @package SignalNoiseTools
 * @since 11.29.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ops wall: every fact the plugin already holds, at density.
 *
 * WHY DATA-DRIVEN. The owner\'s brief is "everything without bloating" — so the
 * lower half is not a hand-picked pair of panels, it is however many the call
 * site can source, and cutting one has to be cheap enough to do in conversation.
 * A panel is therefore an array, not a function: adding or removing one is a
 * single entry at the call site, never a new renderer and never new CSS.
 *
 * WHY UNIFORM ROWS. The first build reused snt_dashboard_render_deploy_row(),
 * whose grid needs 362px of fixed track — inside a ~330px panel it overflowed
 * at every viewport, because its compact variant is keyed to the WINDOW while
 * the real constraint was the CONTAINER. A label/value/dot row cannot have that
 * bug, and it speaks the rail\'s existing dot vocabulary rather than inventing
 * a second status language for the same states.
 *
 * ROWS === NULL IS NOT ROWS === ARRAY(). Never-fetched and fetched-and-empty
 * are different facts; one string for both would state the second while meaning
 * the first. Each panel carries its own wording for each.
 *
 * @since 11.29.2
 * @param array<int,array<string,mixed>> $panels Each: title, rows (array|null),
 *                                               empty, unmeasured.
 * @return void
 */
function sn_dash_render_ops( array $panels ) {
	// An empty grid is banked whitespace wearing a border. Render nothing.
	if ( empty( $panels ) ) {
		return;
	}

	echo '<div class="sn-scr__detail">';
	foreach ( $panels as $panel ) {
		if ( ! is_array( $panel ) ) {
			continue;
		}
		$rows = array_key_exists( 'rows', $panel ) ? $panel['rows'] : null;

		// A column, not a card. Ten drawn boxes on one screen is ten rectangles
		// of non-data pixels competing for the same attention; a rule and a
		// label group just as well and cost nothing.
		echo '<section class="sn-scr__col">';
		echo '<h2 class="sn-scr__colhead">' . esc_html( (string) ( $panel['title'] ?? '' ) ) . '</h2>';

		if ( null === $rows ) {
			echo '<p class="sn-ops__empty">' . esc_html( (string) ( $panel['unmeasured'] ?? '' ) ) . '</p>';
		} elseif ( empty( $rows ) ) {
			echo '<p class="sn-ops__empty">' . esc_html( (string) ( $panel['empty'] ?? '' ) ) . '</p>';
		} else {
			echo '<ul class="sn-ops__list">';
			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) ) {
					sn_dash_render_ops_row( $row );
				}
			}
			echo '</ul>';
		}
		echo '</section>';
	}
	echo '</div>';
}

/**
 * One wall row: optional state dot, a label that may link, a value.
 *
 * @since 11.29.2
 * @param array<string,mixed> $row label, value, href, dot.
 * @return void
 */
function sn_dash_render_ops_row( array $row ) {
	$label = (string) ( $row['label'] ?? '' );
	$value = (string) ( $row['value'] ?? '' );
	$href  = (string) ( $row['href'] ?? '' );
	$dot   = (string) ( $row['dot'] ?? '' );

	echo '<li class="sn-ops__row">';
	if ( '' !== $dot ) {
		// The rail\'s vocabulary, reused deliberately: the same state must not
		// render two ways on one page.
		echo '<span class="sn-rail__dot sn-rail__dot--' . esc_attr( $dot ) . '" aria-hidden="true"></span>';
	}
	if ( '' !== $href ) {
		echo '<a class="sn-ops__label" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
	} else {
		echo '<span class="sn-ops__label">' . esc_html( $label ) . '</span>';
	}
	echo '<span class="sn-ops__value">' . esc_html( $value ) . '</span>';
	echo '</li>';
}

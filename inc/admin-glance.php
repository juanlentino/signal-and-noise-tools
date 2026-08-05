<?php
/**
 * Signal & Noise Tools — first-glance stat-card grid helper.
 *
 * sn_admin_glance_grid() renders the at-a-glance stat-card grid used by the
 * Dashboard hero (Phase 1) and reusable by other tabs later. It is layout, not
 * a wp-dashboard widget — the "no new dashboard widgets" rule is about index.php
 * home widgets, not in-page admin composition.
 *
 * Each card is an associative array:
 *   [
 *     'label'     => string,            // muted small caption
 *     'value'     => string,            // the headline figure (~16-18px)
 *     'id'        => string (optional), // DOM id hook for progressive JS
 *     'meta_html' => string (optional), // pre-escaped / kses-safe sub-line
 *     'pill'      => [                   // optional status chip
 *       'kind' => 'ok' | 'warn' | 'err',
 *       'text' => string,
 *     ],
 *   ]
 *
 * label / value / pill text are escaped here; meta_html is passed through
 * wp_kses_post (callers hand it pre-built markup such as a delta badge). The
 * pill kind is constrained to the ok/warn/err allowlist so a caller cannot
 * inject an arbitrary class fragment. Empty input emits nothing.
 *
 * WP-native styling only (reuses .sn-pill + the --sn-* tokens). The grid +
 * card CSS lives in assets/admin.css (.sn-glance / .sn-glance-card) — no inline
 * <style> from this render path.
 *
 * @package SignalNoiseTools
 * @since 6.43.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a first-glance stat-card grid.
 *
 * @param array<int,array<string,mixed>> $cards List of card definitions (see
 *                                              the file docblock for the shape).
 * @return void
 */
function sn_admin_glance_grid( array $cards ) {
	// Guard: never render an empty grid wrapper.
	if ( empty( $cards ) ) {
		return;
	}

	$pill_kinds = array( 'ok', 'warn', 'err' );

	echo '<div class="sn-glance">';
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$label = isset( $card['label'] ) ? (string) $card['label'] : '';
		$value = isset( $card['value'] ) ? (string) $card['value'] : '';

		// Optional DOM id hook for progressive JS.
		$card_id = isset( $card['id'] ) && '' !== $card['id'] ? (string) $card['id'] : '';

		// v10.48.0: an optional href turns the card into a link to the tab that
		// OWNS the number. The Dashboard's ten cards previously reported state and
		// routed nowhere — you read "0 findings" and then went hunting for which
		// tab owns health. Making each card its own way in is what turns a readout
		// into a command surface.
		//
		// admin_url() is applied by the CALLER, and esc_url() here refuses anything
		// that is not a safe http(s) URL, so a card definition cannot inject a
		// javascript: target.
		// The open/close tags are written as LITERALS in both branches rather than
		// built from a $tag variable. Plugin Check runs its own EscapeOutput sniff
		// and ignores phpcs.xml.dist, so `echo '<' . $tag` reads as unescaped output
		// even though the value is a hard-coded 'a'/'div' — and it is the stricter
		// reading that ships. Literals also make the pairing obvious to a reader.
		$href    = isset( $card['href'] ) ? (string) $card['href'] : '';
		$is_link = ( '' !== $href );
		$id_attr = '' !== $card_id ? ' id="' . esc_attr( $card_id ) . '"' : '';
		if ( $is_link ) {
			echo '<a class="sn-glance-card sn-glance-card--link" href="' . esc_url( $href ) . '"' . $id_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $id_attr is esc_attr'd above.
		} else {
			echo '<div class="sn-glance-card"' . $id_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $id_attr is esc_attr'd above.
		}
		echo '<p class="sn-glance-card__label">' . esc_html( $label ) . '</p>';
		echo '<p class="sn-glance-card__value">' . esc_html( $value ) . '</p>';

		// Optional status pill (kind constrained to the allowlist).
		if ( isset( $card['pill'] ) && is_array( $card['pill'] ) ) {
			$kind = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
			$text = isset( $card['pill']['text'] ) ? (string) $card['pill']['text'] : '';
			if ( in_array( $kind, $pill_kinds, true ) && '' !== $text ) {
				echo '<span class="sn-pill sn-pill--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
			}
		}

		// Optional pre-escaped meta line (e.g. a delta badge).
		if ( ! empty( $card['meta_html'] ) ) {
			echo '<p class="sn-glance-card__meta">' . wp_kses_post( (string) $card['meta_html'] ) . '</p>';
		}

		if ( $is_link ) {
			echo '</a>';
		} else {
			echo '</div>';
		}
	}
	echo '</div>';
}

/**
 * Sort glance cards so anything needing attention leads. (v10.48.0)
 *
 * PURE and STABLE: err before warn before everything else, and within a class
 * the caller's order is preserved. Stability matters more than it looks — the
 * Dashboard's cards are in a deliberate reading order, and a sort that reshuffled
 * the calm ones would make the grid move for no reason on every page load, which
 * is exactly the kind of churn that trains someone to stop reading it.
 *
 * @since 10.48.0
 * @param array<int,array<string,mixed>> $cards
 * @return array<int,array<string,mixed>>
 */
function sn_admin_glance_sort_by_attention( array $cards ) {
	$rank = array( 'err' => 0, 'warn' => 1 );
	$keyed = array();
	foreach ( array_values( $cards ) as $i => $card ) {
		$kind    = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		$keyed[] = array( 'r' => $rank[ $kind ] ?? 2, 'i' => $i, 'c' => $card );
	}
	usort( $keyed, function ( $a, $b ) {
		return $a['r'] === $b['r'] ? $a['i'] <=> $b['i'] : $a['r'] <=> $b['r'];
	} );
	return array_column( $keyed, 'c' );
}

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

		echo '<div class="sn-glance-card">';
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

		echo '</div>';
	}
	echo '</div>';
}

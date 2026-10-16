<?php
/**
 * Signal & Noise Tools: [sn_maturity_roadmap], the hub-wide roadmap board, the render (the column counts, the board HTML, the front stylesheet, the shortcode and its registration).
 *
 * Split from inc/maturity-roadmap-shortcode.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-status item totals across the whole board — the legend's and the
 * header badges' numbers. Computed from the same filtered board the
 * render walks, so a filtered/override board always counts itself.
 *
 * @param array<string,array<string,string[]>> $board
 * @return array<string,int>
 */
function sn_maturity_roadmap_counts( $board ) {
	$counts = array_fill_keys( SN_MATURITY_ROADMAP_STATUSES, 0 );
	foreach ( $board as $columns ) {
		foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
			if ( isset( $columns[ $status ] ) && is_array( $columns[ $status ] ) ) {
				$counts[ $status ] += count( $columns[ $status ] );
			}
		}
	}
	return $counts;
}

/**
 * Render the board. Escaped HTML; statuses outside the whitelist never
 * render; an empty cell renders an em-dash, never collapses.
 *
 * v10.63.0 — "fold the future": done cells stay open (the record IS the
 * page's argument); planned and considering fold into native <details>
 * per cell, closed by default, each summary carrying its count — the
 * board's commitment gradient (solid → dashed → dotted) made physical.
 * A count-trio legend above the table gives the site-wide column scan
 * with zero folds opened. No script: keyboard, screen readers, and
 * find-in-page (which auto-opens matching folds) are the browser's own.
 *
 * @return string
 */
function sn_maturity_roadmap_html() {
	$headings = array(
		'done'        => __( 'Done', 'signal-and-noise-tools' ),
		'planned'     => __( 'Planned', 'signal-and-noise-tools' ),
		'considering' => __( 'Considering', 'signal-and-noise-tools' ),
		'later'       => __( 'Later', 'signal-and-noise-tools' ),
	);
	$summaries = array(
		/* translators: %d: number of planned roadmap items inside the fold. */
		'planned'     => __( '%d planned', 'signal-and-noise-tools' ),
		/* translators: %d: number of considering roadmap items inside the fold. */
		'considering' => __( '%d considering', 'signal-and-noise-tools' ),
		/* translators: %d: number of later roadmap items inside the fold. */
		'later'       => __( '%d later', 'signal-and-noise-tools' ),
	);
	$sublines  = array(
		'done'        => __( 'shipped & verifiable', 'signal-and-noise-tools' ),
		'planned'     => __( 'each names its gate', 'signal-and-noise-tools' ),
		'considering' => __( 'commitments to nothing', 'signal-and-noise-tools' ),
		'later'       => __( 'vetted, not yet weighed', 'signal-and-noise-tools' ),
	);

	$board  = sn_maturity_roadmap_board();
	$counts = sn_maturity_roadmap_counts( $board );

	// H2: the shortcode sits directly under the page's H1 post title (#1040).
	$out = '<h2>' . esc_html__( 'Roadmap', 'signal-and-noise-tools' ) . '</h2>';

	// The legend: the commitment gradient as a count trio, each cell an
	// anchor to the board so the legend is navigation, not decoration.
	$out .= '<nav class="sn-maturity-roadmap-legend" aria-label="' . esc_attr__( 'Roadmap totals by status', 'signal-and-noise-tools' ) . '">';
	foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
		$out .= '<a class="sn-maturity-roadmap-legend__cell sn-maturity-roadmap-legend__cell--' . esc_attr( $status ) . '" href="#sn-maturity-roadmap-board">'
			. '<span class="sn-maturity-roadmap-legend__stat">' . esc_html( (string) $counts[ $status ] ) . '</span>'
			. '<span class="sn-maturity-roadmap-badge sn-maturity-roadmap-badge--' . esc_attr( $status ) . '">' . esc_html( $headings[ $status ] ) . '</span>'
			. '<span class="sn-maturity-roadmap-legend__sub">' . esc_html( $sublines[ $status ] ) . '</span>'
			. '</a>';
	}
	$out .= '</nav>';

	$out .= '<table class="sn-maturity-roadmap-board" id="sn-maturity-roadmap-board"><thead><tr>'
		. '<th class="sn-maturity-roadmap-board__family-h">' . esc_html__( 'Family', 'signal-and-noise-tools' ) . '</th>';
	foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
		$out .= '<th><span class="sn-maturity-roadmap-badge sn-maturity-roadmap-badge--' . esc_attr( $status ) . '">' . esc_html( $headings[ $status ] )
			. '<span class="sn-maturity-roadmap-badge__n">' . esc_html( (string) $counts[ $status ] ) . '</span></span></th>';
	}
	$out .= '</tr></thead><tbody>';

	foreach ( $board as $family => $columns ) {
		$out .= '<tr><td class="sn-maturity-roadmap-board__family" data-label="' . esc_attr__( 'Family', 'signal-and-noise-tools' ) . '">' . esc_html( (string) $family ) . '</td>';
		foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
			$rows = isset( $columns[ $status ] ) && is_array( $columns[ $status ] ) ? $columns[ $status ] : array();
			$out .= '<td class="sn-maturity-roadmap-board__cell sn-maturity-roadmap-board__cell--' . esc_attr( $status ) . '" data-label="' . esc_attr( $headings[ $status ] ) . '">';
			if ( empty( $rows ) ) {
				// An empty column is known, so it says so (an em dash with a
				// hidden "nothing here" until 2026-10-12).
				$out .= '<span class="sn-maturity-roadmap-board__empty">' . esc_html__( 'none', 'signal-and-noise-tools' ) . '</span>';
			} else {
				$list = '<ul>';
				foreach ( $rows as $row ) {
					$list .= '<li>' . esc_html( (string) $row ) . '</li>';
				}
				$list .= '</ul>';
				if ( isset( $summaries[ $status ] ) ) {
					// The future tenses fold; done never does.
					$out .= '<details class="sn-maturity-roadmap-fold"><summary>'
						. '<span class="sn-maturity-roadmap-fold__glyph" aria-hidden="true"></span>'
						. esc_html( sprintf( $summaries[ $status ], count( $rows ) ) )
						. '</summary>' . $list . '</details>';
				} else {
					$out .= $list;
				}
			}
			$out .= '</td>';
		}
		$out .= '</tr>';
	}
	return $out . '</tbody></table>';
}

/** Enqueue the front stylesheet; shortcode-render time only. */
function sn_maturity_roadmap_enqueue() {
	wp_enqueue_style(
		'sn-maturity-roadmap-front',
		plugins_url( 'assets/maturity-roadmap-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * [sn_maturity_roadmap] — returns (never echoes), static content only,
 * safe for any public maturity page. The wrapper rides `alignfull`
 * (the constrained layout's own exemption) and the stylesheet caps it
 * at the site's 1320px frame, so the board earns its width WITH the
 * theme's layout system instead of against it.
 *
 * @param array|string $atts Shortcode attributes (unused; reserved).
 * @return string
 */
function sn_maturity_roadmap_shortcode( $atts = array() ) {
	sn_maturity_roadmap_enqueue();
	// `alignfull` is the theme's own escape hatch from the constrained
	// layout: `.is-layout-constrained` clamps every non-align child to the
	// content width WITH forced auto margins, so a margin-calc breakout
	// silently loses (measured live: the board rendered 760px). Speaking
	// the layout system's dialect wins; the stylesheet then caps at 1320.
	return '<div class="sn-maturity-roadmap sn-maturity-roadmap--wide alignfull">' . sn_maturity_roadmap_html() . '</div>';
}
add_shortcode( 'sn_maturity_roadmap', 'sn_maturity_roadmap_shortcode' );

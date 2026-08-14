<?php
/**
 * Signal & Noise — [sn_reading_path]: the reader-facing chain (R4 4B).
 *
 * Plugin owns the RENDERER, the theme owns the PLACEMENT (single.html, beside
 * the related-notes footer) — the [sn_prov_panel] notes pattern, and the
 * panel-incident rule observed from the start: ONE placement mechanism, no
 * content-filter sibling, so a theme slot can never meet a filter twin.
 *
 * Self-gating: renders '' (and enqueues nothing) whenever there is nothing
 * honest to show — paths not built, the note on no path, or not a single
 * post context. A theme that places the slot early never shows a broken box.
 *
 * Each neighbour link is gated on is_post_publicly_viewable(): the artifact
 * rebuild coalesces ~30s behind a publish transition, so a chain can briefly
 * name a retracted note — a dead link beats leaking a title, and the gap
 * self-heals on rebuild ([[reading-time-shortcode-oracle-pattern]]).
 *
 * Stylesheet rides the render (assets/ml-paths.css), never the pageload.
 *
 * @package SignalNoiseTools
 * @since 11.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'snt_ml_reading_path_neighbour_html' ) ) {
	/**
	 * One neighbour link ('' when the neighbour is not publicly viewable).
	 *
	 * @param int|null $id    Neighbour post id (null at a chain end).
	 * @param string   $label Direction label (already translated).
	 * @param string   $class Modifier class.
	 * @return string Escaped HTML or ''.
	 */
	function snt_ml_reading_path_neighbour_html( $id, $label, $class ) {
		if ( null === $id || ! is_post_publicly_viewable( $id ) ) {
			return '';
		}
		$title = get_the_title( $id );
		if ( '' === $title ) {
			return '';
		}
		return '<a class="sn-reading-path__link ' . esc_attr( $class ) . '" href="' . esc_url( get_permalink( $id ) ) . '">'
			. '<span class="sn-reading-path__dir">' . esc_html( $label ) . '</span> '
			. '<span class="sn-reading-path__title">' . esc_html( $title ) . '</span></a>';
	}
}

if ( ! function_exists( 'snt_ml_reading_path_shortcode' ) ) {
	/**
	 * Render the reading-path nav for the current single post.
	 *
	 * @return string Escaped HTML; '' when there is nothing honest to show.
	 */
	function snt_ml_reading_path_shortcode() {
		if ( ! is_singular( 'post' ) ) {
			return '';
		}
		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 || ! function_exists( 'snt_ml_path_for_post' ) ) {
			return '';
		}
		$row = snt_ml_path_for_post( $post_id );
		if ( null === $row || array() === $row ) {
			return ''; // Not built and no-path both render as absence for a READER.
		}

		$prev = snt_ml_reading_path_neighbour_html( $row['prev'], __( '← Earlier in this path', 'signal-and-noise-tools' ), 'sn-reading-path__link--prev' );
		$next = snt_ml_reading_path_neighbour_html( $row['next'], __( 'Next in this path →', 'signal-and-noise-tools' ), 'sn-reading-path__link--next' );
		if ( '' === $prev && '' === $next ) {
			return ''; // Both neighbours ungated away: a nav with no links is noise.
		}

		wp_enqueue_style(
			'snt-ml-paths',
			plugins_url( 'assets/ml-paths.css', SNT_PATH . 'signal-and-noise-tools.php' ),
			array(),
			SNT_VERSION
		);

		$out  = '<nav class="sn-reading-path" aria-label="' . esc_attr__( 'Reading path', 'signal-and-noise-tools' ) . '">';
		$out .= '<p class="sn-reading-path__meta">';
		$out .= esc_html( sprintf(
			/* translators: 1: position, 2: total, 3: path label. */
			__( 'Part %1$d of %2$d — %3$s', 'signal-and-noise-tools' ),
			(int) $row['position'],
			(int) $row['total'],
			(string) $row['label']
		) );
		$out .= '</p><div class="sn-reading-path__nav">' . $prev . $next . '</div></nav>';
		return $out;
	}
	add_shortcode( 'sn_reading_path', 'snt_ml_reading_path_shortcode' );
}

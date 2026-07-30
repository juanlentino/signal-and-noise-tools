<?php
/**
 * Signal & Noise — reader-facing "Related notes" section.
 *
 * Appends a server-rendered aside to singular post content via the
 * `the_content` filter (priority 20, after core's markup-adding filters).
 * Zero JavaScript; the stylesheet enqueues at render time only.
 *
 * READER-SURFACE SILENCE: unbuilt artifacts (null) and an empty answer ([])
 * both render NOTHING here — build state is an admin concern, never a
 * reader-visible error. The null/[] distinction still matters upstream
 * (inc/ml-pipelines.php maps them differently); this surface just happens
 * to be silent on both.
 *
 * @package SignalNoiseTools
 * @since 10.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ML_RELATED_RENDER_LIMIT = 4;

if ( ! function_exists( 'snt_ml_related_enqueue_front' ) ) {
	/** Enqueue the front-end stylesheet (render-time only). */
	function snt_ml_related_enqueue_front() {
		wp_enqueue_style(
			'snt-ml-related-front',
			plugins_url( 'assets/ml-related-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
			array(),
			SNT_VERSION
		);
	}
}

if ( ! function_exists( 'snt_ml_related_render' ) ) {
	/**
	 * Append the Related notes aside to main-query singular post content.
	 *
	 * Rows are re-checked against live title/permalink at render — a row
	 * whose post resolves to neither renders nothing rather than a dead link.
	 * All output escaped at build; returns, never echoes.
	 *
	 * @param string $content Filtered post content.
	 * @return string Content, with the aside appended when there is one.
	 */
	function snt_ml_related_render( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		// v10.20.0 THEME-OWNERSHIP GATE: when the active theme ships its own
		// native Related Notes surface ([sn_related_notes] in single.html —
		// which consumes the same kernel ranking from theme v11.2.0), this
		// content-filter aside stands down. Before this gate, live note pages
		// carried TWO related sections. On any theme without that renderer,
		// the aside keeps working unchanged.
		if ( function_exists( 'sn_related_notes_shortcode' ) ) {
			return $content;
		}
		// PR #410 review advisory: core wp_trim_excerpt() applies the_content,
		// so a third-party auto-excerpt generated inside the singular main
		// loop would pass all three guards above and leak the aside's text
		// into the excerpt. No such caller exists today; this closes the door.
		if ( function_exists( 'doing_filter' ) && doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}
		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 || ! function_exists( 'snt_ml_related_for_post' ) ) {
			return $content;
		}

		$rows = snt_ml_related_for_post( $post_id, SNT_ML_RELATED_RENDER_LIMIT );
		if ( ! is_array( $rows ) || array() === $rows ) {
			return $content; // null (unbuilt) and [] alike: silent absence.
		}

		$items = '';
		foreach ( $rows as $row ) {
			$rid   = (int) $row['post_id'];
			$title = (string) get_the_title( $rid );
			$url   = (string) get_permalink( $rid );
			if ( '' === $title || '' === $url ) {
				continue;
			}
			$items .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></li>';
		}
		if ( '' === $items ) {
			return $content;
		}

		snt_ml_related_enqueue_front();

		return $content
			. '<aside class="snt-ml-related" aria-labelledby="snt-ml-related-title">'
			. '<h2 class="snt-ml-related-title" id="snt-ml-related-title">'
			. esc_html__( 'Related notes', 'signal-and-noise-tools' )
			. '</h2>'
			. '<ul>' . $items . '</ul>'
			. '</aside>';
	}
}
add_filter( 'the_content', 'snt_ml_related_render', 20 );

<?php
/**
 * Signal & Noise Tools — Analytics panel primitive (v8.5.0).
 *
 * The ONE place that renders panel chrome for the Dashboard → Analytics page.
 * Every panel is a REAL native .postbox (owner: "keep postbox feel as much as
 * you can") with the crisp-console token treatment applied via the
 * .sn-an-postbox marker in assets/analytics/analytics-admin.css. Renderers must
 * never echo postbox markup themselves — that inline duplication across ~11
 * sites is exactly how the pre-v8.5.0 page drifted into a patchwork.
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// snt_analytics_smooth_path (trend). Guarded — a few CLI fixtures declare their
// own stand-in for this fn before requiring this file (pre-dating this require);
// an unconditional require_once here would redeclare it and fatal (see
// tests/analytics-header-region.php).
if ( ! function_exists( 'snt_analytics_smooth_path' ) ) {
	require_once __DIR__ . '/analytics-render-helpers.php';
}

/**
 * Open a panel: postbox shell + header. Pair with snt_an_panel_close().
 *
 * @param string $title Panel title (plain text; escaped here).
 * @param array  $args  {
 *     @type string $inside_class Body class. Default 'inside'.
 *     @type string $panel_class  Extra classes on the .postbox.
 *     @type string $header_meta  Small muted note right of the title (kses'd).
 *     @type bool   $collapsible  A native <details> whose <summary> is the header. Default false.
 *     @type bool   $collapsed    Start collapsed (only with collapsible). Default false.
 * }
 */
function snt_an_panel_open( $title, $args = array() ) {
	if ( snt_an_surface( 'panel-open', array( 'title' => $title, 'args' => $args ) ) ) {
		return;
	}
	$title        = (string) $title;
	$panel_class  = trim( 'postbox sn-an-postbox ' . (string) ( $args['panel_class'] ?? '' ) );
	$inside_class = (string) ( $args['inside_class'] ?? 'inside' );
	$collapsible  = ! empty( $args['collapsible'] );
	$head         = '<h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2>'
		. ( empty( $args['header_meta'] ) ? '' : '<span class="sn-an-head-meta">' . wp_kses_post( (string) $args['header_meta'] ) . '</span>' );

	// 2026-11-10 core-first audit: a collapsible panel is the platform's own
	// disclosure, so the browser owns the toggle, the keyboard and the expanded
	// state, and the page's `open` is the state, as for every other fold. Not
	// core's postbox.js: add_postbox_toggles() binds every `.postbox .hndle` on
	// the page (the plain panels would fold on a title click), save_state()
	// writes every `.postbox` into two user metas, and postbox_classes() has
	// no default-closed. The plain panels keep the .postbox look and nothing else.
	if ( $collapsible ) {
		echo '<details class="' . esc_attr( $panel_class ) . '" data-sn-an-collapsible="' . esc_attr( sanitize_title( $title ) ) . '"'
			. ( empty( $args['collapsed'] ) ? ' open' : '' ) . '>';
		echo '<summary class="postbox-header">' . $head . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $head is built from esc_html() and wp_kses_post() above.
	} else {
		echo '<div class="' . esc_attr( $panel_class ) . '">';
		echo '<div class="postbox-header">' . $head . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $head is built from esc_html() and wp_kses_post() above.
	}
	snt_an_panel_closers( $collapsible ? '</div></details>' : '</div></div>' );
	echo '<div class="' . esc_attr( $inside_class ) . '">';
}

/**
 * Close the panel opened by snt_an_panel_open().
 */
function snt_an_panel_close() {
	if ( snt_an_surface( 'panel-close', array() ) ) {
		return;
	}
	echo snt_an_panel_closers(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed closing-tag literal pushed by snt_an_panel_open().
}

/**
 * The open panels' closing tags, innermost last: a collapsible panel closes
 * as a </details>, a plain one as a </div>. With $push, remember one; without,
 * hand back the innermost (a plain close when none is open, as it always was).
 *
 * @param string|null $push A closing-tag literal to remember.
 * @return string
 */
function snt_an_panel_closers( $push = null ) {
	static $open = array();
	if ( null !== $push ) {
		$open[] = (string) $push;
		return '';
	}
	return array() === $open ? '</div></div>' : (string) array_pop( $open );
}

/**
 * Let a host render shared report values through its own UI components.
 * Null keeps the classic presentation; the callback must return escaped HTML.
 *
 * @param string $piece Primitive name.
 * @param array  $data Primitive inputs, before classic markup is constructed.
 * @return bool Whether the host rendered this primitive.
 */
function snt_an_surface( $piece, $data ) {
	$html = function_exists( 'apply_filters' ) ? apply_filters( 'snt_analytics_surface', null, $piece, $data ) : null;
	if ( ! is_string( $html ) ) {
		return false;
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- host presentation callback returns escaped component markup.
	return true;
}

/**
 * Open a row that lays its child panels side by side.
 *
 * Exists because `snt_an_panel_open()` emits no wrapper, and a CSS grid needs a
 * parent — so panels could only ever stack. Added (v13.82.0) for the Search tab,
 * where three panels each spent a full width on about a third of a screen's
 * worth of content: a six-row query table, a two-row topic table, and a
 * two-paragraph note.
 *
 * PAIR BY CONTENT SIZE, not by topic. The defect this fixes is "these panels are
 * mostly empty", not "these panels are related" — pairing two LARGE panels looks
 * tidier in a wireframe and reads worse on screen, because long paths and long
 * tables wrap badly at half width.
 *
 * auto-fit + minmax(min(100%, 360px), 1fr) is the responsive idiom already in
 * assets/admin.css: it collapses to one column on a narrow admin screen with no
 * media query, and `min(100%, …)` keeps it from overflowing a container narrower
 * than the floor.
 *
 * @since 13.82.0
 * @return void
 */
function snt_an_cols_open() {
	echo '<div class="sn-an-cols">';
}

/**
 * Close a side-by-side row. Separate function rather than a callback so a view
 * reads top to bottom, the way every panel in this file already does.
 *
 * @since 13.82.0
 * @return void
 */
function snt_an_cols_close() {
	echo '</div>';
}

/**
 * Render an interpretation callout inside a panel body: a short "read" of the
 * data, drawn ONLY when there is something to say. The inverse of the empty-fold
 * collector: draw-on-content, skip on null/empty. The sentence is plain text (the
 * resolver never emits markup) so it escapes with esc_html per
 * WORDPRESS-REFERENCE.md section 7.
 *
 * @param string|null $text One-sentence read, or null / '' to render nothing.
 * @return void
 * @since 9.4.0
 */
function snt_an_annotation( $text ) {
	$text = is_string( $text ) ? trim( $text ) : '';
	if ( '' === $text ) {
		return;
	}
	echo '<div class="sn-an-note"><span class="sn-an-note-label">'
		. esc_html__( 'Read', 'signal-and-noise-tools' ) . '</span> '
		. '<span class="sn-an-note-body">' . esc_html( $text ) . '</span></div>';
}

/**
 * Deploy-marker annotation for the Overview: renders the sn_annotation_deploys()
 * read (releases recorded by inc/deploy-history.php that landed inside the
 * selected range) through the standard annotation callout. Quiet when nothing
 * shipped in range — same draw-on-content contract as every other read.
 *
 * @since 9.81.0
 * @param string $from Range start, 'Y-m-d'.
 * @param string $to   Range end, 'Y-m-d'.
 * @return void
 */
function snt_an_deploys_annotation( $from, $to ) {
	if ( ! function_exists( 'sn_annotation_deploys' ) || ! function_exists( 'snt_deploy_history_get' ) ) {
		return;
	}
	snt_an_annotation( sn_annotation_deploys( snt_deploy_history_get(), $from, $to ) );
}

/**
 * Open a row-clamp region around a long table. Full rows stay in the DOM
 * (already fetched — clamping is display-only, zero extra queries); CSS hides
 * rows past $visible; assets/admin.js toggles .sn-an-clamp--open.
 *
 * @param int $total   Total rows the table will render.
 * @param int $visible Rows visible while clamped. Default 5.
 */
function snt_an_clamp_open( $total, $visible = 5 ) {
	if ( snt_an_surface( 'clamp-open', compact( 'total', 'visible' ) ) ) {
		return;
	}
	echo '<div class="sn-an-clamp sn-an-clamp--' . (int) $visible . '" data-sn-an-total="' . (int) $total . '">';
}

/**
 * Close the clamp region; emits the "View all N" toggle only when needed.
 *
 * @param int $total   Total rows rendered.
 * @param int $visible Rows visible while clamped. Default 5.
 */
function snt_an_clamp_close( $total, $visible = 5 ) {
	if ( snt_an_surface( 'clamp-close', compact( 'total', 'visible' ) ) ) {
		return;
	}
	if ( (int) $total > (int) $visible ) {
		echo '<button type="button" class="sn-an-viewall">'
			/* translators: %d is the total number of items */
			. esc_html( sprintf( __( 'View all %d', 'signal-and-noise-tools' ), (int) $total ) )
			. '</button>';
	}
	echo '</div>';
}

// The rest of the primitives live in three parts files (house line cap, 2026-10-14),
// loaded in the order they always ran, after the panel chrome above.
require_once __DIR__ . '/analytics-panels-fold-parts.php';
require_once __DIR__ . '/analytics-panels-trend-parts.php';
require_once __DIR__ . '/analytics-panels-controls-parts.php';

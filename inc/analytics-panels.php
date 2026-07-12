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

/**
 * Open a panel: postbox shell + header. Pair with snt_an_panel_close().
 *
 * @param string $title Panel title (plain text; escaped here).
 * @param array  $args  {
 *     @type string $inside_class Body class. Default 'inside'.
 *     @type string $panel_class  Extra classes on the .postbox.
 *     @type string $header_meta  Small muted note right of the title (kses'd).
 *     @type bool   $collapsible  Adds the toggle button + marker. Default false.
 *     @type bool   $collapsed    Start collapsed (only with collapsible). Default false.
 * }
 */
function snt_an_panel_open( $title, $args = array() ) {
	$title        = (string) $title;
	$panel_class  = trim( 'postbox sn-an-postbox ' . (string) ( $args['panel_class'] ?? '' ) );
	$inside_class = (string) ( $args['inside_class'] ?? 'inside' );
	$collapsible  = ! empty( $args['collapsible'] );
	$collapsed    = $collapsible && ! empty( $args['collapsed'] );
	if ( $collapsed ) {
		$panel_class .= ' sn-an-collapsed';
	}

	echo '<div class="' . esc_attr( $panel_class ) . '"'
		. ( $collapsible ? ' data-sn-an-collapsible="' . esc_attr( sanitize_title( $title ) ) . '"' : '' )
		. '>';
	echo '<div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2>';
	if ( ! empty( $args['header_meta'] ) ) {
		echo '<span class="sn-an-head-meta">' . wp_kses_post( (string) $args['header_meta'] ) . '</span>';
	}
	if ( $collapsible ) {
		echo '<button type="button" class="sn-an-toggle" aria-expanded="' . ( $collapsed ? 'false' : 'true' ) . '">'
			. '<span class="screen-reader-text">' . esc_html__( 'Toggle panel', 'signal-and-noise-tools' ) . '</span>'
			. '</button>';
	}
	echo '</div>';
	echo '<div class="' . esc_attr( $inside_class ) . '">';
}

/**
 * Close the panel opened by snt_an_panel_open().
 */
function snt_an_panel_close() {
	echo '</div></div>';
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
 * Open a row-clamp region around a long table. Full rows stay in the DOM
 * (already fetched — clamping is display-only, zero extra queries); CSS hides
 * rows past $visible; assets/admin.js toggles .sn-an-clamp--open.
 *
 * @param int $total   Total rows the table will render.
 * @param int $visible Rows visible while clamped. Default 5.
 */
function snt_an_clamp_open( $total, $visible = 5 ) {
	echo '<div class="sn-an-clamp sn-an-clamp--' . (int) $visible . '" data-sn-an-total="' . (int) $total . '">';
}

/**
 * Close the clamp region; emits the "View all N" toggle only when needed.
 *
 * @param int $total   Total rows rendered.
 * @param int $visible Rows visible while clamped. Default 5.
 */
function snt_an_clamp_close( $total, $visible = 5 ) {
	if ( (int) $total > (int) $visible ) {
		echo '<button type="button" class="sn-an-viewall">'
			/* translators: %d is the total number of items */
			. esc_html( sprintf( __( 'View all %d', 'signal-and-noise-tools' ), (int) $total ) )
			. '</button>';
	}
	echo '</div>';
}

/**
 * Record a panel that had no data this range instead of drawing an empty card.
 * Collected per request; emitted as one muted line by snt_an_flush_empty_fold().
 *
 * @param string $title Panel title.
 * @return void
 */
function snt_an_note_empty( $title ) {
	if ( ! isset( $GLOBALS['sn_an_empty_panels'] ) || ! is_array( $GLOBALS['sn_an_empty_panels'] ) ) {
		$GLOBALS['sn_an_empty_panels'] = array();
	}
	$GLOBALS['sn_an_empty_panels'][] = (string) $title;
}

/**
 * Emit the collected empty panels as ONE muted "No data in this range yet: A · B"
 * line, then clear the collector. Emits nothing when nothing was collected.
 *
 * @return void
 */
function snt_an_flush_empty_fold() {
	$names                          = isset( $GLOBALS['sn_an_empty_panels'] ) ? (array) $GLOBALS['sn_an_empty_panels'] : array();
	$GLOBALS['sn_an_empty_panels'] = array();
	if ( empty( $names ) ) {
		return;
	}
	$escaped = array_map( 'esc_html', $names );
	echo '<p class="sn-an-empty sn-an-empty-fold">'
		. esc_html__( 'No data in this range yet:', 'signal-and-noise-tools' ) . ' '
		. implode( ' &middot; ', $escaped ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each element esc_html'd above; separator is a static entity.
		. '</p>';
}

/**
 * Shared maturity tier badge (maturity I6, spec §11): ONE component so every
 * view names its tier identically. Whitelisted; an unknown tier renders
 * nothing — never guess a tier. Returns HTML built from escaped fragments;
 * callers may echo it raw or pass it through the panel primitive's header_meta.
 *
 * @param string $tier 'descriptive' | 'diagnostic' | 'predictive' | 'prescriptive'.
 * @return string
 */
function snt_analytics_tier_badge( $tier ) {
	$tiers = array(
		'descriptive'  => __( 'Descriptive', 'signal-and-noise-tools' ),
		'diagnostic'   => __( 'Diagnostic', 'signal-and-noise-tools' ),
		'predictive'   => __( 'Predictive', 'signal-and-noise-tools' ),
		'prescriptive' => __( 'Prescriptive', 'signal-and-noise-tools' ),
	);
	$key = (string) $tier;
	if ( ! isset( $tiers[ $key ] ) ) {
		return '';
	}
	return '<span class="sn-an-tier sn-an-tier--' . esc_attr( $key ) . '">' . esc_html( $tiers[ $key ] ) . '</span>';
}

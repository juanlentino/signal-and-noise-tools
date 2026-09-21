<?php
/**
 * Signal & Noise Tools — the kit's display elements, painted from PHP.
 *
 * See inc/openstation-kit.php for the escaping these build on.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `<os-stat>`: a value, a label, an optional caption, and a severity swatch
 * when the reading is not fine. The swatch reads the app tone contract
 * (`data-tone` = danger|warning|info|neutral), which is why `ok` paints none.
 *
 * @param string $value   The big number.
 * @param string $label   The small label.
 * @param string $caption Optional caption.
 * @param string $kind    Pill kind (`ok` paints no swatch).
 * @param array  $attrs   Extra attributes.
 * @return string
 */
function snt_kit_stat( $value, $label, $caption = '', $kind = '', array $attrs = array() ) {
	$tone = snt_kit_tone( $kind );
	$base = array(
		'value'   => (string) $value,
		'label'   => (string) $label,
		'caption' => '' !== (string) $caption ? (string) $caption : null,
	);
	if ( '' !== (string) $kind && 'success' !== $tone ) {
		$base['swatch']    = true;
		$base['data-tone'] = $tone;
	}
	return snt_kit_tag( 'os-stat', array_merge( $base, $attrs ) );
}

/**
 * `<os-section heading description>` around painted HTML.
 *
 * @param string $heading     Heading.
 * @param string $inner       Inner HTML.
 * @param string $description Optional description.
 * @param array  $attrs       Extra attributes.
 * @return string
 */
function snt_kit_section( $heading, $inner, $description = '', array $attrs = array() ) {
	return snt_kit_tag(
		'os-section',
		array_merge(
			array(
				'heading'     => (string) $heading,
				'description' => '' !== (string) $description ? (string) $description : null,
				'stack'       => true,
			),
			$attrs
		),
		$inner
	);
}

/**
 * `<os-stack gap>` around painted HTML: the column (kit-help Stack, "use it
 * instead of hand-rolling display:flex; flex-direction:column"). 18 is the
 * leaf's own rhythm.
 *
 * @param string $inner Inner HTML.
 * @param int    $gap   Gap in px.
 * @param array  $attrs Extra attributes.
 * @return string
 */
function snt_kit_stack( $inner, $gap = 18, array $attrs = array() ) {
	return snt_kit_tag( 'os-stack', array_merge( array( 'gap' => (string) (int) $gap ), $attrs ), $inner );
}

/**
 * `<os-grid min-item-width gap>` around sibling boxes: as many equal columns
 * as the grid's own width fits, one column below the minimum, no container
 * query (kit-help Grid, 1.1.10). The item count rides along as
 * `--snt-grid-items` because the kit fits tracks to the width alone and keeps
 * the empty ones (`updateTracks()`: `repeat(columns, minmax(0, 1fr))`), the
 * auto-fill shape tests/os-grid-auto-fit.php pins against; os-app.css caps the
 * tracks at the count, so a pair stays a pair on a released leaf and a tile
 * row fills it. A side that painted nothing is dropped, and a lone box is
 * returned bare, alone at full width (the tags_pair idiom), never beside a
 * hole and never a one-track grid.
 *
 * The defaults are the pair: 290 reads the 640px container query it replaces
 * (a 640px window leaves the leaf 602px inside the body inset, and 2 * 290 +
 * 18 = 598 fits; 636px does not).
 *
 * @param string[] $items          Painted boxes, in order; '' is dropped.
 * @param int      $min_item_width Minimum item width in px.
 * @param int      $gap            Gap in px.
 * @param array    $attrs          Extra attributes.
 * @return string The grid, the lone box bare, or '' when nothing painted.
 */
function snt_kit_grid( array $items, $min_item_width = 290, $gap = 18, array $attrs = array() ) {
	$items = array_values( array_filter( array_map( 'strval', $items ), 'strlen' ) );
	if ( count( $items ) < 2 ) {
		return implode( '', $items );
	}
	return snt_kit_tag(
		'os-grid',
		array_merge(
			array(
				'min-item-width' => (string) (int) $min_item_width,
				'gap'            => (string) (int) $gap,
				'style'          => '--snt-grid-items:' . count( $items ),
			),
			$attrs
		),
		implode( '', $items )
	);
}

/**
 * `<os-notice tone>`; dismissible only when asked.
 *
 * @param string $kind        Pill kind or tone.
 * @param string $inner       Inner HTML.
 * @param bool   $dismissible Whether the notice can be dismissed.
 * @return string
 */
function snt_kit_notice( $kind, $inner, $dismissible = false ) {
	return snt_kit_tag(
		'os-notice',
		array(
			'tone'            => snt_kit_tone( $kind ),
			'not-dismissible' => ! $dismissible,
		),
		$inner
	);
}

/**
 * A Site Health verdict ({status, summary}) as the top of a kit box: the
 * summary as a hint line when the status is `good`, else as a notice, danger
 * for `critical` and warning for anything else. The 17.4.0 box shape
 * (Breached passwords, Action Scheduler backlog), shared since #1599 by the
 * drift, reader-behaviour and pinning boxes.
 *
 * @param array<string,mixed> $verdict status + summary, as the health functions return it.
 * @return string
 */
function snt_kit_verdict( array $verdict ) {
	$status  = (string) ( $verdict['status'] ?? '' );
	$summary = snt_kit_esc( (string) ( $verdict['summary'] ?? '' ) );
	if ( 'good' === $status ) {
		return '<p class="snt-hint">' . $summary . '</p>';
	}
	return snt_kit_notice( 'critical' === $status ? 'err' : 'warn', $summary );
}

/**
 * `<os-badge tone>` and `<os-chip tone>`.
 *
 * @param string $kind Pill kind or tone.
 * @param string $text Text.
 * @return string
 */
function snt_kit_badge( $kind, $text ) {
	return snt_kit_tag( 'os-badge', array( 'tone' => snt_kit_tone( $kind ) ), snt_kit_esc( $text ) );
}

/**
 * @param string $text Text.
 * @param string $kind Pill kind or tone ('' for the plain chip).
 * @return string
 */
function snt_kit_chip( $text, $kind = '' ) {
	return snt_kit_tag( 'os-chip', array( 'tone' => '' !== (string) $kind ? snt_kit_tone( $kind ) : null ), snt_kit_esc( $text ) );
}

/**
 * `<os-relative-time datetime>`: a moment that ages live (OpenStation
 * `src/ui/components/os-relative-time`, Stable). The attribute is the moment
 * in ISO 8601 UTC; the light-DOM child is the server's own signed
 * human_time_diff() reading ("5 mins ago" / "in 5 mins"), the paint before
 * the component upgrades and the paint the fixture suites read. Pass a
 * fallback when the sentence around the moment wants other words.
 *
 * @param int    $timestamp_gmt Unix timestamp (UTC).
 * @param string $fallback_text Light-DOM text; '' derives it from human_time_diff().
 * @return string
 */
function snt_kit_relative_time( $timestamp_gmt, $fallback_text = '' ) {
	$ts = (int) $timestamp_gmt;
	if ( '' === (string) $fallback_text ) {
		$now  = time();
		$diff = human_time_diff( min( $ts, $now ), max( $ts, $now ) );
		/* translators: %s: human time diff. */
		$fallback_text = $ts > $now ? sprintf( __( 'in %s', 'signal-and-noise-tools' ), $diff ) : sprintf( __( '%s ago', 'signal-and-noise-tools' ), $diff );
	}
	return snt_kit_tag( 'os-relative-time', array( 'datetime' => gmdate( 'Y-m-d\TH:i:s\Z', $ts ) ), snt_kit_esc( $fallback_text ) );
}

/**
 * `<os-code>`; block by default, wrapped so long lines fold.
 *
 * @param string $text  Code text (escaped here).
 * @param bool   $block Block or inline.
 * @return string
 */
function snt_kit_code( $text, $block = true ) {
	return snt_kit_tag( 'os-code', array( 'block' => (bool) $block, 'wrap' => (bool) $block ), snt_kit_esc( $text ) );
}

/**
 * `<os-steps>` of bare `<os-step>`s: a setup sequence, numbered by the kit's
 * CSS counter (OpenStation `src/ui/components/os-steps`, Stable). Each body is
 * already-escaped markup; the step has no title, the sentence is the body.
 *
 * @param string[] $bodies One escaped body per step.
 * @return string
 * @since 17.4.4
 */
function snt_kit_steps( array $bodies ) {
	$steps = '';
	foreach ( $bodies as $body ) {
		$steps .= snt_kit_tag( 'os-step', array(), (string) $body );
	}
	return snt_kit_tag( 'os-steps', array(), $steps );
}

/**
 * `<os-empty-state icon heading description>`.
 *
 * @param string $heading     Heading.
 * @param string $description Description.
 * @param string $icon        Dashicons slug (without the prefix is fine).
 * @return string
 */
function snt_kit_empty( $heading, $description = '', $icon = '' ) {
	return snt_kit_tag(
		'os-empty-state',
		array(
			'heading'     => (string) $heading,
			'description' => '' !== (string) $description ? (string) $description : null,
			'icon'        => '' !== (string) $icon ? (string) $icon : null,
		)
	);
}

/**
 * The leaf bar: the list toolbar every native window paints, with its
 * status control bound to a state key. A pick writes the key and repaints;
 * no panels, the server paints the chosen leaf.
 *
 * 17.4.3: this was `<os-tabs class="os-app-list__tabs">`, whose active tab
 * wears the station's accent underline while the window chrome's active tab
 * is bold white; the two navigation rows never shared a colour. Pages, Posts,
 * Users and Plugins paint this level as `statusControl()` does
 * (app-runtime `list-ui.ts`): `<os-segmented class="os-app-list__status">`
 * on a desk, `<os-select class="os-app-list__status">` on a phone, where nine
 * pills in 360px wrap into ragged rows. The runtime decides by
 * `isMobileStamped()` at paint time; a server paint cannot, so both twins
 * ship and `apps/sn-dashboard/sn-dashboard.css` shows one by
 * `html[data-os-mode="mobile"]`; off the stamp the pill row scrolls
 * sideways in a window narrower than its nine pills. The two components
 * share the `os-pick` contract, so `os-bind` is the same on both.
 *
 * @param string               $active Active value.
 * @param array<string,string> $items  value => label, in order.
 * @param string               $bind   State key.
 * @param string               $label  Accessible label.
 * @return string
 */
function snt_kit_tabs( $active, array $items, $bind = 'sub', $label = '', array $attrs = array() ) {
	$segments = '';
	$options  = '';
	foreach ( $items as $value => $text ) {
		$segments .= snt_kit_tag( 'os-segment', array( 'value' => (string) $value ), snt_kit_esc( $text ) );
		$options  .= snt_kit_tag( 'os-option', array( 'value' => (string) $value ), snt_kit_esc( $text ) );
	}
	$label = '' !== (string) $label ? (string) $label : null;
	$desk  = snt_kit_tag(
		'os-segmented',
		array( 'class' => 'os-app-list__status', 'value' => (string) $active, 'os-bind' => (string) $bind, 'label' => $label ),
		$segments
	);
	$phone = snt_kit_tag(
		'os-select',
		// os-key: os-select mints an auto id on connect and the morph keys a live
		// node by os-key or id, so an un-keyed server paint replaces it on every
		// repaint (#1116 fixed the Analytics selects the same way).
		array( 'class' => 'os-app-list__status snt-subbar__phone', 'os-key' => 'subbar-phone', 'value' => (string) $active, 'os-bind' => (string) $bind, 'aria-label' => $label ),
		$options
	);
	return snt_kit_tag(
		'header',
		array_merge( array( 'class' => 'os-app-list__toolbar snt-subbar' ), $attrs ),
		snt_kit_tag( 'div', array( 'class' => 'os-app-list__toolbar-left' ), $desk . $phone )
	);
}

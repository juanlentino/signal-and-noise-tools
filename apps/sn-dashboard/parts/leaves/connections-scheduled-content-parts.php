<?php
/**
 * S&N Dashboard — Connections → Scheduled: the leaf's row/section parts.
 *
 * The classic leaf (inc/schedule-admin.php, `sn_admin_render_scheduled_content_section()`)
 * folds two producers (fragment/queue rows + native future posts) into one
 * .widefat table, plus a derived "version swaps" table above it. Neither
 * table can become an `<os-table>` here: every row carries one or two live
 * `<form>` ops (Run now / Re-purge / Run swap now), and os-table's `data` is
 * JSON — it cannot carry a form. Each row instead paints as an
 * `<li class="snt-list__row">` inside `<ul class="snt-list">` — the SAME
 * list vocabulary content-tags-parts.php's `tags_cluster_html()` uses for a
 * multi-column row (radio + checkbox + label + count), reusing only
 * `snt-list__label` (the one flexible, ellipsising cell — here the Target)
 * and `snt-list__value` (fixed-width cells) rather than inventing new
 * classes; a leading header `<li>` of the same shape (no modifier class —
 * that file's own comment explains why `snt-list__row--head` /
 * `snt-list__col` are deliberately never invented, since neither stylesheet
 * defines them) carries the classic column names. Per-row ops that sit
 * side by side (Run now + Re-purge) are wrapped in `<os-cluster gap="8">`,
 * the house idiom for that (monitoring-insights-parts.php, dashboard.php,
 * security-audit-log.php) — not the invented `.snt-schedule-op` class, which
 * has no rule in either stylesheet.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The header row for the main status list (Target / Type / Action / Window /
 * Status / Next transition / Actions) — the classic `<thead>`'s column names,
 * on the SAME row shape as the data rows so they align without a modifier
 * class that no stylesheet defines.
 *
 * @return string
 */
function scheduled_content_row_head_html() {
	$cols = array(
		__( 'Target', 'signal-and-noise-tools' ),
		__( 'Type', 'signal-and-noise-tools' ),
		__( 'Action', 'signal-and-noise-tools' ),
		__( 'Window', 'signal-and-noise-tools' ),
		__( 'Status', 'signal-and-noise-tools' ),
		__( 'Next transition', 'signal-and-noise-tools' ),
		__( 'Actions', 'signal-and-noise-tools' ),
	);
	return scheduled_content_head_row_html( $cols );
}

/**
 * The header row for the version-swaps list (Target / Swap at / Status /
 * Next transition / Actions) — the classic swap `<thead>`'s column names.
 *
 * @return string
 */
function scheduled_content_swap_head_html() {
	$cols = array(
		__( 'Target', 'signal-and-noise-tools' ),
		__( 'Swap at', 'signal-and-noise-tools' ),
		__( 'Status', 'signal-and-noise-tools' ),
		__( 'Next transition', 'signal-and-noise-tools' ),
		__( 'Actions', 'signal-and-noise-tools' ),
	);
	return scheduled_content_head_row_html( $cols );
}

/**
 * One header `<li>`: the first column as `snt-list__label` (matching the
 * data rows' flexible Target cell), the rest as `snt-list__value`.
 *
 * @param string[] $cols Column names, in row order.
 * @return string
 */
function scheduled_content_head_row_html( array $cols ) {
	$out = '';
	foreach ( $cols as $i => $col ) {
		$class = 0 === $i ? 'snt-list__label' : 'snt-list__value';
		$out  .= '<span class="' . $class . '">' . \snt_kit_esc( $col ) . '</span>';
	}
	return '<li class="snt-list__row">' . $out . '</li>';
}

/**
 * One 7-column row (Target / Type / Action / Window / Status / Next
 * transition / Actions) — the fragment and future-post rows share this.
 *
 * @param string $target_html  Painted target cell.
 * @param string $type         Type text ("Fragment"/"Page").
 * @param string $action       Action text (the row's verb / "Publish").
 * @param string $window_html  Painted window cell.
 * @param string $status       Status text.
 * @param string $next_html    Painted "next transition" cell.
 * @param string $actions_html Painted actions cell.
 * @return string
 */
function scheduled_content_row_html( $target_html, $type, $action, $window_html, $status, $next_html, $actions_html ) {
	return '<li class="snt-list__row">'
		. '<span class="snt-list__label">' . $target_html . '</span>'
		. '<span class="snt-list__value">' . \snt_kit_esc( $type ) . '</span>'
		. '<span class="snt-list__value">' . \snt_kit_esc( $action ) . '</span>'
		. '<span class="snt-list__value">' . $window_html . '</span>'
		. '<span class="snt-list__value">' . \snt_kit_esc( $status ) . '</span>'
		. '<span class="snt-list__value">' . $next_html . '</span>'
		. '<span class="snt-list__value">' . $actions_html . '</span>'
		. '</li>';
}

/**
 * The Target cell for a fragment/swap row: the host post's title, linked to
 * its editor (an OTHER admin screen — a kit door) when we have one, plus its
 * id; a fallback label when there is no host post.
 *
 * @param int    $target_id      The host post id (0 when unlinked).
 * @param string $unlinked_label Fallback text when $target_id is 0.
 * @return string
 */
function scheduled_content_target_html( $target_id, $unlinked_label ) {
	$target_id = (int) $target_id;
	if ( $target_id <= 0 ) {
		return '<span class="snt-hint">' . \snt_kit_esc( $unlinked_label ) . '</span>';
	}
	$title     = (string) get_the_title( $target_id );
	$edit_link = get_edit_post_link( $target_id );
	$body      = ( is_string( $edit_link ) && '' !== $edit_link )
		? \snt_kit_door( $title, $edit_link )
		: '<span>' . \snt_kit_esc( $title ) . '</span>';
	return $body . ' <span class="snt-hint">#' . \snt_kit_esc( (string) $target_id ) . '</span>';
}

/**
 * The Target cell for a native-scheduled-post row: its own title/id/edit_link,
 * as sn_schedule_future_posts() normalizes them (no get_the_title() lookup).
 *
 * @param array<string,mixed> $post A sn_schedule_future_posts() row.
 * @return string
 */
function scheduled_content_post_target_html( array $post ) {
	$title     = (string) ( $post['title'] ?? '' );
	$post_id   = (int) ( $post['id'] ?? 0 );
	$edit_link = (string) ( $post['edit_link'] ?? '' );
	$body      = '' !== $edit_link ? \snt_kit_door( $title, $edit_link ) : '<span>' . \snt_kit_esc( $title ) . '</span>';
	if ( $post_id > 0 ) {
		$body .= ' <span class="snt-hint">#' . \snt_kit_esc( (string) $post_id ) . '</span>';
	}
	return $body;
}

/**
 * One tiny op form (Run now / Re-purge): the SAME sn_action + row_id the
 * classic sn_admin_render_schedule_op_button() posts, as a kit form.
 *
 * @param int    $row_id    The schedule row id.
 * @param string $sn_action schedule_run_now|schedule_repurge.
 * @param string $label     Submit label.
 * @return string
 */
function scheduled_content_op_form( $row_id, $sn_action, $label ) {
	return \snt_kit_form(
		$sn_action,
		'',
		array(
			'submit' => $label,
			'hidden' => array( 'row_id' => (string) (int) $row_id ),
		)
	);
}

/**
 * One fragment/queue row: same reads as sn_admin_render_schedule_fragment_row().
 *
 * @param array<string,mixed> $row A wp_sn_schedules row.
 * @return string
 */
function scheduled_content_fragment_row_html( array $row ) {
	$row_id    = (int) ( $row['id'] ?? 0 );
	$target_id = (int) ( $row['target_ref'] ?? 0 );
	$action    = (string) ( $row['action'] ?? '' );
	$status    = (string) ( $row['status'] ?? 'queued' );

	$target = scheduled_content_target_html( $target_id, __( '(unlinked fragment)', 'signal-and-noise-tools' ) );
	$window = function_exists( 'sn_admin_schedule_window_html' ) ? \sn_admin_schedule_window_html( $row['starts_at'] ?? null, $row['ends_at'] ?? null ) : '';
	$next   = function_exists( 'sn_admin_schedule_next_transition' ) ? \sn_admin_schedule_next_transition( $row['starts_at'] ?? null, $row['ends_at'] ?? null ) : '';

	$actions = '<span class="snt-hint">&mdash;</span>';
	if ( $row_id > 0 ) {
		$actions = '<os-cluster gap="8">'
			. scheduled_content_op_form( $row_id, 'schedule_run_now', __( 'Run now', 'signal-and-noise-tools' ) )
			. scheduled_content_op_form( $row_id, 'schedule_repurge', __( 'Re-purge', 'signal-and-noise-tools' ) )
			. '</os-cluster>';
	}

	return scheduled_content_row_html(
		$target,
		__( 'Fragment', 'signal-and-noise-tools' ),
		$action,
		$window,
		$status,
		'' !== $next ? \snt_kit_esc( $next ) : '&mdash;',
		$actions
	);
}

/**
 * One native-scheduled-post row: same reads as
 * sn_admin_render_schedule_future_post_row(). Core-managed, so no ops.
 *
 * @param array<string,mixed> $post A sn_schedule_future_posts() row.
 * @return string
 */
function scheduled_content_post_row_html( array $post ) {
	$gmt    = (string) ( $post['scheduled_gmt'] ?? '' );
	$next   = function_exists( 'sn_admin_schedule_next_transition' ) ? \sn_admin_schedule_next_transition( $gmt, null ) : '';
	$window = function_exists( 'sn_admin_schedule_fmt_gmt' ) ? \snt_kit_esc( \sn_admin_schedule_fmt_gmt( $gmt ) ) : '';

	return scheduled_content_row_html(
		scheduled_content_post_target_html( $post ),
		__( 'Page', 'signal-and-noise-tools' ),
		__( 'Publish', 'signal-and-noise-tools' ),
		$window,
		__( 'Scheduled', 'signal-and-noise-tools' ),
		'' !== $next ? \snt_kit_esc( $next ) : '&mdash;',
		'<span class="snt-hint">' . \snt_kit_esc( __( 'native', 'signal-and-noise-tools' ) ) . '</span>'
	);
}

/**
 * One version-swap row (v8.0.0): same reads as sn_admin_render_schedule_swaps()'s
 * per-pair body — 5 columns (no Type/Action; the pair IS the operation).
 *
 * @param array<string,mixed> $pair One sn_schedule_swap_pairs() entry.
 * @return string
 */
function scheduled_content_swap_row_html( array $pair ) {
	$target_id = (int) ( $pair['target_ref'] ?? 0 );
	$hide      = (array) ( $pair['hide'] ?? array() );
	$show      = (array) ( $pair['show'] ?? array() );
	$hide_id   = (int) ( $hide['id'] ?? 0 );
	$show_id   = (int) ( $show['id'] ?? 0 );
	$swap_at   = (string) ( $pair['swap_at'] ?? '' );

	$target = scheduled_content_target_html( $target_id, __( '(unlinked)', 'signal-and-noise-tools' ) );
	$window = function_exists( 'sn_admin_schedule_fmt_gmt' ) ? \snt_kit_esc( \sn_admin_schedule_fmt_gmt( $swap_at ) ) : '';
	$status = \snt_kit_esc( (string) ( $hide['status'] ?? 'queued' ) . ' → ' . (string) ( $show['status'] ?? 'queued' ) );
	$next   = function_exists( 'sn_admin_schedule_next_transition' ) ? \sn_admin_schedule_next_transition( $swap_at, null ) : '';

	$actions = '<span class="snt-hint">&mdash;</span>';
	if ( $hide_id > 0 && $show_id > 0 ) {
		$actions = \snt_kit_form(
			'schedule_swap_run_now',
			'',
			array(
				'submit' => __( 'Run swap now', 'signal-and-noise-tools' ),
				'hidden' => array( 'hide_id' => (string) $hide_id, 'show_id' => (string) $show_id ),
			)
		);
	}

	return '<li class="snt-list__row">'
		. '<span class="snt-list__label">' . $target . '</span>'
		. '<span class="snt-list__value">' . $window . '</span>'
		. '<span class="snt-list__value">' . $status . '</span>'
		. '<span class="snt-list__value">' . ( '' !== $next ? \snt_kit_esc( $next ) : '&mdash;' ) . '</span>'
		. '<span class="snt-list__value">' . $actions . '</span>'
		. '</li>';
}

/**
 * The version-swaps section: same reads as sn_admin_render_schedule_swaps().
 * Emits nothing when there are no derived pairs.
 *
 * @param array<int,array<string,mixed>> $pairs sn_schedule_swap_pairs() output.
 * @return string
 */
function scheduled_content_swaps_html( array $pairs ) {
	if ( empty( $pairs ) ) {
		return '';
	}
	$rows = scheduled_content_swap_head_html();
	foreach ( $pairs as $pair ) {
		if ( is_array( $pair ) ) {
			$rows .= scheduled_content_swap_row_html( $pair );
		}
	}
	return \snt_kit_section(
		__( 'Version swaps', 'signal-and-noise-tools' ),
		'<ul class="snt-list">' . $rows . '</ul>',
		__( 'Two scheduled containers on the same page whose windows meet at one instant: the current version hides and the new version reveals together, with a single edge purge.', 'signal-and-noise-tools' )
	);
}

/**
 * The first-glance hero: same cards as snt_schedule_glance_cards() (defined
 * in the classic file — reused verbatim, it is already pure data), inside the
 * classic's own landmark (`<section aria-label="Scheduled content at a
 * glance">`) so it keeps its accessible name.
 *
 * @param array<int,array<string,mixed>> $cards From snt_schedule_glance_cards().
 * @return string
 */
function scheduled_content_glance_html( array $cards ) {
	$out = '';
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$out .= \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), (string) ( $card['meta_html'] ?? '' ) );
	}
	return \snt_kit_tag(
		'section',
		array( 'aria-label' => __( 'Scheduled content at a glance', 'signal-and-noise-tools' ) ),
		'<div class="snt-stats">' . $out . '</div>'
	);
}

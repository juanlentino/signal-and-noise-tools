<?php
/**
 * Signal & Noise Tools: scheduled-content admin status list + ops.
 *
 * Task 8 of the scheduled-content subsystem. A READ-MOSTLY admin surface under
 * Connections -> Scheduled that folds two data sources into one native
 * .wp-list-table:
 *   - sn_schedule_all():          the fragment/queue rows (sn/scheduled blocks
 *                                 mirrored into wp_sn_schedules), with a window
 *                                 and a queued/active/done/error status.
 *   - sn_schedule_future_posts(): WordPress posts/pages in `future` status,
 *                                 native-scheduled to auto-publish.
 *
 * It is read-mostly: the only writes are two per-fragment ops, "Run now" (force
 * the boundary fire) and "Re-purge" (re-dispatch the row's Cloudflare purge).
 * Both POST through the shared sn_handle_admin_post() dispatcher on the
 * page=sn-connections route, so the cap check (manage_options) + the shared
 * nonce (sn_theme_options_nonce) are enforced by the dispatcher BEFORE either
 * handler body below runs. The handlers therefore only do the row work.
 *
 * Native scheduled posts get NO ops buttons: they are core-managed (core
 * publishes them, and inc/cloudflare-purge.php already purges on that publish).
 *
 * Modeled on inc/cron-dashboard-admin.php (the native widefat-striped read list
 * with per-row button-small actions) and inc/webhooks-admin.php (the inline
 * <form method="post"> + wp_nonce_field + hidden sn_action ops idiom). NO
 * brutalist styling. wp-admin reads native.
 *
 * TIMEZONE: starts_at / ends_at are stored UTC. They are converted to the site
 * timezone for display via get_date_from_gmt() (UTC string -> site-tz string),
 * never shown raw. The fragment status/window come straight from the row; the
 * "Next transition" column is a human_time_diff() to the soonest future boundary.
 *
 * NOT required from the plugin bootstrap here; Task 9 wires it. The render fn is
 * referenced by NAME from the admin registry (inc/admin-tabs-data.php), so it
 * only needs to be defined when rendering runs.
 *
 * IA SCHED1 (fold arc): the row wall folds. Glance cards stay OPEN above it —
 * the honesty layer is never collapsible — and the closed
 * <details class="sn-schedule-log sn-disclosure"> carries the TRUE total in its
 * summary, so the fold hides the evidence and never that there is any. The list
 * caps at SN_SCHEDULE_DISPLAY_CAP with the house remainder line.
 *
 * The ordering is the load-bearing part, not the fold. A cap over an unsorted
 * union of two producers drops whichever rows happened to land late, and the
 * rows worth keeping are exactly the ones about to fire — so the renderer sorts
 * on a COPY by soonest pending transition (sn_admin_schedule_ordered_rows()),
 * across BOTH sources, with "nothing pending" sorting last rather than reading
 * as the soonest possible moment. Correctness of the cap must not depend on a
 * distant producer continuing to return a helpful order.
 *
 * @package SignalNoiseTools
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display cap for the scheduled-content wall (IA SCHED1). Truncates the LIST
 * only — the glance cards and the fold summary always carry the true total, so
 * a capped list can never under-report how much is scheduled.
 */
const SN_SCHEDULE_DISPLAY_CAP = 25;

/**
 * Render the scheduled-content status list: the union of the fragment/queue rows
 * and the native scheduled posts, one native .wp-list-table.
 *
 * Every dynamic value (post titles, URLs, datetimes, statuses) is escaped at its
 * output sink: esc_html for text, esc_url for hrefs, esc_attr for attributes. A
 * post title is user-controlled, so it MUST be esc_html'd.
 *
 * @return void
 */
/**
 * First-glance hero cards for the Scheduled-content tab: total awaiting, the
 * fragment (reveal/hide block) count, and the native future-post count. Pure —
 * sourced from the already-fetched lists, no extra query.
 *
 * @param array $fragments sn_schedule_all() rows.
 * @param array $posts     sn_schedule_future_posts() rows.
 * @return array<int,array<string,mixed>> Cards for sn_admin_glance_grid().
 *
 * @since 6.45.0
 */
function snt_schedule_glance_cards( $fragments, $posts ) {
	$frag = is_array( $fragments ) ? count( $fragments ) : 0;
	$post = is_array( $posts ) ? count( $posts ) : 0;
	return array(
		array(
			'label'     => 'Scheduled',
			'value'     => (string) ( $frag + $post ),
			'meta_html' => esc_html( 'awaiting transition' ),
		),
		array(
			'label'     => 'Fragments',
			'value'     => (string) $frag,
			'meta_html' => esc_html( 'reveal / hide blocks' ),
		),
		array(
			'label'     => 'Future posts',
			'value'     => (string) $post,
			'meta_html' => esc_html( 'auto-publish' ),
		),
	);
}

function sn_admin_render_scheduled_content_section() {
	$fragments = function_exists( 'sn_schedule_all' ) ? sn_schedule_all() : array();
	$posts     = function_exists( 'sn_schedule_future_posts' ) ? sn_schedule_future_posts() : array();
	$total     = count( $fragments ) + count( $posts );

	// Glance hero (v6.45.0): total / fragments / future posts — first-glance over
	// the full-width table (the leaf is marked 'wide'). Only when there is content;
	// the empty path keeps its friendly empty row below.
	if ( $total > 0 && function_exists( 'sn_admin_glance_grid' ) ) {
		echo '<section aria-label="Scheduled content at a glance">';
		sn_admin_glance_grid( snt_schedule_glance_cards( $fragments, $posts ) );
		echo '</section>';
	}

	// v8.0.0: version swaps — pairs DERIVED from the fragment rows (old
	// container's until === new container's from, same host). Rendered above
	// the flat table so the one-operation view leads; the underlying two rows
	// stay listed below (they carry the per-row detail + individual ops).
	if ( function_exists( 'sn_schedule_swap_pairs' ) ) {
		sn_admin_render_schedule_swaps( sn_schedule_swap_pairs( $fragments ) );
	}

	echo '<p class="sn-field-helper">' . esc_html__( 'Hand-authored content scheduled to reveal or hide on a date (signal-noise/scheduled blocks), plus WordPress posts and pages waiting to auto-publish. Times shown in the site timezone.', 'signal-and-noise-tools' ) . '</p>';

	if ( 0 === $total ) {
		echo '<table class="wp-list-table widefat striped"><tbody><tr><td>'
			. esc_html__( 'No scheduled content. Add a signal-noise/scheduled block to a page, or schedule a post for the future, and it will appear here.', 'signal-and-noise-tools' )
			. '</td></tr></tbody></table>';
		return;
	}

	// IA SCHED1: the row wall folds. The glance above stays open — the honesty
	// layer is never collapsible — and the summary carries the TRUE total, so
	// the fold hides the evidence but never that there is any.
	$ordered = sn_admin_schedule_ordered_rows( $fragments, $posts );
	$shown   = array_slice( $ordered, 0, SN_SCHEDULE_DISPLAY_CAP );

	echo '<details class="sn-schedule-log sn-disclosure">';
	echo '<summary>' . esc_html(
		sprintf(
			/* translators: %d: total scheduled items. */
			_n( '%d scheduled item', '%d scheduled items', $total, 'signal-and-noise-tools' ),
			$total
		)
	) . '</summary>';

	echo '<table class="wp-list-table widefat striped">';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Target', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Type', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Action', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Window', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Status', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Next transition', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Actions', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $shown as $entry ) {
		if ( 'post' === $entry['kind'] ) {
			sn_admin_render_schedule_future_post_row( $entry['row'] );
		} else {
			sn_admin_render_schedule_fragment_row( $entry['row'] );
		}
	}

	echo '</tbody></table>';

	// Remainder line in the house shape (motion / contrast set it): a
	// .sn-field-helper paragraph AFTER the table, "+N more <noun>, sorted
	// <key>-first — the tail is <what>." Inside the fold, because it describes
	// the list it follows.
	$remainder = $total - count( $shown );
	if ( $remainder > 0 ) {
		echo '<p class="sn-field-helper sn-schedule-remainder">';
		printf(
			/* translators: %d: hidden row count */
			esc_html(
				_n(
					'+%d more scheduled item, sorted soonest-first — the tail is the furthest out.',
					'+%d more scheduled items, sorted soonest-first — the tail is the furthest out.',
					$remainder,
					'signal-and-noise-tools'
				)
			),
			(int) $remainder
		);
		echo '</p>';
	}

	echo '</details>';
}

/**
 * Render the derived version-swap pairs as their own compact table (v8.0.0).
 *
 * Each pair is ONE operational unit: the old container hides and the new one
 * reveals at the same instant, so the row shows the single swap time and a
 * single "Run swap now" op (both fires; the per-request purge memo makes it
 * one edge purge). Emits nothing when no pairs exist.
 *
 * @param array $pairs sn_schedule_swap_pairs() output.
 * @return void
 */
function sn_admin_render_schedule_swaps( array $pairs ) {
	if ( empty( $pairs ) ) {
		return;
	}

	echo '<h3>' . esc_html__( 'Version swaps', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-field-helper">' . esc_html__( 'Two scheduled containers on the same page whose windows meet at one instant: the current version hides and the new version reveals together, with a single edge purge.', 'signal-and-noise-tools' ) . '</p>';

	echo '<table class="wp-list-table widefat striped">';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Target', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Swap at', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Status', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Next transition', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Actions', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $pairs as $pair ) {
		$target_id = (int) ( $pair['target_ref'] ?? 0 );
		$hide_id   = (int) ( $pair['hide']['id'] ?? 0 );
		$show_id   = (int) ( $pair['show']['id'] ?? 0 );

		echo '<tr>';

		echo '<th scope="row">';
		$edit_link = $target_id > 0 ? get_edit_post_link( $target_id ) : '';
		if ( $target_id > 0 && is_string( $edit_link ) && '' !== $edit_link ) {
			echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( get_the_title( $target_id ) ) . '</a>';
			echo ' <small>#' . esc_html( (string) $target_id ) . '</small>';
		} elseif ( $target_id > 0 ) {
			echo esc_html( get_the_title( $target_id ) ) . ' <small>#' . esc_html( (string) $target_id ) . '</small>';
		} else {
			echo '<small>' . esc_html__( '(unlinked)', 'signal-and-noise-tools' ) . '</small>';
		}
		echo '</th>';

		// The single swap instant, site-tz.
		echo '<td>' . esc_html( sn_admin_schedule_fmt_gmt( $pair['swap_at'] ?? '' ) ) . '</td>';

		// Pair status: old-side → new-side.
		$hide_status = (string) ( $pair['hide']['status'] ?? 'queued' );
		$show_status = (string) ( $pair['show']['status'] ?? 'queued' );
		echo '<td>' . esc_html( $hide_status . ' → ' . $show_status ) . '</td>';

		// Relative time to the swap instant (blank once past).
		$next = sn_admin_schedule_next_transition( $pair['swap_at'] ?? null, null );
		echo '<td>' . ( '' !== $next ? esc_html( $next ) : '&mdash;' ) . '</td>';

		// One op: run the whole swap now.
		echo '<td>';
		if ( $hide_id > 0 && $show_id > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-connections' ) ) . '" class="sn-schedule-op">';
			wp_nonce_field( 'sn_theme_options_nonce' );
			echo '<input type="hidden" name="sn_action" value="schedule_swap_run_now">';
			echo '<input type="hidden" name="hide_id" value="' . esc_attr( (string) $hide_id ) . '">';
			echo '<input type="hidden" name="show_id" value="' . esc_attr( (string) $show_id ) . '">';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Run swap now', 'signal-and-noise-tools' ) . '</button>';
			echo '</form>';
		} else {
			echo '<small>&mdash;</small>';
		}
		echo '</td>';

		echo '</tr>';
	}

	echo '</tbody></table>';
}

/**
 * Render one fragment/queue row of the scheduled-content table.
 *
 * @param array $row A wp_sn_schedules row (id, target_ref, action, starts_at,
 *                   ends_at, status, …).
 * @return void
 */
function sn_admin_render_schedule_fragment_row( array $row ) {
	$row_id    = isset( $row['id'] ) ? (int) $row['id'] : 0;
	$target_id = isset( $row['target_ref'] ) ? (int) $row['target_ref'] : 0;
	$action    = isset( $row['action'] ) ? (string) $row['action'] : '';
	$status    = isset( $row['status'] ) ? (string) $row['status'] : 'queued';

	echo '<tr>';

	// Target: link to the host post's editor when we have one + an edit link.
	echo '<th scope="row">';
	$edit_link = $target_id > 0 ? get_edit_post_link( $target_id ) : '';
	if ( $target_id > 0 && is_string( $edit_link ) && '' !== $edit_link ) {
		echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( get_the_title( $target_id ) ) . '</a>';
		echo ' <small>#' . esc_html( (string) $target_id ) . '</small>';
	} elseif ( $target_id > 0 ) {
		echo esc_html( get_the_title( $target_id ) ) . ' <small>#' . esc_html( (string) $target_id ) . '</small>';
	} else {
		echo '<small>' . esc_html__( '(unlinked fragment)', 'signal-and-noise-tools' ) . '</small>';
	}
	echo '</th>';

	// Type.
	echo '<td>' . esc_html__( 'Fragment', 'signal-and-noise-tools' ) . '</td>';

	// Action: reveal / hide (the row's stored action verb).
	echo '<td>' . esc_html( $action ) . '</td>';

	// Window: from -> until, converted UTC -> site timezone for display.
	echo '<td>' . wp_kses_post( sn_admin_schedule_window_html( $row['starts_at'] ?? null, $row['ends_at'] ?? null ) ) . '</td>';

	// Status.
	echo '<td>' . esc_html( $status ) . '</td>';

	// Next transition: relative time to the soonest FUTURE boundary, if any.
	$next_frag = sn_admin_schedule_next_transition( $row['starts_at'] ?? null, $row['ends_at'] ?? null );
	echo '<td>' . ( '' !== $next_frag ? esc_html( $next_frag ) : '&mdash;' ) . '</td>';

	// Actions: two tiny POST forms. Cap + nonce are enforced by the dispatcher.
	echo '<td>';
	if ( $row_id > 0 ) {
		sn_admin_render_schedule_op_button( $row_id, 'schedule_run_now', __( 'Run now', 'signal-and-noise-tools' ) );
		echo ' ';
		sn_admin_render_schedule_op_button( $row_id, 'schedule_repurge', __( 'Re-purge', 'signal-and-noise-tools' ) );
	} else {
		echo '<small>&mdash;</small>';
	}
	echo '</td>';

	echo '</tr>';
}

/**
 * Render one native-scheduled-post row of the scheduled-content table. These are
 * core-managed (no ops buttons).
 *
 * @param array $post A normalized future-post row from sn_schedule_future_posts()
 *                    (id, title, scheduled_gmt, edit_link, …).
 * @return void
 */
function sn_admin_render_schedule_future_post_row( array $post ) {
	$title     = isset( $post['title'] ) ? (string) $post['title'] : '';
	$post_id   = isset( $post['id'] ) ? (int) $post['id'] : 0;
	$edit_link = isset( $post['edit_link'] ) ? (string) $post['edit_link'] : '';
	$gmt       = isset( $post['scheduled_gmt'] ) ? (string) $post['scheduled_gmt'] : '';

	echo '<tr>';

	// Target: the post title, linked to its editor when core gave us a link.
	echo '<th scope="row">';
	if ( '' !== $edit_link ) {
		echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $title ) . '</a>';
	} else {
		echo esc_html( $title );
	}
	if ( $post_id > 0 ) {
		echo ' <small>#' . esc_html( (string) $post_id ) . '</small>';
	}
	echo '</th>';

	// Type.
	echo '<td>' . esc_html__( 'Page', 'signal-and-noise-tools' ) . '</td>';

	// Action: native posts always publish at their scheduled instant.
	echo '<td>' . esc_html__( 'Publish', 'signal-and-noise-tools' ) . '</td>';

	// Window: a single instant (the publish time), site-tz.
	echo '<td>' . esc_html( sn_admin_schedule_fmt_gmt( $gmt ) ) . '</td>';

	// Status: core-managed.
	echo '<td>' . esc_html__( 'Scheduled', 'signal-and-noise-tools' ) . '</td>';

	// Next transition: relative to the publish instant.
	$next_post = sn_admin_schedule_next_transition( $gmt, null );
	echo '<td>' . ( '' !== $next_post ? esc_html( $next_post ) : '&mdash;' ) . '</td>';

	// Actions: none; native posts are core-managed.
	echo '<td><small>' . esc_html__( 'native', 'signal-and-noise-tools' ) . '</small></td>';

	echo '</tr>';
}

/**
 * One tiny POST form rendering a single op button (Run now / Re-purge) for a
 * fragment row. POSTs to admin.php?page=sn-connections with the shared nonce, a
 * hidden sn_action, and the row id. Mirrors the webhooks-admin inline-form idiom.
 *
 * @param int    $row_id The schedule row id.
 * @param string $action The sn_action value (schedule_run_now|schedule_repurge).
 * @param string $label  The visible (already-translated) button label.
 * @return void
 */
function sn_admin_render_schedule_op_button( $row_id, $action, $label ) {
	$row_id = (int) $row_id;
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-connections' ) ) . '" class="sn-schedule-op">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="' . esc_attr( $action ) . '">';
	echo '<input type="hidden" name="row_id" value="' . esc_attr( (string) $row_id ) . '">';
	echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
	echo '</form>';
}

/**
 * Build the "from -> until" window cell HTML, converting the stored UTC
 * boundaries to the site timezone. Inline markup (a small arrow + open-ended
 * labels) so the caller runs it through wp_kses_post.
 *
 * @param mixed $starts_at Stored UTC starts_at ('Y-m-d H:i:s'), or null/''.
 * @param mixed $ends_at   Stored UTC ends_at ('Y-m-d H:i:s'), or null/''.
 * @return string Escaped HTML for the cell.
 */
function sn_admin_schedule_window_html( $starts_at, $ends_at ) {
	$from = sn_admin_schedule_fmt_gmt( $starts_at );
	$to   = sn_admin_schedule_fmt_gmt( $ends_at );

	$from_label = '' !== $from ? esc_html( $from ) : '<small>' . esc_html__( 'always', 'signal-and-noise-tools' ) . '</small>';
	$to_label   = '' !== $to ? esc_html( $to ) : '<small>' . esc_html__( 'never', 'signal-and-noise-tools' ) . '</small>';

	return $from_label . ' &rarr; ' . $to_label;
}

/**
 * Format one stored UTC DATETIME boundary into a site-timezone display string.
 * Empty / unset returns '' so the caller can render an open-ended label.
 *
 * The stored value is UTC; get_date_from_gmt() shifts it into the site timezone.
 *
 * @param mixed $gmt The stored UTC 'Y-m-d H:i:s' value, or null/''.
 * @return string Site-timezone 'Y-m-d H:i' string, or '' when absent.
 */
function sn_admin_schedule_fmt_gmt( $gmt ) {
	$gmt = (string) $gmt;
	if ( '' === $gmt ) {
		return '';
	}
	return (string) get_date_from_gmt( $gmt, 'Y-m-d H:i' );
}

/**
 * Compute a human-readable "next transition" for a row: the relative time to the
 * soonest FUTURE boundary among (starts_at, ends_at). Returns '' when there is no
 * future boundary (both past, or both unset); the caller renders a placeholder.
 *
 * Boundaries are stored UTC; "now" is UTC unix. Comparing UTC-to-UTC keeps the
 * relative diff correct regardless of the server/site timezone.
 *
 * @param mixed $starts_at Stored UTC starts_at, or null/''.
 * @param mixed $ends_at   Stored UTC ends_at, or null/''.
 * @return string A label like "in 3 days", or '' when nothing is pending.
 */
function sn_admin_schedule_next_transition( $starts_at, $ends_at ) {
	$next = sn_admin_schedule_next_transition_ts( $starts_at, $ends_at );
	if ( 0 === $next ) {
		return ''; // no pending boundary; the caller renders a dash placeholder.
	}

	/* translators: %s is a human-readable relative time, e.g. "3 days". */
	return sprintf( __( 'in %s', 'signal-and-noise-tools' ), human_time_diff( (int) current_time( 'timestamp', true ), $next ) );
}

/**
 * The same computation as a TIMESTAMP rather than a label — the sort key behind
 * the ordered list (IA SCHED1). Split out because a display cap must slice an
 * ordered list, and ordering by a human string ("in 3 days" vs "in 30 minutes")
 * sorts alphabetically, which is not an order at all.
 *
 * @param mixed $starts_at Stored UTC starts_at, or null/''.
 * @param mixed $ends_at   Stored UTC ends_at, or null/''.
 * @return int Unix ts of the soonest FUTURE boundary, or 0 when none pends.
 */
function sn_admin_schedule_next_transition_ts( $starts_at, $ends_at ) {
	$now  = (int) current_time( 'timestamp', true );
	$next = 0;

	foreach ( array( $starts_at, $ends_at ) as $boundary ) {
		$boundary = (string) $boundary;
		if ( '' === $boundary ) {
			continue;
		}
		$ts = strtotime( $boundary . ' UTC' );
		if ( false === $ts || $ts <= $now ) {
			continue;
		}
		if ( 0 === $next || $ts < $next ) {
			$next = $ts;
		}
	}

	return $next;
}

/**
 * Merge the two sources into ONE list ordered by soonest pending transition.
 *
 * Sorting happens HERE, on a copy, rather than being assumed of the producers:
 * a display cap that slices an unsorted list silently drops whichever rows
 * happened to land late, and the ones worth keeping are exactly the ones about
 * to fire. Rows with no pending boundary (everything already past) sort LAST —
 * they are history, and they must not push a live row out of the cap.
 *
 * @param array $fragments sn_schedule_all() rows.
 * @param array $posts     sn_schedule_future_posts() rows.
 * @return array<int,array{kind:string,row:array,ts:int}> Ordered, soonest first.
 */
function sn_admin_schedule_ordered_rows( $fragments, $posts ) {
	$out = array();

	foreach ( (array) $fragments as $row ) {
		$out[] = array(
			'kind' => 'fragment',
			'row'  => (array) $row,
			'ts'   => sn_admin_schedule_next_transition_ts( $row['starts_at'] ?? null, $row['ends_at'] ?? null ),
		);
	}
	foreach ( (array) $posts as $post ) {
		$out[] = array(
			'kind' => 'post',
			'row'  => (array) $post,
			'ts'   => sn_admin_schedule_next_transition_ts( $post['scheduled_gmt'] ?? null, null ),
		);
	}

	usort(
		$out,
		static function ( $a, $b ) {
			// 0 means "nothing pending" — sort those to the bottom rather than
			// letting a zero read as the soonest possible moment.
			if ( 0 === $a['ts'] || 0 === $b['ts'] ) {
				return $a['ts'] === $b['ts'] ? 0 : ( 0 === $a['ts'] ? 1 : -1 );
			}
			return $a['ts'] <=> $b['ts'];
		}
	);

	return $out;
}

/**
 * Ops handler: "Run now" forces the boundary fire for one fragment row. The cap
 * + nonce are enforced by sn_handle_admin_post() before this runs.
 *
 * @param array $post The raw $_POST from the dispatcher.
 * @return string An sn_flash code.
 */
function sn_handle_schedule_run_now( array $post ) {
	$row_id = isset( $post['row_id'] ) ? (int) $post['row_id'] : 0;
	if ( $row_id <= 0 ) {
		return 'schedule_invalid';
	}
	if ( function_exists( 'sn_schedule_fire' ) ) {
		sn_schedule_fire( $row_id );
	}
	return 'schedule_fired';
}

/**
 * Ops handler: "Re-purge" re-dispatches the Cloudflare purge for one fragment
 * row's stored purge_urls. The cap + nonce are enforced by sn_handle_admin_post()
 * before this runs.
 *
 * @param array $post The raw $_POST from the dispatcher.
 * @return string An sn_flash code.
 */
function sn_handle_schedule_repurge( array $post ) {
	$row_id = isset( $post['row_id'] ) ? (int) $post['row_id'] : 0;
	if ( $row_id <= 0 ) {
		return 'schedule_invalid';
	}

	$row = function_exists( 'sn_schedule_get' ) ? sn_schedule_get( $row_id ) : null;
	if ( ! is_array( $row ) ) {
		return 'schedule_invalid';
	}

	$urls = (array) json_decode( (string) ( $row['purge_urls'] ?? '' ), true );
	if ( function_exists( 'sn_schedule_purge_urls' ) ) {
		sn_schedule_purge_urls( $urls );
	}
	return 'schedule_repurged';
}

/**
 * Ops handler (v8.0.0): "Run swap now" force-fires BOTH sides of a version
 * swap as one operation. The cap + nonce are enforced by sn_handle_admin_post()
 * before this runs; sn_schedule_swap_run() re-validates that the two ids are a
 * real pair (ids are attacker-shaped input) and refuses anything else without
 * firing.
 *
 * @param array $post The raw $_POST from the dispatcher (hide_id, show_id).
 * @return string An sn_flash code.
 */
function sn_handle_schedule_swap_run_now( array $post ) {
	$hide_id = isset( $post['hide_id'] ) ? (int) $post['hide_id'] : 0;
	$show_id = isset( $post['show_id'] ) ? (int) $post['show_id'] : 0;
	if ( $hide_id <= 0 || $show_id <= 0 || ! function_exists( 'sn_schedule_swap_run' ) ) {
		return 'schedule_invalid';
	}
	return sn_schedule_swap_run( $hide_id, $show_id ) ? 'schedule_swap_fired' : 'schedule_invalid';
}

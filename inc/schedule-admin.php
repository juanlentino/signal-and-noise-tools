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
 * @package SignalNoiseTools
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
function sn_admin_render_scheduled_content_section() {
	$fragments = function_exists( 'sn_schedule_all' ) ? sn_schedule_all() : array();
	$posts     = function_exists( 'sn_schedule_future_posts' ) ? sn_schedule_future_posts() : array();

	echo '<p class="sn-field-helper">' . esc_html__( 'Hand-authored content scheduled to reveal or hide on a date (signal-noise/scheduled blocks), plus WordPress posts and pages waiting to auto-publish. Times shown in the site timezone.', 'signal-noise-tools' ) . '</p>';

	$total = count( $fragments ) + count( $posts );
	if ( 0 === $total ) {
		echo '<table class="wp-list-table widefat striped"><tbody><tr><td>'
			. esc_html__( 'No scheduled content. Add a signal-noise/scheduled block to a page, or schedule a post for the future, and it will appear here.', 'signal-noise-tools' )
			. '</td></tr></tbody></table>';
		return;
	}

	echo '<table class="wp-list-table widefat striped">';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Target', 'signal-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Type', 'signal-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Action', 'signal-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Window', 'signal-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Status', 'signal-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Next transition', 'signal-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Actions', 'signal-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $fragments as $row ) {
		sn_admin_render_schedule_fragment_row( $row );
	}
	foreach ( $posts as $post ) {
		sn_admin_render_schedule_future_post_row( $post );
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
		echo '<small>' . esc_html__( '(unlinked fragment)', 'signal-noise-tools' ) . '</small>';
	}
	echo '</th>';

	// Type.
	echo '<td>' . esc_html__( 'Fragment', 'signal-noise-tools' ) . '</td>';

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
		sn_admin_render_schedule_op_button( $row_id, 'schedule_run_now', __( 'Run now', 'signal-noise-tools' ) );
		echo ' ';
		sn_admin_render_schedule_op_button( $row_id, 'schedule_repurge', __( 'Re-purge', 'signal-noise-tools' ) );
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
	echo '<td>' . esc_html__( 'Page', 'signal-noise-tools' ) . '</td>';

	// Action: native posts always publish at their scheduled instant.
	echo '<td>' . esc_html__( 'Publish', 'signal-noise-tools' ) . '</td>';

	// Window: a single instant (the publish time), site-tz.
	echo '<td>' . esc_html( sn_admin_schedule_fmt_gmt( $gmt ) ) . '</td>';

	// Status: core-managed.
	echo '<td>' . esc_html__( 'Scheduled', 'signal-noise-tools' ) . '</td>';

	// Next transition: relative to the publish instant.
	$next_post = sn_admin_schedule_next_transition( $gmt, null );
	echo '<td>' . ( '' !== $next_post ? esc_html( $next_post ) : '&mdash;' ) . '</td>';

	// Actions: none; native posts are core-managed.
	echo '<td><small>' . esc_html__( 'native', 'signal-noise-tools' ) . '</small></td>';

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

	$from_label = '' !== $from ? esc_html( $from ) : '<small>' . esc_html__( 'always', 'signal-noise-tools' ) . '</small>';
	$to_label   = '' !== $to ? esc_html( $to ) : '<small>' . esc_html__( 'never', 'signal-noise-tools' ) . '</small>';

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

	if ( 0 === $next ) {
		return ''; // no pending boundary; the caller renders a dash placeholder.
	}

	/* translators: %s is a human-readable relative time, e.g. "3 days". */
	return sprintf( __( 'in %s', 'signal-noise-tools' ), human_time_diff( $now, $next ) );
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

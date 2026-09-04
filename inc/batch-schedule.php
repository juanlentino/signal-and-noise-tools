<?php
/**
 * Signal & Noise Tools — batch schedule edit (wp-admin bulk action).
 *
 * SURFACE DECISION D2, taken 2026-09-01: this lands as a **wp-admin bulk
 * action**, never an `sn-apply` change type.
 *
 * WHY THAT MATTERS AND IS NOT A STYLE CHOICE. `sn-apply`'s posture on
 * `post_date` is purely protective: dates are captured before a write, passed
 * through, re-asserted after, and a violation triggers a restore whose effect is
 * VERIFIED by re-reading the row. Its invariant is flat — *post_date never
 * moves*. Adding a change type that moves dates would weaken that to *never
 * moves except for this type*, and the flat version is what currently
 * guarantees an MCP edit cannot publish a scheduled post early. A human-gated
 * admin path is a different risk object and leaves the MCP invariant whole.
 *
 * THE TRAP THIS IS DESIGNED AGAINST, verified in core:
 * `wp_insert_post()` silently coerces an explicitly-passed `future` status to
 * `publish` whenever `post_date_gmt` lands within a minute of now — core's own
 * status resolution, the same path `check_and_publish_future_post()` calls
 * "jumping the gun". So a batch that moves a scheduled post's date INTO that
 * window does not schedule it: it publishes it, immediately, as a side effect.
 * A batch spanning that boundary would publish posts the operator was merely
 * rescheduling.
 *
 * `inc/sn-apply/block-edit.php` already refuses that boundary for MCP writes
 * (409 `snt_sn_apply_schedule_overdue`). This file mirrors the SAME comparison
 * for the admin path rather than re-deriving it — one rule, two surfaces.
 *
 * @package SignalNoiseTools
 * @since 13.56.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Would writing this date on this post trip core's early-publish coercion?
 *
 * PURE — no post lookup, no clock of its own — so both sides of the boundary
 * are testable without fixtures. The comparison mirrors
 * `snt_sn_apply_block_edit`'s byte-for-byte: core compares the target GMT date
 * against now and coerces when the gap is under a minute.
 *
 * Only `future` is at risk. A `draft` or `publish` post carries no scheduled
 * transition for core to resolve, so moving its date moves only the date.
 *
 * @since 13.56.0
 * @param string $status       The post's status.
 * @param string $new_date_gmt The date being written, 'Y-m-d H:i:s' GMT.
 * @param int    $now_ts       Unix time; injectable so a test is not a race.
 * @return bool True when the write would early-publish rather than reschedule.
 */
function snt_batch_date_would_early_publish( $status, $new_date_gmt, $now_ts ) {
	if ( 'future' !== (string) $status ) {
		return false;
	}
	$target = strtotime( (string) $new_date_gmt );
	if ( false === $target ) {
		// An unparseable date is not proven safe. Refusing an unreadable input
		// is cheap; guessing at it is how a post publishes early.
		return true;
	}
	$minute = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
	return ( $target - (int) $now_ts ) < $minute;
}

/**
 * Plan a batch, returning what WOULD be written and what is refused.
 *
 * PURE, and it returns both halves on purpose. A batch that silently skips its
 * unsafe rows reports a smaller, cleaner-looking success — the same shape as a
 * join that drops rows. The caller must be able to say "12 rescheduled, 3
 * refused, and here is why" rather than "12 rescheduled".
 *
 * @since 13.56.0
 * @param array  $posts        [ post_id => [ 'status' => string, 'date_gmt' => string ] ].
 * @param string $new_date_gmt Target date for every post in the batch.
 * @param int    $now_ts       Unix time.
 * @return array{apply:int[],refused:array<int,string>}
 */
function snt_batch_schedule_plan( $posts, $new_date_gmt, $now_ts ) {
	$apply   = array();
	$refused = array();
	foreach ( (array) $posts as $id => $meta ) {
		$id     = (int) $id;
		$status = (string) ( $meta['status'] ?? '' );
		if ( snt_batch_date_would_early_publish( $status, $new_date_gmt, $now_ts ) ) {
			$refused[ $id ] = 'would_early_publish';
			continue;
		}
		$apply[] = $id;
	}
	return array( 'apply' => $apply, 'refused' => $refused );
}

/* ════════════════════════════════════════════════════════════════════════
 * THE SURFACE — a wp-admin bulk action on the posts list (v13.56.0).
 *
 * Everything above is pure and testable; everything below is the thin WordPress
 * shell around it. The shell decides NOTHING: it collects input, calls the
 * planner, writes what the planner allowed, and reports both halves.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Add the action to the posts-list bulk dropdown.
 *
 * Posts only. Pages and other types carry no scheduled-publication workflow on
 * this site, and a bulk date move is only meaningful where scheduling is.
 *
 * @since 13.56.0
 * @param array $actions
 * @return array
 */
function snt_batch_schedule_register_action( $actions ) {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return $actions;
	}
	$actions['snt_batch_reschedule'] = __( 'Reschedule (Signal & Noise)…', 'signal-and-noise-tools' );
	return $actions;
}
// Registrations are function_exists-guarded so the planner above stays loadable
// in a standalone harness — the same shape inc/ssrf-guard.php uses.
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'bulk_actions-edit-post', 'snt_batch_schedule_register_action' );
}

/**
 * Handle the bulk action.
 *
 * WordPress hands this a verified nonce and the selected ids; the capability is
 * re-checked here anyway, because the dropdown filter above only controls what
 * is OFFERED, never what is submitted.
 *
 * The date arrives from a field the screen renders (below). A batch with no
 * date is a no-op rather than an error: the operator picked the action and then
 * did not fill it in.
 *
 * @since 13.56.0
 * @param string $redirect
 * @param string $action
 * @param int[]  $post_ids
 * @return string
 */
function snt_batch_schedule_handle( $redirect, $action, $post_ids ) {
	if ( 'snt_batch_reschedule' !== $action ) {
		return $redirect;
	}
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress verifies the bulk-action nonce before dispatching this filter.
	$raw = isset( $_REQUEST['snt_batch_date'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['snt_batch_date'] ) ) : '';
	if ( '' === $raw ) {
		return add_query_arg( 'snt_batch_nodate', 1, $redirect );
	}

	// The field is a datetime-local in SITE time; the guard and core both work
	// in GMT. Converting here, once, keeps the planner clock-agnostic.
	$gmt = get_gmt_from_date( str_replace( 'T', ' ', $raw ) . ':00' );
	if ( ! is_string( $gmt ) || '' === $gmt ) {
		return add_query_arg( 'snt_batch_baddate', 1, $redirect );
	}

	$posts = array();
	foreach ( (array) $post_ids as $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			continue;
		}
		$posts[ (int) $id ] = array( 'status' => (string) $post->post_status );
	}

	$plan = snt_batch_schedule_plan( $posts, $gmt, time() );

	$moved = 0;
	foreach ( $plan['apply'] as $id ) {
		// post_date is SITE time, post_date_gmt is GMT — passing both keeps
		// core from re-deriving one from the other and drifting by the offset.
		$res = wp_update_post(
			array(
				'ID'            => (int) $id,
				'post_date'     => get_date_from_gmt( $gmt ),
				'post_date_gmt' => $gmt,
			),
			true
		);
		if ( ! is_wp_error( $res ) ) {
			$moved++;
		}
	}

	return add_query_arg(
		array(
			'snt_batch_moved'   => $moved,
			'snt_batch_refused' => count( $plan['refused'] ),
		),
		$redirect
	);
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'handle_bulk_actions-edit-post', 'snt_batch_schedule_handle', 10, 3 );
}

/**
 * Render the date field into the posts-list toolbar.
 *
 * `restrict_manage_posts` is the only hook that puts a control beside the bulk
 * dropdown without a JS injection, and its value rides in $_REQUEST with the
 * bulk submission.
 *
 * @since 13.56.0
 * @param string $post_type
 * @return void
 */
function snt_batch_schedule_render_field( $post_type ) {
	if ( 'post' !== $post_type || ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	echo '<label class="screen-reader-text" for="snt_batch_date">'
		. esc_html__( 'Reschedule selected posts to', 'signal-and-noise-tools' ) . '</label>';
	echo '<input type="datetime-local" id="snt_batch_date" name="snt_batch_date" value="" />';
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'restrict_manage_posts', 'snt_batch_schedule_render_field' );
}

/**
 * Report BOTH halves after the redirect.
 *
 * A refusal count of zero is omitted, but a non-zero one is never silent — the
 * operator selected those posts and must be told they did not move, and why.
 *
 * @since 13.56.0
 * @return void
 */
function snt_batch_schedule_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only rendering of counts this plugin put in the redirect it issued.
	$req = $_REQUEST;
	if ( isset( $req['snt_batch_nodate'] ) ) {
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'No date was entered, so nothing was rescheduled.', 'signal-and-noise-tools' )
			. '</p></div>';
		return;
	}
	if ( isset( $req['snt_batch_baddate'] ) ) {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'That date could not be read, so nothing was rescheduled.', 'signal-and-noise-tools' )
			. '</p></div>';
		return;
	}
	if ( ! isset( $req['snt_batch_moved'] ) ) {
		return;
	}
	$moved   = (int) $req['snt_batch_moved'];
	$refused = isset( $req['snt_batch_refused'] ) ? (int) $req['snt_batch_refused'] : 0;

	$msg = sprintf(
		/* translators: %d: number of posts rescheduled. */
		_n( '%d post rescheduled.', '%d posts rescheduled.', $moved, 'signal-and-noise-tools' ),
		$moved
	);
	if ( $refused > 0 ) {
		$msg .= ' ' . sprintf(
			/* translators: %d: number of scheduled posts left untouched. */
			_n(
				'%d scheduled post was left untouched: the new date is within a minute of now, and WordPress would have published it immediately instead of rescheduling it.',
				'%d scheduled posts were left untouched: the new date is within a minute of now, and WordPress would have published them immediately instead of rescheduling them.',
				$refused,
				'signal-and-noise-tools'
			),
			$refused
		);
	}
	printf(
		'<div class="notice notice-%s"><p>%s</p></div>',
		esc_attr( $refused > 0 ? 'warning' : 'success' ),
		esc_html( $msg )
	);
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_notices', 'snt_batch_schedule_notice' );
}

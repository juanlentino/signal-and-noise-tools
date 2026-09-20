<?php
/**
 * Signal & Noise Tools: the batch reschedule's native twin (REST).
 *
 * OpenStation's Posts window has no bulk dropdown and no toolbar field, so
 * the classic action in inc/batch-schedule.php was unreachable from it. This
 * route is the same write behind the same capability: the window's bulk
 * action (assets/os-posts-reschedule.js) asks for the date in a modal and
 * POSTs the ids and the datetime-local value here. The shell decides nothing:
 * it validates the SHAPE of the date (#1179), hands the batch to the one
 * shared write, and answers with both halves and the classic sentence.
 *
 * Its own file so the planner file stays loadable in the standalone harness
 * without WP_Error or the REST classes.
 *
 * @package SignalNoiseTools
 * @since 17.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The classic action's gate, not the preferences route's manage_options.
 *
 * @return bool
 */
function snt_batch_schedule_rest_permission() {
	return current_user_can( 'edit_others_posts' );
}

/**
 * POST /openstation/reschedule: { ids: int[], date: 'YYYY-MM-DDTHH:MM' }.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response|WP_Error
 */
function snt_batch_schedule_rest_handle( $req ) {
	$site = snt_batch_schedule_parse_date( (string) $req->get_param( 'date' ) );
	if ( '' === $site ) {
		return new WP_Error(
			'snt_batch_baddate',
			__( 'That date could not be read, so nothing was rescheduled.', 'signal-and-noise-tools' ),
			array( 'status' => 400 )
		);
	}
	$gmt = get_gmt_from_date( $site );
	$r   = snt_batch_schedule_apply( array_map( 'intval', (array) $req->get_param( 'ids' ) ), $gmt, time() );
	$r['message'] = snt_batch_schedule_message( $r['moved'], $r['refused'], $r['unpublish'], $r['skipped'] );
	return rest_ensure_response( $r );
}

/**
 * @return void
 */
function snt_batch_schedule_register_rest() {
	register_rest_route(
		'signal-noise/v1',
		'/openstation/reschedule',
		array(
			'methods'             => 'POST',
			'callback'            => 'snt_batch_schedule_rest_handle',
			'permission_callback' => 'snt_batch_schedule_rest_permission',
			'args'                => array(
				'ids'  => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
					'required' => true,
					'minItems' => 1,
				),
				'date' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'snt_batch_schedule_register_rest' );

<?php
/**
 * Signal & Noise Tools: the content-queue ability's registration and schema
 * (split from inc/abilities-content-queue.php, 2026-10-12).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Register the ability.
 *
 * @since 15.8.0
 */
function snt_abilities_content_queue_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$row_schema = array(
		'type'       => 'object',
		'properties' => array(
			'id'       => array( 'type' => 'integer' ),
			'title'    => array( 'type' => 'string' ),
			'date'     => array( 'type' => 'string' ),
			'day'      => array( 'type' => 'string' ),
			'time'     => array( 'type' => 'string' ),
			'when'     => array( 'type' => 'string' ),
			'edit_url' => array( 'type' => 'string' ),
		),
	);
	wp_register_ability( 'signal-noise/content-queue', array(
		'label'               => 'Content queue',
		'description'         => 'The scheduled queue at a glance: the next four scheduled posts in date order with a site-timezone label ("Tomorrow 9:38 AM", "Sat 11:38 AM", "Dec 27"), the scheduled count and the date the queue runs to, the draft count (null when unreadable), the first hole in the schedule by the publishing-cadence flag\'s own threshold (null when none or when the history is too thin to say), the last three published posts with a relative age, and for the calendar every scheduled post with its site-timezone day and time, today, the Monday of this week, the gaps marked planned, and the newest three drafts this user can edit, by title, and how many more there are (null when the user cannot see every draft). Read-only; the reading behind the SN Queue desktop widget.',
		'category'            => 'content',
		'permission_callback' => 'snt_ability_perm_edit_posts',
		'execute_callback'    => 'snt_ability_content_queue',
		'input_schema'        => array(
			// The [object,null] union: readonly ⇒ GET run-path ⇒ an omitted
			// ?input= delivers NULL, and a plain 'object' rejects every such call.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'next'            => array( 'type' => 'array', 'items' => $row_schema ),
				'scheduled_total' => array( 'type' => 'integer' ),
				'runs_to'         => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'date'  => array( 'type' => 'string' ),
						'label' => array( 'type' => 'string' ),
					),
				),
				'published'       => array( 'type' => 'array', 'items' => $row_schema ),
				'drafts'          => array( 'type' => array( 'integer', 'null' ) ),
				'gap'             => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'from'       => array( 'type' => 'string' ),
						'to'         => array( 'type' => 'string' ),
						'from_label' => array( 'type' => 'string' ),
						'to_label'   => array( 'type' => 'string' ),
					),
				),
				'scheduled'       => array( 'type' => 'array', 'items' => $row_schema ),
				'today'           => array( 'type' => 'string' ),
				'week_start'      => array( 'type' => 'string' ),
				'planned'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'from' => array( 'type' => 'string' ),
							'to'   => array( 'type' => 'string' ),
						),
					),
				),
				'draft_list'      => array( 'type' => 'array', 'items' => $row_schema ),
				'draft_more'      => array( 'type' => array( 'integer', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'        => true,
				'destructive'     => false,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
}
add_action( 'wp_abilities_api_init', 'snt_abilities_content_queue_register' );

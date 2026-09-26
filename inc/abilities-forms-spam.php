<?php
/**
 * Abilities for the forms spam sweep: a read (what the rules would catch,
 * and each form's own defences) and a write (mark those, switch the free
 * defences on). The write re-scans and refuses any id the rules do not flag.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_abilities_api_init',
	static function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'signal-noise/forms-spam-scan',
			array(
				'label'               => __( 'Forms spam scan', 'signal-and-noise-tools' ),
				'description'         => __( 'AllTerrain Forms entries in the inbox (read or unread, up to 500) that the content rules would mark spam, each with its reason (snt:<signals>), and every form\'s own spam settings (honeypot, time trap, hourly rate limit, blocklist). Read-only; available=false when Forms is not active.', 'signal-and-noise-tools' ),
				'category'            => 'diagnostics',
				'permission_callback' => 'snt_ability_perm_manage_options',
				'execute_callback'    => 'snt_fs_scan',
				'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
				),
			)
		);
		wp_register_ability(
			'signal-noise/forms-spam-apply',
			array(
				'label'               => __( 'Forms spam apply', 'signal-and-noise-tools' ),
				'description'         => __( 'Mark the given Forms entries spam, through Forms\' own status setter so "Not spam" undoes it; ids the rules do not flag right now are refused. enable_defaults switches on the honeypot, a 3 s time trap and an hourly rate limit on any form that has them off. Nothing is deleted.', 'signal-and-noise-tools' ),
				'category'            => 'content',
				'permission_callback' => 'snt_ability_perm_manage_options',
				'execute_callback'    => 'snt_fs_apply',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'entry_ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'maxItems' => 500 ),
						'enable_defaults' => array( 'type' => 'boolean', 'default' => false ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
				),
			)
		);
	}
);

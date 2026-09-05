<?php
/**
 * Signal & Noise Tools — ability: signal-noise/note-dossier.
 *
 * Everything the estate knows about ONE note, as blocks, for the Signal &
 * Noise OpenStation app's dossier. Read-only, so the run route is GET with
 * bracket-encoded input (input[post_id]=N&input[days]=30); scalars arrive as
 * strings and are cast here. Gated on edit_post for that note: the ability's
 * own permission callback is the only gate on the native run route.
 *
 * This file is a DOOR: it registers with the builders absent (every
 * standalone suite loads it alone) and answers a 500 that says so. It is
 * not on the MCP read door and needs no listing: REST-reachable behind its
 * own permission is what the app needs. The ledger is read over HTTP on each
 * call, which is what `open_world_hint` says.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array|null $input { post_id, days? } -- strings on GET.
 * @return array<string,mixed>|WP_Error
 */
function snt_ability_note_dossier( $input ) {
	if ( ! function_exists( 'sn_note_dossier_compose' ) || ! function_exists( 'sn_note_dossier_days' ) ) {
		return new WP_Error( 'snt_note_dossier_unavailable', __( 'The dossier builders are not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
	$days    = sn_note_dossier_days( is_array( $input ) ? ( $input['days'] ?? 30 ) : 30 );
	$out     = $post_id > 0 ? sn_note_dossier_compose( $post_id, $days ) : null;
	if ( ! is_array( $out ) ) {
		return new WP_Error( 'snt_note_dossier_not_found', __( 'No such note.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}
	return $out;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/note-dossier', array(
		'label'               => 'Note dossier',
		'description'         => 'Everything the estate knows about ONE note, as blocks the Signal & Noise desktop app paints beside its list: trust (the anchor proof read from the public ledger record of the newest confirmed version, the signer against the keys the ledger publishes, the citations received), numbers (views and visits over the requested window from the durable analytics table; impressions and clicks from the Search Console sync in ITS window; machine reads, which the sensor does not count per document, said so), operating state (the last edge-freshness verdict for the URL, coverage, sitemap membership, scheduled fragments) and editorial (tags, reading time, word count, the excerpt agents receive, related notes from the kernel). Each block names its source and window; a source that could not be read is a warning block naming it, never a zero; an unreachable ledger is a gap, never "not anchored". An unpublished note gets trust and editorial only. Read-only: GET with input[post_id] and input[days] in {7, 30, 90}.',
		'category'            => 'content',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_note_dossier',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The note (post_type post).' ),
				'days'    => array( 'type' => 'integer', 'enum' => array( 7, 30, 90 ), 'default' => 30, 'description' => 'The window for the analytics tiles. Search Console keeps its own window.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'post_id'    => array( 'type' => 'integer' ),
				'days'       => array( 'type' => 'integer' ),
				'is_public'  => array( 'type' => 'boolean', 'description' => 'Published and not password-protected; numbers and operating state exist only then.' ),
				'blocks'     => array( 'type' => 'array', 'description' => 'Blocks in the order trust, numbers, state, editorial; each { group, kind: stats|status|text|table, heading, ... , source?, window?, door? }.' ),
				'fetched_at' => array( 'type' => 'integer', 'description' => 'Unix time the dossier was composed.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				// readonly => the run controller requires GET.
				'readonly'        => true,
				'idempotent'      => true,
				// It reads the public ledger over HTTP on every call.
				'open_world_hint' => true,
			),
		),
	) );
} );

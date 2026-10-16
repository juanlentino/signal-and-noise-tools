<?php
/**
 * Signal & Noise Tools, Abilities API: signal-noise/search-unanswered.
 *
 * The unanswered-searches list (inc/search-console-unanswered.php) on the
 * read door. A sibling of search-performance rather than a key on it:
 * search-performance has a byte-identical remote twin whose shape is pinned
 * by the remote contract (inc/mcp/mcp-remote-contract.php), and query text
 * on the remote door is the owner's call, not this one. Local only.
 *
 * Read-only: one get_option() through snt_gsc_data(), arithmetic, no model.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Execute callback: signal-noise/search-unanswered. */
function snt_ability_search_unanswered( $input ) {
	unset( $input );
	if ( ! function_exists( 'snt_gsc_unanswered_live' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Search Console store not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return array_merge( array( 'ok' => true ), snt_gsc_unanswered_live() );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/search-unanswered', array(
		'label'               => 'Search Console: searches the corpus does not answer',
		'description'         => 'Queries with 5+ impressions and 0 clicks in the synced Search Console window (28 days ending ~3 days back), summed across the pages Google showed: most shown first, capped at 25 with `more` counting the rest ("the list is capped, not complete"). Each row: query, impressions, impression-weighted average position, the page Google showed most, and no_note:true when that page is the home page or an index. state: never_synced | awaiting_sync (synced before the query x page rows existed) | fetch_failed | measured; measured with no rows is the real zero. fetch_capped:true means the sync read only its top 500 query x page rows. Arithmetic over the stored rows, never a model. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_search_unanswered',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'           => array( 'type' => 'boolean' ),
				'state'        => array( 'type' => 'string', 'enum' => array( 'never_synced', 'awaiting_sync', 'fetch_failed', 'measured' ) ),
				'window'       => array( 'type' => array( 'object', 'null' ) ),
				'floor'        => array( 'type' => 'integer' ),
				'cap'          => array( 'type' => 'integer' ),
				'rows'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'query'       => array( 'type' => 'string' ),
							'impressions' => array( 'type' => 'integer' ),
							'position'    => array( 'type' => 'number' ),
							'page'        => array( 'type' => 'string' ),
							'no_note'     => array( 'type' => 'boolean' ),
						),
					),
				),
				'total'        => array( 'type' => 'integer' ),
				'more'         => array( 'type' => 'integer', 'description' => 'Rows past the cap: the list is capped, not complete.' ),
				'fetch_capped' => array( 'type' => 'boolean' ),
				'note'         => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'        => true,
				'destructive'     => false,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
} );

<?php
/**
 * Signal & Noise Tools — `signal-noise/bing-search-performance`.
 *
 * The Bing twin of search-performance: what the site earns in Bing search
 * over the last synced window, read from the option the daily sync stores.
 * `source` names the engine so a consumer holding both readings never
 * confuses them. Read-only; synced:false with null totals means nothing has
 * synced, which is not a zero.
 *
 * @since 16.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The output, from a stored record (or null). PURE. */
function sn_bing_status_shape( $data, $ready ) {
	if ( ! is_array( $data ) || empty( $data['synced_at'] ) ) {
		return array(
			'ok'         => true,
			'source'     => 'bing',
			'ready'      => (bool) $ready,
			'synced'     => false,
			'site'       => '',
			'window'     => null,
			'synced_at'  => 0,
			'totals'     => null,
			'queries'    => array(),
			'last_error' => (string) ( $data['last_error'] ?? '' ),
			'note'       => $ready ? 'A key is stored but nothing has synced yet; the daily sync runs on its own.' : 'No Bing Webmaster key in the keyring.',
		);
	}
	return array(
		'ok'         => true,
		'source'     => 'bing',
		'ready'      => (bool) $ready,
		'synced'     => true,
		'site'       => (string) ( $data['site'] ?? '' ),
		'window'     => $data['window'] ?? null,
		'synced_at'  => (int) $data['synced_at'],
		'totals'     => $data['totals'] ?? null,
		'queries'    => array_values( (array) ( $data['queries'] ?? array() ) ),
		'last_error' => (string) ( $data['last_error'] ?? '' ),
		'note'       => 'Bing updates traffic daily and query positions weekly; the window ends on the newest day Bing reports.',
	);
}

function snt_ability_bing_search_performance( $input = array() ) {
	return sn_bing_status_shape( function_exists( 'sn_bing_data' ) ? sn_bing_data() : null, function_exists( 'sn_bing_is_ready' ) && sn_bing_is_ready() );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/bing-search-performance', array(
		'label'               => 'Bing Webmaster: the stored window',
		'description'         => 'What the site earns in Bing search over the last synced window (28 days ending on the newest day Bing reports): totals (clicks, impressions, days counted) and the top queries (clicks, impressions, CTR, average impression position). source is always "bing" so a consumer holding the Search Console reading beside it never confuses the two. synced:false with null totals means nothing has synced: that is not a zero. Read-only over data the daily sync already stores.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_bing_search_performance',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'source'     => array( 'type' => 'string' ),
				'ready'      => array( 'type' => 'boolean' ),
				'synced'     => array( 'type' => 'boolean' ),
				'site'       => array( 'type' => 'string' ),
				'window'     => array( 'type' => array( 'object', 'null' ) ),
				'synced_at'  => array( 'type' => 'integer' ),
				'totals'     => array( 'type' => array( 'object', 'null' ) ),
				'queries'    => array( 'type' => 'array' ),
				'last_error' => array( 'type' => 'string' ),
				'note'       => array( 'type' => 'string' ),
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

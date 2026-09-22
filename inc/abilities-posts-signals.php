<?php
/**
 * Signal & Noise Tools — Abilities API: posts-signals (the sn-status source
 * for the `posts_signals` section). The Posts tab's rows, as data: the SAME
 * sn_analytics_posts_signals() the tab paints, so an agent reads the three
 * flags rather than re-deriving them from four other sections. Read-only
 * over stored syncs; never inspects, never syncs, never probes.
 *
 * @package SignalNoiseTools
 * @since 14.6.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Execute callback: signal-noise/posts-signals. */
function snt_ability_posts_signals( $input ) {
	unset( $input );
	if ( ! function_exists( 'sn_analytics_posts_signals' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Posts signals module not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$s    = sn_analytics_posts_signals();
	$rows = array();
	foreach ( (array) $s['rows'] as $r ) {
		$rows[] = array(
			'id'              => (int) $r['id'],
			'title'           => (string) $r['title'],
			'permalink'       => (string) $r['permalink'],
			'published_at'    => $r['publish_ts'] > 0 ? gmdate( 'c', (int) $r['publish_ts'] ) : null,
			'modified_at'     => $r['modified_ts'] > 0 ? gmdate( 'c', (int) $r['modified_ts'] ) : null,
			'body_changed_at' => $r['body_changed_ts'] > 0 ? gmdate( 'c', (int) $r['body_changed_ts'] ) : null,
			'age_days'        => $r['age'],
			'words'           => $r['words'],
			'index'           => $r['index'],
			'coverage_state'  => (string) $r['coverage_state'],
			'last_crawl'      => array( 'value' => null === $r['last_crawl']['value'] ? null : gmdate( 'c', (int) $r['last_crawl']['value'] ), 'why' => $r['last_crawl']['why'] ),
			'impressions'     => $r['impressions'],
			'clicks'          => $r['clicks'],
			'position'        => $r['position'],
			'inbound'         => $r['inbound'],
			'anchor'          => $r['anchor'],
			'related'         => $r['related'],
			'views'           => $r['views'],
			'flags'           => $r['flags'],
		);
	}
	return array(
		'ok'     => true,
		'rows'   => $rows,
		'counts' => $s['counts'],
		'strip'  => $s['strip'],
		'note'   => 'Every field is {value, why}: value null means the signal is absent for that note and why says why (not shown by Google in this window, not inspected in the last run, never crawled, unsigned, not in the kernel yet). Never read a null as zero. flags are the three binaries the Posts tab derives once: not_indexed (coverage indexed === false), stale_crawl (last crawl before the last BODY change: the newest signed commit, or post_modified when unsigned), orphaned (inbound links <= 1). A gap never raises a flag. strip.machine_reads is SITE-WIDE: the crawler sensor keeps no document paths, so it cannot be split per note; do not estimate one.',
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$field = array( 'type' => 'object', 'properties' => array( 'value' => array(), 'why' => array( 'type' => 'string' ) ) );
	wp_register_ability( 'signal-noise/posts-signals', array(
		'label'               => 'Per-note signals (the Posts tab as data)',
		'description'         => 'One row per published note over the dense signals the site already syncs, exactly as the S&N Analytics Posts tab paints them: Search Console impressions/clicks/position for the stored window, URL Inspection coverage with coverage_state verbatim and the last crawl, inbound internal links from the live link graph, the anchored provenance version and block (and whether the followed key signed it), the ML kernel\'s related-note count and top score, and lifetime human views as a RAW number with no verdict. Every field is {value, why}: a null value is an ABSENT signal with its reason, never a zero. flags carries the three binaries derived in one place (not_indexed, stale_crawl, orphaned); read them rather than re-deriving from search-coverage + inbound-pass. counts are counts of binaries over the rows. strip carries the site-wide context every row is read against, including machine reads, which are site-wide ONLY by the sensor\'s privacy contract. Read-only over stored syncs; never inspects, syncs or probes. Sample sizes: there is no decay shape here on purpose; the site\'s highest lifetime view count is in the teens.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_posts_signals',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'     => array( 'type' => 'boolean' ),
				'rows'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'              => array( 'type' => 'integer' ),
							'title'           => array( 'type' => 'string' ),
							'permalink'       => array( 'type' => 'string' ),
							'published_at'    => array( 'type' => array( 'string', 'null' ) ),
							'modified_at'     => array( 'type' => array( 'string', 'null' ) ),
							'body_changed_at' => array( 'type' => array( 'string', 'null' ) ),
							'age_days'        => $field,
							'words'           => $field,
							'index'           => $field,
							'coverage_state'  => array( 'type' => 'string' ),
							'last_crawl'      => $field,
							'impressions'     => $field,
							'clicks'          => $field,
							'position'        => $field,
							'inbound'         => $field,
							'anchor'          => $field,
							'related'         => $field,
							'views'           => $field,
							'flags'           => array( 'type' => 'object', 'properties' => array( 'not_indexed' => array( 'type' => 'boolean' ), 'stale_crawl' => array( 'type' => 'boolean' ), 'orphaned' => array( 'type' => 'boolean' ) ) ),
						),
					),
				),
				'counts' => array( 'type' => 'object' ),
				'strip'  => array( 'type' => 'object' ),
				'note'   => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );

<?php
/**
 * Signal & Noise Tools — Abilities API: Search Console on the read door
 * (measurement weave, Phase 1 — docs/proposals/measurement-weave-2026-08-31.md).
 *
 * Before this file, Search Console appeared in ZERO abilities-*.php and ZERO
 * inc/mcp/* files: six derives were built and readable only by the admin
 * screen. These three abilities wrap them for an agent, over data the daily
 * sync already stores — no new fetch, no new quota. Each is a SOURCE for an
 * sn-status section (search_performance / search_drift / search_crossexam),
 * per the default-to-consolidated-reads policy; the narrow slugs exist so the
 * section dispatcher has something to call, exactly as cron_health does.
 *
 * Three things the payloads say out loud, because a reader cannot infer them:
 *
 *  - THE FLOOR. The sync requests the page dimension with a 250-row limit, so
 *    any total summed from the stored rows undercounts on a large site, in a
 *    knowable direction. `totals.capped` carries snt_gsc_window_totals()'s
 *    flag onto the wire; a section returning a total without it would let an
 *    agent read a truncated sum as a complete one.
 *  - NULL IS NOT ZERO. A property that never synced and a window Google shows
 *    no clicks for are different facts. `synced:false` with null totals is the
 *    first; a zero row is the second. Drift likewise: `state:"accruing"` (the
 *    history cannot answer yet) is not `state:"measured"` with nothing drifting.
 *  - THE GRAIN. The cross-exam is a WINDOW agreement between two instruments
 *    whose windows do not line up (Google's ends ~3 days back). It is never a
 *    per-page join — the ledger has no path dimension — and the payload says
 *    so in `grain` and `caveat`, not only in a code comment.
 *
 * @package SignalNoiseTools
 * @since 13.57.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Near-ranking band: page 1 bottom through page 2. A filter over stored rows. */
const SNT_SEARCH_OPPORTUNITY_POS_MIN = 8.0;
const SNT_SEARCH_OPPORTUNITY_POS_MAX = 20.0;

/**
 * The search_performance payload, PURE over the stored window.
 *
 * @param array|null $data   snt_gsc_data() — null when nothing has synced.
 * @param array|null $totals snt_gsc_window_totals() — null when nothing has synced.
 * @return array
 */
function snt_search_performance_impl( $data, $totals ) {
	if ( ! is_array( $data ) ) {
		return array(
			'ok'            => true,
			'synced'        => false,
			'property'      => '',
			'window'        => null,
			'synced_at'     => 0,
			'pages'         => array(),
			'queries'       => array(),
			'opportunities' => array(),
			'totals'        => null,
			'note'          => 'Search Console has never synced on this site. Nothing here is a zero.',
		);
	}
	$pages = array();
	foreach ( (array) ( $data['pages'] ?? array() ) as $path => $m ) {
		if ( ! is_array( $m ) ) {
			continue;
		}
		$pages[] = array(
			'path'        => (string) $path,
			'clicks'      => (int) ( $m['clicks'] ?? 0 ),
			'impressions' => (int) ( $m['impressions'] ?? 0 ),
			'ctr'         => round( (float) ( $m['ctr'] ?? 0 ), 4 ),
			'position'    => round( (float) ( $m['position'] ?? 0 ), 1 ),
		);
	}
	usort( $pages, static function ( $a, $b ) {
		$by = $b['impressions'] <=> $a['impressions'];
		return 0 !== $by ? $by : strcmp( $a['path'], $b['path'] );
	} );

	$opportunities = array_values( array_filter( $pages, static function ( $p ) {
		return $p['position'] >= SNT_SEARCH_OPPORTUNITY_POS_MIN
			&& $p['position'] <= SNT_SEARCH_OPPORTUNITY_POS_MAX
			&& $p['impressions'] >= SNT_GSC_DRIFT_MIN_IMPRESSIONS;
	} ) );

	$capped = is_array( $totals ) ? ! empty( $totals['capped'] ) : false;
	return array(
		'ok'            => true,
		'synced'        => true,
		'property'      => (string) ( $data['property'] ?? '' ),
		'window'        => is_array( $data['window'] ?? null ) ? $data['window'] : null,
		'synced_at'     => (int) ( $data['synced_at'] ?? 0 ),
		'pages'         => $pages,
		'queries'       => array_values( (array) ( $data['queries'] ?? array() ) ),
		'opportunities' => $opportunities,
		'totals'        => is_array( $totals ) ? array(
			'clicks'      => (int) ( $totals['clicks'] ?? 0 ),
			'impressions' => (int) ( $totals['impressions'] ?? 0 ),
			'days'        => (int) ( $totals['days'] ?? 0 ),
			'capped'      => $capped,
		) : null,
		'note'          => $capped
			? sprintf( 'Page rows hit the %d-row sync limit: totals are a FLOOR over the pages Google ranked highest, not a site total.', SNT_GSC_PAGE_ROW_LIMIT )
			: 'Totals cover every page row Google returned for the window.',
	);
}

/**
 * The search_drift payload, PURE over snt_gsc_position_drift()'s answer.
 *
 * @param array|null $drift null = history cannot answer yet; [] = measured, nothing drifts.
 * @return array
 */
function snt_search_drift_impl( $drift ) {
	if ( null === $drift ) {
		return array(
			'ok'       => true,
			'state'    => 'accruing',
			'drifting' => array(),
			'note'     => sprintf( 'Fewer than two position snapshots at least %d days apart. "Accruing" is not "no drift".', SNT_GSC_DRIFT_MIN_SPAN_DAYS ),
		);
	}
	$rows = array();
	foreach ( (array) $drift as $path => $d ) {
		$rows[] = array(
			'path'        => (string) $path,
			'from'        => (float) ( $d['from'] ?? 0 ),
			'to'          => (float) ( $d['to'] ?? 0 ),
			'drift'       => (float) ( $d['drift'] ?? 0 ),
			'impressions' => (int) ( $d['impressions'] ?? 0 ),
		);
	}
	return array(
		'ok'       => true,
		'state'    => 'measured',
		'drifting' => $rows, // worst first — the derive's own order.
		'note'     => sprintf( 'Pages whose position worsened by %.1f+ with %d+ impressions. Positive drift is WORSE.', SNT_GSC_DRIFT_FLOOR, SNT_GSC_DRIFT_MIN_IMPRESSIONS ),
	);
}

/**
 * The search_crossexam payload: the derive's verdict, its sentence, and the
 * grain caveat IN THE PAYLOAD.
 *
 * @param array  $x       snt_gsc_crossexam().
 * @param string $reading snt_gsc_crossexam_reading( $x ).
 * @return array
 */
function snt_search_crossexam_impl( $x, $reading ) {
	$x = is_array( $x ) ? $x : array( 'ok' => false, 'reason' => 'no_result' );
	return array_merge( $x, array(
		'reading' => (string) $reading,
		'grain'   => 'window',
		'caveat'  => 'Agreement in magnitude between two instruments over windows that do not line up (Google ends ~3 days back). NOT a per-page join: the crawler ledger has no path dimension.',
	) );
}

/** Execute callback: signal-noise/search-performance. */
function snt_ability_search_performance( $input ) {
	unset( $input );
	if ( ! function_exists( 'snt_gsc_data' ) || ! function_exists( 'snt_gsc_window_totals' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Search Console store not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_search_performance_impl( snt_gsc_data(), snt_gsc_window_totals() );
}

/** Execute callback: signal-noise/search-drift. */
function snt_ability_search_drift( $input ) {
	unset( $input );
	if ( ! function_exists( 'snt_gsc_position_drift' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Search Console derive not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_search_drift_impl( snt_gsc_position_drift() );
}

/** Execute callback: signal-noise/search-crossexam. */
function snt_ability_search_crossexam( $input ) {
	unset( $input );
	if ( ! function_exists( 'snt_gsc_crossexam' ) || ! function_exists( 'snt_gsc_crossexam_reading' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Search Console cross-exam not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$x = snt_gsc_crossexam();
	return snt_search_crossexam_impl( $x, snt_gsc_crossexam_reading( $x ) );
}

/** Execute callback: signal-noise/search-coverage (v13.63.0). Stored map only; never inspects. */
function snt_ability_search_coverage( $input ) {
	unset( $input );
	if ( ! function_exists( 'snt_gsc_coverage_data' ) || ! function_exists( 'snt_gsc_coverage_summary' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Search Console coverage not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$d = snt_gsc_coverage_data();
	$s = snt_gsc_coverage_summary( $d );
	return array_merge( array( 'ok' => true ), $s, array( 'entries' => is_array( $d ) ? (array) $d['entries'] : array() ) );
}

/**
 * slug => [label, description, execute callback, output_schema]. One table so
 * the registration loop, the read allowlist and the tests derive from it.
 *
 * @return array<string,array>
 */
function snt_search_console_abilities() {
	$row = array(
		'type'       => 'object',
		'properties' => array(
			'path'        => array( 'type' => 'string' ),
			'clicks'      => array( 'type' => 'integer' ),
			'impressions' => array( 'type' => 'integer' ),
			'ctr'         => array( 'type' => 'number' ),
			'position'    => array( 'type' => 'number' ),
		),
	);
	return array(
		'signal-noise/search-performance' => array(
			'label'       => 'Search Console: the stored window',
			'description' => 'What the site earns in Google Search over the last synced window (28 days ending ~3 days back): page rows (clicks, impressions, CTR, position; most-shown first), the top queries, near-ranking opportunities (position 8-20 with real impressions — a filter over stored rows, no new fetch), and totals. totals.capped is TRUE when the page rows hit the sync\'s 250-row limit — then every total is a FLOOR, not a site figure. synced:false with null totals means the property has never synced: that is not a zero. Read-only over data the daily sync already stores.',
			'execute'     => 'snt_ability_search_performance',
			'output'      => array(
				'type'       => 'object',
				'properties' => array(
					'ok'            => array( 'type' => 'boolean' ),
					'synced'        => array( 'type' => 'boolean' ),
					'property'      => array( 'type' => 'string' ),
					'window'        => array( 'type' => array( 'object', 'null' ) ),
					'synced_at'     => array( 'type' => 'integer' ),
					'pages'         => array( 'type' => 'array', 'items' => $row ),
					'queries'       => array( 'type' => 'array' ),
					'opportunities' => array( 'type' => 'array', 'items' => $row ),
					'totals'        => array(
						'type'       => array( 'object', 'null' ),
						'properties' => array(
							'clicks'      => array( 'type' => 'integer' ),
							'impressions' => array( 'type' => 'integer' ),
							'days'        => array( 'type' => 'integer' ),
							'capped'      => array( 'type' => 'boolean', 'description' => 'TRUE = the sums are a floor (page rows hit the sync limit).' ),
						),
					),
					'note'          => array( 'type' => 'string' ),
				),
			),
		),
		'signal-noise/search-drift'       => array(
			'label'       => 'Search Console: position drift',
			'description' => 'Pages whose average Google position worsened materially across the stored history (positive drift = WORSE; worst first). state:"accruing" means the history cannot answer yet (fewer than two snapshots far enough apart) and is NOT "no drift"; state:"measured" with an empty list is the real, good zero. Read-only over stored snapshots.',
			'execute'     => 'snt_ability_search_drift',
			'output'      => array(
				'type'       => 'object',
				'properties' => array(
					'ok'       => array( 'type' => 'boolean' ),
					'state'    => array( 'type' => 'string', 'enum' => array( 'accruing', 'measured' ) ),
					'drifting' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'path'        => array( 'type' => 'string' ),
								'from'        => array( 'type' => 'number' ),
								'to'          => array( 'type' => 'number' ),
								'drift'       => array( 'type' => 'number' ),
								'impressions' => array( 'type' => 'integer' ),
							),
						),
					),
					'note'     => array( 'type' => 'string' ),
				),
			),
		),
		'signal-noise/search-coverage'    => array(
			'label'       => 'Search Console: index coverage per post (stored)',
			'description' => 'Which published posts Google has actually indexed, from the URL Inspection API, inspected weekly and STORED — reading this never spends inspection quota. Per post: verdict, Google\'s coverage_state verbatim, indexing/robots/fetch states, last crawl time, canonical agreement, and indexed (true/false; null when Google gave no coverage state — never a guess). Summary counts indexed / not_indexed / unknown / errors, lists not_indexed_paths and canonical_mismatch. This is the discriminator the disagreement scan\'s no_impressions reading needs: a page with zero impressions is either NOT INDEXED (crawl or quality) or indexed with no query demand (topic), and Search Analytics cannot tell those apart. synced:false means the weekly inspection has never run. Read-only.',
			'execute'     => 'snt_ability_search_coverage',
			'output'      => array(
				'type'       => 'object',
				'properties' => array(
					'ok'                 => array( 'type' => 'boolean' ),
					'synced'             => array( 'type' => 'boolean' ),
					'synced_at'          => array( 'type' => 'integer' ),
					'capped'             => array( 'type' => 'boolean' ),
					'inspected'          => array( 'type' => 'integer' ),
					'indexed'            => array( 'type' => 'integer' ),
					'not_indexed'        => array( 'type' => 'integer' ),
					'unknown'            => array( 'type' => 'integer' ),
					'errors'             => array( 'type' => 'integer' ),
					'by_coverage_state'  => array( 'type' => 'object' ),
					'not_indexed_paths'  => array( 'type' => 'array' ),
					'canonical_mismatch' => array( 'type' => 'array' ),
					'entries'            => array( 'type' => 'object' ),
				),
			),
		),
		'signal-noise/search-crossexam'   => array(
			'label'       => 'Search Console x crawler ledger: do the instruments agree?',
			'description' => 'Compares Google\'s impressions with the crawler ledger\'s search-engine fetches over roughly the same window and names the verdict (agree | gsc_without_crawler | crawler_without_gsc | both_quiet) with the sentence each earns — they are DIFFERENT problems. grain is always "window": this is agreement in magnitude between instruments whose windows do not line up, never a per-page join (the ledger has no path dimension). ok:false with a reason means an instrument did not answer, which is not "zero". Read-only.',
			'execute'     => 'snt_ability_search_crossexam',
			'output'      => array(
				'type'       => 'object',
				'properties' => array(
					'ok'      => array( 'type' => 'boolean' ),
					'reason'  => array( 'type' => 'string' ),
					'verdict' => array( 'type' => 'string' ),
					'gsc'     => array( 'type' => 'object' ),
					'ledger'  => array( 'type' => 'object' ),
					'reading' => array( 'type' => 'string' ),
					'grain'   => array( 'type' => 'string', 'enum' => array( 'window' ) ),
					'caveat'  => array( 'type' => 'string' ),
				),
			),
		),
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	foreach ( snt_search_console_abilities() as $slug => $def ) {
		wp_register_ability( $slug, array(
			'label'               => $def['label'],
			'description'         => $def['description'],
			'category'            => 'diagnostics',
			'permission_callback' => 'snt_ability_perm_manage_options',
			'execute_callback'    => $def['execute'],
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => $def['output'],
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'        => true,
					'idempotent'      => true,
					'open_world_hint' => false,
				),
			),
		) );
	}
} );

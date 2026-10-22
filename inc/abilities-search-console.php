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

require_once __DIR__ . '/abilities-indexing-requests.php'; // 2026-10-22: the indexing-requests callback (its row is in the table below).

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
function snt_search_drift_impl( $drift, array $progress = array() ) {
	if ( null === $drift ) {
		// v13.88.2 — SAY HOW FAR OFF IT IS. This returned a bare `accruing`,
		// which is true and useless: on the day the watch came due, "still
		// accruing" was indistinguishable from "stuck and will never flip", and
		// settling that meant reading inc/search-console-derive.php.
		$snapshots = (int) ( $progress['snapshots'] ?? 0 );
		$span      = (float) ( $progress['span_days'] ?? 0.0 );
		$needed    = (int) ( $progress['needed_days'] ?? SNT_GSC_DRIFT_MIN_SPAN_DAYS );

		return array(
			'ok'       => true,
			'state'    => 'accruing',
			'drifting' => array(),
			'progress' => array(
				'snapshots'   => $snapshots,
				'span_days'   => $span,
				'needed_days' => $needed,
			),
			/* translators: 1: span in days, 2: required days, 3: snapshot count */
			'note'     => sprintf(
				'%.1f of %d days across %d snapshots. "Accruing" is not "no drift".',
				$span,
				$needed,
				$snapshots
			),
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
	// Progress is computed even when drift CAN answer: the call is a couple of
	// array reads over an option already in memory, and passing it
	// unconditionally keeps the impl pure over its two inputs.
	return snt_search_drift_impl(
		snt_gsc_position_drift(),
		function_exists( 'snt_gsc_drift_progress' ) ? snt_gsc_drift_progress() : array()
	);
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
	$s = snt_gsc_coverage_summary( $d, function_exists( 'snt_ml_inbound_by_path' ) ? snt_ml_inbound_by_path() : null, function_exists( 'sn_bing_url_info_data' ) ? sn_bing_url_info_data() : null ); // v13.65.0: inbound_links per not-indexed row; bing beside it.
	return array_merge( array( 'ok' => true ), $s, array(
		'entries' => is_array( $d ) ? (array) $d['entries'] : array(),
		'status'  => function_exists( 'snt_gsc_coverage_last_status' ) ? snt_gsc_coverage_last_status() : null, // v13.64.0: the last run's timing.
		'history' => function_exists( 'snt_gsc_coverage_history' ) ? snt_gsc_coverage_history() : array(), // one reading per completed weekly run, oldest first.
		'sitemaps' => function_exists( 'snt_gsc_sitemaps_data' ) ? snt_gsc_sitemaps_data() : null, // the sitemaps report, read with the weekly run.
	) );
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
	$ir_row = array(
		'type'       => 'object',
		'properties' => array(
			'url'             => array( 'type' => 'string' ),
			'path'            => array( 'type' => 'string' ),
			'coverage_state'  => array( 'type' => 'string', 'description' => 'Google\'s coverage state, verbatim.' ),
			'days'            => array( 'type' => 'integer', 'description' => 'Days since publish.' ),
			'core'            => array( 'type' => 'boolean', 'description' => 'One of the core pages (about, resume, workflow, services, contact, now, uses, colophon, the maturity pages, provenance and its children) or a Spanish twin of one.' ),
			'inbound_indexed' => array( 'type' => array( 'integer', 'null' ), 'description' => 'Body links to this URL from published notes and pages Google has indexed. null = the link graph holds no node for it (unknown, not zero).' ),
			'chrome'          => array( 'type' => 'boolean', 'description' => 'The site header or footer links this page (or, for a Spanish twin, its English page), so every page links it. Never folded into inbound_indexed.' ),
			'template'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Templates that link this URL outside the post content: post (every note), page (every page), home (the front page). Never folded into inbound_indexed.' ),
			'inspect_url'     => array( 'type' => 'string', 'description' => 'Search Console URL Inspection for this URL: open it and click Request indexing.' ),
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
			'description' => 'Pages whose average Google position worsened materially across the stored history (positive drift = WORSE; worst first). state:"accruing" means the history cannot answer yet and is NOT "no drift" — it carries `progress` {snapshots, span_days, needed_days} so you can see HOW FAR OFF it is rather than only that it is not ready; state:"measured" with an empty list is the real, good zero. Read-only over stored snapshots.',
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
			'description' => 'Which of the site\'s public URLs Google has actually indexed — posts, Pages AND tag archives (v13.109.0: before that the sweep walked posts only, so every Page including the provenance hub and its essays, plus every tag archive, was absent; their zero-impression readings were unanswerable rather than negative). Entries carry kind (post|term) alongside post_id/term_id. From the URL Inspection API, inspected weekly and STORED — reading this never spends inspection quota. Per post: verdict, Google\'s coverage_state verbatim, indexing/robots/fetch states, last crawl time, canonical agreement, and indexed (true/false; null when Google gave no coverage state — never a guess). Summary counts indexed / not_indexed / unknown / errors, lists not_indexed_paths and canonical_mismatch. This is the discriminator the disagreement scan\'s no_impressions reading needs: a page with zero impressions is either NOT INDEXED (crawl or quality) or indexed with no query demand (topic), and Search Analytics cannot tell those apart. synced:false means the weekly inspection has never run. Read-only.',
			'execute'     => 'snt_ability_search_coverage',
			'output'      => array(
				'type'       => 'object',
				'properties' => array(
					'ok'                 => array( 'type' => 'boolean' ),
					'synced'             => array( 'type' => 'boolean' ),
					'complete'           => array( 'type' => 'boolean', 'description' => 'false = a run is in progress or was interrupted; the map is partial.' ),
					'synced_at'          => array( 'type' => 'integer' ),
					'capped'             => array( 'type' => 'boolean' ),
					'inspected'          => array( 'type' => 'integer' ),
					'indexed'            => array( 'type' => 'integer' ),
					'not_indexed'        => array( 'type' => 'integer' ),
					'unknown'            => array( 'type' => 'integer' ),
					'errors'             => array( 'type' => 'integer' ),
					// v13.63.1: a PHP map with no keys serializes as [] — the schema must
					// admit both, or the door refuses the never-synced answer itself.
					'by_coverage_state'  => array( 'type' => array( 'object', 'array' ) ),
					'inbound_available'  => array( 'type' => 'boolean', 'description' => 'v13.65.0: not_indexed_paths[].inbound_links is computed (else null).' ),
					'not_indexed_paths'  => array( 'type' => 'array', 'description' => 'Each row: path, url, coverage_state, last_crawl_time, verdict, inbound_links, and bing: Bing\'s GetUrlInfo answer in plain words ("Bing crawled 2026-10-01", "not known to Bing", "Bing not read (...)"), null when Bing was not asked. A crawl date is not an index verdict.' ),
					'canonical_mismatch' => array( 'type' => 'array' ),
					'entries'            => array( 'type' => array( 'object', 'array' ) ),
					'status'             => array( 'type' => array( 'object', 'null' ) ),
					'sitemaps'           => array( 'type' => array( 'object', 'null' ), 'description' => 'The Search Console sitemaps report, read at the end of each weekly run: synced_at, last_error, sitemaps[] (path, last_submitted, last_downloaded, is_pending, is_index, errors, warnings, submitted). null before the first read. Google\'s deprecated indexed count is not kept.' ),
					'history'            => array( 'type' => 'array', 'description' => 'One summary reading per completed weekly run, oldest first, at most 104 (two years): synced_at, inspected, indexed, not_indexed, crawled_not_indexed, unknown, errors, median_crawl_age_days (null when no entry reported a crawl time), crawl_times. [] before the first run completes.' ),
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
		// 2026-10-22: the indexing guard's read (callback: inc/abilities-indexing-requests.php).
		'signal-noise/indexing-requests' => array(
			'label'       => 'Search Console: URLs to request indexing for (stored)',
			'description' => 'The owner\'s weekly action list from the stored URL Inspection map, the internal link graph (notes and pages) and the post table. request_list: published notes and pages the sitemap lists that Google has not indexed 7 or more days after publish, ranked "Crawled - currently not indexed" first, then core pages, then the rest oldest first, at most 10 (request_total is the true count). stuck: the same at 30 days, at most 25 (stuck_total). publish_check: notes and core pages published 3 or more days ago with fewer than 2 links from indexed pages (body links are read rendered, so links blocks and shortcodes print count; a page the site header or footer links passes, since the chrome links it from every page; a link in the single or page template counts once per indexed note or page, and one in the front-page template once for the home page), out of the sitemap, or with a canonical elsewhere; reasons per row; inbound_judged:false before the inspection map exists. Tag archives, /verify and anything noindex never appear in request_list or stuck; publish_check may name a published note or core page the sitemap leaves out, which is its warning. coverage_run_at is when the inspection map last moved (null = never). Read-only: it never inspects, and nothing here can request indexing (open each inspect_url).',
			'execute'     => 'snt_ability_indexing_requests',
			'output'      => array(
				'type'       => 'object',
				'properties' => array(
					'ok'              => array( 'type' => 'boolean' ),
					'request_list'    => array( 'type' => 'array', 'items' => $ir_row ),
					'request_total'   => array( 'type' => 'integer' ),
					'stuck'           => array( 'type' => 'array', 'items' => $ir_row ),
					'stuck_total'     => array( 'type' => 'integer' ),
					'publish_check'   => array(
						'type'       => 'object',
						'properties' => array(
							'checked'        => array( 'type' => 'integer' ),
							'inbound_judged' => array( 'type' => 'boolean' ),
							'chrome_paths'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Paths the header and footer link (rendered through core, cached per theme and parts), plus the Spanish twins of those pages. A page here passes the inbound rule. Empty = the chrome was not read.' ),
							'template_paths' => array( 'type' => 'object', 'description' => 'Paths the theme templates link outside the post content: post (single, from every indexed note), page (from every indexed page), home (front page, from the home page). Read from block markup, cached per theme.' ),
							'graph_render'   => array( 'type' => 'object', 'description' => 'What building the link graph cost on this read: rendered (bodies rendered now), cached (served from the per-post cache), failed (render threw; raw body used, retried next read), ms, budget_hit (the 15-second render budget ran out) and remaining (bodies read raw this time; the next read continues).' ),
							'failures'       => array( 'type' => 'array', 'description' => 'Each row: path, url, post_type, inbound_indexed (body links from indexed pages, integer or null), inbound_source (chrome | template | body), template[], reasons[] of inbound | sitemap | canonical. The inbound rule counts body and template sources as one set.' ),
						),
					),
					'generated_at'    => array( 'type' => 'integer' ),
					'coverage_run_at' => array( 'type' => array( 'integer', 'null' ) ),
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
				'mcp'          => array( 'public' => true, 'type' => 'tool' ),
				'annotations'  => array(
					'readonly'        => true,
					'destructive'     => false,
					'idempotent'      => true,
					'open_world_hint' => false,
				),
			),
		) );
	}
} );

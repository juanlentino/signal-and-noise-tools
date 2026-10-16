<?php
/**
 * Signal & Noise Tools: Insights, the signal collection (the search join key, the search section, every SN-owned signal for the prompt).
 *
 * Split from inc/insights.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregate all SN-owned signals into one dict for the AI prompt.
 *
 * @return array Structured signals — see spec §5.1.
 */
/**
 * The weave join key: sn_path_join_key() when loaded, else the old inline
 * spelling (leading slash guaranteed so the two agree on the common case).
 *
 * @since 13.57.0
 */
function snt_insights_join_key( $uri ) {
	if ( function_exists( 'sn_path_join_key' ) ) {
		return sn_path_join_key( $uri );
	}
	$p = trim( (string) $uri );
	if ( 0 === strpos( $p, 'http' ) ) {
		$p = (string) wp_parse_url( $p, PHP_URL_PATH );
	}
	$p = '/' . trim( $p, '/' );
	return '/' === $p ? '' : $p;
}

/**
 * The 'search' section, PURE over the stored window. Fills $map with
 * join-key => {clicks, impressions, ctr, position} for the post-list join.
 *
 * @since 13.57.0
 * @param array|null $data   snt_gsc_data().
 * @param array|null $totals snt_gsc_window_totals().
 * @param array      $map    OUT.
 * @return array
 */
function snt_insights_search_section( $data, $totals, &$map ) {
	$map = array();
	if ( ! is_array( $data ) ) {
		return array( 'synced' => false );
	}
	foreach ( (array) ( $data['pages'] ?? array() ) as $path => $m ) {
		$key = snt_insights_join_key( (string) $path );
		if ( '' === $key || ! is_array( $m ) ) {
			continue;
		}
		$map[ $key ] = array(
			'clicks'      => (int) ( $m['clicks'] ?? 0 ),
			'impressions' => (int) ( $m['impressions'] ?? 0 ),
			'ctr'         => round( (float) ( $m['ctr'] ?? 0 ), 4 ),
			'position'    => round( (float) ( $m['position'] ?? 0 ), 1 ),
		);
	}
	return array(
		'synced'      => true,
		'window'      => is_array( $data['window'] ?? null ) ? $data['window'] : null,
		'totals'      => is_array( $totals ) ? $totals : null, // carries `capped`: a floor when true.
		'top_queries' => array_slice( array_values( (array) ( $data['queries'] ?? array() ) ), 0, 10 ),
	);
}

function snt_insights_collect_signals() {
	$out = array(
		'site'           => array(),
		'analytics'      => array(),
		'posts'          => array(),
		'webhooks'       => array(),
		'cron_freshness' => array(),
		'search'         => array(),
		'collected_at'   => time(),
	);

	// ── 1. Site identity ──
	$out['site'] = array(
		'name'        => (string) sn_setting( 'identity.site_name', get_bloginfo( 'name' ) ),
		'description' => (string) sn_setting( 'identity.site_description', '' ),
		'person'      => (string) sn_setting( 'identity.person_name', '' ),
		'job_title'   => (string) sn_setting( 'identity.job_title', '' ),
		'home_url'    => home_url( '/' ),
	);

	// ── 2. Analytics — first-party 7-day traffic (was Plausible until v6.0.0) ──
	// Read the durable rollup accessors (human class), shaped like the old
	// dashboard_data ({aggregate, pages, sources}) so the prompt + the post-list
	// views_7d join keep working without the retired Plausible client.
	$an_to    = gmdate( 'Y-m-d' );
	$an_from  = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ); // inclusive 7-day window
	$an_pages = function_exists( 'sn_analytics_top_paths' ) ? sn_analytics_top_paths( $an_from, $an_to, 'human', 100 ) : array();
	$an_pages = is_array( $an_pages ) ? $an_pages : array();
	// v9.68.1: the dims accessor reports a FAILED read as null — this payload
	// feeds an AI prompt, so it degrades to [] (the safe prompt shape); the
	// dashboard surfaces carry the honest read-failure fold instead.
	$an_sources       = function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'referrer', $an_from, $an_to, 'human', 20 ) : array();
	$out['analytics'] = array(
		'aggregate' => function_exists( 'sn_analytics_range_totals' ) ? sn_analytics_range_totals( $an_from, $an_to, 'human' ) : array(),
		'pages'     => $an_pages,
		'sources'   => is_array( $an_sources ) ? $an_sources : array(),
	);

	// Build a quick {relative-permalink-path => views_7d} map for the post-list
	// join. The first-party top_paths rows are shaped { path: "/notes/my-slug",
	// views: <int>, … }. Joining by relative path (not slug) makes the match work
	// for any permalink structure, including nested permalinks like /notes/<slug>/.
	$views_map = array();
	foreach ( $an_pages as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		// v13.57.0: the WEAVE join key (Rule 1). Both sides of every per-path
		// join go through sn_path_join_key() — three spellings agreed on the
		// common case and diverged on bare/empty inputs, dropping rows silently.
		$path = isset( $row['path'] ) ? snt_insights_join_key( (string) $row['path'] ) : '';
		if ( '' !== $path ) {
			$views_map[ $path ] = (int) ( $row['views'] ?? 0 );
		}
	}

	// ── 2b. Search — the stored Search Console window (weave Phase 2) ──
	// Per-path metrics joined onto the post list below through the same key;
	// a never-synced property degrades to synced:false (never a zero row),
	// and the section-2 precedent holds: the prompt sees a safe shape, the
	// dashboard carries the honest read-failure fold.
	$search_map    = array();
	$out['search'] = snt_insights_search_section( function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null, function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null, $search_map );

	// ── 3. Post list (published, post/page, < 2yo) ──
	global $wpdb;
	$cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( SN_INSIGHTS_POST_MAX_AGE_DAYS * DAY_IN_SECONDS ) );
	$post_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_name, post_status, post_type, post_date_gmt, post_modified_gmt
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_date_gmt >= %s
		 LIMIT 500",
		$cutoff_gmt
	), ARRAY_A );

	$posts = array();
	if ( is_array( $post_rows ) ) {
		foreach ( $post_rows as $r ) {
			$slug = (string) $r['post_name'];
			$published_ts = strtotime( $r['post_date_gmt'] . ' UTC' );
			$modified_ts  = strtotime( $r['post_modified_gmt'] . ' UTC' );

			// Defense-in-depth: also enforce the age cap in PHP, so the
			// impl is robust even if the SQL WHERE clause doesn't filter
			// (e.g. wpdb mock in tests).
			if ( $published_ts < time() - ( SN_INSIGHTS_POST_MAX_AGE_DAYS * DAY_IN_SECONDS ) ) {
				continue;
			}

			$tags = array();
			$cats = array();
			if ( function_exists( 'wp_get_post_terms' ) ) {
				$tag_terms = wp_get_post_terms( (int) $r['ID'], 'post_tag', array( 'fields' => 'names' ) );
				$cat_terms = wp_get_post_terms( (int) $r['ID'], 'category', array( 'fields' => 'names' ) );
				$tags = is_array( $tag_terms ) ? array_values( $tag_terms ) : array();
				$cats = is_array( $cat_terms ) ? array_values( $cat_terms ) : array();
			}

			$permalink = get_permalink( (int) $r['ID'] );
			$permalink_path = snt_insights_join_key( (string) $permalink ); // v13.57.0: the weave key, same as views_map/search_map.

			$posts[] = array(
				'id'                   => (int) $r['ID'],
				'title'                => (string) $r['post_title'],
				'slug'                 => $slug,
				'url'                  => $permalink,
				'published'            => gmdate( 'Y-m-d', $published_ts ),
				'modified'             => gmdate( 'Y-m-d', $modified_ts ),
				'days_since_publish'   => (int) floor( ( time() - $published_ts ) / DAY_IN_SECONDS ),
				'days_since_modified'  => (int) floor( ( time() - $modified_ts ) / DAY_IN_SECONDS ),
				'type'                 => (string) $r['post_type'],
				'tags'                 => $tags,
				'categories'           => $cats,
				'views_7d'             => isset( $views_map[ $permalink_path ] ) ? (int) $views_map[ $permalink_path ] : 0,
				// null, not a zero row: a page Google never showed is not a page shown with no clicks.
				'search_28d'           => isset( $search_map[ $permalink_path ] ) ? $search_map[ $permalink_path ] : null,
			);
		}
	}

	// Sort by views_7d desc, then by days_since_publish asc as tie-break.
	usort( $posts, function( $a, $b ) {
		if ( $a['views_7d'] !== $b['views_7d'] ) {
			return $b['views_7d'] - $a['views_7d'];
		}
		return $a['days_since_publish'] - $b['days_since_publish'];
	} );

	// Cap at top N.
	$out['posts'] = array_slice( $posts, 0, SN_INSIGHTS_POST_CAP );

	// ── 3b. Body-grounding: attach a bounded content excerpt to the
	// highest-priority posts so the advisor reasons about substance, not
	// just metadata. Source preference: the author's curated post_excerpt
	// (whitespace-only treated as empty) → fall back to a word-capped
	// body extract. Two-layer cap: per-excerpt word cap AND a running
	// total-chars ceiling. Zero new AI calls — these ride the weekly
	// prompt that's already being sent.
	$excerpt_chars = 0;
	$limit = min( SN_INSIGHTS_EXCERPT_CAP, count( $out['posts'] ) );
	for ( $i = 0; $i < $limit; $i++ ) {
		if ( $excerpt_chars >= SN_INSIGHTS_EXCERPT_TOTAL_CHARS ) {
			break;
		}
		$pid  = (int) $out['posts'][ $i ]['id'];
		$post = get_post( $pid );
		$excerpt = '';
		if ( $post && isset( $post->post_excerpt ) && '' !== trim( (string) $post->post_excerpt ) ) {
			$excerpt = trim( (string) $post->post_excerpt );
		} elseif ( function_exists( 'snt_ai_extract_post_text' ) ) {
			$excerpt = snt_ai_extract_post_text( $pid, SN_INSIGHTS_EXCERPT_WORDS );
		}
		if ( '' === $excerpt ) {
			continue;
		}
		// Bound this excerpt to the remaining total-chars budget. The author
		// post_excerpt branch is otherwise verbatim (uncapped), so a single
		// pathologically-long excerpt can't blow the prompt token budget; this
		// also removes the loop-top check's one-excerpt overshoot. mb_strcut
		// keeps the byte ceiling without splitting a multibyte char.
		$remaining = SN_INSIGHTS_EXCERPT_TOTAL_CHARS - $excerpt_chars;
		if ( strlen( $excerpt ) > $remaining ) {
			$excerpt = function_exists( 'mb_strcut' ) ? mb_strcut( $excerpt, 0, $remaining ) : substr( $excerpt, 0, $remaining );
		}
		$out['posts'][ $i ]['excerpt'] = $excerpt;
		$excerpt_chars += strlen( $excerpt );
	}

	// ── 4. Webhook summary ──
	$wh_count_active = 0;
	$wh_summary = array();
	if ( function_exists( 'sn_webhooks_all' ) ) {
		foreach ( sn_webhooks_all() as $wh ) {
			if ( ! empty( $wh['enabled'] ) ) {
				$wh_count_active++;
			}
			$wh_id = isset( $wh['id'] ) ? (string) $wh['id'] : '';
			if ( '' === $wh_id ) { continue; }
			$log = function_exists( 'sn_webhook_log_read' ) ? sn_webhook_log_read( $wh_id ) : array();
			if ( empty( $log ) ) { continue; }
			$success = 0; $total = 0; $last_fired = 0;
			foreach ( $log as $entry ) {
				$total++;
				if ( ! empty( $entry['success'] ) ) { $success++; }
				if ( isset( $entry['fired_at'] ) && (int) $entry['fired_at'] > $last_fired ) {
					$last_fired = (int) $entry['fired_at'];
				}
			}
			$wh_summary[ $wh_id ] = array(
				'name'                       => isset( $wh['name'] ) ? (string) $wh['name'] : $wh_id,
				'enabled'                    => ! empty( $wh['enabled'] ),
				'success_rate'               => $total > 0 ? round( $success / $total, 4 ) : 0,
				'last_attempt_ago_seconds'   => $last_fired > 0 ? ( time() - $last_fired ) : null,
				'total_attempts_logged'      => $total,
			);
		}
	}
	$out['webhooks'] = array(
		'total_active'              => $wh_count_active,
		'recent_deliveries_summary' => $wh_summary,
	);

	// ── 5. Cron freshness — query the snt_cron_history table ──
	$out['cron_freshness'] = array();
	$table = $wpdb->prefix . 'snt_cron_history';
	// fired_at is stored as a UTC DATETIME string (cron-history writes gmdate).
	// Never UNIX_TIMESTAMP() it in SQL — MySQL interprets the argument in the
	// SESSION timezone, silently shifting the math on any non-UTC session.
	// Select the raw string and convert PHP-side with strtotime($s . ' UTC'),
	// the same idiom as snt_cron_history_for_hook(). The cutoff is bound as a
	// UTC datetime string so it compares in the column's own representation.
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix + a plugin constant (no user input).
	$cron_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT hook,
		        MAX(fired_at) AS last_fired_at,
		        SUM(CASE WHEN fired_at >= %s THEN 1 ELSE 0 END) AS fires_24h
		 FROM {$table}
		 GROUP BY hook",
		$cutoff
	), ARRAY_A );
	if ( is_array( $cron_rows ) ) {
		foreach ( $cron_rows as $r ) {
			$hook = isset( $r['hook'] ) ? (string) $r['hook'] : '';
			if ( '' === $hook ) { continue; }
			// Guard the empty string BEFORE appending ' UTC': strtotime(' UTC')
			// resolves to the CURRENT time (the schedule-block trap).
			$last_raw = isset( $r['last_fired_at'] ) ? trim( (string) $r['last_fired_at'] ) : '';
			$last     = '' !== $last_raw ? (int) strtotime( $last_raw . ' UTC' ) : 0;
			$out['cron_freshness'][ $hook ] = array(
				'last_fired_ago_minutes' => $last > 0 ? (int) floor( ( time() - $last ) / 60 ) : null,
				'last_24h_count'         => isset( $r['fires_24h'] ) ? (int) $r['fires_24h'] : 0,
			);
		}
	}

	// v10.24.0: attach the kernel's cadence verdicts so the advisor reasons
	// about SURPRISE (z vs the rhythm's own history) instead of re-deriving
	// staleness from raw minutes in-model. Additive; guarded; the raw
	// cron_freshness rows above stay untouched.
	if ( function_exists( 'snt_ml_cadence_flags' ) ) {
		$cad = snt_ml_cadence_flags();
		if ( is_array( $cad ) ) {
			$out['cadence_flags'] = array(
				'flags'        => array_map( static function ( $f ) {
					$row = array(
						'kind'    => (string) ( $f['kind'] ?? '' ),
						'subject' => (string) ( $f['subject'] ?? '' ),
						'z'       => round( (float) ( $f['z'] ?? 0 ), 2 ),
					);
					// A quiet week arrives explained: the movers are arithmetic
					// from the rollups, so the advisor reads them, never derives them.
					if ( isset( $f['movers'] ) ) {
						$row['movers'] = $f['movers'];
					}
					return $row;
				}, (array) ( $cad['flags'] ?? array() ) ),
				'cron_skipped' => (bool) ( $cad['cron_skipped'] ?? false ),
			);
		}
	}

	return $out;
}

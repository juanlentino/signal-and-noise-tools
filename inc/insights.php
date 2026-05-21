<?php
/**
 * Signal & Noise Tools — Insights Tab + Content Opportunity Advisor.
 *
 * Cross-system AI synthesis: combines Plausible analytics + WP publish
 * history + webhook delivery patterns + cron firings + site identity
 * into a single AI call that returns 5 actionable recommendations per
 * scan ("write_about", "update_post", "cadence_change",
 * "topic_double_down", "topic_pivot").
 *
 * 4-surface dispatch (same pattern as Cron/Webhooks/Health):
 *   - wp-admin form (Insights tab → Run Analysis button)
 *   - REST POST  /signal-noise/v1/insights/run
 *   - REST GET   /signal-noise/v1/insights/last
 *   - Abilities API: signal-noise/run-insights-scan + get-insights
 *   - desktop-mode ⌘K: sn-cmd-insights (aiCallable)
 *
 * All four converge on the snt_insights_*_impl() pure functions below.
 *
 * @package SignalNoiseTools
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_INSIGHTS_CACHE_KEY',         'sn_insights_last_scan' );
define( 'SN_INSIGHTS_CACHE_TTL',         7 * DAY_IN_SECONDS );
define( 'SN_INSIGHTS_STATE_OPT',         'sn_insights_state' );
define( 'SN_INSIGHTS_CRON_HOOK',         'sn_insights_weekly_scan' );
define( 'SN_INSIGHTS_POST_CAP',          100 );
define( 'SN_INSIGHTS_POST_MAX_AGE_DAYS', 730 );  // 2 years
define( 'SN_INSIGHTS_STATE_LIST_CAP',    200 );  // FIFO cap per state list
define( 'SN_INSIGHTS_SNOOZE_DAYS',       30 );
define( 'SN_INSIGHTS_MIN_VALID_RECS',    3 );    // below this → scan failed

/**
 * Aggregate all SN-owned signals into one dict for the AI prompt.
 *
 * @return array Structured signals — see spec §5.1.
 */
function snt_insights_collect_signals() {
	$out = array(
		'site'           => array(),
		'plausible'      => array(),
		'posts'          => array(),
		'webhooks'       => array(),
		'cron_freshness' => array(),
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

	// ── 2. Plausible — pass through whatever the cache has ──
	$pl = sn_plausible_dashboard_data();
	$out['plausible'] = is_array( $pl ) ? $pl : array();

	// Build a quick {relative-permalink-path => views_7d} map for the
	// post-list join. The Plausible breakdown endpoint returns rows
	// shaped { page: "/notes/my-slug", visitors: <int> } — visitors is a
	// SCALAR, not nested under .value (that's the aggregate shape, see
	// inc/plausible-widget.php:105 vs :183). Joining by relative path
	// (not slug) makes the match work for any permalink structure,
	// including nested permalinks like /notes/<slug>/.
	$views_map = array();
	if ( ! empty( $out['plausible']['pages'] ) && is_array( $out['plausible']['pages'] ) ) {
		foreach ( $out['plausible']['pages'] as $row ) {
			$page = isset( $row['page'] ) ? trim( (string) $row['page'], '/' ) : '';
			$visitors = isset( $row['visitors'] ) ? (int) $row['visitors'] : 0;
			if ( '' !== $page ) {
				$views_map[ $page ] = $visitors;
			}
		}
	}

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
			$permalink_path = function_exists( 'wp_make_link_relative' )
				? trim( wp_make_link_relative( $permalink ), '/' )
				: trim( (string) $permalink, '/' );

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
	$cutoff = time() - DAY_IN_SECONDS;
	$cron_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT hook,
		        MAX(UNIX_TIMESTAMP(fired_at)) AS last_fired_ts,
		        SUM(CASE WHEN UNIX_TIMESTAMP(fired_at) >= %d THEN 1 ELSE 0 END) AS fires_24h
		 FROM {$table}
		 GROUP BY hook",
		$cutoff
	), ARRAY_A );
	if ( is_array( $cron_rows ) ) {
		foreach ( $cron_rows as $r ) {
			$hook = isset( $r['hook'] ) ? (string) $r['hook'] : '';
			if ( '' === $hook ) { continue; }
			$last = isset( $r['last_fired_ts'] ) ? (int) $r['last_fired_ts'] : 0;
			$out['cron_freshness'][ $hook ] = array(
				'last_fired_ago_minutes' => $last > 0 ? (int) floor( ( time() - $last ) / 60 ) : null,
				'last_24h_count'         => isset( $r['fires_24h'] ) ? (int) $r['fires_24h'] : 0,
			);
		}
	}

	return $out;
}

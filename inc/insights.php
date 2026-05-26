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

/**
 * Build the AI prompt + system instruction and call the AI client.
 *
 * @param array $signals Output of snt_insights_collect_signals().
 * @return string|WP_Error Raw AI response text, or WP_Error on failure.
 */
function snt_insights_call_ai( $signals ) {
	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return new WP_Error(
			'snt_insights_ai_unavailable',
			'AI client not available. Configure a provider in Settings → Connectors.',
			array( 'status' => 503 )
		);
	}

	$system = snt_insights_system_instruction();
	$prompt = wp_json_encode( $signals );
	if ( ! is_string( $prompt ) ) {
		return new WP_Error( 'snt_insights_encode_failed', 'Failed to encode signals as JSON.' );
	}

	return snt_ai_generate_with_constraints( $prompt, $system, 1500 );
}

/**
 * System instruction for the content opportunity advisor.
 * Centralized so the prompt can be tweaked in one place.
 */
function snt_insights_system_instruction() {
	return <<<INSTRUCTIONS
You are a content strategist analyzing a personal site's data. You will receive a JSON blob with: site identity, 7-day Plausible analytics, post publish history with traffic per post, webhook delivery patterns, and cron freshness signals.

Return ONLY a JSON array of exactly 5 recommendations. Each must be an object:

{
  "id": "rec_<short-stable-slug>",
  "type": "write_about" | "update_post" | "cadence_change" | "topic_double_down" | "topic_pivot",
  "title": "<concise headline; max 80 chars>",
  "rationale": "<2-3 sentence explanation citing specific numbers from the data>",
  "evidence_pills": ["<short fact 1>", "<short fact 2>"],
  "target": null
}

When the recommendation refers to a specific existing post, set target to {"post_id": <int>, "url": "<string>"}. Otherwise target is null.

Rules:
- Cite specific numbers (view counts, days, percentages). No vague claims.
- Prioritize recommendations the site owner can act on this week.
- Mix recommendation types — don't return 5 of the same type.
- No marketing fluff, no exclamation marks.
- The "id" field should be deterministic from type + title (slugified, max 32 chars).
- Output JSON only. No preamble, no markdown fences.
INSTRUCTIONS;
}

/**
 * Parse + validate raw AI response into an array of recommendations.
 *
 * Strips optional markdown code fences (defensive — model sometimes
 * wraps JSON in ```json … ``` despite the system instruction).
 *
 * @param string $raw Raw text returned by the AI.
 * @return array|WP_Error Array of validated recommendations OR WP_Error
 *                        if fewer than SN_INSIGHTS_MIN_VALID_RECS remain.
 */
function snt_insights_parse_response( $raw ) {
	$text = trim( (string) $raw );

	// Strip ```json … ``` or ``` … ``` fences if present.
	if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m ) ) {
		$text = trim( $m[1] );
	}

	$decoded = json_decode( $text, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error(
			'snt_insights_invalid_json',
			'AI response was not valid JSON.',
			array( 'raw' => substr( $text, 0, 500 ) )
		);
	}

	$allowed_types = array( 'write_about', 'update_post', 'cadence_change', 'topic_double_down', 'topic_pivot' );

	$valid = array();
	foreach ( $decoded as $entry ) {
		if ( ! is_array( $entry ) ) { continue; }
		// Required keys.
		foreach ( array( 'id', 'type', 'title', 'rationale', 'evidence_pills' ) as $key ) {
			if ( ! array_key_exists( $key, $entry ) ) { continue 2; }
		}
		if ( ! in_array( $entry['type'], $allowed_types, true ) ) { continue; }
		if ( ! is_string( $entry['title'] ) || strlen( $entry['title'] ) === 0 || strlen( $entry['title'] ) > 80 ) { continue; }
		if ( ! is_string( $entry['rationale'] ) || strlen( $entry['rationale'] ) === 0 ) { continue; }
		if ( ! is_array( $entry['evidence_pills'] ) ) { continue; }

		// Validate target if present.
		$target = null;
		if ( array_key_exists( 'target', $entry ) && is_array( $entry['target'] ) ) {
			$pid = isset( $entry['target']['post_id'] ) ? (int) $entry['target']['post_id'] : 0;
			if ( $pid > 0 ) {
				$post = get_post( $pid );
				if ( ! $post ) { continue; }  // drop — references non-existent post
				$target = array(
					'post_id' => $pid,
					'url'     => isset( $entry['target']['url'] ) ? (string) $entry['target']['url'] : '',
				);
			}
		}

		$valid[] = array(
			'id'             => (string) $entry['id'],
			'type'           => (string) $entry['type'],
			'title'          => (string) $entry['title'],
			'rationale'      => (string) $entry['rationale'],
			'evidence_pills' => array_values( array_map( 'strval', $entry['evidence_pills'] ) ),
			'target'         => $target,
		);
	}

	if ( count( $valid ) < SN_INSIGHTS_MIN_VALID_RECS ) {
		return new WP_Error(
			'snt_insights_too_few_valid',
			sprintf( 'Only %d valid recommendations parsed (need at least %d).', count( $valid ), SN_INSIGHTS_MIN_VALID_RECS ),
			array( 'parsed_count' => count( $valid ) )
		);
	}

	return $valid;
}

/**
 * Read the per-recommendation state from sn_insights_state.
 * Returns dict with three arrays/maps. Defaults to empty.
 */
function snt_insights_state_read() {
	$stored = get_option( SN_INSIGHTS_STATE_OPT, array() );
	if ( ! is_array( $stored ) ) { $stored = array(); }
	return array(
		'dismissed_ids' => isset( $stored['dismissed_ids'] ) && is_array( $stored['dismissed_ids'] ) ? array_values( $stored['dismissed_ids'] ) : array(),
		'snoozed_until' => isset( $stored['snoozed_until'] ) && is_array( $stored['snoozed_until'] ) ? $stored['snoozed_until'] : array(),
		'done_ids'      => isset( $stored['done_ids'] )      && is_array( $stored['done_ids'] )      ? array_values( $stored['done_ids'] )      : array(),
	);
}

function snt_insights_state_write( $state ) {
	// FIFO cap each list.
	if ( count( $state['dismissed_ids'] ) > SN_INSIGHTS_STATE_LIST_CAP ) {
		$state['dismissed_ids'] = array_slice( $state['dismissed_ids'], -SN_INSIGHTS_STATE_LIST_CAP );
	}
	if ( count( $state['done_ids'] ) > SN_INSIGHTS_STATE_LIST_CAP ) {
		$state['done_ids'] = array_slice( $state['done_ids'], -SN_INSIGHTS_STATE_LIST_CAP );
	}
	if ( count( $state['snoozed_until'] ) > SN_INSIGHTS_STATE_LIST_CAP ) {
		asort( $state['snoozed_until'] );
		$state['snoozed_until'] = array_slice( $state['snoozed_until'], -SN_INSIGHTS_STATE_LIST_CAP, null, true );
	}
	update_option( SN_INSIGHTS_STATE_OPT, $state, false );
}

function snt_insights_dismiss( $rec_id ) {
	$rec_id = (string) $rec_id;
	if ( '' === $rec_id ) { return; }
	$state = snt_insights_state_read();
	if ( ! in_array( $rec_id, $state['dismissed_ids'], true ) ) {
		$state['dismissed_ids'][] = $rec_id;
		snt_insights_state_write( $state );
	}
}

function snt_insights_snooze( $rec_id ) {
	$rec_id = (string) $rec_id;
	if ( '' === $rec_id ) { return; }
	$state = snt_insights_state_read();
	$state['snoozed_until'][ $rec_id ] = time() + ( SN_INSIGHTS_SNOOZE_DAYS * DAY_IN_SECONDS );
	snt_insights_state_write( $state );
}

function snt_insights_mark_done( $rec_id ) {
	$rec_id = (string) $rec_id;
	if ( '' === $rec_id ) { return; }
	$state = snt_insights_state_read();
	if ( ! in_array( $rec_id, $state['done_ids'], true ) ) {
		$state['done_ids'][] = $rec_id;
		snt_insights_state_write( $state );
	}
}

/**
 * Filter a recommendations array against the saved state.
 * Hides dismissed + active-snoozed. Done recs stay (callers can flag).
 */
function snt_insights_filter_active( $recommendations ) {
	if ( ! is_array( $recommendations ) ) { return array(); }
	$state = snt_insights_state_read();
	$now   = time();
	$dismissed = array_flip( $state['dismissed_ids'] );
	$snoozed   = $state['snoozed_until'];

	$out = array();
	foreach ( $recommendations as $rec ) {
		$id = isset( $rec['id'] ) ? (string) $rec['id'] : '';
		if ( '' === $id ) { continue; }
		if ( isset( $dismissed[ $id ] ) ) { continue; }
		if ( isset( $snoozed[ $id ] ) && (int) $snoozed[ $id ] > $now ) { continue; }
		$out[] = $rec;
	}
	return $out;
}

/**
 * Run a full scan: collect signals → call AI → parse → cache.
 *
 * @param bool $force If true, bypass the 7-day cache.
 * @return array|WP_Error Scan result OR WP_Error.
 */
function snt_insights_run_scan( $force = false ) {
	if ( ! $force ) {
		$cached = snt_insights_last_scan();
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$started = microtime( true );

	$signals = snt_insights_collect_signals();
	$raw     = snt_insights_call_ai( $signals );
	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$parsed = snt_insights_parse_response( $raw );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}

	$result = array(
		'scanned_at'      => time(),
		'elapsed_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
		'recommendations' => $parsed,
		'signal_summary'  => array(
			'posts_count'    => count( $signals['posts'] ),
			'webhooks_count' => isset( $signals['webhooks']['total_active'] ) ? (int) $signals['webhooks']['total_active'] : 0,
			'cron_hooks_seen' => count( $signals['cron_freshness'] ),
		),
	);

	set_transient( SN_INSIGHTS_CACHE_KEY, $result, SN_INSIGHTS_CACHE_TTL );
	return $result;
}

/**
 * Read the cached scan result, or null if no cache.
 */
function snt_insights_last_scan() {
	$cached = get_transient( SN_INSIGHTS_CACHE_KEY );
	return is_array( $cached ) ? $cached : null;
}

/**
 * Returns true if the weekly cron is opted in.
 */
function snt_insights_weekly_cron_enabled() {
	return (bool) sn_setting( 'insights.weekly_cron_enabled', false );
}

/**
 * Schedule the weekly cron if the setting is on AND not already scheduled.
 */
function snt_insights_maybe_schedule_weekly_cron() {
	if ( ! snt_insights_weekly_cron_enabled() ) {
		return;
	}
	if ( ! wp_next_scheduled( SN_INSIGHTS_CRON_HOOK ) ) {
		// First firing in 1 hour; recurrence is weekly.
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', SN_INSIGHTS_CRON_HOOK );
	}
}

/**
 * Cancel any scheduled weekly cron event.
 */
function snt_insights_unschedule_weekly_cron() {
	$ts = wp_next_scheduled( SN_INSIGHTS_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, SN_INSIGHTS_CRON_HOOK );
	}
}

/**
 * Cron handler: run a forced scan (bypasses 7-day cache).
 */
function snt_insights_weekly_scan_cb() {
	snt_insights_run_scan( true );
}
add_action( SN_INSIGHTS_CRON_HOOK, 'snt_insights_weekly_scan_cb' );

// v4.1.1 (B-04): hook on `init` (not `admin_init`) — see cron-history.php for rationale.
// admin_init does not fire on WP-CLI or front-end-only requests, so the cron
// was never scheduled on installs whose first hit wasn't an admin page.
add_action( 'init', 'snt_insights_maybe_schedule_weekly_cron' );

/**
 * Compact summary for desktop-mode's wp_localize_script.
 * Mirrors snt_cron_summary_for_localize from v3.0.0.
 * @since 3.6.0
 */
function snt_insights_summary_for_localize() {
	$last = snt_insights_last_scan();
	if ( ! is_array( $last ) ) {
		return array(
			'scanned_at'      => null,
			'active_count'    => 0,
			'total_count'     => 0,
		);
	}
	$state = snt_insights_state_read();
	$active = snt_insights_filter_active( $last['recommendations'] );
	return array(
		'scanned_at'      => isset( $last['scanned_at'] ) ? (int) $last['scanned_at'] : null,
		'active_count'    => count( $active ),
		'total_count'     => count( $last['recommendations'] ),
		'dismissed_count' => count( $state['dismissed_ids'] ),
		'done_count'      => count( $state['done_ids'] ),
	);
}

<?php
/**
 * Signal & Noise Tools — Weekly digest narration.
 *
 * A read-only prose "what happened this week" digest — a second output mode
 * over the same first-party analytics the Insights advisor reads, but framed
 * as narrative (what happened) rather than the advisor's 5 structured
 * recommendations (what to do). Reuses the shared Sonnet-pinned AI wrapper,
 * a 7-day transient cache, and an opt-in weekly cron (default OFF), mirroring
 * the inc/insights.php skeleton.
 *
 * Compact payload (~1.5K input tokens): totals + period-over-period deltas +
 * top-10 paths/sources/events + (graceful) edge machine-split. NO per-post
 * substance (that's the advisor's job). Cookieless: only aggregate counts are
 * sent, and the system instruction forbids inferring sessions/journeys.
 *
 * @package SignalNoiseTools
 * @since 6.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_NARRATION_CACHE_KEY',  'sn_insights_narration' );
define( 'SN_NARRATION_CACHE_TTL',  7 * DAY_IN_SECONDS );
define( 'SN_NARRATION_CRON_HOOK',  'sn_insights_narration_weekly' );
define( 'SN_NARRATION_MAX_TOKENS', 512 );

/**
 * Collect the compact 7-day signal projection for the digest prompt.
 *
 * @return array Structured signals (JSON-encoded as the prompt body).
 */
function snt_narration_collect_signals() {
	$to   = gmdate( 'Y-m-d' );
	$from = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ); // inclusive 7-day window

	$signals = array(
		'site'         => array(
			'name'        => (string) sn_setting( 'identity.site_name', get_bloginfo( 'name' ) ),
			'description' => (string) sn_setting( 'identity.site_description', '' ),
			'person'      => (string) sn_setting( 'identity.person_name', '' ),
		),
		'window'       => array(
			'from' => $from,
			'to'   => $to,
			'days' => 7,
		),
		'totals'       => function_exists( 'sn_analytics_range_totals' ) ? sn_analytics_range_totals( $from, $to, 'human' ) : array(),
		'deltas'       => function_exists( 'sn_analytics_period_deltas' ) ? sn_analytics_period_deltas( $from, $to, 'human' ) : array(),
		'engaged_rate' => function_exists( 'sn_analytics_engaged_rate_delta' ) ? sn_analytics_engaged_rate_delta( $from, $to, 'human' ) : array(),
		'top_paths'    => function_exists( 'sn_analytics_top_paths' ) ? sn_analytics_top_paths( $from, $to, 'human', 10 ) : array(),
		'top_sources'  => function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $from, $to, 'human', 10 ) : array(),
		'top_events'   => function_exists( 'sn_analytics_top_events' ) ? sn_analytics_top_events( $from, $to, 10 ) : array(),
		'collected_at' => time(),
	);

	// Edge / machine-traffic signals — included ONLY when the edge rollup is
	// configured and actually saw hits (graceful: returns zeros otherwise, which
	// we omit so the prompt stays honest about what the beacon can vs can't see).
	if ( function_exists( 'sn_edge_machine_split' ) ) {
		$ms = sn_edge_machine_split( $from, $to );
		if ( is_array( $ms ) && ! empty( $ms['edge'] ) ) {
			$signals['machine'] = array(
				'machine_pct' => (int) ( $ms['machine_pct'] ?? 0 ),
				'machine'     => (int) ( $ms['machine'] ?? 0 ),
				'edge_hits'   => (int) ( $ms['edge'] ?? 0 ),
			);
			if ( function_exists( 'sn_edge_range_totals' ) ) {
				$threats = (int) ( sn_edge_range_totals( $from, $to )['threats'] ?? 0 );
				if ( $threats > 0 ) {
					$signals['machine']['threats_blocked'] = $threats;
				}
			}
		}
	}

	return $signals;
}

/**
 * System instruction for the weekly digest. Centralized for one-place tuning.
 *
 * @return string
 */
function snt_narration_system_instruction() {
	return <<<INSTRUCTIONS
You are writing a brief weekly analytics digest for the owner of a personal site. You will receive a JSON blob covering a 7-day window: traffic totals, period-over-period deltas (this week vs the prior 7 days), an engagement-rate delta, the top pages, the top traffic sources, the top custom events, and — only when present — a "machine" block summarizing non-human edge traffic the on-page analytics cannot see.

Write a short, plain digest of what happened this week. Return ONLY a JSON object:

{
  "headline": "<one-line summary of the week; max 120 chars>",
  "paragraphs": ["<2-3 short paragraphs of prose, each 1-3 sentences>"],
  "highlights": ["<2-4 terse bullet facts, each citing a specific number>"]
}

Rules:
- Cite specific numbers and week-over-week changes (e.g. "views up 12% to 1,430"). No vague claims, no marketing fluff, no exclamation marks.
- Lead with the most important change. If traffic was flat, say so plainly.
- Mention machine/bot traffic or blocked threats ONLY if the "machine" block is present in the data.
- COOKIELESS DATA — this site has no per-visitor identity. NEVER infer or mention sessions, user journeys, new-vs-returning visitors, funnels, or per-person paths. "visits" is a per-day visitor-count approximation, not a tracked identity, and cannot be followed across days. Describe only aggregate counts and rates.
- Output JSON only. No preamble, no markdown fences.
INSTRUCTIONS;
}

/**
 * Build the prompt + call the AI wrapper (Sonnet-pinned, tagged 'insights_narration').
 *
 * @param array $signals Collected signals.
 * @return string|WP_Error Raw AI text or WP_Error.
 */
function snt_narration_call_ai( $signals ) {
	if ( ! function_exists( 'snt_ai_generate_with_constraints' ) ) {
		return new WP_Error( 'snt_ai_unavailable', 'AI client not available.', array( 'status' => 503 ) );
	}
	$prompt = wp_json_encode( $signals );
	if ( ! is_string( $prompt ) ) {
		return new WP_Error( 'snt_narration_encode_failed', 'Failed to encode signals as JSON.' );
	}
	return snt_ai_generate_with_constraints( $prompt, snt_narration_system_instruction(), SN_NARRATION_MAX_TOKENS, 'insights_narration' );
}

/**
 * Parse + validate the raw AI digest into { headline, paragraphs[], highlights[] }.
 *
 * Strips optional markdown fences (defensive). Returns WP_Error on a missing
 * headline or empty body. Highlights may be empty.
 *
 * @param string $raw Raw AI text.
 * @return array|WP_Error
 */
function snt_narration_parse_response( $raw ) {
	$text = trim( (string) $raw );

	if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m ) ) {
		$text = trim( $m[1] );
	}

	$decoded = json_decode( $text, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error(
			'snt_narration_invalid_json',
			'AI digest response was not valid JSON.',
			array( 'raw' => substr( $text, 0, 500 ) )
		);
	}

	$headline = ( isset( $decoded['headline'] ) && is_string( $decoded['headline'] ) ) ? trim( $decoded['headline'] ) : '';
	if ( '' === $headline ) {
		return new WP_Error( 'snt_narration_no_headline', 'AI digest is missing a headline.' );
	}
	if ( strlen( $headline ) > 120 ) {
		$headline = substr( $headline, 0, 120 );
	}

	$paragraphs = array();
	if ( isset( $decoded['paragraphs'] ) && is_array( $decoded['paragraphs'] ) ) {
		foreach ( $decoded['paragraphs'] as $p ) {
			if ( is_string( $p ) && '' !== trim( $p ) ) {
				$paragraphs[] = trim( $p );
			}
			if ( count( $paragraphs ) >= 4 ) {
				break;
			}
		}
	}
	if ( empty( $paragraphs ) ) {
		return new WP_Error( 'snt_narration_no_body', 'AI digest had no usable paragraphs.' );
	}

	$highlights = array();
	if ( isset( $decoded['highlights'] ) && is_array( $decoded['highlights'] ) ) {
		foreach ( $decoded['highlights'] as $h ) {
			if ( is_string( $h ) && '' !== trim( $h ) ) {
				$highlights[] = trim( $h );
			}
			if ( count( $highlights ) >= 6 ) {
				break;
			}
		}
	}

	return array(
		'headline'   => $headline,
		'paragraphs' => $paragraphs,
		'highlights' => $highlights,
	);
}

/**
 * Run a digest: collect → call → parse → cache. Returns the cached digest
 * within 7 days unless $force.
 *
 * @param bool $force Bypass the 7-day cache.
 * @return array|WP_Error
 */
function snt_narration_run( $force = false ) {
	if ( ! $force ) {
		$cached = snt_narration_last();
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$started = microtime( true );
	$signals = snt_narration_collect_signals();
	$raw     = snt_narration_call_ai( $signals );
	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$parsed = snt_narration_parse_response( $raw );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}

	$result = array(
		'generated_at' => time(),
		'elapsed_ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
		'headline'     => $parsed['headline'],
		'paragraphs'   => $parsed['paragraphs'],
		'highlights'   => $parsed['highlights'],
	);

	set_transient( SN_NARRATION_CACHE_KEY, $result, SN_NARRATION_CACHE_TTL );
	return $result;
}

/**
 * Read the cached digest, or null if none.
 *
 * @return array|null
 */
function snt_narration_last() {
	$cached = get_transient( SN_NARRATION_CACHE_KEY );
	return is_array( $cached ) ? $cached : null;
}

/**
 * Is the weekly-digest cron opt-in enabled?
 *
 * @return bool
 */
function snt_narration_enabled() {
	return (bool) sn_setting( 'insights.narration_enabled', false );
}

/**
 * Self-healing weekly cron sync: schedule when enabled+unscheduled, unschedule
 * when disabled+scheduled. Runs on every load via the `init` hook, so toggling
 * the setting takes effect on the next request without a dedicated save hook.
 * Separate from the Insights recs cron (different payload projection — no shared
 * collection to amortize), so this never touches inc/insights.php's cron logic.
 *
 * @return void
 */
function snt_narration_maybe_schedule_cron() {
	$on        = snt_narration_enabled();
	$scheduled = wp_next_scheduled( SN_NARRATION_CRON_HOOK );
	if ( $on && ! $scheduled ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', SN_NARRATION_CRON_HOOK );
	} elseif ( ! $on && $scheduled ) {
		wp_unschedule_event( $scheduled, SN_NARRATION_CRON_HOOK );
	}
}
add_action( 'init', 'snt_narration_maybe_schedule_cron' );

/**
 * Explicit unschedule (used by the settings-save handler for an immediate sync).
 *
 * @return void
 */
function snt_narration_unschedule_cron() {
	$ts = wp_next_scheduled( SN_NARRATION_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, SN_NARRATION_CRON_HOOK );
	}
}

/**
 * Cron handler: force a fresh digest (bypasses the 7-day cache).
 *
 * @return void
 */
function snt_narration_weekly_cron_cb() {
	snt_narration_run( true );
}
add_action( SN_NARRATION_CRON_HOOK, 'snt_narration_weekly_cron_cb' );

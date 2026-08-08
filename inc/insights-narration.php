<?php
/**
 * Signal & Noise Tools — Weekly digest narration.
 *
 * A read-only prose "what happened this week" digest — a second output mode
 * over the same first-party analytics the Insights advisor reads, but framed
 * as narrative (what happened) rather than the advisor's 5 structured
 * recommendations (what to do). Reuses the shared Sonnet-pinned AI wrapper
 * and a 7-day transient cache. As of v9.4.1 (annotations R2) the dashboard
 * surface + opt-in weekly cron were retired; the digest now lives only as the
 * two narration Abilities (signal-noise/run-narration + get-narration).
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

// v9.64.2: key versioned _v2 — this cache is a FIXED key (not prompt-hashed
// like the analytics-narrator artifacts), so the voice-contract change could
// not bust it; the bump orphans any pre-voice digest (the old transient just
// expires within its 7-day TTL).
define( 'SN_NARRATION_CACHE_KEY',  'sn_insights_narration_v2' );
define( 'SN_NARRATION_CACHE_TTL',  7 * DAY_IN_SECONDS );
// v9.2.1: raised 512 -> 1024. On real traffic data the model's digest overran
// 512 completion tokens and the JSON truncated mid-response, hard-failing the
// parser (snt_narration_invalid_json). 1024 fits the (brevity-capped) output
// with ample margin; the parser also salvages a truncated response now.
define( 'SN_NARRATION_MAX_TOKENS', 1024 );
// v7.2.2: ephemeral diagnostic — the code + message (+ bounded raw output) of
// the most recent digest FAILURE, so the admin notice reports the REAL error
// instead of the blanket "configure AI" copy. Mirrors SN_INSIGHTS_LAST_ERROR_KEY
// (the v7.0.1 pattern that turned an un-reproducible Insights bug into a
// confirmed truncation fix). Consumed by the next admin render; short TTL.
define( 'SN_NARRATION_LAST_ERROR_KEY', 'sn_narration_last_error' );

// v9.51.2: the digest MUST generate off the request path. snt_narration_run()
// gathers signals (several 6s-capped AE queries) and then makes the plugin's
// single largest AI call (1024 completion tokens over a big signals prompt) —
// which, non-streamed, runs past WordPress's default 30s HTTP timeout on the
// provider ("cURL error 28 ... 0 bytes received", the reason this digest never
// generated when run-narration invoked it inline). Generation now rides a
// near-term single-event cron (snt_narration_schedule → SN_NARRATION_HOOK), and
// the provider call raises its HTTP timeout for that one call (below). Distinct
// from the retired weekly-cron hook (sn_insights_narration_weekly, cleared in
// v9.5.0 by inc/narration-cron-cleanup.php).
define( 'SN_NARRATION_HOOK', 'sn_insights_narration_generate' );
define( 'SN_NARRATION_HTTP_TIMEOUT', 120 );

/**
 * Record the most recent digest failure (code + message + bounded raw model
 * output when the error carries it) for the admin notice. Non-errors ignored.
 *
 * @param WP_Error|mixed $err The failure from snt_narration_run().
 * @return void
 */
function snt_narration_store_last_error( $err ) {
	if ( ! is_wp_error( $err ) ) {
		return;
	}
	$data = $err->get_error_data();
	$raw  = ( is_array( $data ) && isset( $data['raw'] ) ) ? (string) $data['raw'] : '';
	set_transient(
		SN_NARRATION_LAST_ERROR_KEY,
		array(
			'code'    => (string) $err->get_error_code(),
			'message' => (string) $err->get_error_message(),
			'raw'     => substr( $raw, 0, 300 ),
			'at'      => time(),
		),
		15 * 60
	);
}

/**
 * Read the stored digest failure, or null.
 *
 * @return array|null
 */
function snt_narration_last_error() {
	$err = get_transient( SN_NARRATION_LAST_ERROR_KEY );
	return is_array( $err ) ? $err : null;
}

/**
 * Clear the stored digest failure (a successful run supersedes it).
 *
 * @return void
 */
function snt_narration_clear_last_error() {
	delete_transient( SN_NARRATION_LAST_ERROR_KEY );
}

/**
 * Collect the compact 7-day signal projection for the digest prompt.
 *
 * @return array Structured signals (JSON-encoded as the prompt body).
 */
function snt_narration_collect_signals() {
	$to   = gmdate( 'Y-m-d' );
	$from = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ); // inclusive 7-day window

	// v9.68.1: top_sources reports a FAILED durable read as null — this payload
	// feeds an AI prompt, so it degrades to [] (the safe prompt shape); the
	// dashboard surfaces carry the honest read-failure fold instead.
	$top_sources = function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $from, $to, 'human', 10 ) : array();

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
		'top_sources'  => is_array( $top_sources ) ? $top_sources : array(),
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

	// Field Core Web Vitals (v7.2.0) — durable bucket rows (worker v1.8.0 double7,
	// rolled by inc/analytics-buckets.php). Same include-only-when-present contract
	// as the machine block: no vitals rows in the window ⇒ no cwv key, so the
	// prompt never narrates fictional vitals. Band order is Good/NI/Poor by map.
	if ( function_exists( 'sn_analytics_distribution' ) ) {
		$cwv = array();
		foreach ( array( 'lcp', 'inp', 'cls' ) as $vital ) {
			$dist  = sn_analytics_distribution( $vital, $from, $to, 'human' );
			$total = 0;
			foreach ( $dist as $band ) {
				$total += (int) ( $band['views'] ?? 0 );
			}
			if ( $total > 0 ) {
				$cwv[ $vital ] = array(
					'samples'  => $total,
					'good_pct' => (int) round( (int) ( $dist[0]['views'] ?? 0 ) / $total * 100 ),
					'poor_pct' => (int) round( (int) ( $dist[2]['views'] ?? 0 ) / $total * 100 ),
				);
			}
		}
		if ( ! empty( $cwv ) ) {
			$signals['cwv'] = $cwv;
		}
	}

	// Security activity (v7.2.0) — aggregate counts only (cookieless: no IPs, no
	// identities). Guard side: the cached 7-day login-guard headline; audit side:
	// the 7d-vs-prior audit-event totals. All-zero + unconfigured ⇒ no security
	// key, so a quiet week is silence, not narrated zeros.
	$security = array();
	if ( function_exists( 'sn_login_defense_headline' ) ) {
		$lg = sn_login_defense_headline();
		if ( ! empty( $lg['configured'] ) && (int) ( $lg['checked'] ?? 0 ) > 0 ) {
			$guard = array(
				'checked'    => (int) ( $lg['checked'] ?? 0 ),
				'blocked'    => (int) ( $lg['blocked'] ?? 0 ),
				'block_rate' => (int) ( $lg['block_rate'] ?? 0 ),
			);
			if ( function_exists( 'sn_analytics_query' ) && function_exists( 'sn_login_defense_top_country_sql' ) ) {
				$rows    = sn_analytics_query( sn_login_defense_top_country_sql( 7, 1 ) );
				$country = is_array( $rows ) ? (string) ( $rows[0]['country'] ?? '' ) : '';
				if ( '' !== $country ) {
					$guard['top_country'] = $country;
				}
			}
			$security['login_guard'] = $guard;
		}
	}
	if ( function_exists( 'snt_audit_get_summary_impl' ) ) {
		$audit = snt_audit_get_summary_impl();
		$cur   = (int) ( $audit['last_7d_vs_prior']['current'] ?? 0 );
		if ( $cur > 0 ) {
			$security['audit'] = array(
				'events_7d' => $cur,
				'prior_7d'  => (int) ( $audit['last_7d_vs_prior']['prior'] ?? 0 ),
				'pct_delta' => (int) ( $audit['last_7d_vs_prior']['pct_delta'] ?? 0 ),
			);
		}
	}
	if ( ! empty( $security ) ) {
		$signals['security'] = $security;
	}

	// Statistical anomalies (A6) — this week's aggregate totals that landed
	// outside their trailing ~6-week typical range. Same include-only-when-present
	// contract as the other optional blocks: no movers ⇒ no key, so a normal
	// week stays silent instead of narrating manufactured drama.
	$movers = function_exists( 'sn_analytics_baseline_movers' )
		? sn_analytics_baseline_movers( $from, $to, 'human' )
		: array();
	if ( ! empty( $movers ) ) {
		$signals['anomaly_flags'] = $movers;
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
You are writing a brief weekly analytics digest for the owner of a personal site. You will receive a JSON blob covering a 7-day window: traffic totals, period-over-period deltas (this week vs the prior 7 days), an engagement-rate delta, the top pages, the top traffic sources, the top custom events, and — each only when present — a "machine" block summarizing non-human edge traffic the on-page analytics cannot see, a "cwv" block of field Core Web Vitals shares, a "security" block of login-guard/audit aggregates, and an "anomaly_flags" block listing week totals that landed outside their typical range.

Write a short, plain digest of what happened this week. Return ONLY a JSON object:

{
  "headline": "<one-line summary of the week; max 120 chars>",
  "paragraphs": ["<1-2 short paragraphs of plain prose>"],
  "highlights": ["<2-4 terse bullet facts, each citing a specific number>"]
}

Rules:
- Cite specific numbers and week-over-week changes (e.g. "views up 12% to 1,430"). No vague claims, no marketing fluff, no exclamation marks.
- Lead with the most important change. If traffic was flat, say so plainly.
- Mention machine/bot traffic or blocked threats ONLY if the "machine" block is present in the data.
- Mention page-experience / Core Web Vitals ONLY if the "cwv" block is present. good_pct/poor_pct are the share of page-loads in Google's Good/Poor band for that metric this window.
- Mention security activity ONLY if the "security" block is present. These are aggregate counts (login-guard blocks at the edge, audit events on the site); never speculate about attackers or origins beyond the given numbers.
- Mention statistical anomalies ONLY if the "anomaly_flags" block is present. Each flag is an aggregate week metric (views, visits, scroll or dwell) whose value this week is more than two standard deviations from its trailing ~6-week mean. Cite it as the value with its typical range and direction, e.g. "views 1,500, above the typical 990-1,010". typical_low/typical_high bound that range. These are aggregate within-week figures — never per-person, never cross-day.
- VOCABULARY (the totals block; v9.64.1): "views" counts pageviews. "pageview_visits" counts visitor-days with at least one pageview — this is the ONLY number to call "visits". "visits" and "unique_visitor_days" count unique visitor-DAYS with ANY beacon activity, including feed/RSS reads with zero pageviews — call them "visitor-days", never "visits". "viewless_visits" = unique_visitor_days minus pageview_visits (feed readers and beacon-only visits). The same applies in "deltas": its "visits" series compares visitor-days; its "pageview_visits" series is the honest visits delta.
- STRUCTURAL, NOT AN ANOMALY: visitor-days exceeding views is a structural property of this measurement, fully explained by viewless_visits. When you mention the gap, state that explanation (e.g. "90 visitor-days exceed 47 views because 50 visitor-days were viewless: feed readers and beacon-only visits"). NEVER call it unusual, unexplained, or an anomaly, and never write that no explanation exists. The ONLY genuine anomaly of this kind is integrity_violation=true (views below pageview_visits — arithmetically impossible; a data bug worth flagging plainly).
- COOKIELESS DATA — visit metrics (bounce rate, pages/visit, engaged-read %, funnel completion) are WITHIN-DAY aggregates only; describe them as aggregate rates. A "visit" resets at UTC midnight and is never a cross-day identity. NEVER infer or mention new-vs-returning visitors, per-person identity, or following any individual across days.
- VOICE (v9.64.2): the audience is the site owner glancing at this on a phone. Plain English only. NO statistical jargon in prose: never write sigma, σ, backtest, interval, robust, confidence, or point estimate — the analytics screen's chips and footer carry that machinery. State numbers plainly (47 views, 40 visits). Keep the prose to at most 4-5 short plain-English sentences across all paragraphs; the final paragraph may be one line starting "Worth a look:". Mention a forecast only when it is actionable, in plain words ("expect a quiet week"): never as numbers with intervals. A genuine anomaly gets at most one plain sentence.
- NO MARKDOWN in any string value: no asterisks, no underscores, no heading marks, no bullet characters, no emojis — plain prose only.
- Keep the ENTIRE response under 200 words total (headline + all paragraphs + all highlights combined). This is a glance digest, not a report: prefer 2 short paragraphs and 3 highlights over exhausting every signal.
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

	// v9.51.2: raise the provider HTTP timeout for THIS call only — the 1024-token
	// digest overruns the 30s default. Added then removed (never left registered),
	// so no other outbound request is affected. Best-effort: only takes when the
	// AI client routes through the WP HTTP API; harmless otherwise. This runs
	// cron-side (snt_narration_schedule), so a long wait blocks nothing.
	$raise    = static function () { return SN_NARRATION_HTTP_TIMEOUT; };
	$has_hook = function_exists( 'add_filter' ) && function_exists( 'remove_filter' );
	if ( $has_hook ) {
		add_filter( 'http_request_timeout', $raise, 100 );
	}
	try {
		$result = snt_ai_generate_with_constraints( $prompt, snt_narration_system_instruction(), SN_NARRATION_MAX_TOKENS, 'insights_narration' );
	} finally {
		// finally, not a trailing remove — the raised timeout must NEVER outlive
		// this one call, even if the generate path throws.
		if ( $has_hook ) {
			remove_filter( 'http_request_timeout', $raise, 100 );
		}
	}
	return $result;
}

/**
 * Queue a background (cron-side) digest generation, deduped per force value so
 * repeated run-narration calls don't stack events. Returns true if a NEW event
 * was scheduled, false if one was already pending or cron is unavailable. The
 * heavy AI call NEVER runs in the calling request — see snt_narration_call_ai.
 *
 * @param bool $force Bypass the cache when the event fires.
 * @return bool
 */
function snt_narration_schedule( $force = false ) {
	if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
		return false;
	}
	$args = array( (bool) $force );
	if ( false !== wp_next_scheduled( SN_NARRATION_HOOK, $args ) ) {
		return false;
	}
	return (bool) wp_schedule_single_event( time(), SN_NARRATION_HOOK, $args );
}

/**
 * SN_NARRATION_HOOK cron callback: run the (heavy) generation off the request
 * path. Result lands in the 7-day cache; get-narration / the admin surface read
 * it. Errors are already recorded by snt_narration_run's own diagnostics.
 *
 * @param bool $force
 * @return void
 */
function snt_narration_cron_run( $force = false ) {
	if ( function_exists( 'snt_narration_run' ) ) {
		snt_narration_run( (bool) $force );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( SN_NARRATION_HOOK, 'snt_narration_cron_run', 10, 1 );
}

/**
 * Extract the COMPLETE JSON string elements of a named string-array field from
 * loose/truncated model text. Returns each fully-quoted value (a truncated final
 * string has no closing quote, so it is naturally excluded). Bounds the scan to
 * the field's closing "]" when present. Each match is json_decode'd individually
 * so JSON escapes (\", \\, \n) resolve correctly.
 *
 * @param string $text  Raw (fence-stripped) model text.
 * @param string $field Field name ('paragraphs' | 'highlights').
 * @return string[] Complete, non-empty string values (possibly empty).
 */
function snt_narration_salvage_string_array( $text, $field ) {
	if ( ! preg_match( '/"' . preg_quote( $field, '/' ) . '"\s*:\s*\[/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}
	$region = substr( $text, $m[0][1] + strlen( $m[0][0] ) );
	$close  = strpos( $region, ']' );
	if ( false !== $close ) {
		$region = substr( $region, 0, $close );
	}
	if ( ! preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"/', $region, $mm ) ) {
		return array();
	}
	$out = array();
	foreach ( $mm[0] as $quoted ) {
		$val = json_decode( $quoted, true );
		if ( is_string( $val ) && '' !== trim( $val ) ) {
			$out[] = trim( $val );
		}
	}
	return $out;
}

/**
 * Salvage a {headline, paragraphs[], highlights[]} digest from model output that
 * a direct json_decode could not parse — the model hit max_tokens mid-response
 * or wrapped the JSON in prose. Recovers the first complete "headline" plus every
 * complete paragraph/highlight string. Returns null (caller reports the error) if
 * a complete headline and at least one complete paragraph cannot be recovered —
 * so it never fabricates a digest out of nothing. Called ONLY after a direct
 * decode fails, so the happy path is untouched.
 *
 * @param string $text Raw (fence-stripped) model text.
 * @return array|null { headline, paragraphs[], highlights[] } or null.
 */
function snt_narration_salvage_truncated( $text ) {
	$text = (string) $text;
	if ( ! preg_match( '/"headline"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/', $text, $hm ) ) {
		return null; // no complete headline → nothing trustworthy to salvage.
	}
	$headline = json_decode( $hm[1], true );
	if ( ! is_string( $headline ) || '' === trim( $headline ) ) {
		return null;
	}
	$paragraphs = snt_narration_salvage_string_array( $text, 'paragraphs' );
	if ( empty( $paragraphs ) ) {
		return null; // a headline with no complete paragraph is not a usable digest.
	}
	return array(
		'headline'   => trim( $headline ),
		'paragraphs' => $paragraphs,
		'highlights' => snt_narration_salvage_string_array( $text, 'highlights' ),
	);
}

/**
 * Parse + validate the raw AI digest into { headline, paragraphs[], highlights[] }.
 *
 * Strips optional markdown fences (defensive), then json_decodes; if that fails
 * (max_tokens truncation or a prose wrapper), falls back to
 * snt_narration_salvage_truncated() before erroring. Returns WP_Error on a
 * missing headline or empty body. Highlights may be empty.
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
		// v9.2.1: the model hit max_tokens mid-response (or wrapped the JSON in
		// prose), so a direct decode fails. Salvage the complete parts of the
		// known {headline, paragraphs, highlights} object before giving up —
		// mirrors inc/insights.php's truncation salvage (snt_insights_recover_
		// json_array, v7.1.1). Only reached after decode fails, so it never
		// changes the happy path.
		$decoded = snt_narration_salvage_truncated( $text );
	}
	if ( ! is_array( $decoded ) ) {
		return new WP_Error(
			'snt_narration_invalid_json',
			'AI digest response was not valid JSON.',
			array( 'raw' => substr( $text, 0, 500 ) )
		);
	}

	// v9.64.2: the digest strings are rendered as plain text, so markdown marks
	// the model emitted despite the instruction ban are REMOVED here (never
	// escaped) — the single parse boundary every digest passes through.
	$headline = ( isset( $decoded['headline'] ) && is_string( $decoded['headline'] ) ) ? trim( snt_ai_untrusted_display( $decoded['headline'] ) ) : '';
	if ( '' === $headline ) {
		return new WP_Error( 'snt_narration_no_headline', 'AI digest is missing a headline.' );
	}
	if ( strlen( $headline ) > 120 ) {
		$headline = substr( $headline, 0, 120 );
	}

	$paragraphs = array();
	if ( isset( $decoded['paragraphs'] ) && is_array( $decoded['paragraphs'] ) ) {
		foreach ( $decoded['paragraphs'] as $p ) {
			$p = is_string( $p ) ? trim( snt_ai_untrusted_display( $p ) ) : '';
			if ( '' !== $p ) {
				$paragraphs[] = $p;
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
			$h = is_string( $h ) ? trim( snt_ai_untrusted_display( $h ) ) : '';
			if ( '' !== $h ) {
				$highlights[] = $h;
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

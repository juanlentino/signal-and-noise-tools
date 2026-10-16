<?php
/**
 * Signal & Noise Tools, Abilities API: Machine Readers summary (read-only).
 *
 * Exposes the edge sensor's crawler aggregates to AI / automation callers as
 * the same compact glance the Desktop Mode tile renders:
 *
 *   - signal-noise/get-machine-readers-summary
 *
 * The Machine Readers tab (inc/machine-readers-admin.php) is the full picture;
 * this is the answer to "who read this site, and did the declared AI-training
 * crawlers touch the rights files" without a human loading wp-admin.
 *
 * TWO THINGS THIS FILE REFUSES TO DO.
 *
 * 1. Fabricate a zero. The sensor is a remote worker behind a token, so "no
 *    rows" has two very different causes: nobody crawled, or we never asked.
 *    A failed or unconfigured read returns ok:false plus the machine-readable
 *    reason and NO counts at all: an absent total cannot be misread as a
 *    measured zero, which is exactly what an agent would do with `total: 0`.
 *    Same contract the tab's connection callout states in prose.
 *
 * 2. Fork the payload. snt_desktop_machine_readers_payload()
 *    (inc/desktop-mode-payloads.php) already shapes this exact glance for
 *    the tile's REST route, so the tile window delegates to it rather than
 *    keeping a second implementation that would drift the first time either
 *    side gained a field. The DM route hardcodes its 30-day window, so a
 *    caller asking for any other window takes the rebuild path below, which
 *    is the same algorithm over the same helpers.
 *
 * Read-only: reads snt_mr_fetch() (a short display transient in front of one
 * GET) and never writes, never triggers the sensor's own collection.
 *
 * @package SignalNoiseTools
 * @since 10.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The DM tile's window, and this ability's default (the delegation hinge). */
const SN_MR_ABILITY_DEFAULT_DAYS = 30;

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/get-machine-readers-summary', array(
		'label'               => 'Get Machine Readers Summary',
		// Every field's meaning stated here, because an agent picks fields from
		// this text alone: what the numbers count, what they do NOT prove, and
		// what an ok:false response means (see the honesty note in the header).
		'description'         => 'Returns a compact summary of machine (crawler) reads observed at the edge over a window (days: 1-90, default 30): '
			. '`total` reads across all surfaces, `families` (the top 3 crawler families by reads, descending), '
			. '`ai_training` (reads from families whose public declarations class them as AI-training crawlers) and `ai_rights` (the slice of those reads that hit the `rights` surface class: /.well-known/tdmrep.json, /license.xml, /tdm-policy. NOTE: /robots.txt and /llms.txt are their OWN surface classes and are NOT counted here, so a low ai_rights does not mean the crawler ignored every declaration). '
			. '`ai_surfaces` breaks the SAME ai_training reads down per surface class instead of collapsing them into one number, so "did AI-training crawlers fetch robots.txt / llms.txt / the sitemap here" has a direct answer; each entry is `{surface, hits}`, descending by hits, and it is an empty array (never omitted) when no AI-training family read anything in the window. '
			. 'Provenance rides along so the numbers can be judged: `sensor_version` is the deployed edge worker, `crawler_list` is its list-drift verdict (in sync | drift | check failed), null when either document could not be read. '
			. 'User agents are self-reported, so this is observation, never proof of identity. '
			. 'series: "day" (optional) adds `daily`: one entry per UTC date, oldest first, zero-filled, each with day, total, first_party, ai_training, purposes and ai_surfaces that sum to the window figures; the window is rolling, so the first and last dates carry partial: true, and `daily_total_exact` says whether the per-day totals are exact. The sensor records no response status, so there is no ai_training_status. '
			. 'When the sensor is unconfigured or unreachable the response is `ok: false` with a machine-readable `error` (not_configured, blocked, network, bad_schema, http_NNN) and NO counts at all: an absent total means "we never asked", which is not the same claim as zero crawlers. Read-only.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_machine_readers_summary',
		'input_schema'        => array(
			// Accept null: readonly abilities (GET) receive null when the caller
			// omits ?input= (the pattern in abilities-analytics.php).
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array(
					'type'        => 'integer',
					'default'     => SN_MR_ABILITY_DEFAULT_DAYS,
					'minimum'     => 1,
					'maximum'     => 90,
					'description' => 'Window in days; clamped to the sensor\'s own 1-90 range.',
				),
				'series' => array(
					'type'        => 'string',
					'enum'        => array( 'day' ),
					'description' => 'Optional. "day" adds `daily` (per UTC date, oldest first) and `daily_total_exact`. Omitted, the response is unchanged.',
				),
			),
			'additionalProperties' => false,
		),
		// Mirrors snt_desktop_machine_readers_payload()'s response order, with
		// `error` appended. Nothing is `required`: the failure response carries
		// ok/error/days ONLY, deliberately (see the header). Additive only:
		// Desktop Mode normalizes ability schemas at desktop_mode_ai_tools.
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'             => array( 'type' => 'boolean' ),
				'days'           => array( 'type' => 'integer' ),
				'total'          => array( 'type' => 'integer' ),
				// v13.34.0. Declared at the same time as the payload gains them —
				// the purposes lesson: a field the schema does not mention is a field
				// no agent can know exists.
				'truncated'              => array(
					'type'        => 'boolean',
					'description' => 'True when the edge reports the aggregate read hit its row cap. The BREAKDOWNS below may then be partial even when `total` is exact.',
				),
				'total_exact'            => array(
					'type'        => 'boolean',
					'description' => 'True when `total` came from the edge\'s day-only totals view, which cannot truncate. False means it was summed from the aggregate and is a floor, not a count.',
				),
				'days_covered'           => array(
					'type'        => array( 'integer', 'null' ),
					'description' => 'Days the sensor actually holds data for in this window, counted from the day-rows the totals view returned. LESS than `days` means the window reaches past the start of the data, and any period-over-period comparison across it is an artifact of when the sensor started rather than a change in traffic. null when the edge cannot answer.',
				),
				// v13.43.0. Declared in the same change that adds it to the
				// payload — the v13.33.0 lesson, where four fields were returned
				// but undeclared since v10.79.0 and no agent could know the axis
				// existed. This is the axis that answers CONCENTRATION.
				'identity'               => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'Signature verification folded against the dimensions it shares a row with. '
						. '`measured` is reads carrying a real state; `valid` / `invalid` / `unknown_key` / `unsigned` split it. '
						. '`by_agent` and `by_surface` list VERIFIED reads only, `{agent|surface, hits}` descending — an agent whose signature failed never appears there, because listing it beside one that passed would read as proof of the opposite. '
						. 'null, NOT a block of zeros, when no read in the window carried a signature state at all: that is "never measured", which is a different claim from "measured, none verified" (which reports valid 0 with empty leaderboards). '
						. 'Use by_agent to tell an ecosystem from a single signer, and by_surface to tell genuine adoption from traffic to a newly-served endpoint. '
						. 'Basis: signed (a Web Bot Auth signature checked out). Signed is not verified: `verification` is Cloudflare\'s verified-bot category, a different source.',
				),
				'families'       => array(
					'type'        => 'array',
					'description' => 'The top 3 families by reads. Basis claimed: the family is what the user agent said. first_party_hits is the part of a family that is this site\'s own tools (the sensor\'s first_party flag); unclassified-machine is mostly that. Hits presenting a robots.txt control token are credited to no family (see impersonations).',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'family'           => array( 'type' => 'string' ),
							'hits'             => array( 'type' => 'integer' ),
							'first_party_hits' => array( 'type' => 'integer' ),
						),
					),
				),
				'ai_training'    => array(
					'type'        => 'integer',
					'description' => 'LEGACY, family-based: reads from the hand-curated AI-training family set (openai, anthropic, google-ai, commoncrawl, bytedance, apple-ai, meta-ai, mistral, cohere, allen-ai) whatever each agent read for, so it counts search, user and unmapped agents inside those families (openai-searchbot, openai-chatgpt-user, mistral-user, cohere-ai, google-other) and misses training agents outside them (amazon-bot). Kept because a published figure used it; prefer ai_training_by_purpose. Control-token hits are not counted. Basis claimed.',
				),
				'ai_rights'      => array( 'type' => 'integer' ),
				'ai_surfaces'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'surface' => array( 'type' => 'string' ),
							'hits'    => array( 'type' => 'integer' ),
						),
					),
				),
				// ADDITIVE (v13.33.0). These four have been in the payload since
				// v10.79.0 (snt_mr_summary_payload()) but were never declared, so an
				// agent reading this schema could not know the purpose axis exists —
				// the axis the surface's own docs call the defensible one. Declaring
				// them changes no value; it stops the contract understating the payload.
				'purposes'               => array(
					'type'        => array( 'array', 'null' ),
					'description' => 'Reads per declared purpose, highest first, basis claimed. First-party reads and control-token hits are left out. Purpose `unknown` is UNMAPPED: the taxonomy has no entry for the reader (displayed as "unmapped"). null when the taxonomy is unavailable — never an empty array, which would read as "measured zero".',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'purpose' => array( 'type' => 'string' ),
							'hits'    => array( 'type' => 'integer' ),
						),
					),
				),
				'ai_training_by_purpose' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => 'AI-training reads counted on the PURPOSE axis: purpose train, first-party reads and control-token hits left out, basis claimed. verification.ai_training_by_purpose says how much of it Cloudflare verified. Reported beside ai_training rather than replacing it: the frozen families over-count, and the gap between the two is the over-count made visible.',
				),
				'first_party'            => array(
					'type'        => array( 'integer', 'null' ),
					'description' => 'Reads from this site\'s own tools (the sensor\'s first_party flag, basis claimed). Not readership: left out of purposes and ai_training_by_purpose; counted in total and in families, where first_party_hits shows it.',
				),
				'taxonomy'               => array(
					'type'        => array( 'string', 'null' ),
					'description' => 'Version of the published cohort definition the counts were derived under.',
				),
				'sensor_version' => array( 'type' => array( 'string', 'null' ) ),
				'crawler_list'   => array( 'type' => array( 'string', 'null' ) ),
				// Contract 16: every attributed count says how it is known.
				'impersonations'         => array(
					'type'        => array( 'array', 'null' ),
					'description' => 'Hits presenting a robots.txt control token (Google-Extended, Applebot-Extended), which is never a crawler of its own, so they are credited to no vendor, family or purpose. {agent, token, hits, networks (top 5 {network, hits}), basis: claimed}. They still count in total. null when the taxonomy is unavailable.',
					'items'       => array( 'type' => 'object' ),
				),
				'verification'           => array(
					'type'        => 'object',
					'description' => 'Each attributed count split by Cloudflare\'s verified-bot category, the rights evidence\'s source and since date: {claimed, verified, verified_matched, unverified, unverifiable}, where claimed = verified + unverified + unverifiable. verified: the category was set; verified_matched: and it fits the claimed purpose (train: AI Crawler; search: Search Engine Crawler or AI Search; unknown and dev never match); unverifiable: a day before since, or the partial day it began. `families` is keyed by family (the rows `families` counts), `purposes` by purpose and `ai_training_by_purpose` is its train entry (the rows `purposes` counts, null without the taxonomy), `ai_training` the legacy family figure. Verified is not signed (see identity).',
					'properties'  => array(
						'source'                 => array( 'type' => 'string' ),
						'since'                  => array( 'type' => array( 'string', 'null' ) ),
						'basis'                  => array( 'type' => 'object' ),
						'families'               => array( 'type' => 'object' ),
						'purposes'               => array( 'type' => array( 'object', 'null' ) ),
						'ai_training'            => array( 'type' => 'object' ),
						'ai_training_by_purpose' => array( 'type' => array( 'object', 'null' ) ),
					),
				),
				'basis'                  => array(
					'type'        => 'object',
					'description' => 'The evidence basis of each attributed field: signed (a cryptographic signature checked out), verified (Cloudflare\'s verified-bot category), claimed (the user agent the client sent), inferred (a rule or a score), unmeasured (not recorded).',
				),
				'reader_families'        => array(
					'type'        => 'array',
					'description' => 'The top 3 families by reads from readers: this site\'s own tools and control-token hits left out, so the top family is a reader, never the site\'s own probes. {family, hits (claimed), verified (Cloudflare)}.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'family'   => array( 'type' => 'string' ),
							'hits'     => array( 'type' => 'integer' ),
							'verified' => array( 'type' => 'integer' ),
						),
					),
				),
				'error'          => array( 'type' => array( 'string', 'null' ) ),
				// 23.5.0: present ONLY when the call passed series: "day".
				'daily'                  => array(
					'type'        => 'array',
					'description' => 'Present only with series: "day". One entry per UTC date in the window, oldest first, zero-filled: a date with no reads is zeros and empty lists, not a gap. The window is rolling (now minus `days`), so it touches days+1 dates and the first and last carry partial: true. Each field sums to its window figure. Dates before the sensor held data also read zero; days_covered says how many dates it holds. When `truncated` is true the per-day breakdowns may be partial, and the aggregate keeps its oldest rows first, so the newest days lose rows first. No response status is recorded by the sensor, so there is no per-status split.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'day'         => array( 'type' => 'string' ),
							'total'       => array( 'type' => 'integer' ),
							'first_party' => array( 'type' => array( 'integer', 'null' ) ),
							'ai_training' => array( 'type' => 'integer' ),
							'purposes'    => array( 'type' => array( 'array', 'null' ) ),
							'ai_surfaces' => array( 'type' => 'array' ),
							'partial'     => array( 'type' => 'boolean' ),
						),
					),
				),
				'daily_total_exact'      => array(
					'type'        => 'boolean',
					'description' => 'Present only with series: "day". True when each day\'s total comes from the exact day-only totals view; false when it was summed from the aggregate and is a floor.',
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'        => true,
				'destructive'     => false,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
} );

/**
 * Ability execute callback: signal-noise/get-machine-readers-summary.
 *
 * Clamps the window to the sensor's own 1-90 range, then either delegates to
 * the Desktop Mode tile route (its window, its payload) or rebuilds the same
 * glance for the window actually asked for.
 *
 * @param array|null $input Optional. { days?: int }.
 * @return array The tile payload (see the output_schema above). ok:false plus
 *               `error` and no counts when the sensor did not answer.
 */
function snt_ability_get_machine_readers_summary( $input ) {
	$input = is_array( $input ) ? $input : array();
	$days  = isset( $input['days'] ) ? (int) $input['days'] : 30;
	$days  = max( 1, min( 90, $days ) );
	$series = $input['series'] ?? null;
	if ( null !== $series && 'day' !== $series ) {
		return new WP_Error( 'ability_invalid_input', 'series must be "day" or omitted.', array( 'status' => 400 ) );
	}
	// v10.2.0: ONE builder, no fork. snt_mr_summary_payload() lives beside the
	// fetch it uses and is the single source both this ability and the desktop
	// tile route read, so neither can drift when the other gains a field.
	return snt_mr_summary_payload( $days, $series );
}


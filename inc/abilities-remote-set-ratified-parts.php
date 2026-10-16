<?php
/**
 * Signal & Noise Tools: the remote set, the three ratified twins (v13.52.0).
 *
 * Split from inc/abilities-remote-set.php (house line cap, 2026-10-13); a pure
 * move, no code changed. The file header there carries the isolation rules every
 * twin here follows. Loaded by require_once at the end of that file, in the
 * order the registrations always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission callbacks for the v13.52.0 twins. Each passes its own slug as a
 * LITERAL — same reasoning as every callback in inc/abilities-remote-set.php: a permission callback is
 * handed only the ability's arguments, never its own name.
 */
function snt_ability_perm_remote_provenance_integrity() {
	return sn_remote_analytics_allows( 'signal-noise/remote-provenance-integrity-status' );
}
function snt_ability_perm_remote_machine_readers() {
	return sn_remote_analytics_allows( 'signal-noise/remote-machine-readers-summary' );
}
function snt_ability_perm_remote_cron_health() {
	return sn_remote_analytics_allows( 'signal-noise/remote-cron-health-summary' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	/* ── v13.52.0 — the three ratified twins (D-rulings in docs/BACKLOG.md).
	 * Each output_schema is copied BYTE-IDENTICALLY from its admin
	 * registration; tests/abilities-remote-set.php enforces === on the pair.
	 * anchor is deliberately ABSENT: RULED LOCAL (D1) — its payload can name
	 * unpublished post titles and the parity rule forbids narrowing.
	 * ────────────────────────────────────────────────────────────────── */

	wp_register_ability( 'signal-noise/remote-provenance-integrity-status', array(
		'label'               => 'Get provenance integrity status (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/provenance-integrity-status. '
			. 'Latest stored integrity sweep: fleet/checked/clean/failed/unreachable '
			. 'counts plus the failing list. The sweep is post_status=publish, so '
			. 'failing[] can only name public titles. Served from the stored sweep; '
			. 'nothing is probed on request. Read-only. Reachable only by a principal '
			. 'holding the sn_read_remote_analytics capability, and only while the '
			. 'remote door is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_provenance_integrity',
		'execute_callback'    => 'snt_ability_provenance_integrity_status',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from inc/provenance-integrity.php.
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'swept_at'    => array( 'type' => 'integer' ),
				'fleet'       => array( 'type' => 'integer' ),
				'checked'     => array( 'type' => 'integer' ),
				'clean'       => array( 'type' => 'integer' ),
				'failed'      => array( 'type' => 'integer' ),
				'unreachable' => array( 'type' => 'integer' ),
				'keys'        => array( 'type' => 'string' ),
				'failing'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'      => array( 'type' => 'integer' ),
							'uid'          => array( 'type' => 'string' ),
							'title'        => array( 'type' => 'string' ),
							'url'          => array( 'type' => 'string' ),
							'version'      => array( 'type' => 'integer' ),
							'failures'     => array( 'type' => 'array' ),
							'last_checked' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-machine-readers-summary', array(
		'label'               => 'Get machine readers summary (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-machine-readers-summary. '
			. 'Aggregate crawler reads over a window: totals, top families, the '
			. 'AI-training slice and its per-surface breakdown. Counts only — no post '
			. 'bodies, no UA samples. series: "day" (optional) adds `daily`: one entry per UTC date, oldest first, zero-filled, each with day, total, first_party, ai_training, purposes and ai_surfaces that sum to the window figures; the window is rolling, so the first and last dates carry partial: true, and `daily_total_exact` says whether the per-day totals are exact. The sensor records no response status, so there is no ai_training_status. Read-only. Reachable only by a principal holding '
			. 'the sn_read_remote_analytics capability, and only while the remote door '
			. 'is explicitly enabled.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_remote_machine_readers',
		'execute_callback'    => 'snt_ability_get_machine_readers_summary',
		'input_schema'        => array(
			// The admin ability accepts days (1-90). The twin carries it too —
			// a window is a read parameter, not a lever.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 30 ),
				'series' => array( 'type' => 'string', 'enum' => array( 'day' ) ),
			),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from inc/abilities-machine-readers.php.
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
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-cron-health-summary', array(
		'label'               => 'Cron health, summarized (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/cron-health-summary — the '
			. 'model-never-levers product of the 2026-08-11 partition review. Status '
			. '(good|recommended|critical), a summary sentence derived from the same '
			. 'counts, and the overdue/missing evidence. No run levers exist on this '
			. 'surface: reading a verdict is not causing one. Read-only. Reachable '
			. 'only by a principal holding the sn_read_remote_analytics capability, '
			. 'and only while the remote door is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_cron_health',
		'execute_callback'    => 'snt_ability_cron_health_summary',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from inc/abilities-cron.php.
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'                     => array( 'type' => 'boolean' ),
				'status'                 => array( 'type' => 'string', 'enum' => array( 'good', 'recommended', 'critical' ) ),
				'checked_at'             => array( 'type' => 'integer' ),
				'recurring'              => array( 'type' => 'integer', 'description' => 'Recurring jobs the dashboard expects to fire.' ),
				'on_schedule'            => array( 'type' => 'integer' ),
				'overdue'                => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'job'        => array( 'type' => 'string' ),
							'cadence'    => array( 'type' => 'integer', 'description' => 'Seconds between expected firings.' ),
							'overdue_by' => array( 'type' => 'integer', 'description' => 'Seconds past the expected firing.' ),
						),
					),
				),
				'missing'                => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Expected recurring jobs with no schedule at all.' ),
				'cron_disabled_constant' => array( 'type' => 'boolean' ),
				'summary'                => array( 'type' => 'string', 'description' => 'Derived from the counts above in the same function; cannot drift from them.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );

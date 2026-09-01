<?php
/**
 * Signal & Noise Tools — Abilities API: sn_status (read-door coherence,
 * owner-reopened consolidation 2026-08-25).
 *
 * "Is the site healthy?" costs ten door calls today. This tool answers the
 * whole question in one, using the SECTIONED-BATCH pattern sn-site-facts
 * proved (caller names the sections it wants; each answer keeps its source's
 * exact shape under its own key) — deliberately NOT the original spec's
 * sn_health, whose five-way merge of disjoint return shapes the wave-2
 * verdict sheet rejected as straining the merge-by-return-shape rule.
 *
 * Registered NEW ALONGSIDE OLD: all ten source abilities stay doored until a
 * telemetry window justifies a wave-4 retirement, exactly as waves 1–2 did.
 *
 * Every section dispatches through snt_sn_site_facts_dispatch() (the
 * check_permissions() → execute() sequence with uniform degradation), so an
 * unregistered or refusing source degrades that ONE section to
 * {error:"unavailable"} while every other requested section still returns.
 * The call itself only errors (a real WP_Error) on invalid INPUT: an
 * empty/unknown sections[] entry, or a missing `hook` when cron_history was
 * requested.
 *
 * THE R1 LESSON, APPLIED AT WRITE TIME instead of at review time:
 * get-cron-history's input_schema REQUIRES `hook` (inc/abilities-cron.php —
 * verified by reading the live registration, not the spec). Dispatched with
 * no args it would 422 at the source and degrade to {error:'unavailable'}
 * forever — sn-site-facts shipped exactly that bug for active_template and
 * needed an adversarial review round to catch it. So `hook` is a top-level
 * input required when (and only when) cron_history is requested, mirroring
 * sn-site-facts' slug contract.
 *
 * @package SignalNoiseTools
 * @since 13.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-status', array(
		'label'               => 'Batch-read operational status (consolidated)',
		'description'         => 'One coherent answer to "what is the site\'s operational state?" — a sectioned batch over the ten narrow status reads: uptime (Better Stack readout), deploy (worker/plugin/theme deploy state), health_scan (last cached content-health summary; null when no scan has run), anchor (provenance OTS anchoring state), provenance_integrity (ledger integrity readout), ipv6_criterion (the pre-committed login-defense gauge: share_pct, measured_days, window_complete, and a named decision), ai_cache_probe (whether Anthropic prompt caching would pay, from recorded calls), cadence (publish + cron rhythm deviations), cron_scheduled (currently scheduled cron events), and cron_history (recorded firings of ONE hook — `hook` is REQUIRED input when this section is requested, because the source ability has no all-hooks default; 400 if missing, ignored by every other section). Pass the subset you need in `sections`; each entry in the returned map carries its source ability\'s exact payload shape — this tool never reshapes, so answers match the narrow tools byte-for-byte. If a source is unregistered or refuses, that ONE section degrades to {error:"unavailable"} while the rest still return. The call only fails as a whole on invalid input (empty/unknown sections, or the missing cron_history hook).',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_sn_status',
		'input_schema'        => array(
			'type'                 => 'object', // 'sections' is required — no bodyless-GET null union (sn-site-facts precedent).
			'required'             => array( 'sections' ),
			'properties'           => array(
				'sections' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => array_keys( snt_sn_status_map() ),
					),
					'minItems' => 1,
				),
				'hook'     => array(
					'type'        => 'string',
					'description' => 'Required when sections includes cron_history (400 if missing or blank). Ignored by every other section.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'sections' => array( 'type' => 'object' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/**
 * section name => source ability slug. Single source of truth for the
 * input_schema enum and the dispatch loop. All ten sources are PLUGIN
 * abilities — unlike sn-site-facts, no section crosses into the theme.
 *
 * @return array<string,string>
 */
function snt_sn_status_map() {
	return array(
		'uptime'               => 'signal-noise/uptime-status',
		'deploy'               => 'signal-noise/get-deploy-status',
		'health_scan'          => 'signal-noise/get-health-scan',
		'anchor'               => 'signal-noise/anchor-status',
		'provenance_integrity' => 'signal-noise/provenance-integrity-status',
		'ipv6_criterion'       => 'signal-noise/login-defense-ipv6-criterion',
		'ai_cache_probe'       => 'signal-noise/ai-cache-probe-status',
		'cadence'              => 'signal-noise/cadence-flags',
		'cron_scheduled'       => 'signal-noise/list-cron-events',
		'cron_history'         => 'signal-noise/get-cron-history',
		// v13.52.0 — the MODEL over the two rows above: status + derived summary
		// + overdue/missing evidence, sharing the Site Health overdue rule. This
		// is the section the remote door twins; the detail rows stay local.
		'cron_health'          => 'signal-noise/cron-health-summary',
		// v13.44.0. Operational state, beside deploy and health_scan.
		'collector'            => 'signal-noise/get-collector-status',
		// manage_options, so it belongs here and NOT on sn-validate, which is
		// read_corpus — that placement would cross permission tiers.
		'corpus_integrity'     => 'signal-noise/corpus-integrity-scan',
		// v13.57.0 — measurement weave Phase 1: Search Console on the read door
		// as SECTIONS (default-to-consolidated-reads), over data the daily sync
		// already stores. Before this, GSC sat on ZERO doors.
		'search_performance'   => 'signal-noise/search-performance',
		'search_drift'         => 'signal-noise/search-drift',
		'search_crossexam'     => 'signal-noise/search-crossexam',
	);
}

/**
 * Ability execute callback: signal-noise/sn-status.
 *
 * @param array|null $input { sections: string[], hook?: string }.
 * @return array{ok:bool,sections:array}|WP_Error
 */
function snt_ability_sn_status( $input ) {
	$input = is_array( $input ) ? $input : array();
	$map   = snt_sn_status_map();

	$sections = isset( $input['sections'] ) ? array_values( array_unique( array_map( 'strval', (array) $input['sections'] ) ) ) : array();
	if ( empty( $sections ) ) {
		return new WP_Error( 'snt_status_empty', __( 'sections must be a non-empty array.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$unknown = array_values( array_diff( $sections, array_keys( $map ) ) );
	if ( ! empty( $unknown ) ) {
		return new WP_Error(
			'snt_status_unknown',
			sprintf(
				/* translators: %s: comma-separated list of unrecognized section names. */
				__( 'Unknown section(s): %s', 'signal-and-noise-tools' ),
				implode( ', ', $unknown )
			),
			array( 'status' => 422 )
		);
	}

	$hook = isset( $input['hook'] ) ? trim( (string) $input['hook'] ) : '';
	if ( in_array( 'cron_history', $sections, true ) && '' === $hook ) {
		return new WP_Error(
			'snt_status_missing_hook',
			__( 'hook is required when requesting cron_history — the source ability records history per hook and has no all-hooks default.', 'signal-and-noise-tools' ),
			array( 'status' => 400 )
		);
	}

	$out = array();
	foreach ( $sections as $section ) {
		$args = ( 'cron_history' === $section ) ? array( 'hook' => $hook ) : array();
		$out[ $section ] = snt_sn_site_facts_dispatch( $map[ $section ], $args );
	}

	return array(
		'ok'       => true,
		'sections' => $out,
	);
}

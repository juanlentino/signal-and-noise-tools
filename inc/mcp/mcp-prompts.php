<?php
/**
 * Signal & Noise — MCP server: prompts (v9.50.0). Two ready-made prompts that
 * chain several already-allowlisted read-door tools into one owner-voiced
 * synthesis a client can run in a single step, exposed over prompts/list +
 * prompts/get on BOTH doors. Sub-project B.
 *
 * Both prompts are static PHP strings — no AI call happens server-side and
 * none of the abilities they name are ever executed here. The returned
 * message just instructs whichever CLIENT is asking to make those tool calls
 * itself and do the synthesis. Placeholder-free by construction: there is no
 * templating step to leave a stray {{token}} behind.
 *
 * @package SignalNoiseTools
 * @since 9.50.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The prompts/list result: the 2 R3 prompt descriptors, identical on both
 * doors.
 *
 * @return array{prompts:array<int,array<string,string>>}
 */
function sn_mcp_prompts_list() {
	return array(
		'prompts' => array(
			array(
				'name'        => 'weekly-report',
				'description' => 'Owner-voiced weekly digest, synthesized from analytics, RSS, uptime, narration, and insights tool calls.',
			),
			array(
				'name'        => 'content-audit',
				'description' => 'Prioritized content-health findings, synthesized from the health, block-migrations, and pattern-adoption scan tool calls.',
			),
		),
	);
}

/**
 * Resolve a prompts/get request. Returns null for an unrecognized name — the
 * caller (sn_mcp_handle_request) maps that to a -32602 JSON-RPC error (R4).
 * $arguments is accepted for MCP shape conformance; neither prompt takes one
 * in v1, so it is unused.
 *
 * @param string $name
 * @param array  $arguments
 * @return array{description:string,messages:array<int,array<string,mixed>>}|null
 */
function sn_mcp_prompt_get( $name, $arguments = array() ) {
	switch ( (string) $name ) {
		case 'weekly-report':
			return sn_mcp_prompt_message(
				"Owner-voiced weekly digest, synthesized from this site's own read tools.",
				'Compile this week\'s digest for the site owner. Call get-analytics-summary, get-analytics-events, get-rss-stats, and uptime-status, then synthesize the results in the owner\'s own voice: direct, concrete numbers, no filler. Any of them can return sparse or empty data for a quiet week; say so plainly instead of inventing a value. Structure the reply as a one-paragraph headline, a short bulleted list of the week\'s numbers, and a closing line on uptime health.'
			);

		case 'content-audit':
			return sn_mcp_prompt_message(
				"Prioritized content-health findings, synthesized from this site's own scan tools.",
				'Run a content-health audit. Call get-health-scan, then sn-scan once per scan_type you need — start with block_migrations and pattern_adoption (sn-scan takes exactly ONE scan_type per call, by design). Merge the findings into ONE prioritized list, highest-impact first: each item naming which scan surfaced it plus a one-line fix suggestion. Any source can return an empty or null result if no scan has run yet or nothing was flagged; state that plainly rather than fabricating findings.'
			);

		default:
			return null;
	}
}

/**
 * Build a single-message prompts/get result: one user-role text message —
 * every prompt this server serves reduces to "here is what to call and how
 * to synthesize it", so one message has always been enough.
 *
 * @param string $description
 * @param string $text
 * @return array{description:string,messages:array<int,array<string,mixed>>}
 */
function sn_mcp_prompt_message( $description, $text ) {
	return array(
		'description' => (string) $description,
		'messages'    => array(
			array(
				'role'    => 'user',
				'content' => array( 'type' => 'text', 'text' => (string) $text ),
			),
		),
	);
}

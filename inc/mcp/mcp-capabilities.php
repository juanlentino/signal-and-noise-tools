<?php
/**
 * Signal & Noise — MCP server: capabilities (allowlist SoT ×2, door identity,
 * protocol version, server identity). Pure data; no side effects. Sub-project
 * B of the machine-readability program (see
 * docs/superpowers/specs/2026-07-11-*B-mcp-*), widened in v9.50.0 to a second
 * door (see inc/mcp/mcp-endpoint.php).
 *
 * @package SignalNoiseTools
 * @since 9.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_MCP_PROTOCOL_VERSION' ) ) {
	// Fallback MCP protocol revision. The endpoint echoes the client's requested
	// version when we support it (sn_mcp_negotiate_version), so this is only used
	// when the client asks for something we don't recognize.
	define( 'SN_MCP_PROTOCOL_VERSION', '2025-06-18' );
}

// Door identifiers. The door is per-request CONTEXT — a parameter threaded
// through allowlist resolution, tool projection, and the call gate — never a
// mutable global. Every sn_mcp_* function that behaves differently per door
// takes $door as its last argument, defaulting to the read door (the door
// nothing existed before v9.50.0 could reach).
if ( ! defined( 'SN_MCP_DOOR_READ' ) ) {
	define( 'SN_MCP_DOOR_READ', 'read' );
}
if ( ! defined( 'SN_MCP_DOOR_RW' ) ) {
	define( 'SN_MCP_DOOR_RW', 'rw' );
}

/**
 * The read-door allowlist: ability slugs exposed as read-only MCP tools. This
 * is a security gate — tools/list advertises exactly these and tools/call
 * rejects anything not here (per sn_mcp_is_allowed, for the read door).
 * Cross-namespace (plugin + theme) resolves through the one global
 * WP_Abilities_Registry. Widened 15 → 23 in v9.50.0 (docs/ai-abilities-catalog
 * audit) and 23 → 25 in v9.82.0 (anchor-status, provenance-integrity-status);
 * the read-only-by-construction guarantee is unchanged — every slug here is
 * PURE-READ or READ-REMOTE by curation, never a write/action/AI-billed
 * ability (those live on the rw door only, see sn_mcp_rw_allowlist).
 *
 * @return string[]
 */
function sn_mcp_allowlist() {
	$slugs = array(
		// Plugin (signal-noise/) — operational reads.
		'signal-noise/get-health-scan',
		'signal-noise/uptime-status',
		'signal-noise/get-deploy-status',
		'signal-noise/get-analytics-summary',
		'signal-noise/get-rss-stats',
		'signal-noise/get-cron-history',
		'signal-noise/list-cron-events',
		'signal-noise/get-insights',
		'signal-noise/get-narration',
		'signal-noise/get-analytics-events',
		'signal-noise/block-migrations-scan',
		'signal-noise/pattern-adoption-scan',
		'signal-noise/list-template-overrides',
		// v9.82.0 — operational status. Both readonly, both sub-second, and what
		// they return is status an agent should be able to see for itself.
		'signal-noise/anchor-status',
		'signal-noise/provenance-integrity-status',
		// Theme (signal-and-noise/) — identity + design system.
		'signal-and-noise/get-theme-version',
		'signal-and-noise/get-latest-theme-tag',
		'signal-and-noise/get-design-tokens',
		'signal-and-noise/get-design-system-summary',
		'signal-and-noise/get-active-template-structure',
		'signal-and-noise/list-block-patterns',
		'signal-and-noise/get-seo-route-meta',
		'signal-and-noise/get-llms-txt',
		'signal-and-noise/get-page-notes-pillars',
		'signal-and-noise/get-reading-time-for-slug',
	);

	/**
	 * Filter the read-door MCP tool allowlist. Callbacks MUST return slugs of
	 * abilities that are safe to expose read-only over an admin-gated endpoint.
	 *
	 * @param string[] $slugs
	 */
	return (array) apply_filters( 'sn_mcp_allowlist', $slugs );
}

/**
 * The rw-door allowlist: the owner-approved safe set of write/action/AI-billed
 * abilities exposed over POST /mcp-rw (v9.50.0). Recounted directly against
 * `abilities-audit-2026-07-15.md`: every ability the audit recommends RW-DOOR,
 * MINUS the three the owner holds back (destructive, later explicit opt-in —
 * never `run-cron-event`, which the audit itself excludes from every MCP
 * surface: unbounded do_action() on any non-sn_* hook), PLUS the two
 * audit-log reads the owner chose to gate behind the rw door instead of the
 * read door (plaintext usernames — see the audit's PII flags on
 * get-audit-log/export-audit-log). Widened to 35 in v9.82.0 by anchor-sweep
 * (30 plugin + 5 theme); the read-door 25 are never duplicated in here — a
 * client wanting reads uses the read door.
 *
 * Held OUT on purpose (present on neither door — verify with
 * sn_mcp_is_allowed before ever touching this list):
 *   - signal-noise/run-cron-event        — unbounded do_action() dispatch.
 *   - signal-noise/ai-orphan-apply       — permanent delete, skips trash, no undo.
 *   - signal-noise/merge-tags            — sitewide term reassign + delete.
 *   - signal-noise/clear-template-overrides — wipes Site Editor template rows.
 *   - signal-noise/run-health-scan       — too slow to survive the wire. The MCP
 *     layer dispatches synchronously with no timeout and no execution budget;
 *     the scan takes roughly 35s today and up to ~105s when something is
 *     actually down, and Cloudflare's ~100s edge cap sits in front of it. Doored,
 *     it would hand an agent a tool that hangs and then dies at the edge with
 *     nothing to show for the wait. Nothing is lost by holding it back: the
 *     results are already reachable through the doored read ability
 *     get-health-scan, and the scan runs on cron whether or not anyone asks.
 *
 * @return string[]
 */
function sn_mcp_rw_allowlist() {
	$slugs = array(
		// Plugin (signal-noise/) — 30.
		'signal-noise/ai-alt-suggest',
		'signal-noise/ai-alt-apply',
		'signal-noise/ai-drift-suggest',
		'signal-noise/ai-drift-apply',
		'signal-noise/ai-alt-inline-suggest',
		'signal-noise/ai-orphan-suggest',
		'signal-noise/ai-link-suggest',
		'signal-noise/ai-link-apply',
		'signal-noise/ai-pair-suggest',
		'signal-noise/pattern-adoption-suggest',
		'signal-noise/pattern-adoption-apply',
		'signal-noise/ai-generate-excerpt',
		'signal-noise/ai-generate-meta-description',
		'signal-noise/ai-generate-og-card-title',
		'signal-noise/run-audit-prune',
		'signal-noise/get-audit-log',
		'signal-noise/export-audit-log',
		'signal-noise/block-migrations-apply',
		'signal-noise/block-migrations-suggest',
		'signal-noise/suggest-tags',
		'signal-noise/prune-unused-tags',
		'signal-noise/regenerate-og-card',
		'signal-noise/unschedule-cron-event',
		'signal-noise/dismiss-candidate',
		'signal-noise/run-insights-scan',
		'signal-noise/run-narration',
		'signal-noise/prepop-dismiss',
		'signal-noise/draft-release-notes',
		'signal-noise/purge-all-caches',
		// v9.82.0 — not readonly, but idempotent: one bounded wp_remote_post
		// (timeout 20) asking the provenance Worker to upgrade already-confirmed
		// proofs. The rw door's kill switch, app-password binding, rate limit,
		// and audit trail are exactly the envelope this wants.
		'signal-noise/anchor-sweep',
		// Theme (signal-and-noise/) — 5, all AI-billed + return-only.
		'signal-and-noise/ai-generate-page-note-summary',
		'signal-and-noise/ai-suggest-block-pattern',
		'signal-and-noise/ai-validate-brand-alignment',
		'signal-and-noise/ai-generate-pattern-content',
		'signal-and-noise/ai-rewrite-in-brand-voice',
	);

	/**
	 * Filter the rw-door MCP tool allowlist. Callbacks MUST NOT add
	 * run-cron-event or the three held-back destructive/blast-radius slugs
	 * documented on sn_mcp_rw_allowlist().
	 *
	 * @param string[] $slugs
	 */
	return (array) apply_filters( 'sn_mcp_rw_allowlist', $slugs );
}

/**
 * Resolve the allowlist for a door. The one place a door identifier turns
 * into a concrete slug list — every per-door consumer (tools/list, tools/call,
 * and the future resources/prompts handlers) calls through here rather than
 * branching on the door itself.
 *
 * @param string $door SN_MCP_DOOR_READ or SN_MCP_DOOR_RW.
 * @return string[]
 */
function sn_mcp_allowlist_for_door( $door ) {
	return SN_MCP_DOOR_RW === $door ? sn_mcp_rw_allowlist() : sn_mcp_allowlist();
}

/**
 * Is a slug exposed over MCP on the given door? Gates tools/call, not just
 * tools/list — a slug excluded from both allowlists (the held trio,
 * run-cron-event) is unknown regardless of which door asks, and an rw-only
 * slug is unknown on the read door even if named directly.
 *
 * @param string $slug
 * @param string $door SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return bool
 */
function sn_mcp_is_allowed( $slug, $door = SN_MCP_DOOR_READ ) {
	return in_array( (string) $slug, sn_mcp_allowlist_for_door( $door ), true );
}

/**
 * Server identity for the initialize response. The rw door's name is
 * distinguished (spec: "…read-write") so a client juggling both connections
 * can tell them apart in its own UI.
 *
 * @param string $door SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return array{name:string,version:string}
 */
function sn_mcp_server_info( $door = SN_MCP_DOOR_READ ) {
	// Branded base defaults to the site title, falling back to a fixed brand
	// when the title is blank; filterable via 'sn_mcp_server_label' so an owner
	// can rename BOTH doors at once without editing this file. Each door then
	// carries an explicit "(Read)" / "(Write)" label so a client that shows the
	// serverInfo name (rather than the connection's own key) distinguishes them.
	$site = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
	$base = '' !== $site ? $site : 'Signal & Noise';
	if ( function_exists( 'apply_filters' ) ) {
		$base = (string) apply_filters( 'sn_mcp_server_label', $base, $door );
	}
	$name = $base . ( SN_MCP_DOOR_RW === $door ? ' (Write)' : ' (Read)' );
	return array(
		'name'    => $name,
		'version' => defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '',
	);
}

/**
 * Negotiate the protocol version: echo the client's requested revision when we
 * recognize it, else our pinned default. Makes us robust to spec revisions
 * without a code change.
 *
 * @param string $requested
 * @return string
 */
function sn_mcp_negotiate_version( $requested ) {
	$supported = array( '2025-06-18', '2025-03-26', '2024-11-05' );
	return in_array( (string) $requested, $supported, true ) ? (string) $requested : SN_MCP_PROTOCOL_VERSION;
}

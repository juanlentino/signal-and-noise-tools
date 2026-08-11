<?php
/**
 * Signal & Noise Tools — the AI Copilot surface.
 *
 * Three things, all riding filters at the boundary: the tool-schema repair
 * that runs at PHP_INT_MAX (so it sees the complete list every turn), the
 * prune list of abilities dropped from that list, and the system-prompt
 * appendix teaching the Copilot our analytics vocabulary.
 *
 * TOOL DEFINITIONS ARE RENT, NOT PURCHASE. Every read-only ability is
 * auto-enrolled and its name + description + input_schema is serialized into
 * EVERY turn, before the user's question is read. Adding one costs tokens
 * forever; the prune list is how that budget is paid.
 *
 * Split out of inc/desktop-mode-integration.php in v10.87.2; the code is
 * unchanged. That file is now the loader and still carries the architectural
 * notes covering all seven modules — read it first.
 *
 * @package SignalNoiseTools
 * @since 9.52.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v9.52.5 — Repair the AI Copilot's tool schemas at the boundary.
 *
 * THE BUG (owner-reported, live): clicking Ask AI returned
 *
 *     Bad Request (400) - tools.12.custom.input_schema.type: Input should be 'object'
 *
 * and the Copilot was DEAD — not degraded. One malformed tool fails the whole
 * request, so every SN ability took the assistant down with it.
 *
 * CAUSE. desktop-mode 0.9.4 made the Copilot's tools WordPress Abilities and
 * offers EVERY read-only ability on the site as a tool automatically — no
 * opt-in (includes/ai-copilot/abilities.php). Its converter then passes the
 * ability's input_schema through RAW as the tool's `parameters`
 * (includes/ai-copilot/search.php:743). Our abilities deliberately declare a
 * ['object','null'] union — that IS their GET/null run-path — and Anthropic
 * requires input_schema.type to be the literal string "object".
 * desktop-mode's own abilities all use a plain 'object', so their Copilot
 * never trips over it; only a third-party union-typed ability breaks it. We
 * were enrolled into a contract we never agreed to, and only a live click
 * surfaced it.
 *
 * WHY NOT "JUST FIX THE SCHEMAS". The union is load-bearing: MCP and the REST
 * GET path rely on being callable with null input. The schemas are correct;
 * the BOUNDARY was missing.
 *
 * THE FIX. We already own the normalizer — sn_mcp_normalize_schema()
 * (inc/mcp/mcp-tools.php), which does exactly this for our own MCP door and
 * whose comment predicted this error verbatim: "strict MCP hosts (e.g. the
 * Anthropic tool-schema validator that a client forwards to) reject". It was
 * simply never wired into a path nobody knew existed. desktop_mode_ai_tools
 * exists to "transform the full tool list just before it goes to the provider"
 * — the right seam.
 *
 * Applied to EVERY tool, not just ours: a top-level union is always invalid for
 * the provider, so this is strictly a repair, and it keeps Ask AI alive if
 * another plugin registers the same shape. Nested property unions are left
 * alone — the provider only constrains the top level, and rewriting them would
 * silently narrow an ability's real contract.
 *
 * Upstream: desktop-mode's converter arguably ought to normalize here itself;
 * any plugin with a union-typed read-only ability kills its Copilot.
 *
 * Post-#475 OpenStation renames this to `openstation_ai_tools`
 * (includes/ai-copilot/search.php:1124 — the real signature there is
 * `apply_filters( 'openstation_ai_tools', $tools, $context )`, a second
 * $context arg this callback never declared and doesn't need) —
 * dual-registered via snt_os_compat_add_filter(), idempotent (the
 * normalizer + prune both compute the same output from the same input every
 * call — sn_mcp_normalize_schema() is proven idempotent below in tests), no
 * double-fire guard needed.
 */
snt_os_compat_add_filter( 'desktop_mode_ai_tools', 'openstation_ai_tools', function( $tools ) {
	if ( ! is_array( $tools ) || ! function_exists( 'sn_mcp_normalize_schema' ) ) {
		return $tools;
	}

	// v9.59.0: PRUNE before normalize. Every read-only ability is auto-enrolled
	// as a Copilot tool with no opt-in, and its name + description + input_schema
	// is serialized into EVERY Ask AI turn, before the user's question is read —
	// rent paid forever, invoked or not. snt_dm_ai_pruned_abilities() lists the
	// ones that cannot earn it (see that function). Pruning removes a tool from
	// the COPILOT LIST ONLY; the ability stays registered, REST/MCP-callable and
	// usable by the UI + the scan→suggest→apply pipeline. Reversible in one line.
	//
	// MATCH ON THE STRIPPED NAME. desktop-mode strips the namespace and
	// underscores the slug (signal-noise/export-audit-log → export_audit_log) via
	// desktop_mode_ai_ability_tool_name() BEFORE this filter sees the tool. We
	// call that same function to compute our targets, so our match can never drift
	// from desktop-mode's transform; if it is unavailable we skip pruning rather
	// than guess. Caveat, documented in snt_dm_ai_pruned_abilities(): the
	// namespace is gone at this seam, so a third-party tool with an identical
	// stripped name would also drop — the names are SN-specific and the risk is
	// theoretical, and there is no namespaced seam to prune at instead.
	if ( function_exists( 'openstation_ai_ability_tool_name' ) || function_exists( 'desktop_mode_ai_ability_tool_name' ) ) {
		$prune = array();
		foreach ( snt_dm_ai_pruned_abilities() as $ability ) {
			$prune[ (string) snt_os_ai_ability_tool_name( $ability ) ] = true;
		}
		if ( $prune ) {
			$tools = array_values( array_filter( $tools, static function ( $tool ) use ( $prune ) {
				$name = ( is_array( $tool ) && isset( $tool['name'] ) ) ? (string) $tool['name'] : '';
				return '' === $name || ! isset( $prune[ $name ] );
			} ) );
		}
	}

	foreach ( $tools as $i => $tool ) {
		// Skip anything without an array `parameters` — never fabricate a schema
		// for a tool that declares none. (This once claimed "command tools carry
		// no parameters". They do: search.php builds every command tool a full
		// object schema with a required `args` string. They're conformant already,
		// so normalizing them is a no-op — but the stated reason was wrong, and a
		// wrong reason is how the next person justifies the next skip.)
		if ( ! is_array( $tool ) || ! isset( $tool['parameters'] ) || ! is_array( $tool['parameters'] ) ) {
			continue;
		}
		// v9.53.1 — NO SKIP. Normalize unconditionally.
		//
		// There used to be an "already conformant, touch nothing" guard here. It
		// was the bug, twice. It asked "is this one of the wrong shapes I know
		// about?" and skipped everything else — so each shape we hadn't met yet
		// sailed through untouched and the provider's 400 simply moved to the
		// next tool:
		//
		//   tools.12 …type: Input should be 'object'                    (v9.52.5)
		//   tools.29 …does not support oneOf/allOf/anyOf at the top level (v9.53.0)
		//   tools.30 …properties: Input should be an object              (v9.53.1)
		//
		// Each time, the normalizer already handled the shape — it just never
		// ran, because the guard had judged the schema fine by the only criteria
		// it knew. Enumerating what's broken cannot work: the list of
		// unsupported constructs belongs to the provider, not to us, and we
		// learn it one 400 at a time.
		//
		// So: always normalize. sn_mcp_normalize_schema() is idempotent — a
		// conformant schema goes in and comes out identical — and it costs a few
		// array ops on a payload we already build per request. That is cheaper
		// than being wrong a fourth time.
		//
		// v9.53.2 — the same lesson, one level up: normalizing unconditionally
		// only helps for tools we SEE. At the default priority 10 we saw only the
		// tools that existed at priority 10, and this filter's own docblock
		// invites others to inject tools ("injecting synthetic command tools").
		// Anything hooked later landed downstream of us. Hence PHP_INT_MAX below:
		// we cannot enumerate who else hooks this or when, so we simply run last.
		$tools[ $i ]['parameters'] = sn_mcp_normalize_schema( $tool['parameters'] );
	}

	return $tools;
}, PHP_INT_MAX );

/**
 * Abilities dropped from the Copilot's per-turn tool list (v9.59.0).
 *
 * These three are read-only (so desktop-mode auto-enrols them as Copilot tools),
 * but a conversational turn can never use them:
 *   - signal-noise/pattern-adoption-suggest and signal-noise/block-migrations-suggest
 *     each require a scan-generated block FINGERPRINT as input. The model cannot
 *     produce a valid fingerprint from natural language, so it can never call them
 *     correctly — they are pure per-turn rent.
 *   - signal-noise/export-audit-log is an export/download action (a CSV/JSON blob).
 *     A chat turn should not emit a download, and signal-noise/get-audit-log already
 *     answers the readable "what's in the audit log" question — so it is redundant
 *     for the Copilot and kept only for the wp-admin export button.
 *
 * Pruning removes them from the COPILOT's list only. Every one stays registered,
 * REST-callable, MCP-exposed, and driven by the wp-admin UI + the
 * scan→suggest→apply pipeline. To restore one, delete its line.
 *
 * OURS ONLY, with a seam caveat: the caller matches on the STRIPPED tool name
 * desktop-mode produces, because the namespace is gone before any plugin can
 * filter the tool list (desktop_mode_ai_search_ability_names() exposes no filter).
 * A third-party ability whose slug stripped to one of these exact names would also
 * be dropped. The names are SN-specific and the risk is theoretical; this is the
 * best available match point.
 *
 * @since 9.59.0
 * @return string[] Full SN ability names to drop from the Copilot tool list.
 */
function snt_dm_ai_pruned_abilities() {
	return array(
		'signal-noise/pattern-adoption-suggest',
		'signal-noise/export-audit-log',
		'signal-noise/block-migrations-suggest',
	);
}

/**
 * Teach the Copilot the analytics vocabulary its own tools return but never
 * define (v9.59.0).
 *
 * desktop_mode_ai_system_prompt_appendix is Stable and STACKED across plugins
 * (desktop-mode concatenates every plugin's appendix), so we append and keep to
 * OUR nouns — the exact terms get-analytics-summary / get-analytics-events emit.
 * EVERY WORD IS RENT: it ships on every Ask AI turn, forever, so there is no
 * brand voice and no history here, only the definitions the tool outputs need. A
 * test caps it at 600 chars.
 *
 * The definitions are the REAL ones (inc/analytics-rollup.php:34-42):
 *   - visits = count(DISTINCT visitor-day hash) — approximate unique visitors,
 *     NOT sessions. Telling the model "visits = sessions" would make it
 *     confidently wrong, so it is stated explicitly.
 *   - time_avg is mean dwell in MILLISECONDS; scroll_avg is a 0-100 percent.
 *   - views is sample-corrected, visits is a raw distinct count, so their ratio
 *     is an estimate.
 *
 * Post-#475 OpenStation renames this to `openstation_ai_system_prompt_appendix`
 * (includes/ai-copilot/search.php:1594) — dual-registered via
 * snt_os_compat_add_filter(). NOT treated as a bare idempotent transform: the
 * real callsite fires this filter TWICE per real request in the ordinary
 * case (the primary /ai/search run AND the follow-up composed-reply leg,
 * each starting from a FRESH $appendix), and blind dual-registration would
 * risk our vocabulary text landing twice in a hypothetical future where both
 * hook names fire for the SAME event. The marker check below makes the
 * append itself idempotent by CONTENT rather than by a once-per-request
 * flag — a flag would incorrectly suppress the second legitimate call.
 *
 * v10.43.0 REJECT #11 LOW: registered with accepted_args=2. The real
 * post-#475 call site passes a 2nd arg — search.php:1594's
 * apply_filters( 'openstation_ai_system_prompt_appendix', '', $ctx_for_filter ) —
 * that this callback doesn't use today. Cheap future-proofing: if the
 * vocabulary text ever needs to branch on $ctx_for_filter, that only means
 * widening the closure's signature, not touching the registration.
 *
 * @since 9.59.0
 */
snt_os_compat_add_filter( 'desktop_mode_ai_system_prompt_appendix', 'openstation_ai_system_prompt_appendix', function ( $appendix ) {
	$appendix = (string) $appendix;
	$marker   = 'Signal & Noise analytics vocabulary.';
	if ( false !== strpos( $appendix, $marker ) ) {
		return $appendix; // Already present — avoid compounding under a hypothetical double-fire.
	}
	return trim( $appendix . "\n" . implode( ' ', array(
		$marker,
		'Traffic is classed human, suspect, or bot; every reported figure is human-only unless a class is named.',
		'"views" is sample-corrected pageviews; "visits" is approximate unique visitors (visitor-day hashes), NOT sessions: treat views and visits as estimates, not an exact ratio.',
		'scroll_avg is mean scroll depth (0-100%); time_avg is mean dwell time in MILLISECONDS.',
		'A null metric means never measured, not zero; a real zero is reported as 0.',
	) ) );
}, 10, 2 );

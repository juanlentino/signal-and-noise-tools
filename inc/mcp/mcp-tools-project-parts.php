<?php
/**
 * Signal & Noise Tools: mcp-tools.php, the annotations, the tool projection
 * and list, and the success and error results.
 *
 * Split from inc/mcp/mcp-tools.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * R6 (lane SEC-C) known-wrong override map: destructiveHint values for
 * rw-door abilities whose OWN meta.annotations declares no 'destructive' key
 * at all. Without an entry here, sn_mcp_ability_annotations() would inherit
 * MCP's own maximally-cautious absent-key default (destructiveHint:true —
 * the research's Finding B) for every one of these — wrongly flagging a
 * read-then-suggest verdict call, a cache-refreshing scan, or a routine file
 * regen as destructive. Every slug below was cross-checked directly against
 * ~/.claude/session-data/abilities-audit-2026-07-15.md's per-ability
 * blast-radius classification (never guessed): none is flagged in that
 * audit's "Notable risks" section, and each one's actual execute callback
 * (read at the same time) does not delete/wipe/irreversibly mutate anything.
 * A slug that DOES declare 'destructive' (e.g. prune-unused-tags) never
 * consults this map at all — its own declared value always wins outright.
 *
 * @return array<string,bool>
 */
function sn_mcp_rw_annotation_destructive_overrides() {
	return array(
		// AI-BILLED verdict/suggestion generators (inc/abilities-ai-health.php):
		// each returns a suggestion only — no wp_update_post/postmeta/file write
		// of any kind (that's the PAIRED *-apply ability, which already
		// declares destructive:true on its own and needs no override). Audit
		// classification: AI-BILLED, not ACTION/WRITE-STATE.
		'signal-noise/ai-alt-suggest'        => false,
		'signal-noise/ai-drift-suggest'      => false,
		'signal-noise/ai-alt-inline-suggest' => false,
		'signal-noise/ai-orphan-suggest'     => false,
		'signal-noise/ai-link-suggest'       => false,
		'signal-noise/ai-pair-suggest'       => false,

		// ACTION + AI-BILLED (inc/abilities-insights.php, inc/abilities-narration.php):
		// each only refreshes + overwrites its OWN cached scan/digest result — not
		// a delete or a mutation of any user content. Audit's "Notable risks"
		// section does not flag either.
		'signal-noise/run-insights-scan' => false,
		'signal-noise/run-narration'      => false,

		// ACTION (file write, idempotent) per the audit — regenerates the
		// social-share PNG in place. Declares only idempotent:true; a routine,
		// repeatable file rebuild is not destructive.
		'signal-noise/regenerate-og-card' => false,

		// The 5 theme AI-generation abilities (return-only — never persist to
		// the DB; ../signal-and-noise/inc/abilities-ai-generation.php). Each
		// declares idempotent:false + readonly:false (trusted as declared) but
		// NO destructive key. Audit note: "return-only" — no
		// wp_update_post/postmeta/file write in any of the 5 callbacks.
		'signal-and-noise/ai-generate-page-note-summary' => false,
		'signal-and-noise/ai-suggest-block-pattern'       => false,
		'signal-and-noise/ai-validate-brand-alignment'    => false,
		'signal-and-noise/ai-generate-pattern-content'    => false,
		'signal-and-noise/ai-rewrite-in-brand-voice'      => false,
	);
}

/**
 * R6 (lane SEC-C): translate an ability's own meta.annotations (WP Abilities
 * vocabulary: 'readonly'/'destructive'/'idempotent', lowercase, no Hint
 * suffix) into MCP's tool-annotations vocabulary (readOnlyHint/
 * destructiveHint/idempotentHint). Every rw-door tool gets all THREE keys
 * explicitly, always — never omit one and let an MCP client apply its own
 * default, which is exactly the gap Finding B identified (an omitted
 * destructiveHint silently becomes `true` client-side).
 *
 * Precedence per key:
 *   - readOnlyHint: the ability's own 'readonly' if declared, else false
 *     (MCP's own default — nothing to override here across the 35 rw slugs).
 *   - idempotentHint: the ability's own 'idempotent' if declared, else false
 *     (MCP's own default — every rw-door ability in this codebase declares
 *     one, but the fallback exists for a future addition that doesn't).
 *   - destructiveHint: the ability's own 'destructive' if declared (always
 *     wins outright, no override consulted) — else false when the ability is
 *     already readOnlyHint:true (a read-only tool cannot be destructive by
 *     definition) — else the per-slug override map — else MCP's own
 *     maximally-cautious default (true), for any slug neither this ability's
 *     own registration nor the override map has ever addressed.
 *
 * @param object $ability A WP_Ability (or test stand-in) exposing get_meta().
 * @param string $slug    The ability's own slug (get_name()) — the override map's key.
 * @return array{readOnlyHint:bool,destructiveHint:bool,idempotentHint:bool}
 */
function sn_mcp_ability_annotations( $ability, $slug ) {
	$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
	$decl = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

	$read_only  = ! empty( $decl['readonly'] );
	$idempotent = ! empty( $decl['idempotent'] );

	if ( array_key_exists( 'destructive', $decl ) ) {
		$destructive = ! empty( $decl['destructive'] );
	} elseif ( $read_only ) {
		$destructive = false;
	} else {
		$overrides   = sn_mcp_rw_annotation_destructive_overrides();
		$destructive = array_key_exists( (string) $slug, $overrides ) ? $overrides[ (string) $slug ] : true;
	}

	return array(
		'readOnlyHint'    => $read_only,
		'destructiveHint' => $destructive,
		'idempotentHint'  => $idempotent,
	);
}

/**
 * Project a WP_Ability into an MCP Tool. inputSchema passes through the
 * ability's own JSON Schema; outputSchema is included only when declared, and
 * is wrapped (sn_mcp_project_output_schema) when its root isn't guaranteed to
 * already be a JSON object. The read door advertises annotations.readOnlyHint
 * ONLY (truthful — every read-door ability is read-only by curation; this
 * single-key shape is byte-frozen, unrelated to R6). The rw door (v9.51.0,
 * lane SEC-C, R6) advertises a fully-populated, curated annotations object
 * via sn_mcp_ability_annotations() — v9.50.0 through v9.50.x omitted rw
 * annotations entirely; R6 replaces that with a translated-and-corrected set.
 *
 * @param object $ability A WP_Ability (or test stand-in) exposing the accessors.
 * @param string $door    SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return array<string,mixed>
 */
function sn_mcp_project_tool( $ability, $door = SN_MCP_DOOR_READ ) {
	$label = (string) $ability->get_label();
	$desc  = (string) $ability->get_description();
	$tool  = array(
		'name'        => sn_mcp_tool_name_from_slug( $ability->get_name() ),
		'description' => trim( '' === $desc ? $label : $label . ': ' . $desc ),
		'inputSchema' => sn_mcp_normalize_schema( $ability->get_input_schema() ),
	);
	$out = $ability->get_output_schema();
	if ( is_array( $out ) && ! empty( $out ) ) {
		$tool['outputSchema'] = sn_mcp_project_output_schema( $out );
	}
	if ( SN_MCP_DOOR_READ === $door ) {
		$tool['annotations'] = array( 'readOnlyHint' => true );
	} elseif ( SN_MCP_DOOR_RW === $door ) {
		$tool['annotations'] = sn_mcp_ability_annotations( $ability, $ability->get_name() );
	}
	return $tool;
}

/**
 * Build the tools/list result: project every allowlisted ability (for the
 * given door) that resolves. The rw door's tools/list is the rw allowlist
 * ONLY — the read-door 25 are never duplicated into it; a client wanting
 * reads uses the read door.
 *
 * @param string $door SN_MCP_DOOR_READ (default) or SN_MCP_DOOR_RW.
 * @return array{tools:array<int,array<string,mixed>>}
 */
function sn_mcp_list_tools( $door = SN_MCP_DOOR_READ ) {
	$tools = array();
	foreach ( sn_mcp_allowlist_for_door( $door ) as $slug ) {
		$ability = sn_mcp_get_ability( $slug );
		if ( $ability ) {
			$tools[] = sn_mcp_project_tool( $ability, $door );
		}
	}
	return array( 'tools' => $tools );
}

/**
 * A successful MCP tool result: both a text block (human) and structuredContent
 * (agent). isError:false.
 *
 * MCP requires structuredContent to be a JSON object: a PHP empty array encodes
 * to [] via wp_json_encode, so it must be cast to an object here to encode as
 * {}. The text block is unaffected — it stays the plain JSON-encoded $data.
 *
 * @param mixed $data
 * @return array<string,mixed>
 */
function sn_mcp_success_result( $data ) {
	$structured = ( is_array( $data ) && empty( $data ) ) ? (object) array() : $data;
	return array(
		'content'           => array(
			array( 'type' => 'text', 'text' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ),
		),
		'structuredContent' => $structured,
		'isError'           => false,
	);
}

/**
 * A tool-level error result (MCP convention: tool failures are results with
 * isError:true, not JSON-RPC errors).
 *
 * @param string $message
 * @return array<string,mixed>
 */
function sn_mcp_error_result( $message ) {
	return array(
		'content' => array( array( 'type' => 'text', 'text' => (string) $message ) ),
		'isError' => true,
	);
}


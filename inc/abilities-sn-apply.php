<?php
/**
 * Signal & Noise Tools — Abilities API: sn_apply (MCP consolidation,
 * session 6b). The consolidated write tool — the only tool on the surface
 * that mutates post content, per docs/mcp-consolidation/SN-MCP-new/sn-apply-spec.md.
 *
 * Registered NEW alongside every ability it absorbs (block-migrations-apply,
 * pattern-adoption-apply, ai-alt-apply, ai-drift-apply, ai-link-apply,
 * update-post-surfaces, regenerate-og-card, anchor-sweep) — nothing below
 * this file was touched, unregistered, or deleted (rw door 35 -> 36).
 *
 * Four gates run in this exact order, EVERY one reporting {passed,...} in
 * the response even when an earlier gate already failed:
 *   1. fingerprint  (inc/sn-apply-validation.php)
 *   2. validation   (inc/sn-apply-validation.php, calls sn_validate's
 *                     internal check functions directly)
 *   3. capability   (inc/sn-apply-gates.php)
 *   4. idempotency  (inc/sn-apply-gates.php)
 *
 * dry_run defaults to TRUE per the spec's exact signature. A dry run still
 * runs all four gates and produces the diff, but performs ZERO writes — see
 * tests/abilities-sn-apply.php's DB-verified zero-writes guard (acceptance
 * test 1).
 *
 * Refusals return WP_Error with array('status'=>N) — the EXISTING
 * inc/mcp/mcp-tools.php plumbing (sn_mcp_call_tool(), unmodified by this
 * session) already turns any ability-level WP_Error into an isError:true
 * tool result, never a JSON-RPC protocol error, and already calls
 * sn_mcp_rw_audit_record() for EVERY rw-door outcome (ok/error/denied) —
 * this ability does not reimplement that. It rides an ADDITIONAL, explicit
 * sn_mcp_rw_audit_record() call of its own (see
 * snt_sn_apply_audit_enrichment() below) purely to widen what gets
 * captured: gate outcomes and revision_id are OUTPUT, not input, so the
 * door's own automatic call (which redacts the raw request $args) can never
 * see them. Two audit rows per call is the accepted cost of that — both ride
 * the exact same existing rails function, sn_mcp_rw_audit_record().
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-apply', array(
		'label'               => 'Apply a change to a post (consolidated write tool)',
		'description'         => 'The only tool that mutates post content. Four gates run in order — fingerprint, server-side validation, mode capability, idempotency — every one reported in the response even when an earlier gate already failed. dry_run defaults to TRUE: a caller has to actively ask to write. mode:"revision" stages a WordPress revision without touching the live post (the PR pattern); mode:"publish" writes live. Routine (non-owner) credentials are granted "revision" only — enforced server-side against the calling identity, never a client-chosen parameter. change.type "og_card" (regenerates a PNG file, not a post field) and "anchor_sweep" (dispatches a live HTTP call to the provenance Worker, no post entity involved) are PUBLISH-ONLY — mode:"revision" refuses both explicitly rather than fabricating a staged version of a side effect that cannot be staged. change.type "create_draft" is the mirror image: REVISION-ONLY — mode:"publish" refuses explicitly, because this tool never makes a draft live; the owner schedules drafts by hand. Under mode:"revision", a real draft post IS created (never published); there is no no-op staging for a nonexistent post. Its target is {new_post:true} (no id — the post does not exist yet) and its payload is {title, content (Gutenberg block markup), excerpt?, tags? (existing vocabulary only), meta_description?, og_card_title?}. The last two (v13.95.0) are attached in the SAME write and validated in the SAME gate-2 pass, by the identical validators change.type "surfaces" runs — so a draft can be created COMPLETE in one call. og_card_title is range-checked against the title in THIS payload rather than a stored one, which is the point: the check runs while the caller can still fix the title in the same call. Both write the same meta keys surfaces writes, so a later surfaces call updates those rows rather than shadowing them, and the response reports surfaces_set (the fields actually attached; an empty array, never absent, so "none supplied" is distinguishable from "field ignored"). target may be a single object or an array (batch): per-post writes are atomic, across posts they are independent — one target failing never rolls back another. change.type "link_reshape" (v10.58.0) moves an existing <a>\'s boundaries within one text node — the ONLY path to change where an anchor starts and stops (sentence_replace is plain prose and would delete the link). target {post_id}; payload {current_anchor (the <a>\'s exact inner text, byte-exact from stored content), new_anchor (MUST be a contiguous substring of current_anchor, occurring exactly once within it — this is what makes the operation pure tag movement with byte-identical rendered prose, asserted server-side after the splice), context_snippet? (disambiguates identical anchors)}. href and every other attribute carry over from the existing tag — never parameters; retargeting is not this tool. new_anchor:"" refuses — unlinking is change.type "unlink" (v10.59.0): payload {anchor_text (the <a>\'s exact inner text), context_snippet?}, same fingerprint binding, same locator, same server-side prose-identity assertion; the wrapper is removed and the text kept, attributes discarded with it. change.fingerprint REQUIRED: the live content_hash (sentence_replace\'s binding; missing 422, stale 409). Provenance-safe by construction: the ledger signs normalized prose (markup stripped), so a reshape coalesces to no new commit. change.type "delete_draft" (v10.58.0) makes create_draft\'s advertised rollback real: target {post_id}, REVISION-ONLY (create_draft\'s mirror), TRASH-ONLY (wp_trash_post, never a hard delete — recoverable from wp-admin Trash until WordPress\'s own purge), DRAFT-ONLY (any other status refuses at gate 2 and again inside the write). change.fingerprint is REQUIRED and binds to the draft\'s current content_hash — create_draft\'s rollback object carries it directly, or read gates.fingerprint.observed from a dry_run:true call; missing is 422, stale is the 409 conflict. Its response rollback.method is "manual_untrash": restoring from Trash is a wp-admin action, deliberately NOT an MCP method. change.type "restore_revision" is the ACCEPTANCE path for the PR pattern — PUBLISH-ONLY (a restore IS the live write; staging a restore would stage a revision of a revision), so a routine credential is always refused here by the same identity grant every other publish-only type already uses. Its target is {post_id} and its payload is {revision_id, apply_staged_meta? (default true)}: the revision must belong to the target post (a foreign revision refuses 409 naming both ids), the fingerprint gate binds to the LIVE post\'s current content_hash (the same value sn_posts exposes — a restore against a since-edited post is a stale-branch merge conflict, and a missing fingerprint is a 422 caller error, distinct from a mismatched one), and the write step self-guarantees a rollback snapshot of the pre-restore live state before restoring, then applies and clears any staged-meta rows queued under the SAME post_id (surfaces\' meta_description/og_card_title/seo_title/focus_keyword staged via mode:"revision") — the first application path for those rows. A dry_run\'s diff carries staged_meta_pending (meta_key -> proposed value, the exact rows acceptance would co-publish) so the review sees the WHOLE pull request, meta included, before deciding — an empty object means nothing is queued. alt_text\'s own staged rows are queued under the ATTACHMENT id it targets, never a post_id, so restore_revision (which only ever targets a post) structurally cannot reach them; they remain stranded, with no application path today. change.type "sentence_replace" is the ONLY path for a body edit the caller COMPOSED ITSELF — there is deliberately NO whole-body update path, and every other body type (drift_replace, emdash_replace, link_insert, block_migration, pattern_adoption) is candidate-driven with a fingerprint minted by its own scan/suggest pipeline that a composing caller cannot produce. Its target is {post_id} and its payload is {phrase (a sentence-scale span >= 20 chars copied BYTE-EXACTLY from sn_posts\' content field — punctuation and quotes included, never retyped), replacement (plain prose only, no HTML — block structure is unreachable from this type), context_snippet? (~200 chars around the span, disambiguates repeated spans)}; change.fingerprint is the LIVE post\'s content_hash from sn_posts, REQUIRED (missing is a 422 caller error, stale is the 409 merge conflict), the same binding restore_revision uses. change.payload.edits (v10.66.0) applies N prose splices to ONE post in ONE write, for change.type "emdash_replace", "drift_replace" and "sentence_replace" only — use it whenever a scan returns more than one candidate for the same post. Without it each candidate is its own call and its own wp_update_post(), and for a Note that means one anchored provenance ledger version PER CANDIDATE: a single logical edit (an em-dash PAIR becoming parentheses) permanently recorded itself as two versions, the intermediate one a half-converted state nobody intended to publish. payload.edits is a list of {phrase, replacement, context_snippet?} — plus a per-edit fingerprint for the drift family, whose fingerprints are minted per candidate; sentence_replace carries no per-edit fingerprint because change.fingerprint (the whole-post content_hash) already binds the entire batch. Every edit is located and fingerprint-checked against the ORIGINAL content and the splices are applied in descending position order, so edits inside each other\'s 80-char fingerprint window are fine and no offset needs re-resolving. ALL-OR-NOTHING: any edit that fails to validate, locate or match refuses the whole batch naming the 1-based edit index, because a partially-applied "one logical edit" is precisely the half-converted state this exists to prevent; two edits claiming overlapping byte ranges are a 422. Maximum 50 edits per call. It is NOT available for link_insert, link_reshape or unlink, which rewrite markup rather than prose and can interact through tag structure in ways a byte-range overlap check cannot see. When payload.edits is present the top-level payload.phrase and payload.replacement are unused, and diff.edits_applied reports the count. change.type "roadmap_board" is the board-as-data path for the public maturity roadmap page: its target is {scope:"maturity_roadmap"} (a site surface, not a post) and its payload is {board} — the FULL replacement board (family label -> {done/planned/considering: sentence[]}; wholesale, there is no per-cell patch) — or {reset:true} to delete the override and return the page to its code-canonical default. PUBLISH-ONLY (an option write has no WordPress revision to stage; dry_run:true is the review step). change.fingerprint is REQUIRED and binds to the CURRENT effective board — there is deliberately no separate read tool: call dry_run:true first and read gates.fingerprint.observed (the current fingerprint) and diff.before (the current board) from the response, then re-issue with that fingerprint; a mismatch is the usual 409 stale conflict. diff.merge (conflicts/code_landed/override_held) names any cell code and the override have both moved since the override\'s base — check conflicts before writing, or a landed code edit is silently overwritten. diff.merge.invalid (bool, always present) is true when the CURRENT merge already fails validation — conflicts reads empty in that case by construction, not because nothing moved, so check invalid before trusting an empty conflicts list; diff.before is the static board, not the real merge, until it is cleared. Gate 2 enforces plain prose (no markup), structural bounds, and a banned-internal-token sweep mirroring the public page\'s own leak-sweep test — copy that would leak an internal name is refused at the door, never rendered. IDEMPOTENCY (v11.5.0): supply idempotency_key to control replay yourself; WITHOUT one, every dry_run:false call gets a server-derived auto-key over (target, type, mode, fingerprint, payload), so a byte-identical retry after a timeout replays the first response (replayed:true) instead of executing twice. Send the SAME key when retrying, a FRESH key per logical call. Exceptions with no auto-key: og_card and anchor_sweep — identical repeats are legitimate there (force-regenerate, re-sweep) and always execute. change.type "block_insert" and "block_replace" (v13.2.0) are the caller-composed BLOCK edit family — the only path to add or replace block markup in an EXISTING post (sentence_replace is deliberately prose-only, and block_migration/pattern_adoption are candidate-driven with scan-minted fingerprints a composing caller cannot produce). block_insert: target {post_id}; payload {blocks (serialized Gutenberg block markup, required), anchor (a sentence-scale span >= 20 chars copied BYTE-EXACTLY from the stored post_content, resolving entirely inside ONE top-level block; omittable only with position "end"), position ("before"|"after"|"end", default "after"), context_snippet? (~200 chars around the anchor, disambiguates repeated ones)}. block_replace: payload {blocks, anchor, context_snippet?} — replaces the WHOLE top-level block containing the anchor, and the diff reports the replaced block\'s serialized form (diff.replaced_block). change.fingerprint REQUIRED: the live content_hash (sentence_replace\'s binding; missing 422, stale 409). Refusals, each by name: markup that does not survive a parse/serialize round-trip byte-identically; content that parses as freeform (a malformed delimiter parses as freeform and round-trips CLEANLY, so it is refused explicitly, never written); any unregistered block name (innerBlocks included, the refusal names the block); an anchor with zero matches (409, naming the anchor), still-ambiguous matches after context_snippet (422, naming it), an anchor spanning a top-level block boundary, or one intersecting a block-delimiter comment. Every diff — dry run AND real write — carries the PROSE DELTA: prose_changed (bool), prose_added / prose_removed (normalized text) and ledger_impact ("coalesces"|"new_version"). The provenance ledger signs NORMALIZED prose, so a restructure-only edit coalesces to no new version while new text mints one — the ledger consequence is visible BEFORE anything is written. Scheduled posts keep post_status and post_date EXACTLY: both are captured before the write, passed explicitly through it, and re-asserted after; a violation attempts a restore whose effect is then VERIFIED by re-reading the row (never inferred from the return code) and fails loudly (500) naming the verified outcome — a scheduled post never publishes early as a silent side effect of a block edit. An OVERDUE scheduled post (post_date_gmt passed or under a minute away, cron not yet fired) refuses UP FRONT in publish mode (409, snt_sn_apply_schedule_overdue): WordPress core\'s own status resolution would silently early-publish it on any write, restore included, so nothing is written at all — let cron publish it (or publish deliberately), then retry with a fresh fingerprint; mode:"revision" still stages fine (staging never touches the live row). payload.edits is refused for both types (block edits interact through tag structure in ways the prose batch\'s byte-range overlap check cannot see): one call per block edit. v13.5.0 — THE NON-ANCHOR LOCATOR AND THE REMAINING VERBS. payload.block_path ("0/<index>" — the EXACT syntax sn_scan\'s block_migrations surfaces on targets[].block_path: a literal 0 seed plus the RAW parse_blocks index, whitespace separator nodes included) is an alternative locator for block_insert/block_replace/block_delete: the only way to reach a dynamic block whose text lives entirely in its delimiter attributes (signal-noise/sidenote, pull-quote — anchoring on attribute JSON refuses by design, which made those blocks WRITE-ONCE before this). EXACTLY ONE locator per call: anchor and block_path both supplied, or neither where one is needed, is a named 422 (snt_sn_apply_locator_conflict / snt_sn_apply_locator_required) — never a silent precedence rule. STALENESS: block_path is position-bound but can never silently hit the wrong block — change.fingerprint (the live content_hash, still REQUIRED) binds your entire view of the post and 409s BEFORE any path is dereferenced; a path that misses under a FRESH hash is caller arithmetic, refused naming the path and what actually sits there (out_of_range / not_a_block for whitespace-separator or freeform indices / not_top_level for nested paths). block_migrations\' descending-order rule does not apply here: one call is one splice, and the next call needs a fresh content_hash by construction. change.type "block_delete" removes one located top-level block (consuming one adjacent whitespace separator so the content keeps its rhythm), REFUSES to empty a post, and reports the removed block\'s serialized form as diff.removed_block. change.type "block_move" relocates one block in a SINGLE call — source is payload.block_path (required); destination is position "before"/"after" plus exactly one of payload.anchor / payload.to_block_path, or position "end" — because assembling a move from two individually-legal replaces can strand the caller mid-swap with a block deleted and no scripted way back; a no-op move refuses; diff.moved_block reports the block. Neither takes payload.blocks (a reword is block_replace). Both carry the full family contract: live-content_hash fingerprint, all four gates, the prose delta (a move reorders prose, so it mints a version — honest), and the scheduled-post guarantee. That guarantee, stated precisely as of v13.5.0: post_status is asserted unchanged for EVERY status, and post_date strictly for status "future" only — WordPress core deliberately floats a draft\'s post_date on save (a draft\'s date is last-touched, not a schedule), and binding it produced false 500s on routine draft edits. change.type "batch" (v13.94.0) applies MULTIPLE, DIFFERENT changes to ONE post in ONE write, and therefore mints ONE provenance ledger version. payload.edits (v10.66.0) already batched N edits of a SINGLE type; batch is for the ordinary editorial shape where they differ — on 2026-09-03 one amendment (a sentence_replace fixing a stale figure, a block_insert adding references, a block_insert adding a correction notice) minted THREE anchored versions, two of them intermediate states nobody intended to publish and all three permanent. target {post_id}; payload {changes: [{type, payload}, ...]}, applied as ONE act. Member types: sentence_replace, block_insert, block_replace, block_delete — each resolves to exactly one byte span, which is what makes the batch orderable. block_move is NOT a member (its destination resolves against post-delete content, which breaks the plan-against-original rule), nor are link_insert/link_reshape/unlink (they rewrite tag structure inside a text node). change.fingerprint REQUIRED: ONE live content_hash binds the WHOLE batch, because every change is located against exactly the content that hash names; missing 422, stale 409. Every change is planned against the ORIGINAL content and the splices applied in DESCENDING position order, so no offset is ever re-resolved. CONFLICTS refuse the whole batch (422) naming BOTH 1-based indexes and the reason: overlapping byte spans (which also covers a prose edit NESTED in a block another change replaces), two inserts at the SAME point (their order would be undefined), and an insert anchored to the leading or trailing edge of a span another change replaces or deletes (its landing point would be undefined). ALL-OR-NOTHING: a refusal writes nothing, because a partially-applied editorial act is precisely the half-converted state this exists to prevent. Maximum 50 changes. The dry run reports the COMBINED diff and a SINGLE ledger_impact plus changes_applied — the whole point is that the caller reviews one editorial act, not N intermediate ones. Carries the family contract in full, the scheduled-post guarantee included: batch and the single block edits now share ONE implementation of it, so a batch can no more publish a scheduled note early than a single edit can.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_sn_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'target', 'change', 'mode' ),
			'properties'           => array(
				'target'          => array(
					// v10.41.1: a nested 'oneOf' (the pre-fix shape) left this property
					// with NO top-level 'type' key at all -- 'oneOf' was its ONLY key.
					// Confirmed live (first real MCP call): the projected tool schema
					// shipped `"target": {}`, and an MCP client facing an untyped
					// parameter serializes whatever it's given as a JSON STRING rather
					// than structured JSON -- every call failed input validation with
					// "input[target][0] is not of type object" regardless of what the
					// caller sent (see inc/mcp/mcp-tools.php's sn_mcp_normalize_schema(),
					// which only ever strips oneOf/allOf/anyOf at the SCHEMA ROOT, never
					// inside a property -- this property had no fallback type left for a
					// stricter host, or an older cached schema, to fall back to). A `type`
					// ARRAY union (unlike `oneOf`) is not a schema combinator -- it is the
					// same pattern already load-bearing elsewhere in this codebase
					// (sn-posts'/sn-scan's `cursor`, every nullable output field) and it
					// says everything the old oneOf said: an object OR an array, with
					// `properties` describing the object branch and `items` describing the
					// array branch side by side (JSON Schema ignores whichever doesn't
					// match the instance's actual type). Runtime enforcement is unchanged
					// -- see snt_sn_apply_resolve_target() / snt_sn_apply_is_batch_target().
					'type'       => array( 'object', 'array' ),
					'properties' => array(
						'post_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'scope'         => array( 'type' => 'string', 'enum' => array( 'provenance_anchors', 'maturity_roadmap' ) ),
						// session 6c: create_draft's target -- the post doesn't exist yet, so
						// there is no id to carry. Runtime enforces === true (not just
						// truthy) in snt_sn_apply_resolve_target(), same posture as scope's enum above.
						'new_post'      => array( 'type' => 'boolean' ),
					),
					'items'      => array( 'type' => 'object' ),
				),
				'change'          => array(
					'type'       => 'object',
					'required'   => array( 'type' ),
					'properties' => array(
						'type'         => array( 'type' => 'string', 'enum' => SNT_SN_APPLY_CHANGE_TYPES ),
						'payload'      => array( 'type' => 'object' ),
						'candidate_id' => array( 'type' => 'string' ),
						'fingerprint'  => array( 'type' => 'string' ),
					),
				),
				'mode'            => array( 'type' => 'string', 'enum' => array( 'revision', 'publish' ) ),
				'dry_run'         => array( 'type' => 'boolean', 'default' => true ),
				'idempotency_key' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'applied'              => array( 'type' => 'boolean' ),
				'mode'                 => array( 'type' => 'string' ),
				'change_type'          => array( 'type' => 'string' ),
				'gates'                => array( 'type' => 'object' ),
				'diff'                 => array( 'type' => array( 'object', 'null' ) ),
				'revision_id'          => array( 'type' => array( 'integer', 'null' ) ),
				'rollback'             => array( 'type' => array( 'object', 'null' ) ),
				'replayed'             => array( 'type' => 'boolean' ),
				// Session 7 — restore_revision's own definitive fields (all
				// other 9 types never populate these; the generic
				// `revision_id` above stays null for restore_revision on
				// purpose, see inc/sn-apply-restore-revision.php).
				'post_id'              => array( 'type' => array( 'integer', 'null' ) ),
				'restored_revision_id' => array( 'type' => array( 'integer', 'null' ) ),
				'rollback_revision_id' => array( 'type' => array( 'integer', 'null' ) ),
				'meta_applied'         => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true, // conservative reading: mode:"publish" overwrites live content.
				'idempotent'  => true, // earned by the idempotency_key gate.
			),
		),
	) );
} );

/**
 * Is $value a JSON-array-shaped batch of targets (vs. a single target
 * object)? array_is_list() is the exact discriminator: a decoded JSON
 * object never produces a 0-based sequential-int-key PHP array unless it
 * used numeric-string keys (not a real target shape), so this is not a
 * heuristic.
 *
 * @param mixed $value
 * @return bool
 */
function snt_sn_apply_is_batch_target( $value ) {
	return is_array( $value ) && array_is_list( $value );
}

/**
 * Ability execute callback: signal-noise/sn-apply.
 *
 * @param array|null $input
 * @return array|WP_Error
 */
function snt_ability_sn_apply( $input ) {
	$input = is_array( $input ) ? $input : array();

	// Transport tolerance (defense in depth, belt-and-braces alongside the
	// schema fix above): a client whose own tool-schema cache still reflects
	// the pre-fix untyped `target` -- or any other MCP host that serializes a
	// non-scalar argument as a JSON string when it can't resolve a type from
	// the schema -- may still send `target` as a STRING even now. Decode it
	// before anything downstream (snt_sn_apply_is_batch_target(),
	// snt_sn_apply_canonical_target(), snt_sn_apply_resolve_target()) ever
	// sees it; every one of those already expects the native PHP array shape
	// this restores. An undecodable string is a client error, not a silent
	// empty target -- refuse loudly rather than falling through to "target
	// not resolved".
	if ( isset( $input['target'] ) && is_string( $input['target'] ) ) {
		$decoded_target = json_decode( $input['target'], true );
		if ( null === $decoded_target && 'null' !== trim( $input['target'] ) ) {
			return new WP_Error(
				'snt_sn_apply_bad_target_encoding',
				'target must be valid JSON when supplied as a string.',
				array( 'status' => 422 )
			);
		}
		$input['target'] = $decoded_target;
	}

	$change = is_array( $input['change'] ?? null ) ? $input['change'] : array();
	$type   = (string) ( $change['type'] ?? '' );
	if ( ! in_array( $type, SNT_SN_APPLY_CHANGE_TYPES, true ) ) {
		return new WP_Error( 'snt_sn_apply_bad_change_type', sprintf( 'change.type must be one of: %s.', implode( ', ', SNT_SN_APPLY_CHANGE_TYPES ) ), array( 'status' => 422 ) );
	}

	$mode = (string) ( $input['mode'] ?? '' );
	if ( ! in_array( $mode, array( 'revision', 'publish' ), true ) ) {
		return new WP_Error( 'snt_sn_apply_bad_mode', 'mode must be "revision" or "publish".', array( 'status' => 422 ) );
	}

	$dry_run = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;

	$raw_target       = $input['target'] ?? array();
	$canonical_target = snt_sn_apply_canonical_target( $raw_target, $change );

	// v11.5.0: replay protection stops being opt-in. A keyless MUTATING call
	// gets a server-derived auto-key (hash of the call's logical identity),
	// so an MCP timeout+retry dedupes instead of depending on gate-1
	// fingerprint side effects. Caller-supplied keys keep their exact
	// pre-11.5.0 contract; dry runs and the two side-effect types (og_card,
	// anchor_sweep — identical repeats are legitimate there) derive none.
	// See snt_sn_apply_effective_idempotency_key() for the full reasoning.
	$idempotency_key = snt_sn_apply_effective_idempotency_key(
		(string) ( $input['idempotency_key'] ?? '' ),
		$type,
		$mode,
		$dry_run,
		$change,
		$canonical_target
	);

	// Gate 4, replay shortcut: a genuine second call with the SAME (key,
	// target) pair returns the FIRST call's response verbatim — no gate
	// recomputation, no re-execution. TARGET-SCOPED (review HIGH,
	// v10.40.0): the same key on a DIFFERENT target derives a different
	// store key and falls through to a fresh execution — idempotency
	// protects the retry of the same logical call, never a cross-target
	// dedupe. Everything below this block only runs for a fresh
	// (key, target) pair (or no key at all).
	//
	// Fix round (REJECT #10, pre-existing defect, session 6b's own build
	// notes flagged it theoretical): dry_run:true NEVER consults the store —
	// a preview always runs fresh. Pre-fix, a keyed dry_run:true call was
	// treated identically to a real call: its preview response (applied:
	// false) got RECORDED, and the natural follow-up dry_run:false call
	// under the same key then REPLAYED that preview forever instead of ever
	// executing the write — restore_revision's own documented workflow
	// (dry-run diff -> owner says apply -> dry_run:false, SAME key is the
	// obvious caller pattern) makes this the expected shape of a real call,
	// not an edge case. The mirror half (never RECORDING a dry run) is
	// below, at the idempotency_record() call site — both changes are
	// one-directional: a dry_run:false call's behavior is completely
	// unchanged (still looks up, still records, still replays exactly as
	// before).
	$idem = $dry_run
		? array( 'passed' => true, 'first_seen' => null, 'replay' => null, 'target_mismatch' => null )
		: snt_sn_apply_gate_idempotency( $idempotency_key, $canonical_target );
	if ( is_array( $idem['target_mismatch'] ?? null ) ) {
		// Belt-and-braces: the stored row was executed against a DIFFERENT
		// target than this request's (impossible under the current key
		// derivation; defends against a future derivation change). Refuse
		// loudly, naming both, rather than replaying the wrong target's
		// result.
		return new WP_Error(
			'snt_sn_apply_idempotency_target_mismatch',
			sprintf(
				'idempotency_key "%s" was previously used against target %s but this request is for target %s. Use a fresh idempotency_key per logical call.',
				$idempotency_key,
				$idem['target_mismatch']['stored'],
				$idem['target_mismatch']['requested']
			),
			array( 'status' => 409 )
		);
	}
	if ( is_array( $idem['replay'] ) ) {
		$replay              = $idem['replay'];
		$replay['replayed']  = true;
		snt_sn_apply_audit_enrichment( $type, $mode, $dry_run, $change, $replay, true );
		return $replay;
	}

	$is_batch = snt_sn_apply_is_batch_target( $raw_target );
	$targets  = $is_batch ? $raw_target : array( $raw_target );

	$results = array();
	foreach ( $targets as $raw_one_target ) {
		$results[] = snt_sn_apply_apply_one( $type, $raw_one_target, $change, $mode, $dry_run );
	}

	if ( $is_batch ) {
		$applied_count = 0;
		$failed_count  = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['applied'] ) ) {
				$applied_count++;
			} elseif ( ! empty( $r['error'] ) ) {
				$failed_count++;
			}
		}
		$response = array(
			'batch'       => true,
			'change_type' => $type,
			'mode'        => $mode,
			'dry_run'     => $dry_run,
			'results'     => $results,
			'summary'     => array(
				'total'   => count( $results ),
				'applied' => $applied_count,
				'failed'  => $failed_count,
			),
			'replayed'    => false,
		);
	} else {
		$response             = $results[0];
		$response['replayed'] = false;

		// Single target only: a gate refusal or write failure (never a
		// dry_run PREVIEW, which never sets 'error') must surface as the
		// ability's own WP_Error return — this is what turns into an
		// isError:true tool result at the MCP layer (unmodified plumbing,
		// see this file's docblock). Batch results stay plain arrays; one
		// target's failure never aborts or fails the whole ability call.
		if ( isset( $response['error'] ) ) {
			snt_sn_apply_audit_enrichment( $type, $mode, $dry_run, $change, $response, false );
			return new WP_Error(
				(string) $response['error']['code'],
				(string) wp_json_encode( $response ),
				array( 'status' => (int) $response['error']['status'] )
			);
		}
	}

	// Fix round (REJECT #10): a dry run never records into the store either
	// — see the gate-4 lookup above for the full rationale. Only a genuine
	// dry_run:false execution is ever eligible to be replayed.
	if ( ! $dry_run ) {
		snt_sn_apply_idempotency_record( $idempotency_key, $canonical_target, $response );
	}
	snt_sn_apply_audit_enrichment( $type, $mode, $dry_run, $change, $response, false );

	return $response;
}

/**
 * Run all four gates + (if warranted) the write for ONE target. Never
 * throws; every failure path is expressed in the returned array's own
 * `error` key (batch semantics: one target's failure must not abort the
 * loop in snt_ability_sn_apply()) — the single-target caller then decides
 * whether to surface it as the ability's own WP_Error return.
 *
 * @param string $type
 * @param mixed  $raw_target
 * @param array  $change
 * @param string $mode
 * @param bool   $dry_run
 * @return array The full per-target response shape (spec's return shape).
 */
function snt_sn_apply_apply_one( $type, $raw_target, array $change, $mode, $dry_run ) {
	$candidate_id = isset( $change['candidate_id'] ) ? (string) $change['candidate_id'] : null;

	$resolved = snt_sn_apply_resolve_target( $type, $raw_target );
	if ( is_wp_error( $resolved ) ) {
		return snt_sn_apply_target_error_response( $type, $mode, $raw_target, $candidate_id, $resolved );
	}

	// Session 7 — restore_revision's structural pre-check, BEFORE any gate
	// runs: the revision named in change.payload.revision_id must exist and
	// belong to THIS target post. Not a gate itself (it never appears in
	// the `gates` object) — the same posture as target resolution above,
	// which this mirrors: there is nothing for gates 1-3 to check a
	// fingerprint or run validation AGAINST until this holds.
	if ( 'restore_revision' === $type ) {
		$precheck = snt_sn_apply_restore_revision_precheck( $resolved['post_id'] ?? 0, (array) ( $change['payload'] ?? array() ) );
		if ( is_wp_error( $precheck ) ) {
			return snt_sn_apply_target_error_response( $type, $mode, $raw_target, $candidate_id, $precheck, 'revision_not_resolved' );
		}
	}

	$gate1 = snt_sn_apply_gate1_fingerprint( $type, $resolved, $change );
	$gate2 = snt_sn_apply_gate2_validation( $type, $resolved, $change, $gate1['new_content'] ?? null );
	$gate3 = snt_sn_apply_gate_capability( $type, $mode );

	$has_error_finding = false;
	foreach ( $gate2['findings'] as $f ) {
		if ( 'error' === ( $f['severity'] ?? '' ) ) {
			$has_error_finding = true;
			break;
		}
	}
	$gate2_passed = ! $has_error_finding;

	$gates = array(
		'fingerprint' => array(
			'passed'   => $gate1['passed'],
			'expected' => $gate1['expected'],
			'observed' => $gate1['observed'],
			'skipped'  => $gate1['skipped'],
			'detail'   => $gate1['detail'],
		),
		'validation'  => array(
			'passed'   => $gate2_passed,
			'findings' => $gate2['findings'],
			'checks'   => $gate2['checks'],
			'skipped'  => empty( $gate2['checks'] ) ? 'no_applicable_checks' : null,
		),
		'capability'  => array(
			'passed'         => $gate3['passed'],
			'granted_modes'  => $gate3['granted_modes'],
			'mode_supported' => $gate3['mode_supported'],
			'reason'         => $gate3['reason'],
		),
		'idempotency' => array( 'passed' => true, 'first_seen' => null ),
	);

	$all_passed = $gate1['passed'] && $gate2_passed && $gate3['passed'];

	$response = array(
		'applied'      => false,
		'mode'         => $mode,
		'target'       => $raw_target,
		'change_type'  => $type,
		'candidate_id' => $candidate_id,
		'gates'        => $gates,
		'diff'         => null,
		'revision_id'  => null,
		'rollback'     => null,
	);

	if ( ! $all_passed ) {
		$response['diff']  = snt_sn_apply_dry_run_diff( $type, $resolved, $change, $gate1 );
		$response['error'] = array(
			// Session 7 — gate1 may carry its own error_code/error_status
			// override (restore_revision's missing-fingerprint case: 422,
			// distinct from the generic 409-stale default every other type
			// still uses). Absent for every other type's gate1 return array,
			// so `??` falls back to byte-identical prior behavior.
			'code'    => ! $gate1['passed'] ? ( $gate1['error_code'] ?? 'snt_sn_apply_fingerprint_stale' ) : ( ! $gate2_passed ? 'snt_sn_apply_validation_failed' : 'snt_sn_apply_mode_not_granted' ),
			'status'  => ! $gate1['passed'] ? ( $gate1['error_status'] ?? 409 ) : ( ! $gate2_passed ? 422 : 403 ),
		);
		return $response;
	}

	if ( $dry_run ) {
		$response['diff'] = snt_sn_apply_dry_run_diff( $type, $resolved, $change, $gate1 );
		return $response;
	}

	$write = snt_sn_apply_execute_write( $type, $resolved, $change, $mode );
	if ( is_wp_error( $write ) ) {
		$response['diff']  = snt_sn_apply_dry_run_diff( $type, $resolved, $change, $gate1 );
		// v13.2.0 (adversarial review): carry the write error's own MESSAGE,
		// the same way snt_sn_apply_target_error_response() already does for
		// target errors — the single-target caller's WP_Error message is
		// wp_json_encode($response), and without this key the human-readable
		// detail (e.g. the schedule guard's verified restore outcome) was
		// silently dropped on exactly the failures that most need it.
		// Additive: nothing asserted this key's absence.
		$response['error'] = array( 'code' => $write->get_error_code(), 'status' => (int) ( $write->get_error_data()['status'] ?? 500 ), 'message' => $write->get_error_message() );
		return $response;
	}

	$response['applied']     = true;
	$response['diff']        = $write['diff'];
	$response['revision_id'] = $write['revision_id'];
	if ( 'revision' === $mode && $write['revision_id'] ) {
		$response['rollback'] = array( 'method' => 'restore_revision', 'revision_id' => $write['revision_id'] );
	} elseif ( 'create_draft' === $type && ! empty( $write['write_result']['post_id'] ) ) {
		// create_draft's own rollback shape (session 6c) -- there is no
		// revision_id (it never routes through snt_sn_apply_stage_revision()),
		// so the generic revision-mode branch above never fires for it. A
		// draft delete is trash, reversible -- the same "nothing is final
		// yet" posture mode:"revision" promises everywhere else in this tool.
		// v10.58.0 (audit item 6): delete_draft now EXISTS as a change type,
		// and its gate 1 requires the draft's content_hash — carried here so
		// the create -> delete round trip is one-shot, no sn_posts re-fetch.
		$rollback_post = get_post( (int) $write['write_result']['post_id'] );
		$response['rollback'] = array(
			'method'      => 'delete_draft',
			'post_id'     => (int) $write['write_result']['post_id'],
			'fingerprint' => ( $rollback_post && function_exists( 'snt_corpus_content_hash' ) ) ? snt_corpus_content_hash( (string) $rollback_post->post_content ) : null,
		);
	} elseif ( 'delete_draft' === $type && is_array( $write['write_result'] ?? null ) ) {
		// The trash is undone from wp-admin (Posts -> Trash -> Restore) — a
		// HUMAN action. The "manual_" prefix is deliberate: this field must
		// never again name a method that isn't reachable through the tool
		// surface (the exact defect that created this change type).
		$response['rollback'] = array(
			'method'  => 'manual_untrash',
			'post_id' => (int) ( $write['write_result']['post_id'] ?? 0 ),
			'note'    => (string) ( $write['write_result']['restore'] ?? '' ),
		);
	} elseif ( 'restore_revision' === $type && is_array( $write['write_result'] ?? null ) ) {
		// Session 7 -- restore_revision's own definitive fields. mode is
		// ALWAYS 'publish' for this type (see snt_sn_apply_mode_support()),
		// so the generic revision-mode branch above never fires here either.
		// rollback ALWAYS points at the pre-restore snapshot from
		// snt_sn_apply_ensure_rollback_snapshot() -- never at the revision
		// that was just restored (restoring it would re-apply the restore,
		// not undo it).
		$response['post_id']              = $write['write_result']['post_id'] ?? null;
		$response['restored_revision_id'] = $write['write_result']['restored_revision_id'] ?? null;
		$response['rollback_revision_id'] = $write['write_result']['rollback_revision_id'] ?? null;
		$response['meta_applied']         = $write['write_result']['meta_applied'] ?? array();
		if ( ! empty( $write['write_result']['rollback_revision_id'] ) ) {
			$response['rollback'] = array( 'method' => 'restore_revision', 'revision_id' => (int) $write['write_result']['rollback_revision_id'] );
		}
	}
	return $response;
}

/**
 * A target that failed to resolve (404/422) never reaches gates 1-3 (there
 * is nothing to check a fingerprint or run a capability check AGAINST) —
 * still reports all four gates, all `skipped`, so a batch caller sees a
 * consistent shape across every result regardless of WHERE a target failed.
 *
 * @param string   $type
 * @param string   $mode
 * @param mixed    $raw_target
 * @param string|null $candidate_id
 * @param WP_Error $resolved_error
 * @param string   $skip_reason Defaults to 'target_not_resolved' (every
 *                                pre-session-7 call site). Session 7's
 *                                restore_revision structural pre-check
 *                                passes 'revision_not_resolved' instead —
 *                                the TARGET (post) resolved fine; it is the
 *                                REVISION named in the payload that failed.
 * @return array
 */
function snt_sn_apply_target_error_response( $type, $mode, $raw_target, $candidate_id, WP_Error $resolved_error, $skip_reason = 'target_not_resolved' ) {
	return array(
		'applied'      => false,
		'mode'         => $mode,
		'target'       => $raw_target,
		'change_type'  => $type,
		'candidate_id' => $candidate_id,
		'gates'        => array(
			'fingerprint' => array( 'passed' => false, 'expected' => null, 'observed' => null, 'skipped' => $skip_reason, 'detail' => null ),
			'validation'  => array( 'passed' => false, 'findings' => array(), 'checks' => array(), 'skipped' => $skip_reason ),
			'capability'  => array( 'passed' => false, 'granted_modes' => array(), 'mode_supported' => false, 'reason' => $skip_reason ),
			'idempotency' => array( 'passed' => true, 'first_seen' => null ),
		),
		'diff'         => null,
		'revision_id'  => null,
		'rollback'     => null,
		'error'        => array(
			'code'    => $resolved_error->get_error_code(),
			'status'  => (int) ( $resolved_error->get_error_data()['status'] ?? 404 ),
			// The full response this array is embedded in becomes a
			// single-target caller's WP_Error MESSAGE verbatim
			// (wp_json_encode($response) in snt_ability_sn_apply()) — the
			// original error's own message text is otherwise lost entirely.
			// Session 7's restore_revision precheck needs its detail (naming
			// BOTH the requested and actual parent post ids) to survive that
			// trip; every pre-session-7 caller of this function gains the
			// same detail for free, never a regression (nothing previously
			// asserted this key's absence).
			'message' => $resolved_error->get_error_message(),
		),
	);
}

/**
 * The additional, enrichment-only sn_mcp_rw_audit_record() call — see the
 * file docblock's "two audit rows per call" note. Flattens exactly the
 * scalar fields sn_mcp_rw_audit_safe_args() now allowlists for this ability
 * (change_type/mode/dry_run/candidate_id/idempotency_key/applied/
 * revision_id/gate_*_passed) — never the full response object, which can
 * carry arbitrary proposed content (payload text) that must never reach the
 * audit log per the door's own default-drop redaction contract.
 *
 * @param string     $type
 * @param string     $mode
 * @param bool       $dry_run
 * @param array      $change
 * @param array      $response
 * @param bool       $replayed
 * @return void
 */
function snt_sn_apply_audit_enrichment( $type, $mode, $dry_run, array $change, array $response, $replayed ) {
	if ( ! function_exists( 'sn_mcp_rw_audit_record' ) ) {
		return;
	}

	$is_batch = ! empty( $response['batch'] );
	$applied  = $is_batch ? ( (int) ( $response['summary']['applied'] ?? 0 ) > 0 ) : (bool) ( $response['applied'] ?? false );
	$gates    = $is_batch ? ( $response['results'][0]['gates'] ?? array() ) : ( $response['gates'] ?? array() );

	$args = array(
		'change_type'             => $type,
		'mode'                    => $mode,
		'dry_run'                 => $dry_run,
		'candidate_id'            => isset( $change['candidate_id'] ) ? (string) $change['candidate_id'] : '',
		'applied'                 => $applied,
		'replayed'                => (bool) $replayed,
		'revision_id'             => $is_batch ? null : ( $response['revision_id'] ?? null ),
		'gate_fingerprint_passed' => (bool) ( $gates['fingerprint']['passed'] ?? false ),
		'gate_validation_passed'  => (bool) ( $gates['validation']['passed'] ?? false ),
		'gate_capability_passed'  => (bool) ( $gates['capability']['passed'] ?? false ),
		'gate_idempotency_passed' => (bool) ( $gates['idempotency']['passed'] ?? true ),
	);

	$outcome = $applied || $dry_run ? 'ok' : 'error';
	sn_mcp_rw_audit_record( 'signal-noise/sn-apply', $args, $outcome );
}

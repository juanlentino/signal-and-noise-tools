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
 *   1. fingerprint  (inc/sn-apply/validation.php)
 *   2. validation   (inc/sn-apply/validation.php, calls sn_validate's
 *                     internal check functions directly)
 *   3. capability   (inc/sn-apply/gates.php)
 *   4. idempotency  (inc/sn-apply/gates.php)
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

/**
 * The sn_apply package.
 *
 * These files were siblings at inc/sn-apply-*.php until v13.96-era; they moved
 * under inc/sn-apply/ and this file became their loader. It keeps the OLD
 * public path on purpose: the bootstrap, and every suite that requires the
 * ability last, still name inc/abilities-sn-apply.php. Nothing outside this
 * directory learned a new path.
 *
 * The order below is the bootstrap's previous require order, preserved
 * verbatim. Nothing here resolves a sibling by __DIR__, so the order is about
 * declaration sequence only - but it was working, and a refactor that also
 * reorders is two changes wearing one diff.
 */
require_once __DIR__ . '/sn-apply/revision.php';
require_once __DIR__ . '/sn-apply/gates.php';
require_once __DIR__ . '/sn-apply/validation.php';
require_once __DIR__ . '/sn-apply/create-draft.php';
require_once __DIR__ . '/sn-apply/delete-draft.php';
require_once __DIR__ . '/sn-apply/link-reshape.php';
require_once __DIR__ . '/sn-apply/block-edit.php';
require_once __DIR__ . '/sn-apply/restore-revision.php';
require_once __DIR__ . '/sn-apply/sentence-replace.php';
require_once __DIR__ . '/provenance-change-class.php'; // change.change_class vocabulary (2026-10-17)
require_once __DIR__ . '/sn-apply/batch-edits.php';
require_once __DIR__ . '/sn-apply/plan-changes.php';
require_once __DIR__ . '/sn-apply/roadmap-board.php';
require_once __DIR__ . '/sn-apply/change-class-backfill.php'; // labels for versions minted before change_class (2026-10-17)
require_once __DIR__ . '/sn-apply/executors.php';

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-apply', array(
		'label'               => 'Apply a change to a post (consolidated write tool)',
		'description'         => 'The only tool that mutates post content. RULES FOR EVERY TYPE (stated once; each type below adds only what differs). GATES: four run in order (fingerprint, server-side validation, mode capability, idempotency), and every one is reported in the response even when an earlier gate already failed. DRY RUN: dry_run defaults to TRUE; a caller has to actively ask to write. MODES: mode:"revision" stages a WordPress revision without touching the live post (the PR pattern); mode:"publish" writes live. Routine (non-owner) credentials are granted "revision" only, enforced server-side against the calling identity, never a client-chosen parameter. PUBLISH-ONLY types, which mode:"revision" refuses by name rather than fabricate a staged version of something that cannot be staged: og_card (regenerates a PNG file, not a post field), anchor_sweep (a live HTTP call to the provenance Worker, no post involved), restore_revision, roadmap_board, change_class_backfill, dismiss. REVISION-ONLY types, which mode:"publish" refuses: create_draft, delete_draft; this tool never makes a draft live, the owner schedules drafts by hand. FINGERPRINT: where a type binds change.fingerprint it is REQUIRED; missing is a 422 caller error, stale is the 409 merge conflict. "Live hash" below means the LIVE post\'s current content_hash as sn_posts exposes it. Candidate-driven types (drift_replace, emdash_replace, link_insert, block_migration, pattern_adoption) bind the fingerprint their own scan or suggest pipeline minted, which a composing caller cannot produce. There is no separate read tool for a fingerprint: call dry_run:true, read gates.fingerprint.observed, then re-issue with it. TARGETS: target may be one object or an array (batch): per-post writes are atomic; across posts they are independent, and one target failing never rolls back another. IDEMPOTENCY (v11.5.0): supply idempotency_key to control replay yourself; WITHOUT one, every dry_run:false call gets a server-derived auto-key over (target, type, mode, fingerprint, payload), so a byte-identical retry after a timeout replays the first response (replayed:true) instead of executing twice. Send the SAME key when retrying, a FRESH key per logical call. og_card and anchor_sweep get no auto-key: identical repeats are legitimate there (force-regenerate, re-sweep) and always execute. CHANGE CLASS: optional change.change_class (reading_aid | correction | copy | claim | unclassified) labels the provenance version this write mints: the author\'s description of the edit, stored beside the signed payload and never in it. An unknown value is a 422; a write that adds or replaces a correction notice cannot be classed reading_aid or copy. PROSE DELTA: the provenance ledger signs NORMALIZED prose (markup stripped). Every block-family and batch diff, dry run AND real write, carries prose_changed (bool), prose_added / prose_removed (normalized text) and ledger_impact ("coalesces" | "new_version"), so the ledger consequence is visible BEFORE anything is written: a restructure-only edit coalesces to no new version, new text mints one. SCHEDULED POSTS (block family and batch): post_status is captured before the write, passed explicitly through it and re-asserted after, for EVERY status; post_date strictly for status "future" only (WordPress floats a draft\'s date on save, so binding it would fail routine draft edits). A violation attempts a restore whose effect is VERIFIED by re-reading the row (never inferred from a return code) and fails loudly (500) naming the verified outcome: a scheduled post never publishes early as a silent side effect. An OVERDUE scheduled post (post_date_gmt passed or under a minute away, cron not yet fired) refuses UP FRONT in publish mode (409, snt_sn_apply_schedule_overdue): core\'s own status resolution would early-publish it on any write, restore included, so nothing is written; let cron publish it (or publish deliberately), then retry with a fresh fingerprint. mode:"revision" still stages (staging never touches the live row). MULTI-CHANGE (payload.edits and batch): each mints ONE provenance ledger version where separate calls would mint one each, all permanent (an em-dash pair once recorded itself as two versions; one amendment as three). Every change is located against the ORIGINAL content and the splices are applied in DESCENDING position order, so no offset is ever re-resolved. ALL-OR-NOTHING: any change that fails to validate, locate or match refuses the whole call, naming its 1-based index, and writes nothing, because a partially-applied editorial act is precisely the half-converted state these exist to prevent; overlapping byte ranges are a 422. Maximum 50 per call. THE TYPES. sentence_replace: the ONLY path for a body edit the caller COMPOSED ITSELF; there is deliberately NO whole-body update path. target {post_id}; payload {phrase (a sentence-scale span >= 20 chars copied BYTE-EXACTLY from sn_posts\' content field, punctuation and quotes included, never retyped), replacement (plain prose only, no HTML; block structure is unreachable from this type), context_snippet? (~200 chars around the span, disambiguates repeats)}. Fingerprint: the live hash. payload.edits (v10.66.0): N prose splices to ONE post in ONE write, for emdash_replace, drift_replace and sentence_replace only; use it whenever a scan returns more than one candidate for the same post. A list of {phrase, replacement, context_snippet?}, plus a per-edit fingerprint for the drift family (minted per candidate); sentence_replace takes none, because change.fingerprint already binds the whole post. Edits inside each other\'s 80-char fingerprint window are fine. NOT available for link_insert, link_reshape, unlink or the block family, which rewrite tag structure in ways a byte-range overlap check cannot see. When present, the top-level payload.phrase and payload.replacement are unused; diff.edits_applied reports the count. link_reshape (v10.58.0): moves an existing <a>\'s boundaries within one text node, the ONLY way to change where an anchor starts and stops (sentence_replace is plain prose and would delete the link). target {post_id}; payload {current_anchor (the <a>\'s exact inner text, byte-exact from stored content), new_anchor (MUST be a contiguous substring of current_anchor occurring exactly once within it: pure tag movement with byte-identical rendered prose, asserted server-side after the splice), context_snippet? (disambiguates identical anchors)}. href and every other attribute carry over from the existing tag, never parameters; retargeting is not this tool. new_anchor:"" refuses. Fingerprint: the live hash. The ledger coalesces (no new version). unlink (v10.59.0): payload {anchor_text (the <a>\'s exact inner text), context_snippet?}; the same fingerprint, locator and server-side prose-identity assertion as link_reshape; the wrapper is removed and the text kept, attributes discarded with it. create_draft (REVISION-ONLY): a real draft post IS created (never published); there is no no-op staging for a nonexistent post. target {new_post:true}; payload {title, content (Gutenberg block markup), excerpt?, tags? (existing vocabulary only), meta_description?, og_card_title?}. The last two are attached in the SAME write and validated in the SAME gate-2 pass by surfaces\' own validators, og_card_title against the title in THIS payload, so a draft can be created complete in one call; they write the same meta keys surfaces writes. The response reports surfaces_set (the fields actually attached; an empty array, never absent). delete_draft (REVISION-ONLY, TRASH-ONLY, DRAFT-ONLY): create_draft\'s rollback. target {post_id}; wp_trash_post, never a hard delete (recoverable from wp-admin Trash until WordPress\'s own purge); any status but draft refuses at gate 2 and again inside the write. Fingerprint: the draft\'s current content_hash (create_draft\'s rollback object carries it). rollback.method is "manual_untrash": restoring from Trash is a wp-admin action, deliberately NOT an MCP method. restore_revision (PUBLISH-ONLY): the ACCEPTANCE path for the PR pattern; a restore IS the live write, so a routine credential is always refused. target {post_id}; payload {revision_id, apply_staged_meta? (default true)}. The revision must belong to the target post (a foreign revision refuses 409 naming both ids). Fingerprint: the live hash (a restore against a since-edited post is a stale-branch conflict). The write takes a rollback snapshot of the pre-restore live state first, then applies and clears the staged-meta rows queued under the SAME post_id (surfaces\' meta_description, og_card_title, seo_title, focus_keyword staged via mode:"revision"). A dry run\'s diff carries staged_meta_pending (meta_key -> proposed value, exactly what acceptance co-publishes; an empty object means nothing is queued). alt_text\'s staged rows are queued under the ATTACHMENT id, so restore_revision (which only targets a post) cannot reach them; they have no application path today. block_insert and block_replace (v13.2.0): the caller-composed BLOCK family, the only path to add or replace block markup in an EXISTING post. block_insert: target {post_id}; payload {blocks (serialized Gutenberg block markup, required), anchor (a sentence-scale span >= 20 chars copied BYTE-EXACTLY from the stored post_content, resolving entirely inside ONE top-level block; omittable only with position "end"), position ("before" | "after" | "end", default "after"), context_snippet?}. block_replace: payload {blocks, anchor, context_snippet?}; replaces the WHOLE top-level block containing the anchor and reports it as diff.replaced_block. Refusals, each by name: markup that does not survive a parse/serialize round-trip byte-identically; content that parses as freeform (a malformed delimiter parses as freeform and round-trips CLEANLY, so it is refused explicitly, never written); any unregistered block name (innerBlocks included, the refusal names the block); an anchor with zero matches (409, naming it), still ambiguous after context_snippet (422, naming it), spanning a top-level block boundary, or intersecting a block-delimiter comment. block_delete: removes one located top-level block (and one adjacent whitespace separator, so the content keeps its rhythm), REFUSES to empty a post, reports diff.removed_block. block_move: relocates one block in a SINGLE call: source payload.block_path (required); destination position "before" / "after" plus exactly one of payload.anchor / payload.to_block_path, or position "end". Assembling a move from two replaces can strand the caller mid-swap with a block deleted and no scripted way back. A no-op move refuses; diff.moved_block reports the block; a move reorders prose, so it mints a version. BLOCK FAMILY (all four): fingerprint is the live hash; the prose delta and the scheduled-post rules apply; payload.edits is refused (one call per block edit); block_delete and block_move take no payload.blocks (a reword is block_replace). LOCATOR (v13.5.0): payload.block_path ("0/<index>", the EXACT syntax sn_scan\'s block_migrations surfaces on targets[].block_path: a literal 0 seed plus the RAW parse_blocks index, whitespace separator nodes included) is an alternative to anchor for block_insert, block_replace and block_delete, and the only way to reach a dynamic block whose text lives entirely in its delimiter attributes (signal-noise/sidenote, pull-quote; anchoring on attribute JSON refuses by design). EXACTLY ONE locator per call: both, or neither where one is needed, is a named 422 (snt_sn_apply_locator_conflict / snt_sn_apply_locator_required), never a silent precedence rule. A block_path is position-bound but can never silently hit the wrong block: the live hash 409s BEFORE any path is dereferenced, and a path that misses under a FRESH hash is caller arithmetic, refused naming the path and what actually sits there (out_of_range / not_a_block for whitespace-separator or freeform indices / not_top_level for nested paths). One call is one splice, so the next call needs a fresh hash (block_migrations\' descending-order rule does not apply). batch (v13.94.0): MULTIPLE, DIFFERENT changes to ONE post in ONE write (payload.edits batches a single type). target {post_id}; payload {changes: [{type, payload}, ...]}, applied as ONE act. Members: sentence_replace, block_insert, block_replace, block_delete (each resolves to exactly one byte span, which makes the batch orderable). Not members: block_move (its destination resolves against post-delete content, which breaks the plan-against-original rule) and link_insert / link_reshape / unlink (they rewrite tag structure inside a text node). ONE live hash binds the WHOLE batch. CONFLICTS refuse the whole batch (422) naming BOTH 1-based indexes and the reason: overlapping byte spans (which also covers a prose edit NESTED in a block another change replaces), two inserts at the SAME point (their order would be undefined), and an insert anchored to the leading or trailing edge of a span another change replaces or deletes (its landing point would be undefined). The dry run reports the COMBINED diff and a SINGLE ledger_impact plus changes_applied: the caller reviews one editorial act, not N intermediate ones. The scheduled-post rules apply in full (batch and the single block edits share ONE implementation of them). roadmap_board (PUBLISH-ONLY; an option write has no WordPress revision, so dry_run:true is the review step): the public maturity roadmap page as data. target {scope:"maturity_roadmap"} (a site surface, not a post); payload {board} (the FULL replacement board: family label -> {done/planned/considering: sentence[]}; wholesale, there is no per-cell patch) or {reset:true} (delete the override; the page returns to its code-canonical default). Fingerprint: binds the CURRENT effective board; read it, and the current board as diff.before, from a dry_run:true call. diff.merge (conflicts/code_landed/override_held) names any cell code and the override have both moved since the override\'s base: check conflicts before writing, or a landed code edit is silently overwritten. diff.merge.invalid (bool, always present) is true when the CURRENT merge already fails validation; conflicts then reads empty by construction, not because nothing moved, so check invalid before trusting an empty conflicts list (diff.before is the static board, not the real merge, until it is cleared). Gate 2 enforces plain prose (no markup), structural bounds and a banned-internal-token sweep mirroring the public page\'s own leak-sweep test: copy that would leak an internal name is refused at the door, never rendered. change_class_backfill (2026-10-17, PUBLISH-ONLY): labels provenance versions minted before change_class existed, beside the signed payload (no payload, hash or anchor moves). target {scope:"provenance_labels"}; payload {window_dates: ["YYYY-MM-DD", ...] (UTC calendar days, matched against each chain entry\'s committed_at, stored as ISO-8601 UTC; v0 genesis excluded), labels: [{post_id, change_class (reading_aid | correction | copy | claim)}], expected_counts? ({class: n} for the labels this call applies; a mismatch is a 422)}. Each listed post must have exactly one version on the window dates, or the whole call refuses (422) with diff.refused naming every post and its candidate versions. The diff carries rows {post_id, title, version, minted_at (the entry\'s committed_at), current_class, proposed_class, audit_match} and change_classes_before/after. audit_match is day-level (sn_apply_write_same_day | no_sn_apply_write_that_day | audit_unavailable): the rw audit log records neither the post nor the version a write minted, so a label with no write behind it is flagged, not refused. Fingerprint: binds the planned rows; the first dry_run:true without it is a 422 carrying the plan. The write labels every planned version or none. dismiss (PUBLISH-ONLY): records a scan dismissal in the dismissal store, not a post field. target {post_id}; payload {surface, block_fingerprint, candidate_type}.',
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
						'scope'         => array( 'type' => 'string', 'enum' => array( 'provenance_anchors', 'maturity_roadmap', 'provenance_labels' ) ),
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
						// 2026-10-17: the author's label for the version this write
						// mints, stored beside the signed payload, never in it.
						'change_class' => array( 'type' => 'string', 'enum' => SN_PROV_CHANGE_CLASSES ),
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
				// purpose, see inc/sn-apply/restore-revision.php).
				'post_id'              => array( 'type' => array( 'integer', 'null' ) ),
				'restored_revision_id' => array( 'type' => array( 'integer', 'null' ) ),
				'rollback_revision_id' => array( 'type' => array( 'integer', 'null' ) ),
				'meta_applied'         => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
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

	// 2026-10-17: the change class is optional; when given it must be one of
	// the fixed vocabulary, checked here so a batch of targets is refused whole.
	if ( array_key_exists( 'change_class', $change ) && ! in_array( $change['change_class'], SN_PROV_CHANGE_CLASSES, true ) ) {
		return new WP_Error( 'snt_sn_apply_bad_change_class', sprintf( 'change.change_class must be one of: %s.', implode( ', ', SN_PROV_CHANGE_CLASSES ) ), array( 'status' => 422 ) );
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
	snt_sn_apply_change_class_gate( $gate2, $change, $resolved, $gate1['new_content'] ?? null );

	$has_error_finding = false;
	foreach ( $gate2['findings'] as $f ) {
		if ( 'error' === ( $f['severity'] ?? '' ) ) {
			$has_error_finding = true;
			break;
		}
	}
	$gate2_passed = ! $has_error_finding;

	// v13.95.1 — A PLAN FAILURE IS NOT A FINGERPRINT FAILURE.
	// gate 1 does double duty for the batching types: it proves the
	// whole-post hash AND runs the planner. When the hash matched and only
	// the plan was refused it now returns fingerprint_ok, and the conflict
	// is reported where it belongs — as a VALIDATION finding, which is what
	// a payload conflict is, and which already carries the 422 this refusal
	// returns. Before this, the response said fingerprint.passed:false with
	// expected and observed IDENTICAL: a contradiction that sends the caller
	// to re-fetch a hash nothing was wrong with.
	$fingerprint_ok = (bool) ( $gate1['fingerprint_ok'] ?? $gate1['passed'] );
	$plan_error     = isset( $gate1['plan_error'] ) ? (string) $gate1['plan_error'] : '';

	if ( '' !== $plan_error && function_exists( 'snt_sn_validate_finding' ) ) {
		$gate2['checks'][]   = 'plan';
		$gate2['findings'][] = snt_sn_validate_finding(
			'plan',
			(string) ( $gate1['error_code'] ?? 'snt_sn_apply_plan_refused' ),
			'error',
			$plan_error,
			null,
			null,
			array(),
			(string) ( $resolved['post_id'] ?? 0 ) . '|plan'
		);
	}

	$gates = array(
		'fingerprint' => array(
			'passed'   => $fingerprint_ok,
			'expected' => $gate1['expected'],
			'observed' => $gate1['observed'],
			'skipped'  => $gate1['skipped'],
			// The planner's message belongs to the validation gate now; the
			// fingerprint gate reports only on the hash it actually checked.
			'detail'   => '' !== $plan_error ? null : $gate1['detail'],
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

	// Recompute after the plan finding above: a plan refusal is a gate-2
	// error finding, so gate 2 must fail with it rather than reporting
	// "passed" beside a refused call.
	if ( '' !== $plan_error ) {
		$gate2_passed             = false;
		$gates['validation']['passed'] = false;
		$gates['validation']['skipped'] = null;
	}

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

	// The change class is held for this post across its write, where
	// sn_prov_record() reads it, and released after whether or not the write
	// minted a version (a coalesced save must not leave it for the next one).
	$class_post = (int) ( $resolved['post_id'] ?? 0 );
	if ( $class_post > 0 ) {
		sn_prov_change_class_set_pending( $class_post, (string) ( $change['change_class'] ?? '' ) );
	}
	try {
		$write = snt_sn_apply_execute_write( $type, $resolved, $change, $mode );
	} finally {
		if ( $class_post > 0 ) {
			sn_prov_change_class_clear_pending( $class_post );
		}
	}
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
		'change_class'            => is_string( $change['change_class'] ?? null ) ? $change['change_class'] : '',
	);

	$outcome = $applied || $dry_run ? 'ok' : 'error';
	sn_mcp_rw_audit_record( 'signal-noise/sn-apply', $args, $outcome );
}

/**
 * The change-class rule as a gate-2 finding: a write that adds or replaces a
 * correction notice cannot be labeled reading_aid or copy. Runs on dry runs
 * too, and only where gate 1 produced the proposed content.
 *
 * @param array       $gate2       Gate-2 result, extended in place.
 * @param array       $change
 * @param array       $resolved
 * @param string|null $new_content
 */
function snt_sn_apply_change_class_gate( array &$gate2, array $change, array $resolved, $new_content ) {
	$post = null === $new_content ? null : get_post( (int) ( $resolved['post_id'] ?? 0 ) );
	if ( ! $post || ! function_exists( 'snt_sn_validate_finding' ) ) {
		return;
	}
	$why = sn_prov_change_class_refusal( (string) ( $change['change_class'] ?? '' ), (string) $post->post_content, (string) $new_content );
	if ( '' === $why ) {
		return;
	}
	$gate2['checks'][]   = 'change_class';
	$gate2['findings'][] = snt_sn_validate_finding( 'change_class', 'snt_sn_apply_change_class_mismatch', 'error', $why, (string) $change['change_class'], 'correction', array(), (string) $post->ID . '|change_class' );
}

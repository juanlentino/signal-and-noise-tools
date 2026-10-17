<?php
/**
 * Signal & Noise Tools — sn_apply per-change-type executors. MCP
 * consolidation session 6b.
 *
 * Everything TYPE-SPECIFIC lives here: target-shape resolution, the
 * mode-support matrix (gate 3's structural half), and the actual write
 * dispatch. Gates 1 (fingerprint) and 2 (server-side validation) live in the
 * sibling inc/sn-apply/validation.php (split out purely for the 450-line
 * file budget — same executor layer). Every write DELEGATES to the real
 * absorbed impl's core logic — this file never re-implements a capability
 * check, a sanitizer, or a fingerprint scheme; see each case below for
 * exactly which function it calls and why.
 *
 * ── The mode-support matrix (grounded, not asserted) ──
 *
 * | type             | revision | publish | why                                                                 |
 * |------------------|:--------:|:-------:|----------------------------------------------------------------------|
 * | block_migration   |   yes    |   yes   | content field write; snt_block_fp_apply()'s injectable write_callback (v10.40.0) routes it through snt_sn_apply_stage_revision(). |
 * | pattern_adoption  |   yes    |   yes   | same mechanism as block_migration (shared engine).                    |
 * | emdash_replace    |   yes    |   yes   | prose em-dash fix; DELEGATES to drift_replace's splice (identical mechanics, different candidate source: snt_emdash_scan_content()).
 * | drift_replace     |   yes    |   yes   | content field write (substr_replace on post_content); snt_ai_drift_apply_impl()'s injectable write_callback (v10.40.0). |
 * | link_insert       |   yes    |   yes   | same mechanism as drift_replace (snt_ai_link_apply_impl()).           |
 * | alt_text          |   yes    |   yes   | postmeta write (_wp_attachment_image_alt); revision mode uses snt_sn_apply_stage_meta() — session 6a's staged-meta draft queue, not a postmeta write. |
 * | surfaces          |  partial |   yes   | excerpt is a content field (post_excerpt, revision-stageable); meta_description/og_card_title/seo_title/focus_keyword are postmeta (staged via snt_sn_apply_stage_meta()). Revision mode stages ALL of these but never regenerates the OG card PNG — that side effect is publish-only (see og_card below). |
 * | og_card           |    NO    |   yes   | sn_generate_og_card() writes a PNG FILE to disk (inc/og-card-generator.php) — not a post field at all. There is no WordPress revision of a file. Refuses structurally in revision mode, never fakes a staged version. |
 * | anchor_sweep      |    NO    |   yes   | dispatches a bounded wp_remote_post() to the provenance Worker (inc/provenance-webhook.php's sn_prov_run_sweep()) — an external side effect with no post entity involved at all (target is scope:"provenance_anchors", not a post_id). Nothing to stage. |
 * | create_draft      |   yes    |   NO    | insert-only (session 6c, the arc's finale — see inc/sn-apply/create-draft.php's docblock for the full B5c origin): post_status is hard-coded 'draft', post_type hard-coded 'post'. mode:"publish" refuses structurally — this tool never makes a draft live; the owner schedules by hand. mode:"revision" is the one accepted mode, but its OWN write mechanism (snt_sn_apply_write_create_draft(), not snt_sn_apply_stage_revision()) — there is no parent post yet to stage a core revision against; "revision" here just means the request goes through the same dry_run-by-default, human-review-first posture as everywhere else in this tool. |
 * | link_reshape      |   yes    |   yes   | audit item 5 (v10.58.0): move an <a>'s boundaries within one text node. new_anchor must be a contiguous, unique substring of current_anchor; href carried over, never a parameter; prose byte-identity ASSERTED post-splice; fingerprint = live content_hash (sentence_replace's binding). Provenance-invisible by item 4's answer (normalized prose is unchanged -> bearing-hash coalesce -> no new commit). |
 * | unlink            |   yes    |   yes   | v10.59.0, link_reshape's promised sibling: remove an <a>'s wrapper, keep the inner text — same locator, same fingerprint binding, same post-splice prose-identity assertion. |
 * | delete_draft      |   yes    |   NO    | create_draft's mirror (audit item 6, v10.58.0): trash-only (wp_trash_post, never a hard delete), draft-only (gate 2 + a last-instant re-check in the write), fingerprint-gated on the draft's content_hash. Revision-only for create_draft's reason; rollback is wp-admin untrash (reported as method "manual_untrash" — a human action, not an MCP method). |
 * | block_insert      |   yes    |   yes   | v13.2.0: caller-composed block markup spliced before/after an anchored top-level block (or appended); content field write via snt_sn_apply_block_edit_impl()'s injectable write_callback. Scheduled posts keep status+date, asserted post-write. |
 * | block_replace     |   yes    |   yes   | v13.2.0: same module — the whole top-level block containing the anchor is replaced; diff reports the replaced block's serialized form. Same write/guard contract as block_insert. |
 * | block_delete      |   yes    |   yes   | v13.5.0: removes one top-level block (located by anchor OR block_path, exactly one); refuses to empty the post; diff reports removed_block. Same write/guard contract. |
 * | block_move        |   yes    |   yes   | v13.5.0: relocates one top-level block in a SINGLE call (source by block_path; destination before/after a block or end); diff reports moved_block. Same write/guard contract. |
 * | change_class_backfill | NO |   yes   | 2026-10-17: labels existing provenance versions beside the signed payload (sn_prov_set_change_class()). A label on an existing version has no revision to stage. |
 * | restore_revision  |    NO    |   yes   | session 7 (the acceptance path — see inc/sn-apply/restore-revision.php's docblock): a restore IS the live write, so "staging a restore" would stage a revision of a revision. mode:"revision" refuses structurally, the exact mechanism og_card/anchor_sweep use. Publish-only means only the rw door's bound owner credential can ever execute it (gate 3's identity grant); a routine credential is refused there by construction, never by new identity code. Promotes a staged revision to live AND applies+clears any queued snt_sn_apply_stage_meta() rows for the same post — the queue's first application path. |
 *
 * "partial" on surfaces is intentionally not a clean yes/no: the TYPE
 * supports revision mode (the gate lets the mode through), but the response
 * itself documents which sub-fields staged vs which side effect (card
 * regen) was skipped — see snt_sn_apply_write_surfaces() in executors-diff-parts.php.
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Session 6c appended 'create_draft' (the arc's finale); session 7 appends
// 'restore_revision' (the acceptance path). Every gate function below
// switches on this list, never a hardcoded count; the
// tests/abilities-sn-apply-delegation-sweep.php ALL-TYPES sweep loops this
// same constant, so it REDs on its own count pin the moment a type is added
// here without a matching sweep-table entry (watched RED, see FINDINGS.md).
const SNT_SN_APPLY_CHANGE_TYPES = array(
	'block_migration', 'pattern_adoption', 'alt_text', 'link_insert',
	'drift_replace', 'surfaces', 'og_card', 'anchor_sweep', 'create_draft',
	'restore_revision', 'emdash_replace', 'sentence_replace',
	'roadmap_board',
	// v13.47.0: the wave-1 dismissal gap. dismiss-candidate has been reachable
	// from NEITHER door since v12.0.0 — sn_mcp_allowlist()'s own comment carries
	// the WATCH ("dismiss-candidate backed sn-scan's `dismissed` flow") and it
	// GATES phase 10. Without it every scan surfaces candidates a caller can
	// APPLY but cannot DISMISS, so a candidate list never converges.
	// See inc/abilities-dismiss.php for the contract this mirrors.
	'dismiss',
	// PHASE 3 (un-versioned handoff): two exclusions the OPTIONS framing reverses.
	// mcp-capabilities.php holds both OFF the rw door "on purpose" — merge-tags
	// as "sitewide term reassign + delete", clear-template-overrides as "wipes
	// Site Editor template rows". BOTH REASONS WERE WRITTEN AGAINST A DOORED
	// TOOL. As a change type each inherits dry_run:true by default, four gates,
	// idempotency and the rw audit trail: same capability, different risk
	// object. Both abilities are manage_options, so no permission tier is
	// crossed. ai-orphan-apply is NOT reversed — it is a permanent delete that
	// skips trash, and irreversibility is the one thing the gates cannot fix.
	'merge_tags',
	'clear_template_overrides',
	// v13.49.0 — the cron half of Phase 3, which the plan got WRONG in a way
	// worth recording. It named two types: run_cron_event allowlisted to
	// scheduled hooks, plus a fire-and-forget schedule_health_scan. Those
	// COLLAPSE: SN_HEALTH_CRON_HOOK ('sn_health_scan_daily') is itself a daily
	// scheduled hook, so an allowlisted run_cron_event would admit the health
	// scan and run it SYNCHRONOUSLY — reintroducing the 35-105s-against-a-~100s
	// edge cap that the fire-and-forget half exists to avoid. One type, not two:
	// schedule_cron_event books a run and returns. run-cron-event stays off
	// every door, because dispatch is the hazard and booking is not.
	'schedule_cron_event',
	// v10.58.0 (audit item 6): makes create_draft's advertised
	// rollback:{method:"delete_draft"} REAL — trash-only, draft-only,
	// fingerprint-gated. See inc/sn-apply/delete-draft.php.
	'delete_draft',
	// v10.58.0 (audit item 5, owner-confirmed after item 4): move an <a>'s
	// boundaries within one text node — rendered prose byte-identical,
	// asserted server-side. See inc/sn-apply/link-reshape.php.
	'link_reshape',
	// v10.59.0: link_reshape's promised sibling — remove an <a>'s wrapper,
	// keep the inner text. Same module file, same identity assertion.
	'unlink',
	// v13.2.0: the caller-composed BLOCK edit family — insert markup
	// before/after an anchored top-level block (or at the end), or replace
	// the whole top-level block containing the anchor. Live content_hash
	// fingerprint, round-trip + registry markup gate, prose-delta reporting.
	// See inc/sn-apply/block-edit.php and its parts files.
	'block_insert',
	'block_replace',
	// v13.5.0: the block edit family's remaining verbs — delete (removal was
	// otherwise impossible through MCP) and move as a SINGLE operation (two
	// individually-legal replaces can strand a caller mid-swap; see
	// inc/sn-apply/block-edit-compute-parts.php's compute_move docblock). Both accept the
	// new block_path locator, the only way to reach a dynamic block whose
	// text lives in its delimiter attributes.
	'block_delete',
	'block_move',
	// v13.94.0 — the heterogeneous batch. `payload.edits` (v10.66.0) already
	// batched N edits of ONE type; this batches DIFFERENT types, because an
	// editorial amendment is rarely homogeneous. On 2026-09-03 a single
	// amendment (one sentence_replace + two block_inserts) minted THREE
	// anchored ledger versions, two of them intermediate states nobody
	// intended to publish and all three permanent. payload.changes is the
	// ordered list; one fingerprint gates the whole batch; one write lands it.
	'batch',
	// 2026-10-17: label versions minted before change.change_class existed,
	// through sn_prov_set_change_class(). See inc/sn-apply/change-class-backfill.php.
	'change_class_backfill',
);

// The rest of the module lives in three parts files (house line cap, 2026-10-14),
// loaded in the order it always ran, after the change-type list above.
require_once __DIR__ . '/executors-resolve-parts.php';
require_once __DIR__ . '/executors-write-parts.php';
require_once __DIR__ . '/executors-diff-parts.php';

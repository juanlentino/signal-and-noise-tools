<?php
/**
 * Signal & Noise Tools — sn_apply change.type "restore_revision" (MCP
 * consolidation session 7 — the acceptance path).
 *
 * docs/mcp-consolidation/FINDINGS.md's "Session 7 pre-design" section is the
 * spec this file builds: `mode:"revision"` responses advertise
 * `rollback:{method:'restore_revision', revision_id}`, but before this file
 * no MCP path could EXECUTE a restore — accepting a staged revision meant
 * classic wp-admin or WP-CLI. Worse, the staged-META queue
 * (snt_sn_apply_stage_meta(), session 6a) had NO application path anywhere:
 * a human restoring via wp-admin applies the content revision and strands
 * queued meta in wp_options forever, since the wp-admin Restore button knows
 * nothing about SN's own meta queue. This file is the FIRST application path
 * for that queue — but scoped to POST-targeted rows only (surfaces' fields,
 * staged under the post's own id). alt_text's own staged rows are queued
 * under the ATTACHMENT id it targets (inc/sn-apply-executors.php's alt_text
 * target resolution), and restore_revision's target is always {post_id} —
 * it structurally never resolves an attachment, so those rows are NOT
 * reached by snt_sn_apply_apply_staged_meta_for_post() below and remain
 * stranded, with no application path anywhere in this codebase today
 * (review MEDIUM 2, REJECT #10 — corrected from an earlier overclaim in
 * this file's own description text that implied alt_text was covered too).
 *
 * ── Publish-only, by the SAME structural mechanism og_card/anchor_sweep use ──
 *
 * A restore IS the live write — it is the acceptance step of the PR pattern.
 * "Staging a restore" would mean staging a revision of a revision, which has
 * no meaning. snt_sn_apply_mode_support('restore_revision') (inc/sn-apply-
 * executors.php) grants only 'publish'; a routine credential (granted
 * 'revision' only, snt_sn_apply_granted_modes()) is refused at gate 3 by the
 * SAME identity grant every other publish-only call already goes through —
 * no new identity code, no new capability check. See FINDINGS.md's "Trust-
 * posture analysis" for the fuller argument: og_card/anchor_sweep are
 * ALREADY publish-only live side effects the owner credential can execute
 * today, so this change type inherits an existing split, it does not widen
 * one.
 *
 * ── Structural pre-check, BEFORE the four gates run ──
 *
 * snt_sn_apply_restore_revision_precheck() runs in
 * inc/abilities-sn-apply.php's snt_sn_apply_apply_one(), right after target
 * resolution and before gate 1. It verifies the revision named in
 * change.payload.revision_id (a) exists, (b) is post_type 'revision'
 * (wp_get_post_revision() enforces both — it returns null for a missing OR
 * wrong-type post, collapsing the two; real 6.9 source, wp-includes/
 * revision.php, fetched raw and read directly, not recalled — see the 6a
 * lesson applied again here), and (c) its post_parent equals the target
 * post_id — the cross-target lesson (review REJECT #8, sn_apply's own
 * idempotency HIGH) generalized: never act on another target's artifact. A
 * foreign-parent revision refuses 409 naming BOTH ids. This is deliberately
 * NOT restricted to sn_apply-staged revisions — wp-admin lets a human
 * restore ANY revision of a post, and narrowing this tool below the human's
 * own capability buys nothing but a confusing extra refusal.
 *
 * ── Gate 1: a REAL fingerprint scheme, not a skip ──
 *
 * inc/sn-apply-validation.php's snt_sn_apply_gate1_fingerprint() binds to
 * the LIVE row's current content_hash — snt_corpus_content_hash(),
 * inc/corpus-inspect.php, the SAME function signal-noise/sn-posts exposes to
 * callers as `content_hash` — REUSED, never a parallel hash. A restore
 * proposed against a since-edited post is the stale-branch merge conflict:
 * the caller observed content_hash via sn_posts, time passed, someone else
 * edited the live post, and blindly restoring now would silently discard
 * that edit. The fingerprint is REQUIRED for this type (missing -> 422,
 * distinct from mismatched -> 409) — every other type's fingerprint is
 * either caller-optional-but-checked or has no scheme at all; this is the
 * first type where "you must tell me what you think is live" is itself part
 * of the gate.
 *
 * ── Gate 2: validated against the REVISION's fields, not the live post's ──
 *
 * snt_sn_apply_gate2_restore_revision() below runs sn_validate's own check
 * functions against the REVISION's post_content (always — the `body` family)
 * and post_excerpt (only when non-empty — the `excerpt` family). post_title
 * has no sn_validate check family anywhere in this codebase (confirmed:
 * neither the inversion table in inc/sn-validate-checks.php nor
 * inc/sn-validate-checks-media.php defines one — og_card_title is a
 * DIFFERENT surface, not post_title itself), so it is honestly left
 * unchecked rather than inventing a check family this session has no
 * grounding for.
 *
 * ── Gate 4: unchanged — target-scoped idempotency, as shipped ──
 *
 * snt_sn_apply_canonical_target()'s existing 'post:N' derivation already
 * covers restore_revision's target shape ({post_id}) — no new canonicalization
 * case was needed. A replay of a completed restore returns the FIRST
 * response verbatim, replayed:true, exactly like every other type.
 *
 * ── The write step ──
 *
 * snt_sn_apply_write_restore_revision() below, in order:
 *   1. Compute the diff (snt_sn_apply_revision_diff(), read-only) BEFORE
 *      anything writes — after the restore, live-vs-revision would show no
 *      difference at all.
 *   2. snt_sn_apply_ensure_rollback_snapshot(): wp_restore_post_revision()
 *      (verified against the real 6.9 source, wp-includes/revision.php)
 *      guarantees NOTHING about a pre-restore snapshot — the pre-restore
 *      live state survives only if the last writer happened to leave a
 *      matching revision row. So this change type must guarantee its OWN
 *      rollback point: compare the newest existing revision's three
 *      allowlisted fields (post_title/post_content/post_excerpt) against
 *      the live row's CURRENT values. If they already match, REUSE that
 *      revision as the rollback target — never duplicate. If they differ
 *      (the common case — the revision being restored almost always DOES
 *      differ from live, that's the entire reason to restore it), stage the
 *      live row's OWN current content as a fresh revision via session 6a's
 *      snt_sn_apply_stage_revision() (never touches the live row; inherits
 *      the v10.41.2 staging-date fix for free, since it IS that primitive).
 *   3. snt_sn_apply_restore_revision() (inc/sn-apply-revision.php,
 *      session 6a) — the existing, verified wrap of wp_restore_post_revision().
 *   4. If apply_staged_meta (default TRUE — see the docblock on
 *      snt_sn_apply_write_restore_revision() below for why): apply and
 *      clear the staged-meta queue for THIS post_id via the new
 *      per-post index (inc/sn-apply-revision.php's
 *      snt_sn_apply_staged_meta_index_option_name(), maintained additively
 *      inside snt_sn_apply_stage_meta() itself — existing callers' return
 *      shape is byte-unchanged).
 *
 * The response's `rollback` ALWAYS points at the snapshot from step 2 — the
 * pre-restore live state — NEVER at the revision that was just restored.
 * Rolling back a restore must undo it, not re-apply it.
 *
 * ── The apply_staged_meta default: TRUE, and why ──
 *
 * FINDINGS.md's "decisive fact" is the whole reason this change type exists:
 * a human restoring via classic wp-admin CANNOT apply the staged-meta queue
 * — the Restore button knows nothing about it, so meta strands in
 * wp_options forever no matter how careful the human is. If this flag
 * defaulted to false, most real callers of THIS tool would reproduce the
 * exact same stranding, silently, defeating the one thing an sn_apply-owned
 * restore path is uniquely positioned to fix ("only an sn_apply-owned
 * restore path can atomically finish what staging started"). The human
 * review gate for this whole tool is dry_run (defaults true everywhere,
 * this type included) — a caller reads the diff in chat, decides, THEN sets
 * dry_run:false. Once that explicit decision is made, completing the
 * acceptance (content + meta together, as one logical "PR") is the more
 * honest default than silently leaving half of it undone. A caller who
 * genuinely wants content-only can pass apply_staged_meta:false explicitly.
 *
 * ── The enumeration problem, honestly bounded ──
 *
 * Before this session there was NO way to list staged-meta rows for a post
 * — snt_sn_apply_stage_meta() wrote one wp_options row per (post_id,
 * meta_key) with no index anywhere. Two options were weighed: (a) a
 * per-post index option, maintained additively inside stage_meta() itself
 * (chosen — see inc/sn-apply-revision.php), or (b) a direct $wpdb LIKE
 * query over wp_options (rejected — this codebase's WPCS ruleset and the
 * WordPress Plugin Check DirectDB sniff both flag unprepared/non-cached
 * direct option-table scans, and every other option-enumeration need in
 * this codebase already uses an explicit index/registry pattern rather than
 * LIKE, e.g. the audit log and idempotency log's own bounded-blob shape).
 * The index is ADDITIVE ONLY — it cannot retroactively enumerate rows
 * staged before it existed (any staged-meta row from v10.40.0-v10.41.2,
 * this feature's first four releases). This is accepted, not silently
 * hidden: the queue is days old and short-lived by design (a draft the
 * owner is expected to accept or discard within the same session it was
 * proposed in), so the population of "invisible to the index" rows is
 * small and time-bounded, not a permanent leak.
 *
 * @package SignalNoiseTools
 * @since 10.42.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structural pre-check for change.type "restore_revision", run BEFORE any
 * of the four gates. Verifies the revision named in $payload['revision_id']
 * exists, is a real revision, and belongs to $post_id — never whether it
 * was staged by sn_apply specifically (wp-admin lets a human restore ANY
 * revision; narrowing below that buys nothing).
 *
 * @param int   $post_id
 * @param array $payload
 * @return true|WP_Error
 *
 * WP_Error codes:
 *   snt_sn_apply_bad_payload           (422) — revision_id missing/non-positive
 *   snt_sn_apply_revision_not_found    (404)
 *   snt_sn_apply_revision_wrong_parent (409) — names BOTH ids
 */
function snt_sn_apply_restore_revision_precheck( $post_id, array $payload ) {
	$post_id     = (int) $post_id;
	$revision_id = isset( $payload['revision_id'] ) ? (int) $payload['revision_id'] : 0;

	if ( $revision_id <= 0 ) {
		return new WP_Error(
			'snt_sn_apply_bad_payload',
			__( 'payload.revision_id is required and must be a positive integer.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	// wp_get_post_revision() returns null for BOTH "no such post" and
	// "found, but not post_type=revision" (real 6.9 source,
	// wp-includes/revision.php — verified, not recalled). Either way there
	// is no revision to restore: 404.
	$revision = function_exists( 'wp_get_post_revision' ) ? wp_get_post_revision( $revision_id ) : null;
	if ( ! $revision ) {
		return new WP_Error(
			'snt_sn_apply_revision_not_found',
			__( 'Revision not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$revision_parent = (int) ( is_object( $revision ) ? ( $revision->post_parent ?? 0 ) : ( $revision['post_parent'] ?? 0 ) );
	if ( $revision_parent !== $post_id ) {
		return new WP_Error(
			'snt_sn_apply_revision_wrong_parent',
			sprintf(
				/* translators: 1: revision ID, 2: the post it actually belongs to, 3: the requested target post ID. */
				__( 'Revision %1$d belongs to post %2$d, not the requested target post %3$d.', 'signal-and-noise-tools' ),
				$revision_id,
				$revision_parent,
				$post_id
			),
			array( 'status' => 409 )
		);
	}

	return true;
}

/**
 * Gate 2 for change.type "restore_revision" — validated against the
 * REVISION's fields (the would-be live state), never the live post's
 * current fields. See this file's docblock for which check families apply
 * and why post_title has none.
 *
 * @param array $resolved Output of snt_sn_apply_resolve_target() — carries post_id.
 * @param array $change   The raw change{} input (payload.revision_id).
 * @return array{checks:string[],findings:array}
 */
function snt_sn_apply_gate2_restore_revision( array $resolved, array $change ) {
	$payload     = (array) ( $change['payload'] ?? array() );
	$revision_id = (int) ( $payload['revision_id'] ?? 0 );
	$revision    = $revision_id > 0 ? get_post( $revision_id ) : null;

	if ( ! $revision ) {
		// Structurally unreachable in practice — snt_sn_apply_restore_revision_precheck()
		// already refused a missing/foreign revision before gate 2 ever runs.
		// Defensive only: no applicable checks rather than a fatal.
		return array( 'checks' => array(), 'findings' => array() );
	}

	$post_id  = (int) ( $resolved['post_id'] ?? 0 );
	$checks   = array( 'body' );
	$findings = function_exists( 'snt_sn_validate_check_body' )
		? snt_sn_validate_check_body( (string) $revision->post_content, $post_id )
		: array();

	if ( '' !== trim( (string) $revision->post_excerpt ) && function_exists( 'snt_sn_validate_check_excerpt' ) ) {
		$checks[]  = 'excerpt';
		$findings  = array_merge( $findings, snt_sn_validate_check_excerpt( (string) $revision->post_excerpt, $post_id ) );
	}

	return array( 'checks' => $checks, 'findings' => $findings );
}

/**
 * Ensure a rollback snapshot exists for $post_id's CURRENT (pre-restore)
 * live state. Reuses the newest existing revision if its three allowlisted
 * fields already match live — never duplicates; stages a fresh snapshot
 * (session 6a's snt_sn_apply_stage_revision(), never touches the live row)
 * otherwise.
 *
 * @param int $post_id
 * @return int|WP_Error The rollback revision's ID.
 */
function snt_sn_apply_ensure_rollback_snapshot( $post_id ) {
	$post_id = (int) $post_id;
	$live    = get_post( $post_id, ARRAY_A );
	if ( ! $live || empty( $live['ID'] ) ) {
		return new WP_Error( 'snt_sn_apply_post_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$newest = null;
	if ( function_exists( 'wp_get_post_revisions' ) ) {
		// check_enabled:false — this is a read-only comparison to decide
		// whether a reusable snapshot already exists, not a policy decision;
		// snt_sn_apply_stage_revision() below still runs its OWN
		// post_type_supports()/wp_revisions_to_keep() checks (loud, never
		// silent) if a fresh snapshot turns out to be needed.
		$revisions = wp_get_post_revisions( $post_id, array( 'check_enabled' => false ) );
		$revisions = is_array( $revisions ) ? array_values( $revisions ) : array();

		// MEDIUM 1 fix (REJECT #10): "the newest revision" must skip
		// autosaves, exactly as real core does — wp_save_post_revision()
		// (wp-includes/revision.php:165, real 6.9 source read directly, not
		// recalled) picks its own "latest revision, but not an autosave" via
		// `str_contains( $revision->post_name, "{$revision->post_parent}-revision" )`,
		// copied verbatim here rather than re-derived. Autosave rows are
		// UPDATED IN PLACE by the next editor autosave
		// (wp-admin/includes/post.php's wp_create_post_autosave() re-uses
		// the SAME post ID rather than inserting a new row) — anchoring a
		// rollback pointer to one would be silently rewritten out from under
		// it the next time the owner's editor autosaves. wp_get_post_revisions()
		// is already sorted newest-first ('date ID' DESC), so the first
		// non-autosave row in iteration order IS "the newest revision, but
		// not an autosave" — never a duplicate of core's own semantics.
		foreach ( $revisions as $candidate ) {
			$candidate   = (array) $candidate;
			$post_name   = (string) ( $candidate['post_name'] ?? '' );
			$is_autosave = ! str_contains( $post_name, $post_id . '-revision' );
			if ( ! $is_autosave ) {
				$newest = $candidate;
				break;
			}
		}
	}

	$matches_live = false;
	if ( $newest ) {
		$newest       = (array) $newest;
		$matches_live = ( (string) ( $newest['post_title'] ?? '' ) === (string) ( $live['post_title'] ?? '' ) )
			&& ( (string) ( $newest['post_content'] ?? '' ) === (string) ( $live['post_content'] ?? '' ) )
			&& ( (string) ( $newest['post_excerpt'] ?? '' ) === (string) ( $live['post_excerpt'] ?? '' ) );
	}

	if ( $newest && $matches_live ) {
		return (int) ( $newest['ID'] ?? 0 );
	}

	$snapshot = snt_sn_apply_stage_revision( $post_id, array(
		'post_title'   => (string) ( $live['post_title'] ?? '' ),
		'post_content' => (string) ( $live['post_content'] ?? '' ),
		'post_excerpt' => (string) ( $live['post_excerpt'] ?? '' ),
	) );
	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}
	return (int) $snapshot['revision_id'];
}

/**
 * Apply and clear every staged-meta row for $post_id, via the per-post
 * index (inc/sn-apply-revision.php's snt_sn_apply_staged_meta_index_option_name(),
 * maintained additively inside snt_sn_apply_stage_meta()). Rows staged
 * before the index existed are NOT enumerated — see this file's docblock,
 * "The enumeration problem, honestly bounded".
 *
 * @param int $post_id
 * @return string[] The meta_keys actually applied, in staged order.
 */
function snt_sn_apply_apply_staged_meta_for_post( $post_id ) {
	$post_id      = (int) $post_id;
	$index_option = snt_sn_apply_staged_meta_index_option_name( $post_id );
	$index        = get_option( $index_option, array() );
	$index        = is_array( $index ) ? $index : array();

	$applied = array();
	foreach ( $index as $meta_key ) {
		$staged = snt_sn_apply_get_staged_meta( $post_id, $meta_key );
		if ( null === $staged ) {
			// Index says staged, row already gone (a prior partial apply, or
			// the row was cleared some other way) — skip, not fatal.
			continue;
		}
		update_post_meta( $post_id, $meta_key, $staged['proposed_value'] );
		delete_option( snt_sn_apply_staged_meta_option_name( $post_id, $meta_key ) );
		$applied[] = $meta_key;
	}

	// The whole queue for this post has now been processed (applied or
	// found already-gone) — clear the index too, via delete rather than an
	// empty array, so a later get_option() default (array()) and "cleared"
	// are indistinguishable, never mistaken for "loaded, still populated".
	delete_option( $index_option );

	return $applied;
}

/**
 * Gate-passed, non-dry-run write for change.type "restore_revision" — the
 * acceptance path. See this file's docblock ("The write step") for the
 * full ordering rationale.
 *
 * @param int  $post_id
 * @param int  $revision_id       Already structurally verified by
 *                                 snt_sn_apply_restore_revision_precheck()
 *                                 before any gate ran.
 * @param bool $apply_staged_meta Default true at the call site
 *                                (inc/sn-apply-executors.php) — see this
 *                                file's docblock for why.
 * @return array{ok:bool,diff:array,revision_id:null,write_result:array}|WP_Error
 */
function snt_sn_apply_write_restore_revision( $post_id, $revision_id, $apply_staged_meta ) {
	$post_id     = (int) $post_id;
	$revision_id = (int) $revision_id;

	// Step 1: compute the diff BEFORE writing anything — after the restore,
	// live-vs-revision would show no difference at all.
	$diff = snt_sn_apply_revision_diff( $revision_id );
	if ( is_wp_error( $diff ) ) {
		return $diff;
	}

	// Step 2: ensure a rollback snapshot of the CURRENT (pre-restore) live
	// state exists — reused or freshly staged, see
	// snt_sn_apply_ensure_rollback_snapshot()'s own docblock.
	$rollback_revision_id = snt_sn_apply_ensure_rollback_snapshot( $post_id );
	if ( is_wp_error( $rollback_revision_id ) ) {
		return $rollback_revision_id;
	}

	// Step 3: the actual acceptance — promote the staged revision to live.
	$restored = snt_sn_apply_restore_revision( $revision_id );
	if ( is_wp_error( $restored ) ) {
		return $restored;
	}

	// Step 4: apply + clear the staged-meta queue, if asked.
	$meta_applied = array();
	if ( $apply_staged_meta ) {
		$meta_applied = snt_sn_apply_apply_staged_meta_for_post( $post_id );
	}

	return array(
		'ok'           => true,
		'diff'         => $diff,
		// The generic top-level `revision_id` response field is ambiguous
		// for this type (restored revision? rollback snapshot?) — left null
		// on purpose; restored_revision_id/rollback_revision_id below are
		// the unambiguous, definitive fields for restore_revision.
		'revision_id'  => null,
		'write_result' => array(
			'post_id'              => $post_id,
			'restored_revision_id' => $revision_id,
			'rollback_revision_id' => $rollback_revision_id,
			'meta_applied'         => $meta_applied,
		),
	);
}

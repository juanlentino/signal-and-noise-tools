<?php
/**
 * Signal & Noise Tools — sn_apply revision-mode write primitive (MCP
 * consolidation session 6a).
 *
 * Builds the `mode: "revision"` half of the sn_apply PR-pattern in isolation,
 * per docs/mcp-consolidation/FINDINGS.md #5: every existing apply ability
 * (block-migrations-apply, pattern-adoption-apply, ai-*-apply,
 * update-post-surfaces) writes live via wp_update_post() only — "revision"
 * in their descriptions means WordPress's automatic post-save revision, not
 * a staged, non-live write. No apply ability calls this file yet; session
 * 6b wires the four sn_apply gates around it and registers the tool.
 *
 * ── Mechanism decision: _wp_put_post_revision(), not a hand-rolled insert ──
 *
 * Core's _wp_put_post_revision( $post, $autosave = false ) (wp-includes/
 * revision.php) accepts a post ID, WP_Post, or ARRAY_A post row, normalizes
 * it, and delegates the actual row-shaping to _wp_post_revision_fields():
 *
 *   $revision_data['post_parent']   = $post['ID'];
 *   $revision_data['post_status']   = 'inherit';
 *   $revision_data['post_type']     = 'revision';
 *   $revision_data['post_name']     = "$post[ID]-revision-v1"; // or -autosave-v1
 *   $revision_data['post_date']     = $post['post_modified'];
 *   $revision_data['post_date_gmt'] = $post['post_modified_gmt'];
 *
 * — plus whatever fields `_wp_post_revision_fields()` allowlists, which by
 * default is `post_title`, `post_content`, `post_excerpt` ONLY (verified
 * against the real WP 7.0.2 source, wp-includes/revision.php:29-59 —
 * `post_author` is explicitly in that function's own DISALLOWED-field unset
 * list alongside `ID`/`post_name`/`post_parent`/`post_date`/`post_status`/
 * `post_type`/`comment_count`; it is never a revisioned field, filter or no
 * filter). Two consequences that shaped this file:
 *
 *   1. A core-created revision row is NOT a full copy of the parent post —
 *      it is deliberately narrow. "Byte-for-byte the same shape as a core
 *      revision" therefore means matching THIS narrow field set, not the
 *      full wp_posts row. Hand-rolling wp_insert_post() with
 *      post_type => 'revision' ourselves would mean re-deriving this
 *      allowlist and risk drifting from it on a future core change.
 *      Delegating to _wp_put_post_revision() keeps us pinned to whatever
 *      core decides that shape is.
 *
 *   2. _wp_put_post_revision() snapshots whatever is in the post array it is
 *      given — it does not know about "proposed" changes. So this file's
 *      job is to build that array: start from get_post( $post_id, ARRAY_A )
 *      (the live row) and override ONLY the keys present in $proposed
 *      (post_title / post_content / post_excerpt) before handing the array
 *      to _wp_put_post_revision(). The live row itself is never touched —
 *      _wp_post_revision_data() (revision.php:75-96) builds the row it
 *      inserts from SCRATCH (allowlisted fields copied in one at a time,
 *      plus explicit post_parent/status/type/name/date), and 'ID' is never
 *      one of those fields, so no 'ID' key ever reaches wp_insert_post() —
 *      it is always an INSERT, never an UPDATE. That is the entire
 *      byte-identical guarantee: there is no code path in this file that
 *      calls wp_update_post() or wp_insert_post() with the parent's own ID.
 *
 * `wp_insert_post()` failure contract, grounded against the real 7.0.2
 * source (wp-includes/revision.php:369-375) rather than assumed from older
 * lore: `_wp_put_post_revision()` calls `wp_insert_post( $post, true )` —
 * `$wp_error = true` IS passed — and returns whatever comes back, including
 * a genuine `WP_Error` on a DB-level failure (wp-includes/post.php's
 * `wp_insert_post()` docblock: "@return int|WP_Error The post ID on
 * success. The value 0 or WP_Error on failure."). So the primary,
 * documented 7.0.2 failure shape IS `WP_Error` — `is_wp_error()` alone
 * catches the real path. This file keeps a second `empty( $revision_id )`
 * check as a deliberately defensive/cross-version arm (older core releases,
 * or a future change to `_wp_put_post_revision()`'s own `$wp_error` opt-in,
 * could still hand back a falsy `0`) — not because `0` is the expected
 * shape today.
 *
 * Also grounded from the same source: `_wp_put_post_revision()` calls
 * `wp_slash( $post )` on the row (revision.php:370, "Since data is from
 * DB.") immediately before `wp_insert_post()`. This file's snapshot is
 * passed to `_wp_put_post_revision()` RAW (unslashed) on purpose — core
 * does the one and only slashing pass. Pre-slashing the snapshot here
 * would double-slash (`\'` → `\\\'`) every quote/backslash a caller
 * proposes, corrupting content on every stage. See the "gnarly content"
 * slash-safety test in tests/sn-apply-revision.php for the regression this
 * guards against.
 *
 * ── The hook-cascade question (byte-identical guarantee, acceptance test 6) ──
 *
 * _wp_put_post_revision() calls wp_insert_post( $post, true ) with
 * post_type = 'revision'. Three hook points matter here, verified against
 * the real 7.0.2 source (wp-includes/post.php) rather than assumed —
 * **none of them are guarded by post_type inside wp_insert_post() itself**:
 *
 *   - `save_post_{$post_type}` / `save_post` / `wp_insert_post` (the action,
 *     not the function): fire UNCONDITIONALLY for every insert, revisions
 *     included (post.php:5182/5193/5204 — no `'revision' !== $post_type`
 *     guard exists anywhere in the function). This corrects an earlier,
 *     wrong assumption in this file — there is no core-level skip. The
 *     actual reason our own `save_post` hooks don't cascade is that BOTH
 *     of them carry their own explicit guard: `inc/schedule-sync.php`'s
 *     `sn_schedule_sync_post()` checks `wp_is_post_revision( $post_id )`
 *     (line ~95) before doing anything, and `inc/post-settings.php`'s
 *     `sn_post_settings_save()` does the same (line ~304) — this is the
 *     standard WP meta-box-save boilerplate pattern (check
 *     `DOING_AUTOSAVE` + `wp_is_post_revision()` first) precisely because
 *     core does NOT filter the hook for you. Safety here is by THEIR
 *     construction, not core's.
 *
 *   - `transition_post_status`: also unconditional (only gated on
 *     attachment-vs-not; a revision insert isn't an attachment, so
 *     `wp_transition_post_status( 'inherit', 'new', $revision_post )` fires
 *     same as any other insert, post.php:5082-5083). Five of our own
 *     modules hook it (ml-artifacts.php, ai-prepopulate.php, indexnow.php,
 *     webhooks.php, websub.php); each is independently safe because it
 *     separately guards on post_type (revision post_type is 'revision',
 *     never in any of their `post`/`page`/allowed-post-types checks) and/or
 *     requires 'publish' to be involved (a revision's status is always
 *     'inherit'). ml-artifacts.php additionally calls
 *     `wp_is_post_revision()` explicitly — internal evidence this exact
 *     "hooks fire for revisions too" gotcha was already found and guarded
 *     once before this session.
 *
 *   - `_wp_put_post_revision` (the action, revision.php:387): fires once on
 *     success, AFTER the insert, with `( $revision_id, $post_id )`. Grepped
 *     this codebase's `inc/` — nothing hooks it today, so it is not a
 *     cascade risk, only listed here for completeness of the table.
 *
 * The pattern across all of this: nothing in WordPress core itself skips a
 * hook because the row being inserted is a revision. Every safety property
 * this file relies on comes from a plugin-side `wp_is_post_revision()` (or
 * equivalent) check, verified present in each of the five files above.
 *
 * ── The meta problem ──
 *
 * Several future sn_apply change types (surfaces, og_card, alt_text) are
 * postmeta writes. Core revisions do not carry arbitrary meta unless a meta
 * key opts in via `register_post_meta( ..., 'revisions_enabled' => true )`
 * (WP 6.4+), which SN does not do for any of its own meta keys today and
 * would require an audit this session does not have scope for (touching
 * every register_post_meta() call site, one per change type, is 6b/6c work
 * at earliest). Recommendation for 6b: DO NOT chase revisions_enabled meta
 * yet. Ship the separate staged-meta draft-queue below — a single wp_options
 * row per (post_id, meta_key) — and let 6b decide how/whether it ever
 * surfaces as a "meta revision." It is deliberately NOT written to postmeta:
 * a value living in postmeta is live data by every other reader in the
 * codebase (surfaces cache, OG card generator, etc.); a staged option row is
 * inert until something explicit promotes it.
 *
 * ── Failure honesty ──
 *
 * A revision-disabled post type (`! post_type_supports( $type, 'revisions' )`)
 * or `wp_revisions_to_keep( $post ) === 0` (explicitly zeroed via the
 * `wp_revisions_to_keep` filter or the WP_POST_REVISIONS constant) is an
 * explicit, loud WP_Error here — never a silent no-op. This deliberately
 * bypasses wp_save_post_revision(), which SILENTLY returns null/0 in both of
 * those cases (and also silently skips when content is unchanged, via the
 * `wp_save_post_revision_check_for_changes` filter) — exactly the kind of
 * "healthy readout measuring the wrong call" this codebase has been bitten
 * by before. This file's own checks run first and error loudly; only then
 * does it call _wp_put_post_revision() directly (not
 * wp_save_post_revision()), so an unchanged-content proposal still stages —
 * sn_apply's dry_run diff already handles "nothing to change" upstream of
 * this primitive.
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SNT_SN_APPLY_STAGED_META_OPTION_PREFIX' ) ) {
	define( 'SNT_SN_APPLY_STAGED_META_OPTION_PREFIX', 'snt_sn_apply_staged_meta_' );
}

if ( ! defined( 'SNT_SN_APPLY_REVISION_CONTENT_FIELDS' ) ) {
	// The only fields this primitive is scoped to stage. Anything else in
	// $proposed is a caller error (422), not silently ignored.
	define( 'SNT_SN_APPLY_REVISION_CONTENT_FIELDS', array( 'post_content', 'post_title', 'post_excerpt' ) );
}

if ( ! function_exists( 'snt_sn_apply_stage_revision' ) ) {
	/**
	 * Stage $proposed content fields as a NEW WordPress revision of
	 * $post_id, without touching the live post row.
	 *
	 * @param int   $post_id  Target post.
	 * @param array $proposed Any of post_content / post_title / post_excerpt.
	 * @return array{revision_id:int,post_id:int,fields_staged:array}|WP_Error
	 *
	 * WP_Error codes:
	 *   snt_sn_apply_post_not_found        (404)
	 *   snt_sn_apply_invalid_proposed      (422)
	 *   snt_sn_apply_revisions_unsupported (409) — post type doesn't support revisions
	 *   snt_sn_apply_revisions_disabled    (409) — wp_revisions_to_keep() === 0
	 *   snt_sn_apply_revision_of_revision  (422) — $post_id is itself a revision
	 *   snt_sn_apply_write_failed          (500)
	 *
	 * @since 10.40.0
	 */
	function snt_sn_apply_stage_revision( $post_id, array $proposed ) {
		$post_id = (int) $post_id;

		$live = get_post( $post_id, ARRAY_A );
		if ( ! $live || empty( $live['ID'] ) ) {
			return new WP_Error( 'snt_sn_apply_post_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
		}

		if ( isset( $live['post_type'] ) && 'revision' === $live['post_type'] ) {
			// Mirrors _wp_put_post_revision()'s own guard; caught here first
			// so the error code/status is ours, not core's untranslated one.
			return new WP_Error( 'snt_sn_apply_revision_of_revision', __( 'Cannot stage a revision of a revision.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}

		$fields_staged = array_keys( $proposed );
		if ( empty( $fields_staged ) ) {
			return new WP_Error( 'snt_sn_apply_invalid_proposed', __( 'proposed must contain at least one field.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		$unknown_fields = array_diff( $fields_staged, SNT_SN_APPLY_REVISION_CONTENT_FIELDS );
		if ( ! empty( $unknown_fields ) ) {
			return new WP_Error(
				'snt_sn_apply_invalid_proposed',
				sprintf(
					/* translators: %s: comma-separated list of unsupported field names. */
					__( 'proposed contains fields this primitive does not stage: %s. Only post_content, post_title, post_excerpt are supported.', 'signal-and-noise-tools' ),
					implode( ', ', $unknown_fields )
				),
				array( 'status' => 422 )
			);
		}

		$post_type = (string) ( $live['post_type'] ?? '' );
		if ( ! post_type_supports( $post_type, 'revisions' ) ) {
			return new WP_Error(
				'snt_sn_apply_revisions_unsupported',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'Post type "%s" does not support revisions — cannot stage without touching the live post.', 'signal-and-noise-tools' ),
					$post_type
				),
				array( 'status' => 409 )
			);
		}

		$keep = wp_revisions_to_keep( (object) $live );
		if ( 0 === (int) $keep ) {
			return new WP_Error( 'snt_sn_apply_revisions_disabled', __( 'Revisions are disabled for this post (wp_revisions_to_keep() = 0) — cannot stage without touching the live post.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
		}

		// Build the array _wp_put_post_revision() will snapshot: the live
		// row with ONLY the caller's proposed fields overridden. Everything
		// else (post_status, post_type, post_parent, taxonomies, meta) is
		// irrelevant to _wp_post_revision_fields()'s allowlist and is never
		// written anywhere — _wp_put_post_revision() unsets 'ID' and always
		// INSERTs, never UPDATEs the row it was given.
		$snapshot = $live;
		foreach ( $proposed as $field => $value ) {
			$snapshot[ $field ] = (string) $value;
		}

		$revision_id = _wp_put_post_revision( $snapshot );

		// Real 7.0.2 contract (wp-includes/revision.php:372): the internal
		// wp_insert_post( $post, true ) opts INTO WP_Error, so a DB failure
		// surfaces here as WP_Error — this is the primary, expected path.
		// The empty() arm below is a deliberate defensive/cross-version
		// fallback (not the documented 7.0.2 shape), kept in case a future
		// core release or filter chain hands back a falsy value instead.
		if ( is_wp_error( $revision_id ) ) {
			return new WP_Error( 'snt_sn_apply_write_failed', $revision_id->get_error_message(), array( 'status' => 500 ) );
		}
		if ( empty( $revision_id ) ) {
			return new WP_Error( 'snt_sn_apply_write_failed', __( 'Revision insert failed.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
		}

		return array(
			'revision_id'   => (int) $revision_id,
			'post_id'       => $post_id,
			'fields_staged' => $fields_staged,
		);
	}
}

if ( ! function_exists( 'snt_sn_apply_stage_meta' ) ) {
	/**
	 * Stage a proposed meta value as a draft-queue row — explicitly NOT a
	 * postmeta write. Overwrites any prior staged value for the same
	 * (post_id, meta_key) pair (last-staged-wins; sn_apply session 6b owns
	 * any "already staged" gate).
	 *
	 * @param int    $post_id
	 * @param string $meta_key
	 * @param mixed  $proposed_value
	 * @param string $fingerprint Caller-supplied provenance token (e.g. the
	 *                            sn_scan candidate's fingerprint). Opaque to
	 *                            this primitive — stored, never interpreted.
	 * @return array{post_id:int,meta_key:string,staged_at:int}|WP_Error
	 *
	 * WP_Error codes:
	 *   snt_sn_apply_post_not_found   (404)
	 *   snt_sn_apply_invalid_meta_key (422)
	 *
	 * @since 10.40.0
	 */
	function snt_sn_apply_stage_meta( $post_id, $meta_key, $proposed_value, $fingerprint = '' ) {
		$post_id  = (int) $post_id;
		$meta_key = (string) $meta_key;

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'snt_sn_apply_post_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
		}
		if ( '' === trim( $meta_key ) ) {
			return new WP_Error( 'snt_sn_apply_invalid_meta_key', __( 'meta_key must not be empty.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}

		$staged_at = time();
		$record    = array(
			'proposed_value' => $proposed_value,
			'staged_at'      => $staged_at,
			'fingerprint'    => (string) $fingerprint,
		);

		update_option( snt_sn_apply_staged_meta_option_name( $post_id, $meta_key ), $record, false );

		return array(
			'post_id'   => $post_id,
			'meta_key'  => $meta_key,
			'staged_at' => $staged_at,
		);
	}
}

if ( ! function_exists( 'snt_sn_apply_get_staged_meta' ) ) {
	/**
	 * Read back a staged meta record. Returns null (not WP_Error) when
	 * nothing is staged — absence is a normal state here, not a failure.
	 *
	 * @param int    $post_id
	 * @param string $meta_key
	 * @return array{proposed_value:mixed,staged_at:int,fingerprint:string}|null
	 *
	 * @since 10.40.0
	 */
	function snt_sn_apply_get_staged_meta( $post_id, $meta_key ) {
		$value = get_option( snt_sn_apply_staged_meta_option_name( (int) $post_id, (string) $meta_key ), null );
		return is_array( $value ) ? $value : null;
	}
}

if ( ! function_exists( 'snt_sn_apply_staged_meta_option_name' ) ) {
	/**
	 * @param int    $post_id
	 * @param string $meta_key
	 * @return string
	 *
	 * @since 10.40.0
	 */
	function snt_sn_apply_staged_meta_option_name( $post_id, $meta_key ) {
		return SNT_SN_APPLY_STAGED_META_OPTION_PREFIX . $post_id . '_' . md5( $meta_key );
	}
}

if ( ! function_exists( 'snt_sn_apply_restore_revision' ) ) {
	/**
	 * Thin, verified wrapper over wp_restore_post_revision() (acceptance
	 * test 8). Core returns `false` on an invalid revision id/object rather
	 * than WP_Error — translated here so every failure path in this file
	 * carries the same {status:N} shape.
	 *
	 * @param int $revision_id
	 * @return array{post_id:int,revision_id:int}|WP_Error
	 *
	 * WP_Error codes:
	 *   snt_sn_apply_revision_not_found (404)
	 *
	 * @since 10.40.0
	 */
	function snt_sn_apply_restore_revision( $revision_id ) {
		$revision_id = (int) $revision_id;
		$parent_id   = wp_restore_post_revision( $revision_id );

		if ( false === $parent_id || empty( $parent_id ) ) {
			return new WP_Error( 'snt_sn_apply_revision_not_found', __( 'Revision not found or restore failed.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
		}

		return array(
			'post_id'     => (int) $parent_id,
			'revision_id' => $revision_id,
		);
	}
}

if ( ! function_exists( 'snt_sn_apply_revision_diff' ) ) {
	/**
	 * Diff a revision against the CURRENT live post (not against the post
	 * as it stood when the revision was created — sn_apply's fingerprint
	 * gate is what protects against a stale comparison; this is a reporting
	 * helper, not a concurrency check).
	 *
	 * @param int $revision_id
	 * @return array{before:array,after:array,fields_changed:array}|WP_Error
	 *
	 * WP_Error codes:
	 *   snt_sn_apply_revision_not_found (404)
	 *   snt_sn_apply_post_not_found     (404) — parent post gone
	 *
	 * @since 10.40.0
	 */
	function snt_sn_apply_revision_diff( $revision_id ) {
		$revision_id = (int) $revision_id;
		$revision    = get_post( $revision_id, ARRAY_A );

		if ( ! $revision || empty( $revision['ID'] ) ) {
			return new WP_Error( 'snt_sn_apply_revision_not_found', __( 'Revision not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
		}

		$parent_id = (int) ( $revision['post_parent'] ?? 0 );
		$live      = $parent_id ? get_post( $parent_id, ARRAY_A ) : null;

		if ( ! $live || empty( $live['ID'] ) ) {
			return new WP_Error( 'snt_sn_apply_post_not_found', __( 'Parent post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
		}

		$before          = array();
		$after           = array();
		$fields_changed  = array();
		foreach ( SNT_SN_APPLY_REVISION_CONTENT_FIELDS as $field ) {
			$live_value      = (string) ( $live[ $field ] ?? '' );
			$revision_value  = (string) ( $revision[ $field ] ?? '' );
			$before[ $field ] = $live_value;
			$after[ $field ]  = $revision_value;
			if ( $live_value !== $revision_value ) {
				$fields_changed[] = $field;
			}
		}

		return array(
			'before'         => $before,
			'after'          => $after,
			'fields_changed' => $fields_changed,
		);
	}
}

<?php
/**
 * Signal & Noise Tools — sn_apply change.type "create_draft" (MCP
 * consolidation session 6c, the arc's final stage).
 *
 * Split into its own file for the same reason inc/sn-apply/validation.php
 * split off inc/sn-apply/executors.php: the ~450-line house budget. All
 * create_draft-specific logic lives here — target resolution and the
 * mode-support matrix entry stay in inc/sn-apply/executors.php (generic
 * per-type dispatch, one line each), gate 1/2 dispatch stays in
 * inc/sn-apply/validation.php (one `case` each) — but the actual gate-2
 * check assembly, the block-delimiter validator, the write primitive, and
 * the dry_run preview computation are all create_draft's OWN, never shared.
 *
 * ── Origin (B5c) ──
 *
 * docs/mcp-consolidation session file 2026-07-30-ml-surface-adoption
 * addendum 2: the owner's ask — "put it in the drafts" — was IMPOSSIBLE
 * with the tool surface as it stood (no create-post ability existed at
 * all). The constraint that shaped every decision in this file: DRAFT
 * status ONLY, never publish/future/schedule. The owner schedules by hand —
 * that manual step IS the human review gate this whole tool exists to
 * preserve, the same posture as mode:"revision" for the other 8 change
 * types, just enforced structurally instead of by caller choice.
 *
 * ── Mode semantics: create_draft is its OWN, not a reuse of "revision" ──
 *
 * mode:"publish" REFUSES at gate 3 (snt_sn_apply_mode_support() below),
 * exactly the same mechanism og_card/anchor_sweep use to refuse
 * mode:"revision" — a structural block, not a policy/identity one. The
 * refusal message says plainly that drafts are scheduled by hand; this
 * tool will never be the thing that makes a draft live.
 *
 * mode:"revision" is the ONLY mode create_draft supports — but it does NOT
 * route through session 6a's snt_sn_apply_stage_revision() primitive the
 * way block_migration/pattern_adoption/drift_replace/link_insert/surfaces
 * do. That primitive stages a WordPress core revision OF AN EXISTING POST
 * — there is no parent post here to stage against; the post doesn't exist
 * until this call creates it. So "revision mode" for create_draft means
 * something narrower and more literal than for the other 8 types: gate 1-4
 * still run in full, dry_run still defaults to true and still previews with
 * zero inserts, but when dry_run:false actually executes, the write IS the
 * real, final artifact (a real wp_insert_post() row) — just one whose
 * status is hard-coded to 'draft', so it is never live/public/indexed. The
 * "reversibility" story that mode:"revision" promises everywhere else
 * (nothing touched until a human promotes it) is delivered here by
 * WordPress's own draft/trash mechanics instead of the staged-revision
 * queue: an unwanted draft is deleted via rollback (below), not "restored".
 *
 * ── Validation gate (gate 2) ──
 *
 * create_draft has no existing sn_validate "surface" to borrow wholesale
 * (every other surface edits something that already exists; this creates).
 * snt_sn_apply_gate2_create_draft() below assembles findings from FOUR real
 * sn_validate check families (excerpt, tags, and — via the body family —
 * drift_lexicon/block_pattern_registered) PLUS two checks unique to this
 * change type: forbidden payload fields (post_status/post_date/post_type
 * are never caller-controllable — accepting any of them would let a caller
 * quietly defeat the whole draft-only guarantee) and block-comment
 * DELIMITER balance (see snt_sn_apply_block_delimiter_findings()'s own
 * docblock for why parse_blocks() alone does not catch this).
 *
 * ── Tag attachment: resolve to term_ids, never pass raw names (trap, review REJECT v10.40.0) ──
 *
 * The first draft of this file passed the caller's RAW tag name strings
 * straight to `wp_set_post_tags( $post_id, $names, false )`, reasoning that
 * gate 2 already proved every name is in the existing vocabulary
 * (`snt_sn_validate_check_tags()`). That reasoning has a hole: WordPress
 * core's `wp_set_post_tags()` — like `wp_set_object_terms()` underneath it —
 * CREATES a new term for any string that does not match an existing one.
 * The `$append` parameter controls whether the call REPLACES or ADDS TO the
 * post's existing term associations; it has nothing to do with whether an
 * unmatched NAME creates a new term — passing `false` does not protect
 * against creation, it only decides whether prior tags are kept. So a
 * gate-2 regression, a normalization mismatch between gate 2's check and
 * this write step, or a race (a tag deleted between the gate-2 read and the
 * write) would silently INVENT vocabulary — exactly the side effect
 * `snt_sn_validate_check_tags()`'s own "existing vocabulary only" contract
 * exists to prevent, and exactly the class of bug the codebase's own
 * `post_status`/`post_date`/`post_type` fence just above this section
 * demonstrates the fix for: never trust gate 2 alone — make the dangerous
 * value structurally unreachable in the write primitive itself.
 * `snt_sn_apply_resolve_tag_ids()` below is that fence for tags: it
 * resolves every proposed name to an EXISTING term_id (reusing the exact
 * same `sn_tag_normalize_key()` + `get_terms()` lookup
 * `snt_sn_validate_check_tags()` performs — never a parallel scheme, so the
 * two can never disagree about what "existing" means), and
 * `snt_sn_apply_write_create_draft()` passes `wp_set_post_tags()` ONLY
 * integer term_ids. An id that does not exist simply fails to attach — core
 * never creates a term from an integer. Resolution runs BEFORE
 * `wp_insert_post()`, not after: an unresolvable tag name refuses (422,
 * naming the tag) before any post row is created, so a bad tag can never
 * leave an orphan draft behind.
 *
 * ── Caps, grounded not invented ──
 *
 * Title cap (200 chars) and content cap (256 KB) are both NEW numbers —
 * unlike sn-validate-checks.php's caps (each reused from an existing
 * generator constant), create_draft has no prior generator to invert. 200
 * chars mirrors WordPress's own post_title column headroom convention used
 * elsewhere in this plugin's UI-facing limits; 256 KB is a deliberately
 * generous ceiling for hand-authored or AI-drafted Gutenberg markup — this
 * plugin has no live wp/db access per the task's standing rule, so "check
 * the biggest real post" could not be run this session; 256 KB is
 * documented here as a chosen, not measured, ceiling and should be
 * revisited against real corpus data in a follow-up session.
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SNT_SN_APPLY_CREATE_DRAFT_TITLE_MAX' ) ) {
	define( 'SNT_SN_APPLY_CREATE_DRAFT_TITLE_MAX', 200 );
}
if ( ! defined( 'SNT_SN_APPLY_CREATE_DRAFT_CONTENT_MAX_BYTES' ) ) {
	// 256 KB — a chosen, documented ceiling; see this file's docblock.
	define( 'SNT_SN_APPLY_CREATE_DRAFT_CONTENT_MAX_BYTES', 262144 );
}

/**
 * Gate 2 for change.type "create_draft". See this file's docblock for which
 * check families this draws from and why the two structural checks
 * (forbidden fields, block delimiters) exist alongside them.
 *
 * @param array $payload
 * @return array{checks:string[],findings:array}
 */
function snt_sn_apply_gate2_create_draft( array $payload ) {
	$checks   = array();
	$findings = array();

	foreach ( array( 'post_status', 'post_date', 'post_type' ) as $forbidden ) {
		if ( array_key_exists( $forbidden, $payload ) ) {
			$checks[]   = 'forbidden_fields';
			$findings[] = snt_sn_validate_finding(
				'create_draft', 'forbidden_field', 'error',
				sprintf(
					/* translators: %s: the forbidden payload field name. */
					__( 'payload.%s is not accepted — create_draft always inserts post_status "draft" as post_type "post", authored by the calling identity. Drafts are scheduled by hand.', 'signal-and-noise-tools' ),
					$forbidden
				),
				$payload[ $forbidden ], null, array(), 'create_draft|forbidden|' . $forbidden
			);
		}
	}

	$title = isset( $payload['title'] ) ? (string) $payload['title'] : '';
	$checks[] = 'title';
	if ( '' === trim( $title ) ) {
		$findings[] = snt_sn_validate_finding( 'create_draft', 'title', 'error', __( 'title is required.', 'signal-and-noise-tools' ), '', null, array(), 'create_draft|title|missing' );
	} elseif ( mb_strlen( $title ) > SNT_SN_APPLY_CREATE_DRAFT_TITLE_MAX ) {
		$findings[] = snt_sn_validate_finding(
			'create_draft', 'title', 'error', __( 'title exceeds the hard character cap.', 'signal-and-noise-tools' ),
			mb_strlen( $title ), '<=' . SNT_SN_APPLY_CREATE_DRAFT_TITLE_MAX, array(), 'create_draft|title|' . $title
		);
	}

	$content = isset( $payload['content'] ) ? (string) $payload['content'] : '';
	$checks[] = 'content';
	if ( '' === trim( $content ) ) {
		$findings[] = snt_sn_validate_finding( 'create_draft', 'content', 'error', __( 'content is required.', 'signal-and-noise-tools' ), '', null, array(), 'create_draft|content|missing' );
	} elseif ( strlen( $content ) > SNT_SN_APPLY_CREATE_DRAFT_CONTENT_MAX_BYTES ) {
		$findings[] = snt_sn_validate_finding(
			'create_draft', 'content', 'error', __( 'content exceeds the hard size cap.', 'signal-and-noise-tools' ),
			strlen( $content ), '<=' . SNT_SN_APPLY_CREATE_DRAFT_CONTENT_MAX_BYTES . ' bytes', array(), 'create_draft|content|size'
		);
	} else {
		$checks[]  = 'block_pattern';
		$findings  = array_merge( $findings, snt_sn_apply_block_delimiter_findings( $content ) );
		if ( function_exists( 'snt_sn_validate_check_body' ) ) {
			$findings = array_merge( $findings, snt_sn_validate_check_body( $content, 0 ) );
		}
	}

	if ( array_key_exists( 'excerpt', $payload ) && function_exists( 'snt_sn_validate_check_excerpt' ) ) {
		$checks[]  = 'excerpt';
		$findings  = array_merge( $findings, snt_sn_validate_check_excerpt( (string) $payload['excerpt'], 0 ) );
	}

	if ( array_key_exists( 'tags', $payload ) && function_exists( 'snt_sn_validate_check_tags' ) ) {
		$checks[]  = 'tags';
		$findings  = array_merge( $findings, snt_sn_validate_check_tags( (array) $payload['tags'], 0 ) );
	}

	// v13.95.0 — the two surface fields, validated HERE rather than in a
	// follow-up call. Both notes drafted on 2026-09-03 needed a second
	// `surfaces` call to attach them, and the first og_card_title failed its
	// char-range check, costing a third.
	//
	// The validators are the SAME ones `surfaces` runs — never a parallel
	// scheme, which is how two rules for one field drift apart. Only their
	// subject differs: post_id is 0 because the post does not exist yet, and
	// og_card_title is measured against the title from THIS payload rather
	// than a stored one. That last part is the whole point — the range check
	// now runs while the caller can still fix the title in the same call.
	if ( array_key_exists( 'meta_description', $payload ) && function_exists( 'snt_sn_validate_check_meta_description' ) ) {
		$checks[]  = 'meta_description';
		$findings  = array_merge( $findings, snt_sn_validate_check_meta_description( (string) $payload['meta_description'], 0 ) );
	}
	if ( array_key_exists( 'og_card_title', $payload ) && function_exists( 'snt_sn_validate_check_og_card_title' ) ) {
		$checks[]  = 'og_card_title';
		$findings  = array_merge( $findings, snt_sn_validate_check_og_card_title( (string) $payload['og_card_title'], 0, $title ) );
	}

	return array( 'checks' => array_values( array_unique( $checks ) ), 'findings' => $findings );
}

/**
 * Deterministic block-comment DELIMITER balance check — NOT full Gutenberg
 * block validation (that would require each registered block's own save()
 * callback, impractical to run server-side for arbitrary/third-party
 * blocks). This catches the narrower, mechanically-checkable failure the
 * task asks for: an unclosed `<!-- wp:name -->`, a stray `<!-- /wp:name -->`
 * with nothing open, or a closing comment whose name doesn't match the
 * block currently open. parse_blocks() alone does NOT catch this — WP's own
 * parser is lenient and silently treats malformed delimiters as freeform
 * HTML rather than erroring (verified against this repo's own test double
 * for parse_blocks and against the documented behavior of
 * wp-includes/class-wp-block-parser.php's frame-stack design, which
 * recovers from bad input rather than failing loudly) — this check exists
 * to close exactly that gap, with a stack-based scan mirroring the block
 * parser's own frame-stack shape, using PCRE recursion `(?4)` to match
 * (possibly nested-brace) JSON attrs without truncating on the first `}`.
 *
 * @param string $content
 * @return array findings (severity 'error') — empty when balanced.
 */
function snt_sn_apply_block_delimiter_findings( $content ) {
	$findings = array();
	$content  = (string) $content;
	$pattern  = '/<!--\s*(\/)?wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)(?:\s+({(?:[^{}]+|(?4))*}))?\s*(\/)?-->/s';

	if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
		if ( false !== strpos( $content, '<!-- wp:' ) ) {
			$findings[] = snt_sn_validate_finding(
				'create_draft', 'block_delimiters', 'error',
				__( 'Content contains "<!-- wp:" but no valid block delimiter could be parsed from it.', 'signal-and-noise-tools' ),
				null, null, array(), 'create_draft|block_delimiters|unparseable'
			);
		}
		return $findings;
	}

	$stack = array();
	foreach ( $matches as $m ) {
		$is_close        = '' !== ( $m[1] ?? '' );
		$name            = ( $m[2] ?? '' ) . ( $m[3] ?? '' );
		$is_self_closing = '' !== ( $m[5] ?? '' );
		if ( $is_self_closing ) {
			continue; // Void block, e.g. <!-- wp:separator /-->: never opens a frame.
		}
		if ( $is_close ) {
			$top = array_pop( $stack );
			if ( $top !== $name ) {
				$findings[] = snt_sn_validate_finding(
					'create_draft', 'block_delimiters', 'error',
					sprintf(
						/* translators: 1: the closing delimiter's block name, 2: the block name it should have closed (or "nothing" if the stack was empty). */
						__( 'Mismatched block closing delimiter "<!-- /wp:%1$s -->" — expected to close "%2$s".', 'signal-and-noise-tools' ),
						$name, null === $top ? 'nothing (no block open)' : $top
					),
					$name, $top, array(), 'create_draft|block_delimiters|mismatch|' . $name
				);
			}
		} else {
			$stack[] = $name;
		}
	}
	if ( ! empty( $stack ) ) {
		$findings[] = snt_sn_validate_finding(
			'create_draft', 'block_delimiters', 'error',
			sprintf(
				/* translators: %s: comma-separated list of block names never closed. */
				__( 'Unclosed block delimiter(s): %s.', 'signal-and-noise-tools' ),
				implode( ', ', $stack )
			),
			$stack, array(), array(), 'create_draft|block_delimiters|unclosed|' . implode( ',', $stack )
		);
	}

	return $findings;
}

/**
 * Resolve proposed tag NAMES to EXISTING term_ids — the structural backstop
 * for the trap documented in this file's header docblock ("Tag attachment:
 * resolve to term_ids, never pass raw names"). `wp_set_post_tags()` CREATES
 * a term for any string that matches nothing in the vocabulary; an integer
 * term_id can only attach, never create. Reuses the EXACT SAME lookup
 * `snt_sn_validate_check_tags()` performs — `sn_tag_normalize_key()` +
 * `get_terms()` — never a parallel matching scheme, so gate 2's verdict and
 * this resolver can never disagree about what "existing" means.
 *
 * @param string[] $tag_names Proposed tag names, in caller order.
 * @return int[]|WP_Error Resolved term_ids, in the SAME order as $tag_names
 *                        with duplicates removed (first occurrence wins),
 *                        or WP_Error (422) naming the FIRST name that does
 *                        not resolve.
 *
 * WP_Error codes:
 *   snt_sn_apply_unknown_tag (422)
 */
function snt_sn_apply_resolve_tag_ids( array $tag_names ) {
	$vocab = array(); // normalized_key => term_id
	$terms = function_exists( 'get_terms' ) ? get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) ) : array();
	foreach ( (array) $terms as $t ) {
		$key           = function_exists( 'sn_tag_normalize_key' ) ? sn_tag_normalize_key( $t->name ) : strtolower( trim( (string) $t->name ) );
		$vocab[ $key ] = (int) $t->term_id;
	}

	$ids  = array();
	$seen = array();
	foreach ( $tag_names as $name ) {
		$name = (string) $name;
		$key  = function_exists( 'sn_tag_normalize_key' ) ? sn_tag_normalize_key( $name ) : strtolower( trim( $name ) );
		if ( ! isset( $vocab[ $key ] ) ) {
			return new WP_Error(
				'snt_sn_apply_unknown_tag',
				sprintf(
					/* translators: %s: the proposed tag name not found in the existing vocabulary. */
					__( 'Tag "%s" is not in the existing post_tag vocabulary — create_draft never grows the tag vocabulary as a side effect of drafting a post.', 'signal-and-noise-tools' ),
					$name
				),
				array( 'status' => 422 )
			);
		}
		$id = $vocab[ $key ];
		if ( ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			$ids[]       = $id;
		}
	}
	return $ids;
}

/**
 * Gate-passed, non-dry-run write for create_draft. This is the "gate-checked
 * draft insert" this file's header docblock describes — the ACTUAL new-post
 * write, not a staged revision (there is no parent post to stage against).
 * post_status is hard-coded 'draft' and post_type hard-coded 'post' — never
 * taken from the payload (gate 2 already rejects either field if the caller
 * sent one, but this function does not trust that alone: it simply never
 * reads those keys from $payload at all, so even a future gate-2 regression
 * could not smuggle a caller-chosen status/type through this function). Tags
 * are resolved to term_ids BEFORE the post is inserted — see this file's
 * header docblock — so an unresolvable tag never leaves an orphan draft.
 *
 * @param array $payload
 * @return array{post_id:int,edit_link:string,status:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_sn_apply_write_failed (500)
 *   snt_sn_apply_unknown_tag  (422) — from snt_sn_apply_resolve_tag_ids()
 */
function snt_sn_apply_write_create_draft( array $payload ) {
	$title   = (string) ( $payload['title'] ?? '' );
	$content = (string) ( $payload['content'] ?? '' );

	$tag_ids = null;
	if ( ! empty( $payload['tags'] ) ) {
		$tag_ids = snt_sn_apply_resolve_tag_ids( array_values( array_map( 'strval', (array) $payload['tags'] ) ) );
		if ( is_wp_error( $tag_ids ) ) {
			return $tag_ids;
		}
	}

	$postarr = array(
		'post_type'    => 'post',
		'post_status'  => 'draft',
		'post_title'   => $title,
		// RAW, exactly as gate 2 validated — never wp_kses_post()'d. Running
		// content through wp_kses_post() here would risk mangling the very
		// block delimiter comments gate 2 just proved are well-formed
		// (kses's job is arbitrary-HTML safety, not block-markup fidelity);
		// gate 2's delimiter/body checks are this primitive's sanitizer.
		'post_content' => $content,
		'post_author'  => get_current_user_id(),
	);
	if ( array_key_exists( 'excerpt', $payload ) ) {
		$postarr['post_excerpt'] = (string) $payload['excerpt'];
	}

	// $wp_error = true — same lesson session 6a had to correct against the
	// real 7.0.2 source for _wp_put_post_revision()'s own wp_insert_post()
	// call: WP_Error, not a silent falsy return, is the documented failure
	// shape once $wp_error=true is passed. Grounded here even more
	// precisely, against this repo's own pinned WP 7.0 stubs (composer.json:
	// php-stubs/wordpress-stubs ^7.0): wp_insert_post()'s stub carries
	// `@phpstan-return ($wp_error is false ? int<0, max> : int<1, max>|WP_Error)`
	// — with a LITERAL `true` argument (not a variable), PHPStan narrows the
	// success type to int<1, max>, i.e. a 0 return is not just undocumented,
	// it is TYPE-LEVEL impossible on this path. Unlike
	// snt_sn_apply_stage_revision()'s _wp_put_post_revision() call (whose
	// stub is the plainer, unrefined `int|WP_Error` — no phpstan-return
	// narrowing — so ITS defensive `empty()` arm is real, PHPStan-legitimate
	// belt-and-braces), an `empty( $post_id )` check here would be dead code
	// PHPStan correctly flags as unreachable (verified: adding it back
	// reproduces `empty.variable: Variable $post_id in empty() always
	// exists and is not falsy` under `composer phpstan`) — so is_wp_error()
	// alone is the complete, honest failure check for this specific call.
	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'snt_sn_apply_write_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
	}

	if ( null !== $tag_ids && function_exists( 'wp_set_post_tags' ) ) {
		// INTEGER term_ids only — never raw names. wp_set_post_tags() with
		// unmatched STRINGS creates a new term ($append controls association
		// REPLACEMENT, not creation); an id that doesn't exist simply fails
		// to attach, it never creates. See this file's header docblock (the
		// "Tag attachment" section, reviewer REJECT, v10.40.0).
		wp_set_post_tags( (int) $post_id, $tag_ids, false );
	}

	// The surface meta, attached to the draft this call just created. The keys
	// are the ones `surfaces` writes (_sn_meta_description / _sn_og_card_title)
	// so a later surfaces call updates the same rows rather than shadowing
	// them. Gate 2 already validated both against this payload's own title.
	$surfaces_set = array();
	foreach ( array( 'meta_description' => '_sn_meta_description', 'og_card_title' => '_sn_og_card_title' ) as $field => $meta_key ) {
		if ( array_key_exists( $field, $payload ) ) {
			update_post_meta( (int) $post_id, $meta_key, (string) $payload[ $field ] );
			$surfaces_set[] = $field;
		}
	}

	return array(
		'post_id'      => (int) $post_id,
		'edit_link'    => (string) get_edit_post_link( $post_id, 'raw' ),
		'status'       => 'draft',
		// Which surface fields this call attached. Empty array, never absent:
		// a caller must be able to tell "none supplied" from "field ignored".
		'surfaces_set' => $surfaces_set,
	);
}

/**
 * Recursively count blocks with a non-null blockName — used only for the
 * dry_run preview's block_count field, never for validation (gate 2's
 * delimiter/body checks are the validation; this is a reporting helper).
 *
 * @param array $blocks parse_blocks() output.
 * @return int
 */
function snt_sn_apply_create_draft_count_blocks( $blocks ) {
	$count = 0;
	foreach ( (array) $blocks as $b ) {
		$b = (array) $b;
		if ( ! empty( $b['blockName'] ) ) {
			$count++;
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$count += snt_sn_apply_create_draft_count_blocks( $b['innerBlocks'] );
		}
	}
	return $count;
}

/**
 * The dry_run preview shape for create_draft — deliberately NOT the
 * before/after content diff every other type's dry_run produces (there is
 * no "before": nothing exists yet). {title, block_count, word_count} is the
 * useful preview for a create: does the title look right, roughly how much
 * content, does it parse into the expected number of blocks.
 *
 * @param array $payload
 * @return array{title:string,block_count:int,word_count:int}
 */
function snt_sn_apply_create_draft_preview( array $payload ) {
	$title   = (string) ( $payload['title'] ?? '' );
	$content = (string) ( $payload['content'] ?? '' );
	$blocks  = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : array();
	$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : strip_tags( $content );

	return array(
		'title'       => $title,
		'block_count' => snt_sn_apply_create_draft_count_blocks( $blocks ),
		'word_count'  => function_exists( 'snt_word_count' ) ? snt_word_count( $stripped ) : str_word_count( $stripped ),
	);
}

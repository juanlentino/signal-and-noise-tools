<?php
/**
 * Signal & Noise Tools — content seed migrations.
 *
 * One-shot DB seed scripts for the Provenance pillar and Notes
 * content surface. Each migration is gated by an SN_*_MIGR_OPT
 * option flag (defined in content-surfaces.php). Migrations run
 * exactly once per environment; idempotent re-runs are no-ops.
 *
 * Body loaders read HTML from inc/seed-content/ — moved from theme
 * to plugin alongside this file in Phase 3.
 *
 * Moved from theme inc/notes-and-provenance.php in Phase 3
 * (theme v8.4.0 / plugin v1.3.0, 2026-05-16). Original ordering preserved.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── BODY LOADERS ─────────────────────────────────────────────────

/**
 * Load the seeded Provenance body markup from disk.
 * Empty string fallback if the seed file is missing — the template will
 * just render an empty post-content area, no fatal.
 */
function sn_load_provenance_body() {
	$body_file = __DIR__ . '/seed-content/provenance-body.html';
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded long-form essay markup from disk. Mirrors
 * sn_load_provenance_body — same fallback semantics.
 */
function sn_load_over_detection_body() {
	$body_file = __DIR__ . '/seed-content/over-detection-body.html';
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
}

/**
 * Load the seeded second long-form essay markup from disk. Mirrors
 * sn_load_over_detection_body — same fallback semantics.
 */
function sn_load_as_substrate_body() {
	$body_file = __DIR__ . '/seed-content/as-substrate-body.html';
	return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
}

// ── MIGRATIONS (one-shot, idempotent per SN_*_MIGR_OPT flag) ───────

/**
 * One-time migration for sites upgrading from v6.1.0 (where the
 * Provenance Page was created with an empty body and all visible content
 * lived in the template). Populates the existing Page's body from the
 * seed file so it becomes editable from Pages → Provenance.
 *
 * Safety:
 *   - Runs at most once per site (guarded by a dedicated option flag).
 *   - Only writes when the existing body is genuinely empty — never
 *     overwrites prose someone has already added.
 *   - The flag is set even on no-op paths so we don't keep checking.
 */
add_action( 'admin_init', 'sn_migrate_provenance_body' );

function sn_migrate_provenance_body() {
	if ( get_option( SN_PROV_BODY_MIGRATED_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — sn_ensure_provenance_page() will seed
		// the body when it runs. Mark migrated so we don't keep checking.
		update_option( SN_PROV_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	if ( '' !== trim( $page->post_content ) ) {
		// Body already has content — could be edits we shouldn't touch.
		update_option( SN_PROV_BODY_MIGRATED_OPT, time(), true );
		return;
	}

	$body = sn_load_provenance_body();
	if ( '' === $body ) {
		// Seed file missing — leave the Page alone, do not mark migrated
		// so we retry on next admin_init in case the file lands later.
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => $body,
	) );

	update_option( SN_PROV_BODY_MIGRATED_OPT, time(), true );
}

/**
 * One-time refinements migration for the Provenance pillar:
 *
 *   1. Inject the inline TOC paragraph (between the hero and the first
 *      separator) if it isn't already present.
 *   2. Add `displayType: "modified"` to the byline's wp:post-date block
 *      so the date reads "last updated" rather than "first published" —
 *      more honest for a permanent reference essay that gets iterated on.
 *
 * Both edits are surgical, defensive, and idempotent: each is skipped
 * when the marker is missing or the change is already applied. Prose
 * paragraphs are never touched. Safe to re-run; in practice runs once
 * per site (guarded by SN_PROV_REFINE_MIGR_OPT).
 */
add_action( 'admin_init', 'sn_migrate_provenance_refinements' );

function sn_migrate_provenance_refinements() {
	if ( get_option( SN_PROV_REFINE_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — nothing to migrate. Mark done so we
		// don't keep scanning on every admin_init.
		update_option( SN_PROV_REFINE_MIGR_OPT, time(), true );
		return;
	}

	$body     = $page->post_content;
	$original = $body;

	// 1. Inject TOC after the hero group close, before the first separator.
	if ( false === strpos( $body, 'sn-provenance-toc' ) ) {
		$hero_start = strpos( $body, '<!-- wp:group {"className":"sn-provenance-hero"' );
		if ( false !== $hero_start ) {
			$hero_close_marker = '<!-- /wp:group -->';
			$hero_close        = strpos( $body, $hero_close_marker, $hero_start );
			if ( false !== $hero_close ) {
				$insert_at = $hero_close + strlen( $hero_close_marker );
				$body      = substr( $body, 0, $insert_at )
					. "\n\n" . sn_provenance_toc_block_markup() . "\n"
					. substr( $body, $insert_at );
			}
		}
	}

	// 2. Add displayType:"modified" to the byline's wp:post-date.
	if ( false === strpos( $body, '"displayType":"modified"' ) ) {
		$body = preg_replace(
			'/<!-- wp:post-date \{"format":"F j, Y",/',
			'<!-- wp:post-date {"format":"F j, Y","displayType":"modified",',
			$body,
			1
		);
	}

	if ( $body !== $original ) {
		wp_update_post( array(
			'ID'           => $page->ID,
			'post_content' => $body,
		) );
	}

	update_option( SN_PROV_REFINE_MIGR_OPT, time(), true );
}

/**
 * One-time migration that injects the reading-time block into the
 * existing Provenance byline. Mirrors the seed file change in 6.3.1.
 *
 * Idempotent — bails if the byline already contains the reading-time
 * marker (paste-by-hand defensive). Gated by SN_PROV_BYLINE_RT_MIGR_OPT
 * so it only runs once per install.
 */
add_action( 'admin_init', 'sn_migrate_provenance_byline_reading_time' );

function sn_migrate_provenance_byline_reading_time() {
	if ( get_option( SN_PROV_BYLINE_RT_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_BYLINE_RT_MIGR_OPT, time(), true );
		return;
	}

	$body     = $page->post_content;
	$original = $body;

	// Skip if the reading-time block is already present (paste-by-hand defensive).
	if ( false === strpos( $body, 'sn-provenance-byline-reading-time' ) ) {
		// Anchor on the byline's wp:post-date opener and the next ` /-->`
		// (the tag is self-closing). strpos avoids the nested-{} pitfall
		// the regex form hits once the 6.2.6 migration adds a style object.
		$start = strpos( $body, '<!-- wp:post-date {"format":"F j, Y"' );
		if ( false !== $start ) {
			$end_marker = ' /-->';
			$end        = strpos( $body, $end_marker, $start );
			if ( false !== $end ) {
				$insert_at = $end + strlen( $end_marker );
				$body      = substr( $body, 0, $insert_at )
					. "\n\n\t" . sn_provenance_byline_reading_time_markup()
					. substr( $body, $insert_at );
			}
		}
	}

	if ( $body !== $original ) {
		wp_update_post( array(
			'ID'           => $page->ID,
			'post_content' => $body,
		) );
	}

	update_option( SN_PROV_BYLINE_RT_MIGR_OPT, time(), true );
}

/**
 * One-time migration that splits the existing /provenance pillar page
 * into a lean two-paper index (parent: /provenance) and a long-form
 * essay (child: /provenance/over-detection). The essay prose itself
 * is never edited — it's lifted verbatim from the existing live page
 * body and moved into the new child page.
 *
 * Algorithm:
 *   1. Locate the essay's hero block (`sn-provenance-hero` className) in
 *      the existing /provenance body. This anchor is stable across the
 *      seed-file shape and the prior unreleased "prepend cards" shape,
 *      so the same code handles both starting states.
 *   2. Everything from that anchor to end-of-body = the essay. Hand it
 *      to a new child page at /provenance/over-detection.
 *   3. Overwrite the parent /provenance body with the cards-only index.
 *
 * Safety:
 *   - If the hero anchor is missing (page was hand-edited away from
 *     seed shape), bail WITHOUT setting the flag, so a future run after
 *     manual recovery can complete the split.
 *   - If a /provenance/over-detection page already exists, leave its
 *     body untouched (admin may have edited prose there) — only the
 *     parent body is rewritten.
 *   - Gated by SN_PROV_SPLIT_MIGR_OPT, runs at most once per install.
 */
add_action( 'admin_init', 'sn_migrate_provenance_split' );

function sn_migrate_provenance_split() {
	if ( get_option( SN_PROV_SPLIT_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Page doesn't exist yet — sn_ensure_provenance_page() will seed
		// it cleanly. Mark migrated so we don't keep scanning.
		update_option( SN_PROV_SPLIT_MIGR_OPT, time(), true );
		return;
	}

	$body        = $page->post_content;
	$hero_anchor = '<!-- wp:group {"className":"sn-provenance-hero"';
	$hero_pos    = strpos( $body, $hero_anchor );

	// If the hero marker is missing the body has been hand-edited away
	// from the seed shape. Bail without flagging — the migration can
	// re-run after the admin restores the marker (or manually splits).
	if ( false === $hero_pos ) {
		return;
	}

	$essay = trim( substr( $body, $hero_pos ) );

	// Create the child page if it doesn't already exist. We never
	// overwrite an existing child body — admin may have edited the prose
	// there, and our migration job is structural (move), not editorial.
	$child = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_OVER_DETECTION_SLUG );
	if ( ! $child ) {
		wp_insert_post( array(
			'post_title'    => 'Provenance Over Detection',
			'post_name'     => SN_OVER_DETECTION_SLUG,
			'post_parent'   => (int) $page->ID,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => $essay,
			'post_excerpt'  => "A short read on why the industry needs to prove what's human, not chase what isn't.",
			'page_template' => 'page-provenance',
		), false );
	}

	// Replace the parent body with the cards-only index. Title also
	// updates so the WP admin reflects the new role of the page.
	wp_update_post( array(
		'ID'           => $page->ID,
		'post_title'   => 'On Provenance',
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_SPLIT_MIGR_OPT, time(), true );
}

/**
 * One-time migration that creates the second long-form essay
 * (/provenance/as-substrate) on installs whose `SN_SEED_FLAG_OPTION` was
 * already set before this page existed — the main seed flow short-
 * circuits on those sites, so the new ensure-call needs its own gate.
 *
 * Idempotent on multiple axes: bails if the dedicated flag is set, and
 * `sn_ensure_as_substrate_page()` itself bails if the child page exists.
 */
add_action( 'admin_init', 'sn_migrate_as_substrate_seed' );

function sn_migrate_as_substrate_seed() {
	if ( get_option( SN_AS_SUBSTRATE_SEED_OPT ) ) {
		return;
	}

	$parent = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $parent ) {
		// Parent page doesn't exist yet — sn_seed_content_surfaces will
		// create both in the same pass on its next admin_init firing.
		// Mark migrated so we don't keep scanning.
		update_option( SN_AS_SUBSTRATE_SEED_OPT, time(), true );
		return;
	}

	sn_ensure_as_substrate_page();
	update_option( SN_AS_SUBSTRATE_SEED_OPT, time(), true );
}

/**
 * One-time migration that updates Card 2 of the /provenance pillar
 * index to include the read-time meta and the "Read the long-form on
 * this site →" affordance pointing at /provenance/as-substrate/. Lives
 * separately from the seed-flow because production sites already have
 * SN_PROV_SPLIT_MIGR_OPT set from v6.5.4 and that flow won't re-run.
 *
 * Strategy: full-body rewrite via sn_provenance_papers_index_markup().
 * The pillar page is a generated index — its body shouldn't be hand-
 * edited, and the index function is the single source of truth for the
 * cards. A defensive sanity check on the SSRN abstract_id 6730343
 * anchor (the unique marker for v6.5.4's Card 2 shape) gates the
 * rewrite: if the marker is missing, the admin has hand-edited away
 * from the seed shape, so we bail WITHOUT setting the flag. That way a
 * future run can complete the migration after manual recovery.
 *
 * Idempotent: bails (and flags) if the body already contains the
 * /provenance/as-substrate/ longform URL — that's the unique marker
 * for the post-migration Card 2 shape, so seeing it means the work is
 * already done.
 */
add_action( 'admin_init', 'sn_migrate_provenance_card2_longform' );

function sn_migrate_provenance_card2_longform() {
	if ( get_option( SN_PROV_CARD2_LF_MIGR_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		// Pillar page doesn't exist yet — sn_seed_content_surfaces will
		// create it cleanly. Mark migrated so we don't keep scanning.
		update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body has the new longform affordance.
	if ( false !== strpos( $body, '/provenance/as-substrate/' ) ) {
		update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 Card 2 shape is present
	// (the SSRN abstract_id 6730343 anchor is the unique marker). If
	// absent, the admin has hand-edited away from the seed — bail
	// WITHOUT flagging so the migration can complete after recovery.
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_CARD2_LF_MIGR_OPT, time(), true );
}

/**
 * One-time migration that switches the /provenance pillar's two card
 * meta lines from hardcoded reading times ("4 min read" / "5 min read")
 * to dynamic `[sn_reading_time slug="..."]` shortcodes pointing at the
 * respective child long-forms. Without this, the pillar drifts every
 * time the prose evolves — the live drift between the pillar's "4 min"
 * and the over-detection byline's "5 min" was the trigger for this
 * migration.
 *
 * Strategy mirrors `sn_migrate_provenance_card2_longform()`: full-body
 * rewrite via `sn_provenance_papers_index_markup()`, gated on a unique
 * marker (the SSRN abstract_id 6730343 anchor on Card 2). If the
 * pillar body has been hand-edited away from seed shape, bail WITHOUT
 * setting the flag so a future run can complete after recovery.
 *
 * Self-idempotent: bails (and flags) if the body already contains the
 * shortcode token `[sn_reading_time slug=` — the unique marker for the
 * post-migration shape.
 */
add_action( 'admin_init', 'sn_migrate_provenance_card_readtimes_dynamic' );

function sn_migrate_provenance_card_readtimes_dynamic() {
	if ( get_option( SN_PROV_RT_DYNAMIC_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body uses the dynamic shortcode form.
	if ( false !== strpos( $body, '[sn_reading_time slug=' ) ) {
		update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 / Card-2-longform-migration
	// shape is present (SSRN abstract_id 6730343 anchor for Card 2).
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_RT_DYNAMIC_OPT, time(), true );
}

/**
 * One-time migration that adds № 01 / № 02 catalog-number markers
 * to the /provenance pillar cards, bringing them visually in line
 * with the /notes pillar treatment. Without this, the
 * sn_provenance_papers_index_markup() update only takes effect on
 * fresh installs — production sites already have the prior shape
 * locked in via earlier migrations' flags.
 *
 * Strategy mirrors `sn_migrate_provenance_card_readtimes_dynamic()`:
 * full-body rewrite via the index function, gated on the SSRN
 * abstract_id 6730343 anchor for Card 2. If absent, admin has hand-
 * edited away from seed shape — bail WITHOUT flagging so a future
 * run can complete after recovery.
 *
 * Self-idempotent: bails (and flags) if the body already contains
 * `sn-catalog-number` — the unique marker for the post-migration
 * shape.
 */
add_action( 'admin_init', 'sn_migrate_provenance_catalog_numbers' );

function sn_migrate_provenance_catalog_numbers() {
	if ( get_option( SN_PROV_CATALOG_NUMBERS_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( ! $page ) {
		update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — body has the catalog-number markers.
	if ( false !== strpos( $body, 'sn-catalog-number' ) ) {
		update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
		return;
	}

	// Defensive: only proceed if the v6.5.4 / Card-2-longform shape
	// is present (SSRN abstract_id 6730343 anchor for Card 2). If
	// absent, the admin has hand-edited away from seed — bail
	// WITHOUT flagging so the migration can complete after recovery.
	if ( false === strpos( $body, 'abstract_id=6730343' ) ) {
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => sn_provenance_papers_index_markup(),
	) );

	update_option( SN_PROV_CATALOG_NUMBERS_OPT, time(), true );
}

/**
 * One-time migration that strips `displayType:"modified"` from the
 * as-substrate page's wp:post-date block, defaulting it to publish-
 * date display.
 *
 * Why: WordPress core's render_block_core_post_date() returns null
 * when displayType is "modified" AND post_modified equals post_date.
 * Newly-inserted posts have those equal, so the byline date renders
 * empty until the first edit. As-substrate is evergreen — by maintainer
 * convention it never gets edited — so under "modified" it would
 * permanently show no date. Switching to publish-date display (the
 * block default) always renders the post_date set at creation.
 *
 * Idempotent: bails (and flags) if the body already lacks
 * `displayType":"modified` — the only marker the migration needs to
 * detect previous completion. Defensive: if the str_replace finds no
 * match (e.g., admin edited the post-date block separately), bails
 * WITHOUT flagging so the migration can complete after recovery.
 */
add_action( 'admin_init', 'sn_migrate_as_substrate_post_date_displaytype' );

function sn_migrate_as_substrate_post_date_displaytype() {
	if ( get_option( SN_AS_DATE_DISPLAYTYPE_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_AS_SUBSTRATE_SLUG );
	if ( ! $page ) {
		update_option( SN_AS_DATE_DISPLAYTYPE_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — no displayType:"modified" left in the body.
	if ( false === strpos( $body, '"displayType":"modified"' ) ) {
		update_option( SN_AS_DATE_DISPLAYTYPE_OPT, time(), true );
		return;
	}

	// Strip the displayType attribute precisely (it sits between the
	// `format` attribute and `style`, which is the seeded order).
	$new = str_replace(
		'"format":"F j, Y","displayType":"modified",',
		'"format":"F j, Y",',
		$body
	);

	if ( $new === $body ) {
		// Pattern didn't match — admin has touched the post-date block.
		// Bail without flagging so a future run can complete after recovery.
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => $new,
	) );

	update_option( SN_AS_DATE_DISPLAYTYPE_OPT, time(), true );
}

/**
 * One-time migration that replaces the over-detection page's hardcoded
 * eyebrow read-time (`A short read · 4 min`) with the dynamic
 * `[sn_reading_time]` shortcode, eliminating the within-page drift
 * between the eyebrow and the byline.
 *
 * Background: v6.5.3 introduced the eyebrow with a hardcoded "4 min"
 * estimate. v6.5.4's seed simplified it to "A short read" only — but
 * the live page wasn't migrated, so production still shows the stale
 * value. The over-detection page's prose has grown since then; the
 * byline (dynamic shortcode) reads "5 min read" while the eyebrow
 * still reads "4 min". This migration syncs the live eyebrow to the
 * shortcode form (matching the as-substrate seed shape).
 *
 * Idempotent: bails (and flags) if the body already contains the
 * literal `A short read · [sn_reading_time]` token. Defensive: if the
 * regex finds no `A short read · N min` pattern (admin already
 * customised the eyebrow), bails WITHOUT flagging.
 */
add_action( 'admin_init', 'sn_migrate_over_detection_eyebrow_dynamic' );

function sn_migrate_over_detection_eyebrow_dynamic() {
	if ( get_option( SN_OD_EYEBROW_DYN_OPT ) ) {
		return;
	}

	$page = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_OVER_DETECTION_SLUG );
	if ( ! $page ) {
		update_option( SN_OD_EYEBROW_DYN_OPT, time(), true );
		return;
	}

	$body = $page->post_content;

	// Already migrated — eyebrow uses the shortcode form.
	if ( false !== strpos( $body, 'A short read · [sn_reading_time]' ) ) {
		update_option( SN_OD_EYEBROW_DYN_OPT, time(), true );
		return;
	}

	// Replace `A short read · N min[ read]` (case-insensitive on min/read,
	// `/u` for the literal middot). Limit to 1 replacement — the eyebrow
	// is the only place this pattern appears.
	$new = preg_replace(
		'/A short read\s*·\s*\d+\s*min(\s*read)?/u',
		'A short read · [sn_reading_time]',
		$body,
		1
	);

	if ( $new === $body || null === $new ) {
		// Pattern didn't match — admin has already changed the eyebrow.
		// Bail without flagging so a future run can complete after recovery.
		return;
	}

	wp_update_post( array(
		'ID'           => $page->ID,
		'post_content' => $new,
	) );

	update_option( SN_OD_EYEBROW_DYN_OPT, time(), true );
}

/**
 * One-time migration that removes any wp_template database override
 * of the `page-notes` template for this theme.
 *
 * Background: WordPress 6.x allows admins to edit block-theme
 * templates via the Site Editor (Appearance → Editor). When that
 * happens, WP creates a `wp_template` custom post that OVERRIDES
 * the .html file in the theme directory. After the override exists,
 * the file becomes irrelevant for template resolution — WP always
 * serves the DB version, even across theme updates. This is by
 * design (so admin edits aren't lost when a theme updates) but it's
 * surprising when the theme author updates a template file expecting
 * the change to take effect and instead the DB override silently
 * keeps serving the old version.
 *
 * That's exactly what happened with the `/notes` two-pillar-card
 * update in commit cbe3ee5: the theme file changed, the deploy
 * worked, but a DB override (created at some earlier point — possibly
 * just by opening the Site Editor on this template) kept WP serving
 * the prior single-card layout.
 *
 * Fix: delete any wp_template post that overrides `page-notes` for
 * this theme. WP then falls back to the theme file, which carries
 * the latest content. Future admin edits via Site Editor would
 * re-create a DB record — this migration is one-shot and won't
 * interfere with future intentional customizations (a new flag would
 * be required to clear those, by design).
 *
 * Idempotent: gated by SN_NOTES_TPL_OVERRIDE_CLEARED_OPT, runs at
 * most once per install. Defensive on `wp_template` post type
 * existence (some WP setups may not have block-theme support
 * registered when this fires).
 */
add_action( 'admin_init', 'sn_migrate_clear_notes_template_override' );

function sn_migrate_clear_notes_template_override() {
	if ( get_option( SN_NOTES_TPL_OVERRIDE_CLEARED_OPT ) ) {
		return;
	}

	if ( ! post_type_exists( 'wp_template' ) ) {
		// Block-theme support not registered yet; mark done so we
		// don't keep retrying. If WP later registers it on a future
		// admin pageload, the migration won't undo whatever state
		// exists then — but the admin would have to manually clear
		// any override via Site Editor anyway.
		update_option( SN_NOTES_TPL_OVERRIDE_CLEARED_OPT, time(), true );
		return;
	}

	$template_ids = get_posts( array(
		'post_type'      => 'wp_template',
		'post_status'    => 'any',
		'name'           => 'page-notes',
		'tax_query'      => array(
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'name',
				'terms'    => 'signal-and-noise',
			),
		),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	foreach ( $template_ids as $template_id ) {
		// Force-delete (skip trash) — block templates aren't useful
		// in trash and would just clutter Pages → Trash.
		wp_delete_post( (int) $template_id, true );
	}

	update_option( SN_NOTES_TPL_OVERRIDE_CLEARED_OPT, time(), true );
}

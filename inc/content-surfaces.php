<?php
/**
 * Signal & Noise Tools — content surfaces.
 *
 * Defines the canonical content structure: Notes category + permalink,
 * /notes index Page, /provenance + /over-detection + /as-substrate
 * Pages, and the query-loop scoping filter. Idempotent — seed
 * functions check for existence before creating, so safe on every
 * admin pageload.
 *
 * Moved from theme inc/notes-and-provenance.php in Phase 3
 * (theme v8.4.0 / plugin v1.3.0, 2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_NOTES_CATEGORY_SLUG    = 'notes';
const SN_NOTES_PAGE_SLUG        = 'notes';
const SN_PROVENANCE_SLUG        = 'provenance';
const SN_OVER_DETECTION_SLUG    = 'over-detection';
const SN_AS_SUBSTRATE_SLUG      = 'as-substrate';
const SN_VERIFY_SLUG            = 'verify';
const SN_PERMALINK_STRUCTURE    = '/notes/%postname%/';
const SN_SEED_FLAG_OPTION       = 'sn_content_surfaces_seeded_v1';
const SN_PROV_BODY_MIGRATED_OPT = 'sn_provenance_body_migrated_v1';
const SN_PROV_REFINE_MIGR_OPT   = 'sn_provenance_refine_migrated_v1';
const SN_PROV_BYLINE_RT_MIGR_OPT = 'sn_provenance_byline_reading_time_migrated_v1';
const SN_PROV_SPLIT_MIGR_OPT    = 'sn_provenance_split_migrated_v1';
const SN_AS_SUBSTRATE_SEED_OPT  = 'sn_provenance_as_substrate_seeded_v1';
const SN_PROV_CARD2_LF_MIGR_OPT = 'sn_provenance_card2_longform_migrated_v1';
const SN_PROV_RT_DYNAMIC_OPT    = 'sn_provenance_card_readtimes_dynamic_v1';
const SN_AS_DATE_DISPLAYTYPE_OPT = 'sn_provenance_as_substrate_date_displaytype_v1';
const SN_OD_EYEBROW_DYN_OPT     = 'sn_provenance_over_detection_eyebrow_dynamic_v1';
const SN_NOTES_TPL_OVERRIDE_CLEARED_OPT = 'sn_notes_template_override_cleared_v1';
const SN_PROV_CATALOG_NUMBERS_OPT = 'sn_provenance_catalog_numbers_v1';
const SN_PROV_VERIFY_PAGE_MIGR_OPT = 'sn_prov_verify_page_migrated_v1';
const SN_ABOUT_SLUG             = 'about';
const SN_ABOUT_BODY_MIGRATED_OPT = 'sn_about_body_migrated_v1';
const SN_CONTACT_SLUG           = 'contact';
const SN_CONTACT_BODY_MIGRATED_OPT = 'sn_contact_body_migrated_v1';
const SN_SERVICES_SLUG          = 'services';
const SN_SERVICES_BODY_MIGRATED_OPT = 'sn_services_body_migrated_v1';
const SN_NOTES_QUERY_ID         = 42;

/**
 * Activation: seed category, pages, and permalink once per theme install.
 *
 * Idempotent — safe to run multiple times. The seed flag prevents
 * unnecessary work on every theme switch.
 */
add_action( 'after_switch_theme', 'sn_seed_content_surfaces' );

/**
 * Also run on admin_init (cheap option read) so a fresh deploy without a
 * theme-switch event still gets the surfaces created. Guarded by the same
 * seed flag, so it only ever does real work once.
 */
add_action( 'admin_init', 'sn_seed_content_surfaces' );

function sn_seed_content_surfaces() {
	if ( get_option( SN_SEED_FLAG_OPTION ) ) {
		return;
	}

	sn_ensure_notes_category();
	sn_ensure_notes_page();
	sn_ensure_provenance_page();
	sn_ensure_over_detection_page();
	sn_ensure_as_substrate_page();
	sn_ensure_verify_page();
	sn_ensure_permalink_structure();

	update_option( SN_SEED_FLAG_OPTION, time(), true );
	flush_rewrite_rules();
}

function sn_ensure_notes_category() {
	$existing = get_term_by( 'slug', SN_NOTES_CATEGORY_SLUG, 'category' );
	if ( $existing ) {
		return (int) $existing->term_id;
	}

	$result = wp_insert_term(
		'Notes',
		'category',
		array( 'slug' => SN_NOTES_CATEGORY_SLUG )
	);

	return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
}

function sn_ensure_notes_page() {
	$existing = get_page_by_path( SN_NOTES_PAGE_SLUG );
	if ( $existing ) {
		return (int) $existing->ID;
	}

	return wp_insert_post( array(
		'post_title'    => 'Notes',
		'post_name'     => SN_NOTES_PAGE_SLUG,
		'post_status'   => 'publish',
		'post_type'     => 'page',
		'post_content'  => '',
		'post_excerpt'  => 'Working notes on music, AI, and the infrastructure underneath. Written when there\'s something worth writing.',
		'page_template' => 'page-notes',
	), false );
}

/**
 * Create the Provenance pillar as a static Page, assigned to the
 * page-provenance.html custom template. Body is pre-populated from
 * inc/seed-content/provenance-body.html — a lean two-paper index
 * (heading + intro + two entries with SSRN + long-form links). The
 * long-form essay for paper 1 lives on the child page /provenance/
 * over-detection (see sn_ensure_over_detection_page).
 *
 * Idempotent: leave any existing /provenance page untouched.
 */
function sn_ensure_provenance_page() {
	$existing = get_page_by_path( SN_PROVENANCE_SLUG );
	if ( $existing ) {
		return (int) $existing->ID;
	}

	return wp_insert_post( array(
		'post_title'    => 'On Provenance',
		'post_name'     => SN_PROVENANCE_SLUG,
		'post_status'   => 'publish',
		'post_type'     => 'page',
		'post_content'  => sn_load_provenance_body(),
		'post_excerpt'  => 'Two papers proposing cryptographic provenance as the foundation of music rights infrastructure.',
		'page_template' => 'page-provenance',
	), false );
}

/**
 * Create the long-form essay as a child page under /provenance, at
 * /provenance/over-detection. Reuses page-provenance.html so the prose
 * inherits the same hero/section/byline treatment the essay was designed
 * for. Idempotent: leave any existing child page untouched (so an admin
 * who edits the essay from Pages → Provenance Over Detection isn't
 * clobbered on a future theme reactivation).
 */
function sn_ensure_over_detection_page() {
	$parent = get_page_by_path( SN_PROVENANCE_SLUG );
	$parent_id = $parent ? (int) $parent->ID : 0;

	$existing = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_OVER_DETECTION_SLUG );
	if ( $existing ) {
		return (int) $existing->ID;
	}

	return wp_insert_post( array(
		'post_title'    => 'Provenance Over Detection',
		'post_name'     => SN_OVER_DETECTION_SLUG,
		'post_parent'   => $parent_id,
		'post_status'   => 'publish',
		'post_type'     => 'page',
		'post_content'  => sn_load_over_detection_body(),
		'post_excerpt'  => "A short read on why the industry needs to prove what's human, not chase what isn't.",
		'page_template' => 'page-provenance',
	), false );
}

/**
 * Create the second long-form essay as a child page under /provenance, at
 * /provenance/as-substrate. Reuses page-provenance.html so the prose
 * inherits the same hero/section/byline treatment the first essay was
 * designed for. Idempotent: leave any existing child page untouched.
 */
function sn_ensure_as_substrate_page() {
	$parent = get_page_by_path( SN_PROVENANCE_SLUG );
	$parent_id = $parent ? (int) $parent->ID : 0;

	$existing = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_AS_SUBSTRATE_SLUG );
	if ( $existing ) {
		return (int) $existing->ID;
	}

	return wp_insert_post( array(
		'post_title'    => 'Provenance as Substrate',
		'post_name'     => SN_AS_SUBSTRATE_SLUG,
		'post_parent'   => $parent_id,
		'post_status'   => 'publish',
		'post_type'     => 'page',
		'post_content'  => sn_load_as_substrate_body(),
		'post_excerpt'  => 'A short read on why music files need fingerprints, not just name tags.',
		'page_template' => 'page-provenance',
	), false );
}

/**
 * Create the public "Verify a Note" how-to as a child page under /provenance,
 * at /provenance/verify. This is the surface the byline panel's "Verify it
 * yourself" link (sn_prov_render_panel → home_url('/provenance/verify'))
 * points at, so without this page that link 404s on every Note.
 *
 * The body embeds the [sn_provenance_verify] shortcode; the_content() expands
 * it to the live verification steps + the published Ed25519 public key, so the
 * instructions never drift from sn_prov_verify_shortcode(). Reuses
 * page-provenance.html so it inherits the same hero/section treatment as its
 * siblings (guaranteed to exist). Idempotent: leave any existing child page
 * untouched.
 */
function sn_ensure_verify_page() {
	$parent = get_page_by_path( SN_PROVENANCE_SLUG );
	$parent_id = $parent ? (int) $parent->ID : 0;

	$existing = get_page_by_path( SN_PROVENANCE_SLUG . '/' . SN_VERIFY_SLUG );
	if ( $existing ) {
		return (int) $existing->ID;
	}

	return wp_insert_post( array(
		'post_title'    => 'Verify a Note',
		'post_name'     => SN_VERIFY_SLUG,
		'post_parent'   => $parent_id,
		'post_status'   => 'publish',
		'post_type'     => 'page',
		'post_content'  => sn_load_verify_body(),
		'post_excerpt'  => 'How to check any Note\'s cryptographic provenance record yourself, without trusting this site.',
		'page_template' => 'page-provenance',
	), false );
}

/**
 * Set the global permalink structure once, only if it isn't already
 * what we want AND there are no existing posts whose URLs would change.
 *
 * Posts are disabled on this site by default; the empty-post-count guard
 * keeps the change safe even on installs where someone already had a
 * different structure. We never overwrite an existing match either.
 */
function sn_ensure_permalink_structure() {
	$current = get_option( 'permalink_structure' );
	if ( SN_PERMALINK_STRUCTURE === $current ) {
		return;
	}

	$existing_post_count = (int) wp_count_posts( 'post' )->publish;
	if ( $existing_post_count > 0 ) {
		return;
	}

	update_option( 'permalink_structure', SN_PERMALINK_STRUCTURE );
}

/**
 * Default Post Category sync.
 *
 * WordPress's `default_category` option controls which category a new
 * post is assigned when the editor doesn't tick anything explicitly.
 * Pointing it at the Notes category means: any post created from the
 * editor lands in `Notes` automatically, which is what makes them show
 * up at /notes (the index query is filtered by the Notes category).
 *
 * Combined with the Note layout being the default `single.html`
 * template, this makes "Posts → Add New → write → Publish" produce a
 * fully-formed Note with no template dropdown, no category checkbox,
 * no manual setup.
 *
 * Self-healing: runs cheaply on every admin_init and only writes when
 * the option drifts. Safe to call before sn_seed_content_surfaces() has
 * created the category — it just no-ops in that case.
 */
add_action( 'admin_init', 'sn_sync_default_category' );

function sn_sync_default_category() {
	$cat = get_term_by( 'slug', SN_NOTES_CATEGORY_SLUG, 'category' );
	if ( ! $cat ) {
		return;
	}
	if ( (int) get_option( 'default_category' ) !== (int) $cat->term_id ) {
		update_option( 'default_category', (int) $cat->term_id );
	}
}

/**
 * Filter the Notes index query loop (queryId 42 in templates/page-notes.html)
 * to surface only Notes-category posts. Keeping the category restriction
 * here — rather than baked as an ID into block markup — means the template
 * works regardless of the term ID assigned at install time.
 */
add_filter( 'query_loop_block_query_vars', function( $query, $block ) {
	$context_query_id = $block->context['queryId'] ?? null;
	if ( SN_NOTES_QUERY_ID !== $context_query_id ) {
		return $query;
	}
	$query['category_name'] = SN_NOTES_CATEGORY_SLUG;
	return $query;
}, 10, 2 );

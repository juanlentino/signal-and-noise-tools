<?php
/**
 * Signal & Noise Tools — Abilities API: sn_site_facts (MCP consolidation,
 * session 3).
 *
 * Consolidated tool absorbing 10 of the 11 abilities
 * ~/.claude/session-data/SN-MCP-new/sn-mcp-consolidation.md's mapping table
 * lists under sn_site_facts. `get-design-system-summary` is RETIRED, not
 * absorbed — its own description says it formats tokens for AI-prompt
 * embedding, a translation layer the raw design_tokens fact makes redundant
 * — so it is left registered and untouched, exactly like every other
 * absorbed-elsewhere ability this session.
 *
 * NAMING DEVIATION (shared with inc/abilities-sn-posts.php): the spec writes
 * bare `sn_site_facts`, but this server derives MCP tool names from ability
 * SLUGS via sn_mcp_tool_name_from_slug() ('/' → '__'), never from a
 * standalone name field — there is no seam to special-case a bare tool name
 * without forking that mapper for two slugs only. Registered as
 * `signal-noise/sn-site-facts`, which projects to the MCP tool name
 * `signal-noise__sn-site-facts`. Noted in FINDINGS.md.
 *
 * CRITICAL property this file is built around: 9 of the 10 fact sources are
 * THEME abilities (`signal-and-noise/*`) that this plugin's MCP door merely
 * re-exposes — the theme registers them, this plugin never re-implements
 * their logic. Each fact is dispatched via wp_get_ability(<source
 * slug>)->execute(), mirroring inc/mcp/mcp-tools.php's own
 * check_permissions() → execute() sequence (see
 * snt_sn_site_facts_dispatch()) so a theme-absent site or a since-retired
 * source ability degrades that ONE fact to {error:"unavailable"} rather than
 * failing the whole call — every other requested fact still returns. The
 * tool itself only errors (a real WP_Error) on genuinely invalid INPUT:
 * an empty/unknown facts[] entry, or a missing `slug` when reading_time,
 * seo_route_meta, OR active_template was requested (see the R1 fix note below).
 *
 * Fact → source-slug map (verified by reading the LIVE registrations: the 9
 * theme slugs are read from inc/mcp/mcp-capabilities.php's sn_mcp_allowlist()
 * — the theme's own source is not in this repo, so most of its exact
 * input_schemas could not be independently confirmed at write time; see the
 * per-fact dispatch args below and docs/mcp-consolidation/FINDINGS.md's
 * session-3-review-round-2 note on the seo_route_meta argument-name
 * assumption that remains open):
 *
 *   theme_version      -> signal-and-noise/get-theme-version           (no args)
 *   latest_theme_tag   -> signal-and-noise/get-latest-theme-tag        (no args)
 *   design_tokens      -> signal-and-noise/get-design-tokens           (no args)
 *   block_patterns     -> signal-and-noise/list-block-patterns         (no args)
 *   template_overrides -> signal-noise/list-template-overrides         (no args — PLUGIN slug, not theme)
 *   active_template    -> signal-and-noise/get-active-template-structure (args: slug + post_type —
 *                          slug is REQUIRED input; post_type is dispatched page-first with one
 *                          post retry; see R1 and the R2 note on
 *                          snt_sn_site_facts_dispatch_active_template())
 *   llms_txt           -> signal-and-noise/get-llms-txt                (no args)
 *   seo_route_meta     -> signal-and-noise/get-seo-route-meta          (args: slug — REQUIRED input)
 *   pillars            -> signal-and-noise/get-page-notes-pillars      (no args)
 *   reading_time       -> signal-and-noise/get-reading-time-for-slug   (args: slug — REQUIRED input, confirmed via
 *                          the `[sn_reading_time slug="..."]` shortcode contract in
 *                          tests/reading-time-shortcode-oracle.php)
 *
 * R1 FIX (adversarial review round 2, same v10.26.0 ship): the first cut of
 * this file dispatched active_template with NO args, reasoning its
 * post_id/slug were both optional (an anyOf, not two required fields). That
 * reasoning was wrong at the EXECUTE level: the theme's real
 * sn_theme_ability_active_template_structure() (signal-and-noise theme repo,
 * inc/abilities-diagnostics.php:535-554) resolves $post from EITHER
 * post_id OR slug and, when NEITHER is present, `$post` stays null and the
 * function returns `WP_Error('post_not_found', ..., array('status'=>404))`
 * deterministically — there is no no-args default path. Dispatched with no
 * args, every active_template call therefore degraded to
 * {error:'unavailable'} on a perfectly healthy site, forever — a
 * permanently dead fact that the original 31/31 green suite never caught
 * because no test ever put 'active_template' in a facts[] array. Fixed:
 * active_template joins reading_time + seo_route_meta in
 * snt_sn_site_facts_slug_required(), dispatched with a slug-carrying shape.
 * Requesting it without slug now takes the existing
 * snt_site_facts_missing_slug 400 path instead of a silent, permanent
 * {error:'unavailable'}. (R1's closing claim that get_page_by_path "needs no
 * post_type disambiguation for this tool's purposes" was itself wrong for
 * POST slugs — see the R2 note on snt_sn_site_facts_dispatch_active_template()
 * for the page-first + post-retry dispatch that superseded it.)
 *
 * @package SignalNoiseTools
 * @since 10.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-site-facts', array(
		'label'               => 'Batch-read site facts (consolidated)',
		'description'         => 'Consolidated read for 10 site facts otherwise requiring 10 sequential calls (get-design-system-summary is retired, not absorbed — its raw counterpart design_tokens supersedes it): theme_version, latest_theme_tag, design_tokens, block_patterns, template_overrides, active_template, llms_txt, seo_route_meta, pillars, reading_time. Pass the subset you need in `facts`; `slug` is REQUIRED when reading_time, seo_route_meta, OR active_template is requested — each targets a specific post and has no site-wide default (400 if missing for any of the three). active_template accepts BOTH page and post slugs: it is resolved page-first, then retried once as a post. Returns a map keyed by requested fact. Each fact dispatches to its real underlying ability (most are theme-owned; this tool never duplicates their logic) — if the theme is absent, a source ability is unregistered, or its own permission/execute step fails, that ONE fact\'s entry becomes {error:"unavailable"} while every other requested fact still returns normally. The call as a whole only fails on invalid input: an empty or unrecognized facts[] entry, or a missing required slug.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_sn_site_facts',
		'input_schema'        => array(
			'type'                 => 'object', // 'facts' is required, so no bodyless-GET null union (mirrors keyword-candidates' precedent).
			'required'             => array( 'facts' ),
			'properties'           => array(
				'facts' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => array_keys( snt_sn_site_facts_map() ),
					),
					'minItems' => 1,
				),
				'slug'  => array(
					'type'        => 'string',
					'description' => 'Required when facts includes reading_time, seo_route_meta, or active_template (400 if any of those is requested and slug is missing or blank). Ignored by every other fact.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'    => array( 'type' => 'boolean' ),
				'facts' => array( 'type' => 'object' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/* ════════════════════════════════════════════════════════════════════════
 * Fact map + slug requirement — declared as plain functions (not a `const`
 * array literal) so the input_schema registration above can call
 * snt_sn_site_facts_map() directly without a PHP 8.3 first-class-callable-in-
 * const-expression concern.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * fact name => source ability slug. The single source of truth for both the
 * input_schema's enum and the dispatch loop.
 *
 * @return array<string,string>
 */
function snt_sn_site_facts_map() {
	return array(
		'theme_version'      => 'signal-and-noise/get-theme-version',
		'latest_theme_tag'   => 'signal-and-noise/get-latest-theme-tag',
		'design_tokens'      => 'signal-and-noise/get-design-tokens',
		'block_patterns'     => 'signal-and-noise/list-block-patterns',
		'template_overrides' => 'signal-noise/list-template-overrides',
		'active_template'    => 'signal-and-noise/get-active-template-structure',
		'llms_txt'           => 'signal-and-noise/get-llms-txt',
		'seo_route_meta'     => 'signal-and-noise/get-seo-route-meta',
		'pillars'            => 'signal-and-noise/get-page-notes-pillars',
		'reading_time'       => 'signal-and-noise/get-reading-time-for-slug',
	);
}

/**
 * Facts requiring the top-level `slug` input. active_template joined this
 * set in the R1 fix (see file docblock) — its source ability has no
 * no-args default path; an empty-args call deterministically 404s at the
 * theme's own execute level, so dispatching it without slug was a
 * permanently dead fact, not a graceful degradation.
 *
 * @return string[]
 */
function snt_sn_site_facts_slug_required() {
	return array( 'reading_time', 'seo_route_meta', 'active_template' );
}

/* ════════════════════════════════════════════════════════════════════════
 * Execute
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Dispatch one fact's source ability: check_permissions() then execute(),
 * the same two-step sequence inc/mcp/mcp-tools.php's sn_mcp_call_tool()
 * uses, so a theme-absent or permission-denied source degrades to the
 * documented {error:"unavailable"} shape rather than a PHP fatal or an
 * unguarded WP_Error passthrough (which would leak the source ability's own
 * internal error code/message into a facts map value — this tool's own
 * contract is uniform per-fact degradation, not error forwarding).
 *
 * @param string $ability_slug
 * @param array  $args
 * @return mixed|array{error:string} The ability's raw result, or {error:'unavailable'}.
 */
function snt_sn_site_facts_dispatch( $ability_slug, $args ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return array( 'error' => 'unavailable' );
	}
	$ability = wp_get_ability( $ability_slug );
	if ( ! $ability ) {
		return array( 'error' => 'unavailable' ); // Theme absent, or the source ability was retired/unregistered.
	}
	$perm = $ability->check_permissions( $args );
	if ( is_wp_error( $perm ) || false === $perm ) {
		return array( 'error' => 'unavailable' );
	}
	$result = $ability->execute( $args );
	if ( is_wp_error( $result ) ) {
		return array( 'error' => 'unavailable' );
	}
	return $result;
}

/**
 * active_template only: page-first dispatch with a single post retry.
 *
 * R2 FIX (verified live 2026-08-02): the theme's real
 * sn_theme_ability_active_template_structure() slug branch defaults
 * post_type to 'page' (abilities-diagnostics.php:541-546 —
 * get_page_by_path( $slug, OBJECT, $input['post_type'] ?? 'page' )), so a
 * bare-slug dispatch can NEVER resolve a post's slug: every POST slug
 * degraded to {error:'unavailable'} while page slugs worked. The theme
 * schema already accepts post_type enum('post','page'); fixed plugin-side
 * (this file owns the dispatch shape, the theme contract is unchanged):
 * dispatch {slug, post_type:'page'} first — explicit now, but identical to
 * the previous effective behavior, so page slugs are resolved in one call
 * exactly as before — and only on failure retry ONCE with post_type:'post'.
 * Only when both lookups fail does the fact degrade to the documented
 * {error:'unavailable'}. The retry keys off the collapsed unavailable shape
 * (this dispatcher's uniform degradation contract deliberately erases the
 * source error), so a theme-absent/permission-denied first attempt also
 * retries once — a harmless second degradation, never a behavior change.
 *
 * @param string $ability_slug
 * @param string $slug
 * @return mixed|array{error:string}
 */
function snt_sn_site_facts_dispatch_active_template( $ability_slug, $slug ) {
	$page_hit = snt_sn_site_facts_dispatch( $ability_slug, array( 'slug' => $slug, 'post_type' => 'page' ) );
	if ( array( 'error' => 'unavailable' ) !== $page_hit ) {
		return $page_hit;
	}
	return snt_sn_site_facts_dispatch( $ability_slug, array( 'slug' => $slug, 'post_type' => 'post' ) );
}

/**
 * Ability execute callback: signal-noise/sn-site-facts.
 *
 * @param array|null $input { facts: string[], slug?: string }.
 * @return array{ok:bool,facts:array}|WP_Error
 */
function snt_ability_sn_site_facts( $input ) {
	$input = is_array( $input ) ? $input : array();
	$map   = snt_sn_site_facts_map();

	$facts = isset( $input['facts'] ) ? array_values( array_unique( array_map( 'strval', (array) $input['facts'] ) ) ) : array();
	if ( empty( $facts ) ) {
		return new WP_Error( 'snt_site_facts_empty', __( 'facts must be a non-empty array.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$unknown = array_values( array_diff( $facts, array_keys( $map ) ) );
	if ( ! empty( $unknown ) ) {
		return new WP_Error(
			'snt_site_facts_unknown',
			sprintf(
				/* translators: %s: comma-separated list of unrecognized fact names. */
				__( 'Unknown fact(s): %s', 'signal-and-noise-tools' ),
				implode( ', ', $unknown )
			),
			array( 'status' => 422 )
		);
	}

	$slug         = isset( $input['slug'] ) ? trim( (string) $input['slug'] ) : '';
	$slug_needed  = array_values( array_intersect( $facts, snt_sn_site_facts_slug_required() ) );
	if ( ! empty( $slug_needed ) && '' === $slug ) {
		return new WP_Error(
			'snt_site_facts_missing_slug',
			sprintf(
				/* translators: %s: comma-separated list of facts requiring slug. */
				__( 'slug is required when requesting: %s', 'signal-and-noise-tools' ),
				implode( ', ', $slug_needed )
			),
			array( 'status' => 400 )
		);
	}

	$out = array();
	foreach ( $facts as $fact ) {
		if ( 'active_template' === $fact ) {
			$out[ $fact ] = snt_sn_site_facts_dispatch_active_template( $map[ $fact ], $slug );
			continue;
		}
		$args         = in_array( $fact, snt_sn_site_facts_slug_required(), true ) ? array( 'slug' => $slug ) : array();
		$out[ $fact ] = snt_sn_site_facts_dispatch( $map[ $fact ], $args );
	}

	return array(
		'ok'    => true,
		'facts' => $out,
	);
}

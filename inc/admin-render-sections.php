<?php
/**
 * Signal & Noise — admin leaf render wrappers.
 *
 * One named render function per do_action-backed / composite admin sub-tab, so
 * the registry (sn_admin_top_tabs) can reference render functions by NAME and a
 * contract test can verify each exists. Each wrapper is a RAW section-body
 * emitter (no .sn-fieldset wrapper of its own) — the dispatcher
 * (sn_admin_render_active_tab) applies the single sn_admin_render_section()
 * wrapper uniformly, keyed by the sub-tab slug, reproducing the pre-refactor
 * arms in inc/admin-page.php verbatim. The Dashboard wrapper is the lone
 * exception: it is a tab-level (no-sub-tab) render the dispatcher calls bare,
 * exactly as the old switch did. Phase 1 — behaviour-preserving; Phase 3
 * normalizes these to the shared section standard.
 *
 * @package SignalNoiseTools
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dashboard landing (no sub-tabs): the hero/cards/diagnostics hook. Called bare. */
function sn_admin_render_dashboard() {
	do_action( 'sn_admin_dashboard_extras' );
}

/** Site → Cloudflare. */
function sn_admin_render_cloudflare_section() {
	do_action( 'sn_admin_cloudflare_tab' );
}

/** Automation → Cron. */
function sn_admin_render_cron_section() {
	do_action( 'sn_admin_cron_tab' );
}

/** Automation → Webhooks. */
function sn_admin_render_webhooks_section() {
	do_action( 'sn_admin_webhooks_tab' );
}

/** Connections → Redirects (v8.10.0): the redirect manager + 404 log. */
function sn_admin_render_redirects_section() {
	do_action( 'sn_admin_redirects_tab' );
}

/** Measurement → Health. */
function sn_admin_render_health_section() {
	do_action( 'sn_admin_health_tab' );
}

/** Measurement → Insights. */
function sn_admin_render_insights_section() {
	do_action( 'sn_admin_insights_tab' );
}


/** Content → Block Migrations (was Tools until v10.46.0). */
function sn_admin_render_block_migrations_section() {
	do_action( 'sn_admin_block_migrations_tab' );
}

/**
 * Content → Pattern Adoption (v10.46.0).
 *
 * Was not a leaf at all until now: the Opportunities card rendered inline in
 * the Health tab's action row, which meant it was reachable only after a HEALTH
 * scan had run — a gate it inherited from its position, not from anything it
 * needed. As a leaf it delegates exactly like its two sibling scanners.
 */
function sn_admin_render_pattern_adoption_section() {
	do_action( 'sn_admin_pattern_adoption_tab' );
}

/** Content → RSS. The tracker module (inc/rss-feed-tracker.php) is always
 *  loaded by the bootstrap, so it always hooks sn_admin_rss_tab; the guard is
 *  a defensive no-op fallback should the module ever be filtered out. */
function sn_admin_render_rss_section() {
	if ( has_action( 'sn_admin_rss_tab' ) ) {
		do_action( 'sn_admin_rss_tab' );
	} else {
		echo '<div class="notice notice-warning inline sn-rss-not-installed"><p><strong>RSS feed-request tracker not loaded.</strong></p></div>';
	}
}

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

/** Connections → Cloudways (v12.17.0): display-only origin-cache status. */
function sn_admin_render_cloudways_section() {
	do_action( 'sn_admin_cloudways_tab' );
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
 * Integrity → Trust checks (v10.47.0). A second VIEW of four checks that already
 * run inside the health scan — never a second copy, and it triggers nothing.
 */
function sn_admin_render_trust_section() {
	do_action( 'sn_admin_trust_tab' );
}

/**
 * Integrity → Reports (v11.13.0).
 *
 * The report-only checks, read out of the same cached Health scan the trust
 * leaf uses. Nothing about the renderer changed — only its host. A measurement
 * that spends four sentences explaining it is not counting defects does not
 * belong on a page whose headline number is a defect count.
 *
 * Reads the INTEGRITY surface rather than "every report in the scan", so a
 * future report-only DEFECT would stay on Health where it belongs.
 */
function sn_admin_render_health_reports_section() {
	$scan = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	if ( ! is_array( $scan ) ) {
		echo '<div class="sn-fieldset"><p class="sn-fieldset-intro">' . esc_html__( 'No scan yet — run one from Measurement → Health.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	$integrity = sn_health_checks_for_surface( $scan, 'integrity' );
	$reports   = array();
	foreach ( $integrity as $key => $check ) {
		if ( sn_health_check_has_report( $check ) ) {
			$reports[ $key ] = $check;
		}
	}
	if ( ! $reports ) {
		echo '<div class="sn-fieldset"><p class="sn-fieldset-intro">' . esc_html__( 'The last scan produced no reports.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	sn_health_render_reports_section( $reports );
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

/**
 * Content → Vocabulary (v11.2.0, R4 4A). The FOURTH content read surface: the
 * corpus-drift mirror. Delegates exactly like its three sibling scanners; the
 * module (inc/ml-drift-admin.php) hooks the action.
 */
function sn_admin_render_drift_section() {
	do_action( 'sn_admin_drift_tab' );
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

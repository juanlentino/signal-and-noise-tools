<?php
/**
 * Signal & Noise Tools — the Command Palette (⌘K) commands.
 *
 * The command table plus its registration loop on `init` priority 6, and the
 * two surviving command implementations.
 *
 * Every registered command MUST have a matching JS run() in
 * assets/desktop-mode.js — tests/desktop-mode-integration.php fails the build
 * otherwise. A command with no run() renders a real, clickable palette entry
 * that silently does nothing (the v9.52.3 lesson).
 *
 * Split out of inc/desktop-mode-integration.php in v10.87.2; the code is
 * unchanged. That file is now the loader and still carries the architectural
 * notes covering all seven modules — read it first.
 *
 * @package SignalNoiseTools
 * @since 1.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Command Palette commands + desktop widgets (init:6).
 *
 * MUST be `init` — see the long note above the script-registration block.
 * desktop-mode reads both registries eagerly at admin_enqueue_scripts:10 and
 * always beats a same-priority callback of ours.
 */
add_action( 'init', function() {
	if ( ! snt_os_active() ) {
		return;
	}

	$commands = array(
		// Maintenance (REST → toast).
		array( 'slug' => 'sn-cmd-force-check',     'label' => 'SN: Force-check updates',       'description' => 'Clear all GitHub + WordPress update transients.',           'icon' => 'dashicons-update' ),
		array( 'slug' => 'sn-cmd-purge-caches',    'label' => 'SN: Purge all caches',          'description' => 'Object cache + Breeze + Varnish + Cloudflare.',           'icon' => 'dashicons-trash' ),
		array( 'slug' => 'sn-cmd-clear-overrides', 'label' => 'SN: Clear template overrides',  'description' => 'Remove wp_template / wp_template_part / wp_navigation DB rows.', 'icon' => 'dashicons-editor-removeformatting' ),
		// The JS run() dispatches purge-all-caches {include_template_overrides:true} (the full-reset ability was removed in v8.0.0).
		array( 'slug' => 'sn-cmd-full-reset',      'label' => 'SN: Full reset',                'description' => 'Clear overrides AND purge every cache.',                  'icon' => 'dashicons-controls-repeat' ),

		// Navigation (window.location).
		array( 'slug' => 'sn-cmd-nav-dashboard',    'label' => 'SN: Open Dashboard',    'description' => 'Site state, recent deploys, maintenance actions.', 'icon' => 'dashicons-dashboard' ),
		array( 'slug' => 'sn-cmd-nav-identity',     'label' => 'SN: Open Identity',     'description' => 'Site name, social profiles, OG cards, SEO copy.',  'icon' => 'dashicons-id' ),
		array( 'slug' => 'sn-cmd-nav-login',        'label' => 'SN: Open Login',        'description' => 'Custom login URL + emergency unlock.',             'icon' => 'dashicons-lock' ),
		array( 'slug' => 'sn-cmd-nav-cloudflare',   'label' => 'SN: Open Cloudflare',   'description' => 'CF API token + zone + auto-purge config.',         'icon' => 'dashicons-cloud' ),
		array( 'slug' => 'sn-cmd-nav-rss',          'label' => 'SN: Open RSS',          'description' => 'Subscriber tracking + recent feed requests.',      'icon' => 'dashicons-rss' ),
		array( 'slug' => 'sn-cmd-nav-reading-time', 'label' => 'SN: Open Reading Time', 'description' => 'Legacy reading-time-string cleanup tool.',         'icon' => 'dashicons-clock' ),

		// Info (read from localized data → toast).
		array( 'slug' => 'sn-cmd-version-theme',  'label' => 'SN: Theme version',  'description' => 'Show current theme version + GitHub-latest comparison.',  'icon' => 'dashicons-admin-appearance' ),
		array( 'slug' => 'sn-cmd-version-plugin', 'label' => 'SN: Plugin version', 'description' => 'Show current plugin version + GitHub-latest comparison.', 'icon' => 'dashicons-admin-plugins' ),

		// Cron Dashboard (v3.0.0).
		array( 'slug' => 'sn-cmd-cron-health', 'label' => 'SN: Cron health overview',    'description' => 'Toast a summary of scheduled events + navigate to the Cron tab.',     'icon' => 'dashicons-clock' ),
		array( 'slug' => 'sn-cmd-cron-list',   'label' => 'SN: Open Cron tab',           'description' => 'Navigate directly to the SN Cron tab in wp-admin.',                  'icon' => 'dashicons-list-view' ),

		// Insights (v3.6.0).
		array( 'slug' => 'sn-cmd-insights',    'label' => 'SN: Open Insights tab',       'description' => 'Navigate to the AI-powered Insights tab in wp-admin.',               'icon' => 'dashicons-lightbulb' ),

		// Audit log (v3.8.3).
		array( 'slug' => 'sn-cmd-audit-summary',       'label' => 'SN: Audit log summary',        'description' => 'Toast last-24h totals, 7-day trend, unique IPs, LLA lockout count.', 'icon' => 'dashicons-shield-alt' ),
		array( 'slug' => 'sn-cmd-audit-recent-logins', 'label' => 'SN: Recent successful logins', 'description' => 'Toast last 10 successful login timestamps + usernames.',              'icon' => 'dashicons-admin-users' ),

		// v9.78.0: the mirror-map gap — every one-shot ability gets a ⌘K entry
		// (glance = widget/badge, one-shot = command, review/config = iframe).
		array( 'slug' => 'sn-cmd-health-scan',    'label' => 'SN: Run health scan',      'description' => 'Run the full check suite now instead of waiting on the 24h cache.',        'icon' => 'dashicons-heart' ),
		array( 'slug' => 'sn-cmd-insights-scan',  'label' => 'SN: Run insights scan',    'description' => 'Refresh the AI insights corpus scan now.',                                  'icon' => 'dashicons-lightbulb' ),
		array( 'slug' => 'sn-cmd-narration',      'label' => 'SN: Run narration',        'description' => 'Regenerate the analytics narration now.',                                   'icon' => 'dashicons-format-chat' ),
		array( 'slug' => 'sn-cmd-prune-tags',     'label' => 'SN: Prune unused tags',    'description' => 'Delete tags with zero published posts.',                                    'icon' => 'dashicons-tag' ),
		array( 'slug' => 'sn-cmd-anchor-sweep',   'label' => 'SN: Sweep anchors',        'description' => 'Ask the provenance Worker to upgrade pending Bitcoin anchors now.',         'icon' => 'dashicons-admin-links' ),

		// v9.52.3: the 12 theme-ability ⌘K launcher commands are GONE.
		//
		// They were registered but never wired — no JS run() — so each rendered a
		// real, clickable palette entry that did nothing. They were parked as
		// display-only pending desktop_mode_register_ai_tool(), the server-side AI
		// tool registry the v3.8.0 plan targeted. desktop-mode REMOVED that API in
		// 0.9.4 and replaced it with WordPress Abilities, so the thing they waited
		// for is never coming.
		//
		// Nothing is lost: the replacement is already live and strictly better.
		// desktop-mode's AI Copilot offers EVERY read-only ability
		// (meta.annotations.readonly) as a tool automatically — no opt-in, the
		// ability's own permission_callback still gates execution — and it can
		// dispatch them with STRUCTURED ARGUMENTS, which is exactly what a bare
		// launcher label never could. That's why 7 of these (get-design-tokens,
		// list-block-patterns, get-active-template-structure, get-theme-version,
		// get-page-notes-pillars, get-reading-time-for-slug,
		// get-design-system-summary) already answer through Ask AI today, and why
		// reading-time — whose required slug argument is the reason it was left
		// display-only ("sequential window.prompt() forms are worse than no UX")
		// — now works there.
		//
		// The 5 ai-* abilities are write-path and excluded from the Copilot's
		// readonly auto-enrol (a search turn can be driven by attacker-controlled
		// content), but remain available over the MCP write door — and since
		// OpenStation 0.9.8 the agents Tools picker enrols EVERY registered
		// ability, so the enforced gate is each ability's permission_callback +
		// annotations, never non-exposure.
		//
		// EVERY ABILITY IS UNTOUCHED. This removes 12 inert labels, not capability.
		// tests/desktop-mode-integration.php now fails the build if any registered
		// command lacks a JS run().
	);

	foreach ( $commands as $cmd ) {
		snt_os_register_command( array(
			'slug'        => $cmd['slug'],
			'label'       => $cmd['label'],
			'description' => $cmd['description'],
			'icon'        => $cmd['icon'],
			'script'      => 'sn-desktop-mode',
		) );
	}
}, 6 );

/* ════════════════════════════════════════════════════════════════════════
 * COMMAND IMPLEMENTATIONS
 *
 * Pure functions returning array (success payload) or WP_Error. Only the
 * impls with live callers remain: snt_cmd_impl_force_check (dashboard
 * button + ability) and snt_cmd_impl_rss_stats (abilities-content). The
 * purge-caches / clear-overrides siblings were deleted in v9.75.0 — their
 * ability execute callbacks apply the sn_*_result filters directly, and
 * the legacy /cmd/* REST routes that once shared them left in v7.0.0.
 *
 * @since v2.5.0 — extracted from snt_desktop_cmd_handler for the
 * abilities-first refactor.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Force-check: clear all "is there a new version?" caches. Single source of
 * truth — the admin dashboard's force-check button handler also calls this.
 *
 * v4.1.1 (D-01): The GHA runs cache (deploy history) is intentionally NOT
 * cleared here. Clearing it would force a 60/h GitHub API request without
 * answering the question the user actually asked. ETag-based conditional
 * requests in snt_gh_recent_runs() handle that cache's freshness without
 * quota cost.
 */
function snt_cmd_impl_force_check() {
	delete_site_transient( 'sn_gh_latest_theme' );
	delete_site_transient( 'sn_gh_latest_plugin' );
	delete_site_transient( 'update_themes' );
	delete_site_transient( 'update_plugins' );
	return array(
		'ok'      => true,
		'message' => 'Update caches cleared. Next page-load fetches fresh data from GitHub.',
	);
}

function snt_cmd_impl_rss_stats() {
	if ( ! function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
		return new WP_Error(
			'snt_rss_unavailable',
			'RSS tracker module not loaded.',
			array( 'status' => 503 )
		);
	}
	$stats    = sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) );
	$last_rel = '';
	if ( ! empty( $stats['most_recent'] ) ) {
		$t = strtotime( $stats['most_recent'] );
		if ( $t ) {
			$last_rel = human_time_diff( $t, time() ) . ' ago';
		}
	}
	return array(
		'ok'   => true,
		'data' => array(
			'last_request'          => $stats['most_recent'] ?? null,
			'last_request_relative' => $last_rel,
			'windows'               => $stats['windows'] ?? array(),
		),
	);
}

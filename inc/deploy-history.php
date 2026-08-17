<?php
/**
 * Signal & Noise Tools — Local deploy history.
 *
 * Records WP-admin Updates installs of the SN theme + plugin into a custom
 * option, then merges those records with the GitHub Actions workflow runs
 * cache so the Dashboard "Recent deploys" panel reflects what *actually*
 * landed — not just what CI recorded.
 *
 * Background: pre-v1.10.1 the plugin auto-deployed on tag push, so every
 * release fired a GHA workflow run and the Dashboard's "Recent deploys"
 * list (sourced from the workflow-runs API) was accurate. v1.10.1 made
 * plugin deploys manual-dispatch only (security: bound the SSH key's
 * blast radius). Theme followed in v8.5.1. Since then, every release that
 * lands via wp-admin → Dashboard → Updates → "Update plugin/theme" is
 * INVISIBLE to the GHA-only "Recent deploys" panel. The user's dashboard
 * froze at the last auto-on-tag-push deploy (plugin v3.8.6) while real
 * usage continued through v3.9.x → v4.1.x.
 *
 * Fix (v4.1.4, audit follow-up): hook `upgrader_process_complete`, filter
 * to just SN packages, persist a compact record per install. The renderer
 * (inc/admin-tab-dashboard.php) merges these with GHA runs, sorted by
 * timestamp newest-first, deduped by (repo, ref). The `html_url` field is
 * empty for wp-admin installs — the dashboard renderer already handles
 * that branch (renders an empty `<span>` instead of the GitHub link).
 *
 * Schema: option `sn_deploy_history` (autoloaded=no — read only on the
 * SN admin page) holds at most 20 records, FIFO. Each record matches the
 * `$runs` shape from snt_gh_recent_runs_merged() exactly so the renderer
 * needs no per-source branching.
 *
 * @package SignalNoiseTools
 * @since 4.1.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_DEPLOY_HISTORY_OPTION   = 'sn_deploy_history';
const SNT_DEPLOY_HISTORY_MAX_ROWS = 20;

// v4.1.5: tiny autoloaded sentinel for the admin_init version-check fast
// path. Stores { plugin: 'X.Y.Z', theme: 'X.Y.Z' } so the version-check
// can short-circuit on every admin page load via in-memory comparison
// against the autoloaded alloptions cache — no DB read on the hot path.
// The history option itself stays autoload=no.
const SNT_DEPLOY_HISTORY_SENTINEL_OPTION = 'sn_deploy_history_current_versions';

// (render hardening FIX 2): the version-change Breeze rollover used to
// fire the full CDN/Varnish purge chain synchronously on admin_init — blocking
// the first admin view after every update on the whole chain. It now schedules
// this single event (deduped via wp_next_scheduled) so the purge runs in cron
// context instead; see snt_deploy_history_purge_rollover_run() below.
const SNT_DEPLOY_HISTORY_PURGE_HOOK = 'sn_deploy_history_purge_rollover';

/**
 * Map of SN package handles to their (repo, package-key) tuples.
 * Mirrors SNT_DEPLOY_REPOS from inc/admin-tab-dashboard.php so the
 * dashboard renderer's `snt_dashboard_short_repo()` logic works on
 * records from this module (the renderer strips the `-tools` suffix
 * for the "plugin" label).
 *
 * Intentionally theme + plugin ONLY. The five Cloudflare workers on
 * Deploy Status (inc/deploy-workers.php / snt_deploy_workers_registry)
 * never install via the WP upgrader, so they have no history rows here
 * and must not be added to this map just to "keep the mirror complete".
 *
 * Keys here are the wp-admin upgrader's identifier — plugin uses
 * the `{folder}/{file}.php` basename, theme uses the stylesheet slug.
 */
const SNT_DEPLOY_HISTORY_PACKAGES = array(
	'plugin' => array(
		'upgrader_id' => 'signal-and-noise-tools/signal-and-noise-tools.php',
		'repo'        => 'juanlentino/signal-and-noise-tools',
	),
	'theme'  => array(
		'upgrader_id' => 'signal-and-noise',
		'repo'        => 'juanlentino/signal-and-noise',
	),
);

/**
 * Record a single SN package install in the deploy history.
 *
 * Called from the `upgrader_process_complete` hook handler below;
 * exposed as a named function so future surfaces (e.g., REST install
 * endpoint, CLI command) can also write records.
 *
 * @param string $package_key 'plugin' or 'theme'
 * @param string $version     Resolved post-install version (e.g., '4.1.4')
 * @return bool true if written, false on validation failure.
 */
function snt_deploy_history_record( $package_key, $version ) {
	if ( ! isset( SNT_DEPLOY_HISTORY_PACKAGES[ $package_key ] ) ) {
		return false;
	}
	$version = trim( (string) $version );
	if ( '' === $version ) {
		return false;
	}

	$repo = SNT_DEPLOY_HISTORY_PACKAGES[ $package_key ]['repo'];

	// Match the snt_gh_recent_runs_merged() record shape so the
	// dashboard renderer doesn't need to know which source emitted
	// the row. Differences from a real GHA run:
	//   - `id` is a synthetic timestamp+rand (positive int; no collision with GHA run IDs in practice)
	//   - `html_url` is empty (renderer falls back to empty <span>)
	//   - `trigger` = 'wp_admin' (vs 'workflow_dispatch' / 'push' for GHA runs)
	//   - `duration_s` = null (instant from user perspective; not measured)
	$record = array(
		'id'         => (int) ( time() * 1000 + wp_rand( 0, 999 ) ),
		'status'     => 'completed',
		'conclusion' => 'success',
		'ref'        => 'v' . ltrim( $version, 'v' ),
		'trigger'    => 'wp_admin',
		'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'duration_s' => null,
		'html_url'   => '',
		'repo'       => $repo,
	);

	$history = get_option( SNT_DEPLOY_HISTORY_OPTION, array() );
	if ( ! is_array( $history ) ) {
		$history = array();
	}

	// Newest first. Cap at SNT_DEPLOY_HISTORY_MAX_ROWS rows.
	array_unshift( $history, $record );
	if ( count( $history ) > SNT_DEPLOY_HISTORY_MAX_ROWS ) {
		$history = array_slice( $history, 0, SNT_DEPLOY_HISTORY_MAX_ROWS );
	}

	update_option( SNT_DEPLOY_HISTORY_OPTION, $history, false );
	return true;
}

/**
 * Return the local install history, newest first.
 *
 * @param int|null $limit Max rows to return. Null = all.
 * @return array Records in `$runs` shape.
 */
function snt_deploy_history_get( $limit = null ) {
	$history = get_option( SNT_DEPLOY_HISTORY_OPTION, array() );
	if ( ! is_array( $history ) ) {
		return array();
	}
	if ( is_int( $limit ) && $limit > 0 && count( $history ) > $limit ) {
		$history = array_slice( $history, 0, $limit );
	}
	return $history;
}

/**
 * Return true if a specific (package, version) pair is already in history.
 *
 * Used by snt_deploy_history_version_check() to dedupe against records
 * the upgrader_process_complete hook may have written for the same
 * version. Linear scan over at most SNT_DEPLOY_HISTORY_MAX_ROWS rows;
 * no DB query (snt_deploy_history_get reads once).
 *
 * @since 4.1.5
 * @param string $package_key 'plugin' or 'theme'
 * @param string $version     Version string with or without leading 'v'.
 * @return bool
 */
function snt_deploy_history_has_version( $package_key, $version ) {
	if ( ! isset( SNT_DEPLOY_HISTORY_PACKAGES[ $package_key ] ) ) {
		return false;
	}
	$repo       = SNT_DEPLOY_HISTORY_PACKAGES[ $package_key ]['repo'];
	$target_ref = 'v' . ltrim( (string) $version, 'v' );

	foreach ( snt_deploy_history_get() as $row ) {
		$row_repo = isset( $row['repo'] ) ? (string) $row['repo'] : '';
		$row_ref  = isset( $row['ref'] )  ? (string) $row['ref']  : '';
		if ( $row_repo === $repo && $row_ref === $target_ref ) {
			return true;
		}
	}
	return false;
}

/**
 * Merge GHA workflow runs with local wp-admin installs.
 *
 * Order: created_at DESC. Dedupe: if the same (repo, ref) appears in
 * both sources, prefer the GHA record (has html_url for click-through).
 * Wp-admin installs that don't have a GHA companion are kept as-is.
 *
 * @param array $repos Array of GitHub "owner/repo" strings to fetch from GHA.
 * @param int   $limit Total records to return after merge + dedupe.
 * @return array Records in `$runs` shape.
 */
function snt_deploy_history_merged( array $repos, $limit = 5 ) {
	$gha_runs = function_exists( 'snt_gh_recent_runs_merged' )
		? snt_gh_recent_runs_merged( $repos, $limit * 2 )
		: array();

	$local = snt_deploy_history_get();

	// Index GHA runs by (repo, ref) so we can dedupe.
	$seen = array();
	foreach ( $gha_runs as $run ) {
		$key = (string) ( $run['repo'] ?? '' ) . '|' . (string) ( $run['ref'] ?? '' );
		$seen[ $key ] = true;
	}

	$merged = $gha_runs;
	foreach ( $local as $row ) {
		$key = (string) ( $row['repo'] ?? '' ) . '|' . (string) ( $row['ref'] ?? '' );
		if ( isset( $seen[ $key ] ) ) {
			continue; // GHA wins.
		}
		$merged[] = $row;
		$seen[ $key ] = true;
	}

	// Sort by created_at DESC.
	usort( $merged, function( $a, $b ) {
		$ta = strtotime( (string) ( $a['created_at'] ?? '' ) );
		$tb = strtotime( (string) ( $b['created_at'] ?? '' ) );
		if ( $ta === $tb ) {
			return 0;
		}
		return ( $ta < $tb ) ? 1 : -1;
	} );

	if ( count( $merged ) > $limit ) {
		$merged = array_slice( $merged, 0, $limit );
	}
	return $merged;
}

/**
 * Hook handler — records SN package installs into the local history.
 *
 * `upgrader_process_complete` fires AFTER the install completes
 * successfully (a WP_Error from the upgrader prevents the hook).
 * So recording `conclusion=success` unconditionally is safe.
 *
 * Filters to *only* SN packages by matching the upgrader's hook_extra
 * against SNT_DEPLOY_HISTORY_PACKAGES — any other plugin/theme update
 * the user does in wp-admin (jetpack, woocommerce, etc.) is ignored.
 *
 * Versions are read POST-install via `get_plugin_data()` /
 * `wp_get_theme()` so the recorded `ref` reflects what's actually
 * on disk now (vs. what the upgrader claimed to be installing).
 *
 * @param WP_Upgrader $upgrader   The upgrader instance (unused; kept for hook signature).
 * @param array       $hook_extra { action, type, plugins[], themes[], bulk }
 */
function snt_deploy_history_on_upgrader_complete( $upgrader, $hook_extra ) {
	unset( $upgrader );
	if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) ) {
		return;
	}
	$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';
	if ( 'update' !== $action && 'install' !== $action ) {
		return;
	}

	if ( 'plugin' === $hook_extra['type'] && ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
		$target = SNT_DEPLOY_HISTORY_PACKAGES['plugin']['upgrader_id'];
		foreach ( $hook_extra['plugins'] as $plugin_basename ) {
			if ( (string) $plugin_basename !== $target ) {
				continue;
			}
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file = WP_PLUGIN_DIR . '/' . $target;
			if ( ! file_exists( $plugin_file ) ) {
				continue;
			}
			$data    = get_plugin_data( $plugin_file, false, false );
			$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
			if ( '' !== $version ) {
				snt_deploy_history_record( 'plugin', $version );
			}
		}
	}

	if ( 'theme' === $hook_extra['type'] && ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
		$target = SNT_DEPLOY_HISTORY_PACKAGES['theme']['upgrader_id'];
		foreach ( $hook_extra['themes'] as $theme_stylesheet ) {
			if ( (string) $theme_stylesheet !== $target ) {
				continue;
			}
			$theme   = wp_get_theme( $target );
			$version = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
			if ( '' !== $version ) {
				snt_deploy_history_record( 'theme', $version );
			}
		}
	}
}
add_action( 'upgrader_process_complete', 'snt_deploy_history_on_upgrader_complete', 10, 2 );

/**
 * Admin-init version-check — self-heals the self-observation gap.
 *
 * The problem (v4.1.4): `upgrader_process_complete` fires in the SAME
 * PHP request as the install. When v4.1.3 was the on-disk version, its
 * code (which had no handler for this hook) is what's in memory during
 * the v4.1.4 install. So the v4.1.4 install can't be recorded by the
 * hook that v4.1.4 added — the handler doesn't exist in v4.1.3's
 * memory image. The install slips through.
 *
 * The fix (v4.1.5): on every admin page load, compare the in-memory
 * plugin + theme versions against a tiny autoloaded sentinel option.
 * If they differ (or sentinel is missing), check if the version is
 * already recorded — if not, record it now.
 *
 * Performance:
 *   - Sentinel option is autoload=true → loaded once per request into
 *     wp_load_alloptions cache. No DB read on the fast path.
 *   - Version compare is in-memory string equality.
 *   - DB write only on actual version change.
 *
 * Dedupe: the upgrader_process_complete hook (from v4.1.5+) WILL fire
 * correctly on future installs since the handler now exists in v4.1.4+
 * memory. To avoid double-recording the same version once that path
 * works, snt_deploy_history_has_version() scans existing history
 * before writing.
 *
 * Capability check: only fires for users with `manage_options` — those
 * are the only users who see the dashboard anyway, so we don't burn
 * cycles on every subscriber visiting wp-admin.
 *
 * @since 4.1.5
 */
function snt_deploy_history_version_check() {
	static $checked = false;
	if ( $checked ) {
		return;
	}
	$checked = true;

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$current_plugin = defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '';

	$current_theme = '';
	if ( function_exists( 'wp_get_theme' ) ) {
		$theme = wp_get_theme();
		// Only treat the active theme as the SN theme if it actually IS
		// 'signal-and-noise' — protects against attributing a different
		// theme's version to the SN repo if the user has switched themes.
		if ( $theme && method_exists( $theme, 'get_stylesheet' ) && 'signal-and-noise' === $theme->get_stylesheet() ) {
			$current_theme = (string) $theme->get( 'Version' );
		}
	}

	if ( '' === $current_plugin && '' === $current_theme ) {
		return;
	}

	$sentinel = get_option( SNT_DEPLOY_HISTORY_SENTINEL_OPTION, array() );
	if ( ! is_array( $sentinel ) ) {
		$sentinel = array();
	}

	$dirty = false;

	if ( '' !== $current_plugin ) {
		$seen_plugin = isset( $sentinel['plugin'] ) ? (string) $sentinel['plugin'] : '';
		if ( $seen_plugin !== $current_plugin ) {
			// Sentinel says we haven't observed this version yet.
			// Double-check via history scan in case the upgrader hook
			// already recorded it during the install request (relevant
			// for v4.1.5 → v4.1.6 and beyond).
			if ( ! snt_deploy_history_has_version( 'plugin', $current_plugin ) ) {
				snt_deploy_history_record( 'plugin', $current_plugin );
			}
			$sentinel['plugin'] = $current_plugin;
			$dirty = true;
		}
	}

	if ( '' !== $current_theme ) {
		$seen_theme = isset( $sentinel['theme'] ) ? (string) $sentinel['theme'] : '';
		if ( $seen_theme !== $current_theme ) {
			if ( ! snt_deploy_history_has_version( 'theme', $current_theme ) ) {
				snt_deploy_history_record( 'theme', $current_theme );
			}
			$sentinel['theme'] = $current_theme;
			$dirty = true;
		}
	}

	if ( $dirty ) {
		// autoload=true → small footprint, accessed per admin request.
		update_option( SNT_DEPLOY_HISTORY_SENTINEL_OPTION, $sentinel, true );

		// v4.8.1: on a real version change, roll over Breeze's HTML page cache
		// (holds inlined critical CSS) — theme gotcha #28. Listener lives in the
		// theme (template-maintenance.php); template_overrides=false preserves
		// Site Editor DB overrides (matches the dashboard "Purge All Caches").
		//
		// (render hardening FIX 2): that purge chain used to fire
		// SYNCHRONOUSLY, right here, on admin_init — the first admin view after
		// an update paid for the full CDN/Varnish purge inline. This admin_init
		// path now does only the cheap option write above; the purge itself
		// moves to snt_deploy_history_purge_rollover_run(), scheduled as a
		// single deduped event so it runs in cron context instead.
		if ( has_filter( 'sn_purge_all_caches_result' ) && function_exists( 'wp_schedule_single_event' ) ) {
			$already_scheduled = function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( SNT_DEPLOY_HISTORY_PURGE_HOOK );
			if ( ! $already_scheduled ) {
				wp_schedule_single_event( time(), SNT_DEPLOY_HISTORY_PURGE_HOOK );
			}
		}
	}
}
add_action( 'admin_init', 'snt_deploy_history_version_check' );

/**
 * Out-of-band handler for the version-change rollover purge — hooked to
 * SNT_DEPLOY_HISTORY_PURGE_HOOK (render hardening FIX 2). Runs the
 * SAME filter chain snt_deploy_history_version_check() used to fire inline;
 * template_overrides stays false to preserve Site Editor DB overrides
 * (matches the dashboard "Purge All Caches" semantics).
 */
function snt_deploy_history_purge_rollover_run() {
	if ( has_filter( 'sn_purge_all_caches_result' ) ) {
		(int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
	}
}
add_action( SNT_DEPLOY_HISTORY_PURGE_HOOK, 'snt_deploy_history_purge_rollover_run' );

<?php
/**
 * Signal & Noise Tools — WP-native update integration.
 *
 * Hooks into WordPress's standard update system so this plugin appears
 * in wp-admin/update-core.php and wp-admin/plugins.php alongside other
 * plugins. Polls the GitHub Tags API every 12h (cached in a site
 * transient) to compare local version against the latest tagged release.
 *
 * When a new tag is available, WP UI shows "Update Available" — the
 * maintainer clicks Update Now and WP downloads + installs from
 * GitHub's auto-generated tag archive (`/archive/refs/tags/<tag>.zip`).
 *
 * The unpacked archive's top-level directory is `signal-and-noise-tools-<version>/`
 * (GitHub strips the leading 'v' from the tag in the dir name); the
 * `upgrader_source_selection` filter renames it to `signal-and-noise-tools/`
 * so WP installs to the slug that matches SN_GH_PLUGIN_BASENAME.
 *
 * Added in v1.4.0 (2026-05-16). Rewritten in v1.10.1 (2026-05-16):
 * the original implementation intercepted "Update Now" with a
 * WP_Error because the legacy auto-deploy-on-tag-push pipeline would
 * .git-checkout the new tag and overwrite the WP installer's work.
 * v1.10.1 moves to WP-UI-driven updates and disables the tag-push
 * auto-deploy (see .github/workflows/deploy.yml). v1.11.1 fixed the
 * 12h cache that was hiding new tags from the WP updater. v1.11.2
 * adds inc/wp-update-git-preservation.php which backs up + restores
 * the .git directory through the install — closing the footgun where
 * WP's clear_destination() would otherwise destroy the .git checkout
 * that the canonical workflow_dispatch deploy depends on. Both install
 * paths (gh workflow run AND wp-admin Update Now) now coexist safely.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_GH_PLUGIN_OWNER          = 'juanlentino';
const SN_GH_PLUGIN_REPO           = 'signal-and-noise-tools';
const SN_GH_PLUGIN_CACHE_KEY      = 'sn_gh_latest_plugin';
const SN_GH_PLUGIN_CACHE_TTL      = HOUR_IN_SECONDS; // v1.11.1: 12h → 1h
const SN_GH_PLUGIN_BASENAME       = 'signal-and-noise-tools/signal-and-noise-tools.php';
const SN_GH_PLUGIN_SLUG           = 'signal-and-noise-tools';
const SN_GH_PLUGIN_LAST_SEEN_OPT  = 'sn_last_seen_plugin_version';
/**
 * Why the last tag fetch failed, in prose, for the Dashboard card. Lives beside
 * the negative cache and shares its lifetime. See sn_gh_record_fetch_failure().
 *
 * @since 9.54.0
 */
const SN_GH_PLUGIN_ERROR_KEY      = 'sn_gh_latest_plugin_error';
/**
 * Failure-cache TTLs, split by whether re-asking could get a different answer.
 * Transient mirrors github-actions-api.php's SNT_GH_RUNS_FAIL_TTL — the value
 * that rode out the 2026-07-16 GitHub incident without ever going dark.
 *
 * @since 9.54.1
 */
const SN_GH_FAIL_TTL_TRANSIENT    = 5 * MINUTE_IN_SECONDS;
const SN_GH_FAIL_TTL_DURABLE      = HOUR_IN_SECONDS;

// The rest of the module lives in three parts files (house line cap, 2026-10-15),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/wp-update-integration-fetch-parts.php';
require_once __DIR__ . '/wp-update-integration-offer-parts.php';
require_once __DIR__ . '/wp-update-integration-install-parts.php';

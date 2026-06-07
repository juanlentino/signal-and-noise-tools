<?php
/**
 * Signal & Noise Tools — Speculation Rules tuning.
 *
 * WordPress 7.0 core ships native Speculation Rules support (the
 * <script type="speculationrules"> emitter in wp-includes/speculative-loading.php),
 * defaulting to mode=auto / eagerness=auto. This module opts the site into a more
 * aggressive PRERENDER + MODERATE profile — for a brutalist, mostly-static notes
 * site the perceived-instant navigation is a clean win — while letting an admin
 * turn the whole thing off from a checkbox.
 *
 * Two core filters drive the behaviour:
 *   - wp_speculation_rules_configuration   — returning null DISABLES speculative
 *     loading entirely; otherwise we return mode=prerender / eagerness=moderate.
 *   - wp_speculation_rules_href_exclude_paths (10,2) — receives ($paths, $mode);
 *     we APPEND the custom login slug path + /contact/* so those never prerender.
 *     Core already excludes /wp-admin/*, /wp-*.php (covers wp-login.php), and
 *     query-string URLs, so we do NOT re-add those.
 *
 * Opt-in default ON via the `perf.speculative_loading` setting (deep-merged from
 * sn_settings_defaults(), migration-free). Toggle lives on the Tools → Performance
 * sub-tab; saved through sn_handle_perf_save() in inc/admin-post-actions.php.
 *
 * Added in v4.10.0 (2026-06-07, T6).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter the core Speculation Rules configuration.
 *
 * When the `perf.speculative_loading` setting is off, return null to disable the
 * feature outright (core honours a null configuration by emitting no rules).
 * Otherwise return the prerender/moderate profile.
 *
 * @param array|null $config Incoming configuration (array with mode + eagerness, or null).
 * @return array{mode:string,eagerness:string}|null
 */
function sn_speculation_configuration( $config ) {
	if ( ! sn_setting( 'perf.speculative_loading', true ) ) {
		return null;
	}
	return array(
		'mode'      => 'prerender',
		'eagerness' => 'moderate',
	);
}
add_filter( 'wp_speculation_rules_configuration', 'sn_speculation_configuration' );

/**
 * Append SN-owned paths to the Speculation Rules href-exclude list.
 *
 * The custom login slug (so a prerender never silently hits the login form) and
 * /contact/* (a form page — prerendering a form is wasteful and can pre-fire
 * analytics/side effects). Core already excludes /wp-admin/*, /wp-*.php (which
 * covers /wp-login.php), and query-string URLs, so those are intentionally NOT
 * re-added here.
 *
 * @param array  $paths Existing exclude paths (URL-path glob patterns).
 * @param string $mode  Speculation mode ('prefetch' or 'prerender'); unused but
 *                       part of the 2-arg filter signature.
 * @return string[]
 */
function sn_speculation_href_exclude_paths( $paths, $mode ) {
	$paths   = (array) $paths;
	$paths[] = '/' . ltrim( sn_login_get_slug(), '/' ) . '/*';
	$paths[] = '/contact/*';
	return array_values( array_unique( $paths ) );
}
add_filter( 'wp_speculation_rules_href_exclude_paths', 'sn_speculation_href_exclude_paths', 10, 2 );

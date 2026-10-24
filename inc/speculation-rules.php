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
 *     A null core passes in (a logged-in visitor, no pretty permalinks) stays
 *     null (2026-10-24).
 *   - wp_speculation_rules_href_exclude_paths (10,2) — receives ($paths, $mode);
 *     we APPEND /contact/* so it never prerenders (never the login slug: these
 *     rules are printed into every page, so it would publish the hidden door).
 *     Core already excludes /wp-admin/*, /wp-*.php (covers wp-login.php), and
 *     query-string URLs, so we do NOT re-add those.
 *
 * Opt-in default ON via the `perf.speculative_loading` setting (deep-merged from
 * sn_settings_defaults(), migration-free). Toggle lives on the Site → Performance
 * sub-tab; saved through sn_handle_perf_save() in inc/admin-post-actions/monitoring.php.
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
 * A null coming IN is core's own "off" (2026-10-24): core passes null for a
 * logged-in visitor and for a site without pretty permalinks, so a prerender
 * never runs the admin bar's per-request work twice. That stays off; the
 * profile only replaces core's default where core would load at all.
 *
 * @param array|null $config Incoming configuration (array with mode + eagerness, or null).
 * @return array{mode:string,eagerness:string}|null
 */
function sn_speculation_configuration( $config ) {
	if ( null === $config || ! sn_setting( 'perf.speculative_loading', true ) ) {
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
 * /contact/* only: a form page, where a prerender is wasteful and can
 * pre-fire analytics or side effects. Core already excludes /wp-admin/*,
 * /wp-*.php (which covers /wp-login.php) and query-string URLs.
 *
 * The masked login slug is deliberately NOT listed (2026-10-18). These rules
 * are printed into every public page, so listing it published the hidden door
 * on every page; and nothing public links to the door, while speculation
 * rules only act on links on the page, so the exclusion protected nothing.
 *
 * @param array  $paths Existing exclude paths (URL-path glob patterns).
 * @param string $mode  Speculation mode ('prefetch' or 'prerender'); unused but
 *                       part of the 2-arg filter signature.
 * @return string[]
 */
function sn_speculation_href_exclude_paths( $paths, $mode ) {
	$paths   = (array) $paths;
	$paths[] = '/contact/*';
	return array_values( array_unique( $paths ) );
}
add_filter( 'wp_speculation_rules_href_exclude_paths', 'sn_speculation_href_exclude_paths', 10, 2 );

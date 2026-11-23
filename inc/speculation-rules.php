<?php
/**
 * Signal & Noise Tools — Speculation Rules tuning.
 *
 * WordPress core ships native Speculation Rules support since 6.8 (the
 * <script type="speculationrules"> emitter in wp-includes/speculative-loading.php),
 * defaulting to mode=auto / eagerness=auto, which core resolves to prefetch /
 * conservative (since 7.1 a host can set another default with the
 * WP_SPECULATIVE_LOADING_DEFAULT_MODE and _EAGERNESS constants). This module
 * opts the site into a more aggressive PRERENDER + MODERATE profile: for a
 * mostly-static notes site the perceived-instant navigation is a clean win.
 * The switch chooses between that profile and core's own default. It never
 * turns core's speculative loading off (2026-11).
 *
 * Two core filters drive the behaviour:
 *   - wp_speculation_rules_configuration: switch on, we return mode=prerender /
 *     eagerness=moderate; switch off, the incoming configuration passes through
 *     unchanged, so core's own default applies. A null core passes in (a
 *     logged-in visitor, no pretty permalinks) stays null in both states
 *     (2026-10-24).
 *   - wp_speculation_rules_href_exclude_paths (10,2): receives ($paths, $mode);
 *     we APPEND /contact/* so it is never loaded speculatively, in either state
 *     (never the login slug: these rules are printed into every page, so it
 *     would publish the hidden door). Core already excludes /wp-admin/*,
 *     /wp-*.php (covers wp-login.php), and query-string URLs, so we do NOT
 *     re-add those.
 *
 * Default ON via the `perf.speculative_loading` setting (deep-merged from
 * sn_settings_defaults(), migration-free). The switch lives on Settings >
 * Front end; saved through sn_handle_perf_save() in
 * inc/admin-post-actions/monitoring.php. Site > Performance reads which
 * profile is in effect.
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
 * Switch on: the prerender/moderate profile. Switch off: the incoming
 * configuration, unchanged, so core's own default applies (prefetch /
 * conservative unless the host set another). Until 2026-11 off returned
 * null, which switched core's own speculative loading off entirely; a plugin
 * setting complements a core function and never deactivates it.
 *
 * A null coming IN is core's own "off" (2026-10-24): core passes null for a
 * logged-in visitor and for a site without pretty permalinks, so a prerender
 * never runs the admin bar's per-request work twice. That stays off in both
 * states; the profile only replaces core's default where core would load at
 * all.
 *
 * @param array|null $config Incoming configuration (array with mode + eagerness, or null).
 * @return array|null
 */
function sn_speculation_configuration( $config ) {
	if ( null === $config || ! sn_setting( 'perf.speculative_loading', true ) ) {
		return $config;
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
 * /contact/* only: a form page, where a prerender or prefetch is wasteful and
 * can pre-fire analytics or side effects. Appended whichever way the switch
 * is set: with it off, core's default rules carry the exclusion too. Core already excludes /wp-admin/*,
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

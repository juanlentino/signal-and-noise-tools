<?php
/**
 * Signal & Noise: contrast usage scan, the stylesheets it reads (the admin-only denylist, the plugin sheets, the source list).
 *
 * Split from inc/health-contrast-usage.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin stylesheets that are enqueued in wp-admin ONLY.
 *
 * The property that matters is which hook enqueues a sheet, and a FILENAME is
 * only a proxy for it. The proxy leaked, and the live report is what caught it:
 * `uptime-status.css` is admin-only and is not named "admin", so two of the
 * three pairings the contrast report flagged were wp-admin status colours
 * (#00a32a at 3.35:1, #dba617 at 2.22:1) scored against a public page they
 * never appear on. Same shape as the `@media print` case — a context the reader
 * never meets, measured as if they did.
 *
 * This list is hand-kept, so it is NOT the guard. The guard is in
 * tests/health-contrast-usage.php, which derives the admin-enqueued set from
 * the plugin's own source (`add_action( 'admin_enqueue_scripts' …)` with no
 * `wp_enqueue_scripts` alongside) and asserts it EQUALS what this function
 * excludes. A new admin sheet reds that test instead of quietly seeding false
 * positives into a report-only check nobody re-reads.
 *
 * Deliberately a DENYLIST, not an allowlist. A missed admin sheet is noise; a
 * missed FRONT-END sheet silently shrinks coverage and hides real defects, and
 * this module exists because a scan that measures the wrong thing reports a
 * clean site. Wrong-direction failures are the expensive ones.
 *
 * @return string[] Basenames.
 */
function sn_health_contrast_usage_admin_sheets() {
	return array(
		'admin.css',
		'audit-log.css',
		// v11.30.2: the index.php dashboard widget's sheet. Admin-only and not
		// named "admin", so it needs declaring here like uptime-status.css.
		'dash-widget.css',
		// v13.30.0: the four subject boxes beside it, same screen, same reason.
		'dash-widgets.css',
		'machine-readers.css',
		'provenance-admin.css',
		'uptime-status.css',
		// v13.95.3: assets/analytics/. This was never in this list because the
		// source glob never reached it - it sat in a subdirectory, so nothing
		// had to decide about it. Walking the tree made it visible and the
		// drift test immediately red: it is enqueued on admin_enqueue_scripts
		// only (inc/admin-menu.php, inc/openstation-host-assets.php), same case as
		// dash-widget.css. analytics-admin.css is caught by the substring rule.
		'analytics-tokens.css',
		// v13.106.0: the native-window kit sheet. Registered on
		// admin_enqueue_scripts only (inc/openstation-host-assets.php), same
		// case as uptime-status.css — it never paints a public page.
		'os-app.css',
		// 2026-10-12: the shell confirm dialog's ink, enqueued with the
		// desktop commands on admin_enqueue_scripts only.
		'snt-confirm-ink.css',
		// 2026-10-12: the link color derived from the accent, same enqueue.
		'snt-link.css',
	);
}

/**
 * Every .css file under the plugin's assets/, at any depth.
 *
 * Returns paths RELATIVE to assets/ as keys so callers can label a sheet
 * unambiguously; a top-level sheet's relative path is just its basename.
 *
 * @return array<string,string> relative path => absolute path.
 */
function sn_health_contrast_usage_plugin_sheets() {
	$base = rtrim( SNT_PATH, '/' ) . '/assets';
	if ( ! is_dir( $base ) ) {
		return array();
	}

	$found = array();
	$walk  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $walk as $file ) {
		if ( ! $file->isFile() || 'css' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$abs = (string) $file->getPathname();
		$found[ ltrim( substr( $abs, strlen( $base ) ), '/' ) ] = $abs;
	}
	ksort( $found ); // stable order: the readout must not reshuffle between runs.

	return $found;
}

/**
 * Stylesheets to scan: this plugin's FRONT-END css plus the active theme's.
 *
 * Admin sheets are excluded on purpose — wp-admin supplies its own palette and
 * its own background, so scoring admin rules against theme tokens would invent
 * failures that no reader can meet.
 *
 * @return array<string,string> label => absolute path.
 */
function sn_health_contrast_usage_sources() {
	$sources = array();
	$admin   = sn_health_contrast_usage_admin_sheets();

	// Depth-agnostic on purpose. This was `glob( 'assets/*.css' )` until
	// v13.95.3, which enumerated only the TOP of assets/ and silently omitted
	// assets/css/prov-verify.css - a sheet served on the public verify route.
	// The docblock above already said a missed front-end sheet is the expensive
	// direction; the population just stopped matching the rule when someone made
	// a subdirectory. A pattern that has to be re-widened by hand every time the
	// tree grows is the bug, so the tree is walked instead of matched.
	foreach ( sn_health_contrast_usage_plugin_sheets() as $rel => $file ) {
		$base = basename( $rel );
		// The substring rule is kept as a belt-and-braces catch for an
		// obviously-named sheet added without updating the list above; the list
		// is what makes the exclusion correct.
		if ( in_array( $base, $admin, true ) || false !== strpos( $base, 'admin' ) ) {
			continue;
		}
		// Keyed by the path RELATIVE to assets/, not the basename: two packages
		// may each hold a `style.css`, and a basename key would let the second
		// silently replace the first - losing a sheet the same way the glob did.
		// For a top-level sheet the relative path IS the basename, so every
		// pre-existing label is unchanged.
		$sources[ 'plugin/' . $rel ] = $file;
	}

	if ( function_exists( 'get_stylesheet_directory' ) ) {
		$theme_root = (string) get_stylesheet_directory();
		foreach ( array( '/assets/css/*.css', '/blocks/*/style.css', '/style.css' ) as $pattern ) {
			foreach ( (array) glob( $theme_root . $pattern ) as $file ) {
				$sources[ 'theme/' . basename( dirname( (string) $file ) ) . '/' . basename( (string) $file ) ] = (string) $file;
			}
		}
	}

	return $sources;
}


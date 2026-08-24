<?php
/**
 * Signal & Noise Tools — which plugin is serving WordPress's own JS packages.
 *
 * WordPress registers `wp-components`, `wp-element`, `wp-block-editor` and the
 * rest of the `wp-*` script handles from `wp-includes/js/dist/`. A plugin may
 * RE-REGISTER those same handles against its own build — the Gutenberg plugin
 * does exactly that, for every one of them. That is a supported, deliberate
 * mechanism, and it is invisible: nothing in wp-admin says the packages your
 * screens are running are not core's.
 *
 * WHY THIS EXISTS, measured 2026-08-23. The Gutenberg plugin was installed.
 * `Settings → AI` (the `ai` plugin's screen) went blank with a minified React
 * error #130 — element type undefined — in the OpenStation shell AND in Classic
 * Admin. Cause: Gutenberg PRs 81391/81433/81434/81435 removed
 * `ValidatedSelectControl`, `ValidatedNumberControl`, `ValidatedRadioControl`
 * and `ValidatedCheckboxControl` from `@wordpress/components`' private APIs
 * (they moved into `@wordpress/dataviews`). Core still ships all four. The `ai`
 * plugin bundles a pre-move dataviews that destructures one of them at module
 * scope, so it evaluated to `undefined` and all 21 select fields on that screen
 * rendered `<undefined/>`.
 *
 * Diagnosing that took a browser session, a patched JSX runtime and a diff of
 * two minified bundles — because the question "who is serving wp.components?"
 * had no answer anywhere on the site. It does now: one field in
 * Site Health → Info, computed from the live script registry.
 *
 * SCOPE, deliberately narrow. This REPORTS an override; it does not judge one.
 * Running Gutenberg is a legitimate choice, and an override is only a hazard
 * when a third party reaches for a private export. So this is not a health
 * check and produces no finding — it is a fact on the diagnostic surface, where
 * the next person asking the question will already be looking.
 *
 * TIMING. It reads the registry as it stands during the request that renders
 * the field. Core registers its defaults on `init`, as does Gutenberg, and the
 * `debug_information` filter runs at render time — well after both.
 *
 * @package SignalNoiseTools
 * @since 12.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribute one registered script `src` to whatever ships it.
 *
 * Returns `core` for `wp-includes/`, `alias` for a handle registered without a
 * src (a dependency-only alias, which serves no code and cannot override
 * anything), `plugin:<folder>` / `theme:<folder>` / `mu-plugin:<folder>`, or
 * `unknown` for a src outside both trees — a CDN rewrite, most likely.
 *
 * The content root is derived from `content_url()` rather than assumed to be
 * `/wp-content`, so a site with a renamed content directory still resolves.
 *
 * @since 12.25.0
 * @param string|false $src Registered src, as WP_Scripts holds it.
 * @return string Origin token.
 */
function snt_script_package_origin_for_src( $src ) {
	if ( empty( $src ) || ! is_string( $src ) ) {
		return 'alias';
	}

	$path = (string) wp_parse_url( $src, PHP_URL_PATH );
	if ( '' === $path ) {
		$path = $src;
	}

	if ( false !== strpos( $path, '/wp-includes/' ) ) {
		return 'core';
	}

	// The content tree is checked BEFORE /wp-admin/ below, deliberately: a
	// plugin is free to ship its own `wp-admin` directory, and
	// `/wp-content/plugins/evil/wp-admin/js/x.js` must be attributed to the
	// plugin rather than laundered into 'core' by a substring match. Order is
	// the guard; a test pins it.
	$content = (string) wp_parse_url( content_url(), PHP_URL_PATH );
	if ( '' === $content || false === strpos( $path, $content . '/' ) ) {
		// Core does not ship every wp-* handle from wp-includes: the editor,
		// the colour picker and friends are served from /wp-admin/js/. Treating
		// only wp-includes as core made a HEALTHY site report
		// "unknown — 2 handles", which is a diagnostic surface crying wolf.
		// Found by the field itself, an hour after it shipped.
		return ( false !== strpos( $path, '/wp-admin/' ) ) ? 'core' : 'unknown';
	}

	$rest = substr( $path, strpos( $path, $content . '/' ) + strlen( $content ) );
	if ( ! preg_match( '#^/(plugins|themes|mu-plugins)/([^/]+)/#', $rest, $m ) ) {
		return 'unknown';
	}

	$kind = array(
		'plugins'    => 'plugin',
		'themes'     => 'theme',
		'mu-plugins' => 'mu-plugin',
	);

	return $kind[ $m[1] ] . ':' . $m[2];
}

/**
 * Every core `wp-*` script handle NOT being served by core, grouped by owner.
 *
 * Handles are sorted inside each group and the groups are sorted by key, so the
 * readout is byte-stable between requests — a field that reorders itself reads
 * as a change when nothing changed.
 *
 * @since 12.25.0
 * @return array<string, string[]> `origin token => handles`, empty when clean.
 */
function snt_script_package_overrides() {
	if ( ! function_exists( 'wp_scripts' ) ) {
		return array();
	}

	$scripts = wp_scripts();
	if ( ! $scripts || empty( $scripts->registered ) ) {
		return array();
	}

	$out = array();
	foreach ( $scripts->registered as $handle => $dep ) {
		$handle = (string) $handle;
		if ( 0 !== strpos( $handle, 'wp-' ) ) {
			continue;
		}

		$origin = snt_script_package_origin_for_src( isset( $dep->src ) ? $dep->src : '' );
		if ( 'core' === $origin || 'alias' === $origin ) {
			continue;
		}

		$out[ $origin ][] = $handle;
	}

	foreach ( $out as $origin => $handles ) {
		sort( $handles );
		$out[ $origin ] = $handles;
	}
	ksort( $out );

	return $out;
}

/**
 * One line for Site Health → Info.
 *
 * Names the owner, the count, and the first few handles — `wp-components`
 * sorts early, which is the one that matters, since it is the package whose
 * private APIs third parties reach into.
 *
 * @since 12.25.0
 * @param int $max Handles to name per owner before overflowing to a count.
 * @return string
 */
function snt_script_package_override_summary( $max = 3 ) {
	$overrides = snt_script_package_overrides();
	if ( ! $overrides ) {
		return __( 'core — no plugin overrides WordPress JS packages', 'signal-and-noise-tools' );
	}

	$parts = array();
	foreach ( $overrides as $origin => $handles ) {
		$total = count( $handles );
		$shown = array_slice( $handles, 0, max( 1, (int) $max ) );
		$more  = $total - count( $shown );

		$parts[] = sprintf(
			/* translators: 1: owner token e.g. plugin:gutenberg, 2: handle count, 3: comma-separated handles. */
			__( '%1$s — %2$d handles (%3$s)', 'signal-and-noise-tools' ),
			$origin,
			$total,
			implode( ', ', $shown ) . ( $more > 0 ? sprintf( ', +%d more', $more ) : '' )
		);
	}

	return implode( ' | ', $parts );
}

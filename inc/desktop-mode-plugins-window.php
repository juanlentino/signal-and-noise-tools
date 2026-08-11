<?php
/**
 * Signal & Noise Tools — fixes for the shell's own Plugins window.
 *
 * The shell ships a custom Plugins window (REST-fed) that consults neither
 * plugins_api nor the update_plugins transient, so the v2.1.2 brand-asset work
 * never reached it. Two surgical filters land our icon and decode our Name on
 * the wire.
 *
 * Also carries the tombstone for the removed inline DOM patch — kept because
 * it documents WHY a document-scoped querySelector can never work here (open
 * shadow root), not as future work.
 *
 * Split out of inc/desktop-mode-integration.php in v10.87.2; the code is
 * unchanged. That file is now the loader and still carries the architectural
 * notes covering all seven modules — read it first.
 *
 * @package SignalNoiseTools
 * @since 2.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ════════════════════════════════════════════════════════════════════════
 * v2.1.3 — Desktop Mode Plugins-window fixes
 *
 * The v2.1.2 brand-assets work (icons + banners via plugins_api + the
 * update_plugins transient) only covers WP core surfaces. Desktop Mode
 * ships its own custom Plugins window (a REST-fed TypeScript frontend
 * under includes/plugins-window/* + src/plugins-window/*) that does NOT
 * consult either of those data sources. Two surgical filters land the
 * icon + decode the plugin name for that surface only.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Provide our plugin's icon to Desktop Mode's custom Plugins window.
 *
 * Desktop Mode derives the icon URL by hardcoding
 *   https://ps.w.org/{dirname(plugin_file)}/assets/icon.svg
 * at includes/plugins-window/rest-fields.php:404-445 — works for the
 * wordpress.org plugin directory, 404s for self-hosted plugins like ours.
 * The update_plugins site_transient icons array (which we populate in
 * inc/wp-update-integration.php) is never read by this code path.
 *
 * The 'desktop_mode_plugins_window_icon_url' filter is exposed at the
 * same line; we return our own SVG so the icon column on Desktop Mode's
 * Plugins panel renders correctly. SVG renders crisp at any DPR — no
 * separate PNG fallback needed.
 *
 * Note on the JS fallback chain: src/plugins-window/icon-fallback.ts
 * only walks SVG → 256.png → 128.png when the URL matches the
 * ps.w.org/<slug>/assets/icon.svg shape. Custom URLs get one shot and
 * then resolve to the dashicons-admin-plugins placeholder. Our SVG is
 * served from the same WP origin as the admin UI, so CSP + mixed-content
 * checks pass and the single shot is enough.
 *
 * Verified against WordPress/desktop-mode
 * (includes/plugins-window/rest-fields.php trunk @ 2026-05-18).
 * Post-#475 OpenStation renames this to `openstation_plugins_window_icon_url`
 * (includes/plugins-window/rest-fields.php:465) — dual-registered via
 * snt_os_compat_add_filter(), idempotent (returns the same canonical URL for
 * the same $slug every call), no double-fire guard needed.
 */
snt_os_compat_add_filter( 'desktop_mode_plugins_window_icon_url', 'openstation_plugins_window_icon_url', function( $url, $slug ) {
	if ( defined( 'SN_GH_PLUGIN_SLUG' ) && SN_GH_PLUGIN_SLUG === $slug ) {
		return plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	}
	return $url;
}, 10, 2 );

/**
 * Decode HTML entities in our plugin's Name on the REST response.
 *
 * Desktop Mode's installed Plugins view calls Core's REST endpoint
 *   GET /wp/v2/plugins?context=view
 * which runs WP_REST_Plugins_Controller::prepare_item_for_response()
 * (wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php
 * lines 578-620). That method calls _get_plugin_data_markup_translate()
 * which unconditionally `wp_kses`'s the Name header (plugin.php line 188)
 * even when called with $markup=false — so the JSON response always
 * carries the entity-encoded form `"name": "Signal &amp; Noise Tools"`.
 *
 * Desktop Mode's frontend then sets `title.textContent = row.name`
 * (src/plugins-window/installed-view.ts + installed-detail.ts), and
 * textContent renders entities literally. The Browse view at card.ts
 * decodes via decodeEntities() — Installed/Detail views forgot to.
 *
 * v2.1.3 attempted this fix via the `all_plugins` filter — wrong layer:
 * `all_plugins` only fires from wp-admin/plugins.php's UI layer, NOT
 * from the REST controller. The REST controller is the ONLY data path
 * Desktop Mode uses for the Installed view.
 *
 * Correct layer: `rest_prepare_plugin` at line 619 of the REST
 * controller, the last writable layer before JSON serialization.
 * Scoped strictly to SN_GH_PLUGIN_BASENAME ($item['_file']) so other
 * plugins' Name strings are never touched.
 *
 * Verified against WordPress/WordPress @ tag 6.9.4:
 *   wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php
 *   lines 578-620 + wp-admin/includes/plugin.php line 188.
 *
 * @since 2.1.6 (supersedes the all_plugins approach from v2.1.3)
 */
add_filter( 'rest_prepare_plugin', function( $response, $item, $request ) {
	if ( ! defined( 'SN_GH_PLUGIN_BASENAME' ) ) {
		return $response;
	}
	if ( ! is_array( $item ) || empty( $item['_file'] ) || SN_GH_PLUGIN_BASENAME !== $item['_file'] ) {
		return $response;
	}

	$data = $response->get_data();
	$dirty = false;

	// Decode the Name field — primary fix.
	if ( isset( $data['name'] ) && false !== strpos( $data['name'], '&amp;' ) ) {
		$data['name'] = html_entity_decode( $data['name'], ENT_QUOTES, 'UTF-8' );
		$dirty = true;
	}

	// Author field also runs through wp_kses in the same function.
	if ( isset( $data['author'] ) && false !== strpos( $data['author'], '&amp;' ) ) {
		$data['author'] = html_entity_decode( $data['author'], ENT_QUOTES, 'UTF-8' );
		$dirty = true;
	}

	// Icon URL — ALWAYS override, never just-when-empty (v2.1.7 fix).
	// Desktop Mode's get_callback may have already populated
	// desktop_mode_icon_url with the ps.w.org URL that 404s for self-
	// hosted plugins; in that case the field is non-empty but wrong, and
	// an `if ( empty(...) )` guard lets it pass through. Self-hosted
	// plugins know their own canonical icon URL — overwrite
	// unconditionally for our basename. Safe scope: gated on
	// $item['_file'] === SN_GH_PLUGIN_BASENAME at the top of this filter.
	//
	// v10.43.0 REJECT #11 LOW: dual-write BOTH REST field keys. Post-#475
	// OpenStation renames the field ITSELF from 'desktop_mode_icon_url' to
	// 'openstation_icon_url' (rest-fields.php) — a different seam from the
	// 'desktop_mode_plugins_window_icon_url' FILTER dual-registered above,
	// which supplies the field's VALUE via get_callback but cannot rename
	// the JSON KEY the response actually carries. Writing only the old key
	// left this belt's "ALWAYS override" promise doing nothing on a
	// post-#475 response, which carries the new key instead. Exactly one
	// key is ever present per install; writing both is a no-op for the
	// absent one.
	$canonical_icon_url = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	if ( ! isset( $data['desktop_mode_icon_url'] ) || $data['desktop_mode_icon_url'] !== $canonical_icon_url ) {
		$data['desktop_mode_icon_url'] = $canonical_icon_url;
		$dirty = true;
	}
	if ( ! isset( $data['openstation_icon_url'] ) || $data['openstation_icon_url'] !== $canonical_icon_url ) {
		$data['openstation_icon_url'] = $canonical_icon_url;
		$dirty = true;
	}

	if ( $dirty ) {
		$response->set_data( $data );
	}
	return $response;
}, 10, 3 );

/**
 * Inline DOM patch — REMOVED. Both halves proved unreachable behind an
 * OPEN SHADOW ROOT; kept as history, not as a "future work" TODO.
 *
 * v2.1.7 shipped an `admin_print_footer_scripts` script that ran a
 * `document.body`-scoped `querySelectorAll()` (+ a `document.body`-scoped
 * `MutationObserver`) to (1) hide a dead "View on WordPress.org" button and
 * (2) defensively re-decode the plugin Name if it ever resurfaced HTML-
 * entity-encoded in the DOM. v10.43.0 (f2faa4b) "fixed" half (1)'s selector
 * from a dead `a[href*="wordpress.org…"]` to `wpd-button, os-button` — still
 * dead, and adversarial review (REJECT #12) proved why: the Installed-view
 * detail panel — button, Name cell, everything — is appended into an OPEN
 * shadow root (`attachShadow({mode:'open'})`, WordPress/openstation
 * `src/ui/core/component.ts:88`) by `wpd-table.ts:1404-1433`. Upstream's own
 * `installed-detail.ts:63-69` documents that document-level DOM access does
 * not pierce this boundary. A query rooted at `document.body` cannot
 * traverse into shadow content, full stop — the tag name it queried for was
 * never the bug. And a `MutationObserver` attached to `document.body` never
 * observes mutations that happen INSIDE a shadow root either, so replacing
 * the selector could never have worked no matter how it was spelled.
 *
 * Half (2), the Name-decode, targeted the exact same table — same shadow
 * root, same unreachability — so it was never the working fix either. The
 * WIRE-level decode, `rest_prepare_plugin` above, always was: it edits the
 * REST payload before Desktop Mode ever renders it, so there is nothing left
 * for a DOM patch to defend. With half (1) gone, `patch()` had no reachable
 * target left in this codebase — nothing else called it — so it is removed
 * wholesale along with the `MutationObserver`, rather than kept running
 * against text nodes it can never see.
 *
 * Reaching either target FOR REAL would need: a shadow-root hop at every
 * custom-element boundary the panel nests through (`el.shadowRoot &&
 * el.shadowRoot.querySelectorAll(...)`, recursively — there is no "pierce
 * all shadow roots" selector); a `MutationObserver` attached to EACH shadow
 * root individually, since one at `document.body` cannot see across the
 * boundary; and re-scoping the button's label match to ONLY this plugin's
 * own detail panel — `installed-detail.ts:333` renders the identical
 * "WordPress.org" label for every wp.org-hosted plugin's legitimate link, so
 * a bare label match, if it could somehow reach in, would hide those too.
 * None of that scaffolding exists today. Building it is future work, not
 * this fix.
 *
 * The 404 itself is accepted as cosmetic: it is upstream's own fallback
 * behavior for a self-hosted, non-wp.org-listed plugin (see the
 * `rest_prepare_plugin` filter's docblock above for why our own icon-url
 * override defeats upstream's empty-icon guard), reachable only from inside
 * this plugin's own detail panel, and no document-scoped patch — this one or
 * any future one — was ever going to hide it without the shadow-root
 * scaffolding described above. Filed upstream rather than left as folklore:
 * WordPress/openstation#492 tracks the missing-slug guard fix; if upstream
 * ships it, the 404 (and this whole accepted-cosmetic note) disappears on
 * its own, no plugin-side work required.
 *
 * @since 2.1.6 wp_enqueue_script approach.
 * @since 2.1.7 superseded by the inline admin_footer version (this comment).
 * @since 10.43.0 (f2faa4b) button-hider selector "fixed" — still dead.
 * @since 10.43.1 removed. REJECT #12: both halves unreachable behind an open
 *                shadow root; see this docblock and CHANGELOG.md.
 */

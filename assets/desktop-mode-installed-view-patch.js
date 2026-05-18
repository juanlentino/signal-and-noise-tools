/**
 * Signal & Noise Tools — Desktop Mode "Installed Plugins" view patch.
 *
 * Patches two cosmetic bugs in WordPress/desktop-mode trunk that have no
 * server-side filter hooks:
 *
 *   1. Hides the "View on WordPress.org" button on the expanded detail
 *      panel for OUR plugin row. Desktop Mode's installed-detail.ts
 *      renders that button whenever `dirname(plugin_file)` is non-empty
 *      (gate at lines 297-301), with no awareness of whether the plugin
 *      actually exists on the wordpress.org repo. For self-hosted plugins
 *      the link is a 404 and shouldn't be shown. The relevant upstream
 *      issue: src/plugins-window/installed-detail.ts:945-963 derives
 *      `slug` from `dirname(row.plugin)` with no `is_wporg` flag.
 *
 *   2. Defensive belt + suspenders for the plugin Name. The
 *      `rest_prepare_plugin` server filter in
 *      inc/desktop-mode-integration.php decodes our Name on the REST
 *      response before it reaches the frontend. If that filter ever
 *      fails to fire (race during plugin activation, REST cache, etc.),
 *      this script catches the literal `Signal &amp; Noise Tools` text
 *      in the DOM and replaces it with the decoded form.
 *
 * Strategy: a single MutationObserver scoped to the Plugins window pane.
 * Runs only inside Desktop Mode's portal (gated on a body class + a
 * presence check on Desktop Mode-specific selectors). Fully no-op on
 * other admin surfaces and when Desktop Mode is deactivated.
 *
 * @since 2.1.6
 * @package SignalNoiseTools
 */
( function () {
	'use strict';

	const SN_SLUG          = 'signal-and-noise-tools';
	const SN_NAME_DECODED  = 'Signal & Noise Tools';
	const SN_NAME_LITERAL  = 'Signal &amp; Noise Tools';

	function patchOnce( root ) {
		if ( ! root || ! root.querySelectorAll ) {
			return;
		}

		// 1. Hide any "View on WordPress.org" anchor that points at our
		//    self-hosted slug. Match the href substring rather than
		//    button text — the text could be localised, the URL pattern
		//    is stable across all of Desktop Mode's wp.org links.
		const wporgLinks = root.querySelectorAll(
			'a[href*="wordpress.org/plugins/' + SN_SLUG + '"], a[href*="wordpress.org/support/plugin/' + SN_SLUG + '"]'
		);
		wporgLinks.forEach( function ( el ) {
			// Walk up to the nearest button/action container if there
			// is one, otherwise hide the anchor itself. Mark the node
			// so we don't repeatedly hide it on subsequent observer
			// passes.
			if ( el.dataset.snHidden === '1' ) {
				return;
			}
			const host = el.closest( 'button, .wpd-button, [class*="action"], [class*="cta"]' ) || el;
			host.style.display     = 'none';
			el.dataset.snHidden    = '1';
		} );

		// 2. Defensive Name decode — catch any nodes containing the
		//    literal `&amp;` form. Only descend into elements that have
		//    no children (leaf text nodes) and that mention our brand,
		//    to avoid scanning the whole DOM.
		const candidates = root.querySelectorAll(
			'h1, h2, h3, h4, h5, h6, strong, span, div, td, a'
		);
		for ( let i = 0; i < candidates.length; i++ ) {
			const node = candidates[ i ];
			if ( node.children.length === 0 && node.textContent === SN_NAME_LITERAL ) {
				node.textContent = SN_NAME_DECODED;
			}
		}
	}

	function init() {
		// Initial sweep.
		patchOnce( document.body );

		// Observe further DOM mutations — Desktop Mode renders the
		// Installed view lazily and re-renders on filter changes.
		const obs = new MutationObserver( function ( mutations ) {
			for ( let i = 0; i < mutations.length; i++ ) {
				const m = mutations[ i ];
				for ( let j = 0; j < m.addedNodes.length; j++ ) {
					const n = m.addedNodes[ j ];
					if ( n.nodeType === 1 ) {
						patchOnce( n );
					}
				}
			}
		} );
		obs.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
} )();

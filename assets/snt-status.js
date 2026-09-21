/**
 * Signal & Noise Tools — shared status-text utility.
 *
 * Exposes window.sntSetStatus(node, text, kind) — sets a status span's
 * textContent and color based on a semantic kind. Replaces 4 byte-identical
 * copies that lived in:
 *   - assets/ai-meta-description.js
 *   - assets/ai-excerpt.js
 *   - assets/ai-og-card-title.js
 *   - assets/health-suggest-actions.js
 *
 * Kind → color map. Two maps, picked by surface (#1616): inside a kit app
 * (`.snt-app`, the S&N Home window on the station's dark surface) the
 * shell's `--os-ui-*` tokens, the only colours the windows use; anywhere
 * else (the classic Health tab, the chromeless iframe, which paints white
 * and loads no admin.css override, and the block editor sidebars) the
 * light-admin hex the palette was chosen on:
 *   ok    → --os-ui-success-fg | #0a5a1a (green; success state)
 *   warn  → --os-ui-warning-fg | #6e4d00 (amber; advisory state)
 *   err   → --os-ui-danger     | #8b1a1a (red; error state)
 *   info  → --os-ui-fg-muted   | #646970 (muted gray; default)
 *
 * Loaded via wp_register_script + wp_enqueue_script alongside any of the
 * 4 caller scripts; those scripts list 'snt-status' in their deps array
 * so WP chains the load order. Single source of truth — palette changes
 * land here once.
 *
 * Audit reference: U-15 (v4.1.6).
 *
 * @since plugin v4.1.6
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	/**
	 * Set status text + semantic color on a span.
	 *
	 * @param {Element} node  Target element (must exist; idempotent no-op if null).
	 * @param {string}  text  Plain-text status to display.
	 * @param {'ok'|'warn'|'err'|'info'} kind  Semantic state.
	 */
	var KIT_INK = {
		ok: 'var(--os-ui-success-fg)',
		warn: 'var(--os-ui-warning-fg)',
		err: 'var(--os-ui-danger)',
		info: 'var(--os-ui-fg-muted)'
	};
	var CLASSIC_INK = {
		ok: '#0a5a1a',
		warn: '#6e4d00',
		err: '#8b1a1a',
		info: '#646970'
	};

	function sntSetStatus( node, text, kind ) {
		if ( ! node ) { return; }
		node.textContent = text;
		// The same surface test health-suggest-actions.js uses to pick
		// os-button and os-modal: a node inside a kit app takes the tokens.
		var ink = node.closest && node.closest( '.snt-app' ) ? KIT_INK : CLASSIC_INK;
		node.style.color = ink[ kind ] || ink.info;
	}

	window.sntSetStatus = sntSetStatus;
} )();

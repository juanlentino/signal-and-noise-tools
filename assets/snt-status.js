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
 * Kind → color map (matches the existing palette across all 4 prior copies):
 *   ok    → #0a5a1a (green; success state)
 *   warn  → #6e4d00 (amber; advisory state)
 *   err   → #8b1a1a (red; error state)
 *   info  → #646970 (muted gray; default)
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
	function sntSetStatus( node, text, kind ) {
		if ( ! node ) { return; }
		node.textContent = text;
		switch ( kind ) {
			case 'ok':   node.style.color = '#0a5a1a'; break;
			case 'warn': node.style.color = '#6e4d00'; break;
			case 'err':  node.style.color = '#8b1a1a'; break;
			default:     node.style.color = '#646970';
		}
	}

	window.sntSetStatus = sntSetStatus;
} )();

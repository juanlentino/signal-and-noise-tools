<?php
/**
 * Signal & Noise Tools: admin-bar.php, the item map, the destructive gate
 * and the contextual post.
 *
 * Split from inc/admin-bar.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map of admin-bar item IDs → AJAX action name + visible label.
 * Single source of truth — used by the menu builder, the AJAX handler
 * registration, and the JS that wires click handlers.
 *
 * Labels lead with a Unicode glyph as a visual cue; this keeps the
 * menu scannable without depending on Dashicons (which load lazily
 * and can cause layout shift on first paint).
 */
function sn_admin_bar_items() {
	return array(
		'sn-quick-force-update-check' => array(
			'action'  => 'sn_quick_force_update_check',
			'label'   => '↺ Force Update Check',
			// `ability` + `input` are the shell twins (see the file docblock):
			// the same impl the handler calls, reached over the run path.
			// `done` is the toast when the output carries no message.
			'ability' => 'get-deploy-status',
			'input'   => array( 'force_refresh' => true ),
			'done'    => 'Update check forced: see Dashboard › Updates.',
		),
		'sn-quick-scan-patterns' => array(
			'action'  => 'sn_quick_scan_patterns',
			'label'   => '⌕ Scan Pattern Adoption',
			'ability' => 'pattern-adoption-scan',
			'done'    => 'Pattern scan complete. %d candidate(s).',
		),
		'sn-quick-clear-overrides' => array(
			'action'  => 'sn_quick_clear_overrides',
			'label'   => '⌫ Clear DB Overrides',
			'ability' => 'clear-template-overrides',
			// DESTRUCTIVE + CONTEXTUAL — force-deletes every wp_template /
			// wp_template_part / wp_navigation with no trash and no undo. Hidden
			// in the Site Editor, which WP 7.1 newly shows the toolbar in and
			// which owns exactly those records. See the guard's docblock.
			'guard'  => 'sn_admin_bar_destructive_allowed',
			// Blocking confirmation before the request fires. The only item that
			// carries one, deliberately: a confirm on every action trains the
			// reader to dismiss it unread, which costs the one place it matters.
			// Plain English, untranslated, matching every other string in this
			// file (this module has no i18n calls at all).
			'confirm' => "Clear DB Overrides\n\n"
				. "This permanently deletes EVERY template, template part and navigation "
				. "menu stored in the database — everything saved in the Site Editor.\n\n"
				. "The records are force-deleted, not moved to Trash. This cannot be undone.\n\n"
				. 'Continue?',
		),
		'sn-quick-regen-og-card' => array(
			'action'  => 'sn_quick_regen_og_card',
			'label'   => '⟳ Regen OG Card',
			'ability' => 'regenerate-og-card',
			// CONTEXTUAL — only shown when a single post is in context
			// (admin post-edit screen or front-end singular). The render
			// guard resolves the post ID; the item carries it to the JS so
			// the AJAX request knows which post to act on.
			'guard'  => 'sn_admin_bar_contextual_post_id',
		),
);
}

/**
 * May the DESTRUCTIVE quick action render on the current screen?
 *
 * WordPress 7.1 makes the toolbar persistent in the Post Editor (including
 * fullscreen, where it was previously hidden) and in the **Site Editor**, which
 * never showed it at all. See the 7.1 dev note "Consistent navigation in
 * WordPress 7.1 with persistent toolbar" (2026-07-13), whose own recommendation
 * for a node that does not belong in an editor is to filter it out there.
 *
 * "Clear DB Overrides" is that node. It dispatches
 * `sn_clear_template_overrides_result`, whose theme-side implementation
 * (signal-and-noise, inc/template-maintenance.php sn_clear_template_overrides)
 * runs `wp_delete_post( $id, true )` — FORCE delete, no trash, no undo — over
 * every `wp_template`, `wp_template_part` and `wp_navigation` in the database.
 * That is precisely the set the Site Editor writes. Before 7.1 the button could
 * not appear in the room it empties; from 7.1 it renders there, one click from
 * the canvas, with no confirmation step.
 *
 * The hazard is not news to this codebase — three separate automatic purge
 * paths in template-maintenance.php pass `template_overrides => false` with a
 * comment saying an update "must never nuke Site Editor edits as a side
 * effect". Those are careful because they fire unattended. This button IS the
 * deliberate nuke; the only thing that ever kept it away from the Site Editor
 * was the toolbar's absence, and 7.1 removed that.
 *
 * Scope is deliberately the Site Editor alone. The Post Editor already showed
 * the bar whenever fullscreen was off, so hiding the item there would take away
 * availability that users have today for a hazard that is not new — and a
 * template override is not the thing a post author is editing. Front end and
 * every other admin screen are unchanged.
 *
 * Fails CLOSED in admin when the screen cannot be resolved: an unhidden
 * force-delete is a worse outcome than a temporarily missing menu row, and the
 * action stays reachable from the Dashboard either way. Hiding the row never
 * gates the action itself — sn_handle_quick_clear_overrides() keeps its own
 * nonce + `manage_options` checks, which are the actual authorization.
 *
 * @return bool True when the destructive item may render.
 */
function sn_admin_bar_destructive_allowed() {
	if ( ! is_admin() ) {
		return true; // Front end: positively not the Site Editor.
	}
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	if ( ! is_object( $screen ) ) {
		return false;
	}
	// Read id AND base: WP_Screen sets both to 'site-editor' for site-editor.php,
	// and checking only one couples us to which of the two core happens to keep.
	foreach ( array( 'id', 'base' ) as $prop ) {
		if ( isset( $screen->$prop ) && 'site-editor' === (string) $screen->$prop ) {
			return false;
		}
	}
	return true;
}

/**
 * Resolve the post ID for the contextual "Regen OG Card" item, or 0 when
 * there is no single post in context. Used as both the menu guard (a
 * positive ID means "show the item") and the source of the post ID the JS
 * forwards to the AJAX handler.
 *
 * Contexts that resolve a post ID:
 *   - Admin post-edit screen: get_current_screen()->base === 'post' with a
 *     numeric ?post= in the query (Classic editor + block editor both load
 *     post.php?post=ID).
 *   - Front-end singular view: is_singular() → get_queried_object_id().
 *
 * @return int Post ID > 0 when in context, else 0.
 */
function sn_admin_bar_contextual_post_id() {
	if ( is_admin() ) {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return 0;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return 0;
		}
		// Nonce check is not appropriate here: this is a read-only render
		// guard inspecting the current admin URL, not a state change. The
		// resolved ID is re-validated (existence + edit cap) in the handler.
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $post_id > 0 ? $post_id : 0;
	}

	if ( function_exists( 'is_singular' ) && is_singular() ) {
		return (int) get_queried_object_id();
	}

	return 0;
}


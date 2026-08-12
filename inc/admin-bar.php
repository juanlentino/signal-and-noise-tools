<?php
/**
 * Signal & Noise — Admin bar quick actions.
 *
 * Adds a top-bar dropdown labeled "S&N" with one-click access to the
 * maintenance actions that previously required navigating to
 * Appearance → Signal & Noise → Dashboard. Available from any admin
 * page AND from the front-end (when the admin bar is shown).
 *
 * Actions exposed:
 *   - Purge All Caches      (object cache + Breeze + Varnish + Cloudflare)
 *   - Clear DB Overrides    (wp_template / wp_template_part / wp_navigation)
 *                           DESTRUCTIVE: force-delete, no trash. Hidden in the
 *                           Site Editor — see sn_admin_bar_destructive_allowed().
 *   - Purge Cloudflare      (CF zone purge — only shown when configured)
 *   - Check for Updates     (re-poll GitHub for theme update)
 *
 * Each action runs over admin-ajax with a per-action nonce. JS shows a
 * toast notification so the user doesn't navigate. Successes are green,
 * failures red. Toasts auto-dismiss after 3.5s; clicking a toast
 * dismisses it immediately.
 *
 * Capability gate: all actions require `manage_options`. The admin bar
 * items aren't even rendered for users without that capability, and the
 * AJAX handlers re-check it server-side.
 *
 * Security:
 *   - Per-action nonces verify each AJAX request
 *   - capability check on every handler
 *   - JS uses textContent (not innerHTML) when manipulating link text
 *   - No user-controlled strings flow into the DOM
 *
 * @package SignalNoise
 * @since 7.0.x
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
			'action' => 'sn_quick_force_update_check',
			'label'  => '↺ Force Update Check',
		),
		'sn-quick-scan-patterns' => array(
			'action' => 'sn_quick_scan_patterns',
			'label'  => '⌕ Scan Pattern Adoption',
		),
		'sn-quick-purge-caches' => array(
			'action' => 'sn_quick_purge_caches',
			'label'  => '↻ Purge All Caches',
		),
		'sn-quick-clear-overrides' => array(
			'action' => 'sn_quick_clear_overrides',
			'label'  => '⌫ Clear DB Overrides',
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
		'sn-quick-cf-purge' => array(
			'action' => 'sn_quick_cf_purge',
			'label'  => '☁ Purge Cloudflare',
			// Only shown when CF is configured.
			'guard'  => 'sn_cf_is_configured',
		),
		'sn-quick-regen-og-card' => array(
			'action' => 'sn_quick_regen_og_card',
			'label'  => '⟳ Regen OG Card',
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

/**
 * Add the parent "S&N" menu and its quick-action submenu items.
 * Priority 100 so we land after WP core's nodes (themes, plugins,
 * comments). Visible on both admin and front-end when the admin bar
 * is shown.
 */
add_action( 'admin_bar_menu', function( $admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$admin_bar->add_node( array(
		'id'    => 'sn-quick',
		'title' => '<span class="ab-label">S&amp;N</span>',
		'href'  => admin_url( 'admin.php?page=sn-theme-options' ),
		'meta'  => array(
			'title' => 'Signal & Noise: quick actions',
		),
	) );

	foreach ( sn_admin_bar_items() as $node_id => $item ) {
		// Conditional items (e.g., Cloudflare only when configured, or the
		// contextual Regen OG Card which is only shown for a single post).
		// A guard that returns falsy hides the item. The post ID a contextual
		// guard resolves is carried to the JS via the script config (see
		// sn_admin_bar_print_script) rather than a DOM attribute — WP core's
		// admin bar renderer doesn't pass arbitrary data-* attrs through.
		if ( ! empty( $item['guard'] ) && is_callable( $item['guard'] ) && ! call_user_func( $item['guard'] ) ) {
			continue;
		}

		$admin_bar->add_node( array(
			'id'     => $node_id,
			'parent' => 'sn-quick',
			'title'  => $item['label'],
			// href = '#' so right-click "open in new tab" doesn't fire
			// the action twice. JS preventDefaults the left click.
			'href'   => '#',
			'meta'   => array(
				'class' => 'sn-quick-action',
			),
		) );
	}

	// Separator-style item linking back to the full dashboard for
	// anything not exposed as a quick action.
	$admin_bar->add_node( array(
		'id'     => 'sn-quick-dashboard',
		'parent' => 'sn-quick',
		'title'  => '⚙ Open Dashboard',
		'href'   => admin_url( 'admin.php?page=sn-theme-options' ),
	) );
}, 100 );

/**
 * Register one wp_ajax handler per quick action. All require
 * `manage_options` and verify the per-action nonce. Returns JSON with
 * a `message` field used by the JS to populate the toast.
 *
 * Registered only on `wp_ajax_*` (admin-side) — these are admin
 * actions, not public API. Signed-out users get a 0 response from
 * WP's `_nopriv_` non-handler.
 */
add_action( 'init', function() {
	$handlers = array(
		'sn_quick_force_update_check' => 'sn_handle_quick_force_update_check',
		'sn_quick_scan_patterns'      => 'sn_handle_quick_scan_patterns',
		'sn_quick_purge_caches'       => 'sn_handle_quick_purge_caches',
		'sn_quick_clear_overrides'    => 'sn_handle_quick_clear_overrides',
		'sn_quick_cf_purge'           => 'sn_handle_quick_cf_purge',
		'sn_quick_regen_og_card'      => 'sn_handle_quick_regen_og_card',
);
	foreach ( $handlers as $action => $callback ) {
		add_action( 'wp_ajax_' . $action, $callback );
	}
} );

function sn_handle_quick_force_update_check() {
	check_ajax_referer( 'sn_quick_force_update_check' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	// Same work signal-noise/get-deploy-status does with force_refresh=true
	// (and the removed force-check-updates wrapper before it): both route
	// through snt_cmd_impl_force_check(), which busts the GitHub tag caches
	// + WP's update_themes/update_plugins transients. Call the same impl so
	// the admin-bar action and the ability stay behaviorally identical.
	if ( ! function_exists( 'snt_cmd_impl_force_check' ) ) {
		wp_send_json_error( array( 'message' => 'Force-check helper unavailable.' ), 500 );
	}
	snt_cmd_impl_force_check();
	wp_send_json_success( array(
		'message' => 'Update check forced: see Dashboard › Updates.',
	) );
}

function sn_handle_quick_scan_patterns() {
	check_ajax_referer( 'sn_quick_scan_patterns' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	if ( ! function_exists( 'snt_pattern_adoption_run_scan' ) ) {
		wp_send_json_error( array( 'message' => 'Pattern-adoption scanner unavailable.' ), 500 );
	}
	// snt_pattern_adoption_run_scan() returns an ENVELOPE:
	// array( 'candidates' => [...], 'counts' => [...], 'scanned_at' => int ).
	// Surface count( $result['candidates'] ) — NOT count( $result ), which
	// would always be 3 (the envelope's key count). This exact off-by-envelope
	// bug was caught in the v4.6.0 pattern-adoption-scan ability.
	$result = snt_pattern_adoption_run_scan();
	$count  = ( is_array( $result ) && isset( $result['candidates'] ) && is_array( $result['candidates'] ) )
		? count( $result['candidates'] )
		: 0;
	wp_send_json_success( array(
		'message' => sprintf(
			'Pattern scan complete. %d candidate%s.',
			$count,
			1 === $count ? '' : 's'
		),
	) );
}

function sn_handle_quick_regen_og_card() {
	check_ajax_referer( 'sn_quick_regen_og_card' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	// Nonce verified above by check_ajax_referer.
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		wp_send_json_error( array( 'message' => 'No post in context.' ), 400 );
	}
	// Per-post capability — manage_options alone is not enough to regen a
	// card for an arbitrary post; the user must be able to edit THIS post.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => 'You cannot edit this post.' ), 403 );
	}
	// Same work the signal-noise/regenerate-og-card ability does: its
	// execute_callback (snt_ability_regenerate_og_card) calls
	// sn_generate_og_card( $post_id ) (returns bool). Call the same impl so
	// the admin-bar action and the ability stay behaviorally identical.
	if ( ! function_exists( 'sn_generate_og_card' ) ) {
		wp_send_json_error( array( 'message' => 'OG card generator not available.' ), 500 );
	}
	if ( ! sn_generate_og_card( $post_id ) ) {
		wp_send_json_error( array(
			'message' => 'OG card regeneration failed (check that GD + theme fonts are available).',
		), 500 );
	}
	wp_send_json_success( array( 'message' => 'OG card regenerated for this post.' ) );
}

function sn_handle_quick_purge_caches() {
	check_ajax_referer( 'sn_quick_purge_caches' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	// Dispatched via the sn_purge_all_caches_result filter contract —
	// theme module template-maintenance.php owns the implementation.
	// template_overrides => false matches dashboard "Purge All Caches"
	// semantics — don't nuke Site Editor edits as a side effect.
	if ( ! has_filter( 'sn_purge_all_caches_result' ) ) {
		wp_send_json_error( array( 'message' => 'Cache helper unavailable.' ), 500 );
	}
	apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
	wp_send_json_success( array( 'message' => 'All caches purged.' ) );
}

function sn_handle_quick_clear_overrides() {
	check_ajax_referer( 'sn_quick_clear_overrides' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	// Dispatched via the sn_clear_template_overrides_result filter
	// contract — theme module template-maintenance.php owns the
	// implementation; returns 0 if not loaded.
	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
	wp_send_json_success( array(
		'message' => $count . ' DB override(s) cleared.',
	) );
}

function sn_handle_quick_cf_purge() {
	check_ajax_referer( 'sn_quick_cf_purge' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
	}
	if ( function_exists( 'sn_cf_purge_everything' ) && sn_cf_purge_everything() ) {
		wp_send_json_success( array( 'message' => 'Cloudflare zone purge dispatched.' ) );
	}
	wp_send_json_error( array(
		'message' => 'Cloudflare not configured: set token + zone first.',
	), 400 );
}

/**
 * Inline-print the JS that wires admin-bar clicks to AJAX + toast.
 *
 * Inline (rather than enqueued) because:
 *   - The script is small (~50 lines)
 *   - It needs nonces dynamically generated per pageload (can't be
 *     cached as a static asset effectively)
 *   - One fewer HTTP request on every admin/front-end pageview
 *     where the admin bar is shown
 *
 * Fires on both admin and front-end footers via the corresponding
 * action hooks. Guarded on capability + admin-bar-showing.
 *
 * Security: uses textContent (not innerHTML) when manipulating link
 * labels. Everything flowing from server to client is server-controlled:
 * the action name, the nonce, the contextual postId, and the confirm
 * prose for destructive items. All four ride wp_json_encode(), which
 * escapes the closing-tag sequence, so none can break out of the
 * <script> block. Toast message comes from the AJAX response — also
 * server-controlled, but textContent ensures any future bug there can't
 * escalate to XSS. The confirm string reaches only window.confirm(),
 * which renders text, never markup.
 */
function sn_admin_bar_print_script() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! is_admin_bar_showing() ) {
		return;
	}

	$nonces = array();
	foreach ( sn_admin_bar_items() as $node_id => $item ) {
		$guard_value = null;
		if ( ! empty( $item['guard'] ) && is_callable( $item['guard'] ) ) {
			$guard_value = call_user_func( $item['guard'] );
			if ( ! $guard_value ) {
				continue;
			}
		}
		$node = array(
			'action' => $item['action'],
			'nonce'  => wp_create_nonce( $item['action'] ),
		);
		// A guard that returns a positive int (e.g. the contextual
		// sn_admin_bar_contextual_post_id) supplies the post_id the JS
		// forwards to admin-ajax for this item. Param-less items omit it.
		if ( is_int( $guard_value ) && $guard_value > 0 ) {
			$node['postId'] = $guard_value;
		}
		// Confirmation prose for destructive items. Forwarded to the client
		// because the gate has to be here: the item's href is '#' and the JS owns
		// the request, so a confirm declared in sn_admin_bar_items() that never
		// reaches this config is a confirm that does nothing at all. Asserted
		// end-to-end in tests/admin-bar-quick-actions.php rather than only at the
		// declaration — a one-sided check on the items array would pass while the
		// button fired unconfirmed.
		if ( ! empty( $item['confirm'] ) && is_string( $item['confirm'] ) ) {
			$node['confirm'] = $item['confirm'];
		}
		$nonces[ $node_id ] = $node;
	}

	$config = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nodes'   => $nonces,
	);
	?>
	<script>
	(function () {
		const cfg = <?php echo wp_json_encode( $config ); ?>;
		if (!cfg || !cfg.nodes) return;

		Object.keys(cfg.nodes).forEach(function (nodeId) {
			const link = document.querySelector('#wp-admin-bar-' + nodeId + ' > a.ab-item');
			if (!link) return;
			const meta = cfg.nodes[nodeId];
			// Cache the original label as text so we can restore it
			// without ever touching innerHTML.
			const originalText = link.textContent;

			link.addEventListener('click', function (e) {
				// preventDefault first and unconditionally: href is '#', so an
				// early return past this point would jump the page to the top.
				e.preventDefault();
				if (link.dataset.snBusy === '1') return;
				// Destructive items gate here, BEFORE the busy flag and the label
				// swap — a declined confirm must leave the row untouched and
				// immediately re-clickable, not stuck spinning on a request that
				// never fired. Native confirm() rather than a custom modal: it is
				// keyboard-accessible and screen-reader-announced for free, and a
				// hand-rolled dialog inside the admin bar would have to re-earn
				// both. Items without a confirm string skip this entirely.
				if (typeof meta.confirm === 'string' && meta.confirm !== '' && !window.confirm(meta.confirm)) {
					return;
				}
				link.dataset.snBusy = '1';
				link.textContent = '… ' + originalText.replace(/^\S+\s*/, '');

				const body = new URLSearchParams();
				body.set('action', meta.action);
				body.set('_ajax_nonce', meta.nonce);
				// Optional contextual param (e.g. Regen OG Card carries the
				// post ID resolved server-side). Param-less items skip it.
				if (typeof meta.postId === 'number' && meta.postId > 0) {
					body.set('post_id', String(meta.postId));
				}

				fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				})
				.then(function (r) {
					return r.json().then(function (data) {
						return { ok: r.ok, data: data };
					});
				})
				.then(function (res) {
					const success = !!(res.ok && res.data && res.data.success);
					const payload = res.data && res.data.data;
					const msg = (payload && typeof payload.message === 'string') ? payload.message : 'Done.';
					snToast(msg, success);
				})
				.catch(function () {
					snToast('Network error.', false);
				})
				.finally(function () {
					link.textContent = originalText;
					delete link.dataset.snBusy;
				});
			});
		});

		function snToast(message, success) {
			const el = document.createElement('div');
			// textContent — never innerHTML — so a future bug in the
			// server response can't lead to XSS.
			// v6.47.0: announce the toast to assistive tech (sole feedback for
			// each action). success -> polite 'status', error -> 'alert'. WCAG 4.1.3.
			el.setAttribute('role', success ? 'status' : 'alert');
			el.textContent = message;
			el.style.cssText = [
				'position:fixed',
				'top:46px',
				'right:20px',
				'background:' + (success ? '#00a32a' : '#d63638'),
				'color:#fff',
				'padding:10px 16px',
				'border-radius:4px',
				'font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
				'box-shadow:0 2px 12px rgba(0,0,0,0.18)',
				'z-index:999999',
				'opacity:0',
				'transform:translateY(-8px)',
				'transition:opacity 180ms,transform 180ms',
				'cursor:pointer',
				'max-width:360px'
			].join(';');
			el.addEventListener('click', dismiss);
			document.body.appendChild(el);
			requestAnimationFrame(function () {
				el.style.opacity = '1';
				el.style.transform = 'translateY(0)';
			});
			const t = setTimeout(dismiss, 3500);
			function dismiss() {
				clearTimeout(t);
				el.style.opacity = '0';
				el.style.transform = 'translateY(-8px)';
				setTimeout(function () { el.remove(); }, 200);
			}
		}
	})();
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts', 'sn_admin_bar_print_script' );
add_action( 'wp_print_footer_scripts',    'sn_admin_bar_print_script' );

/**
 * Lightweight inline CSS for the admin bar S&N label — same dual-
 * context print (admin + front-end) as the JS.
 */
function sn_admin_bar_print_style() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! is_admin_bar_showing() ) {
		return;
	}
	?>
	<style>
	#wpadminbar #wp-admin-bar-sn-quick > .ab-item .ab-label {
		font-weight: 600;
		letter-spacing: 0.04em;
	}
	</style>
	<?php
}
add_action( 'admin_print_styles', 'sn_admin_bar_print_style' );
add_action( 'wp_print_styles',    'sn_admin_bar_print_style' );

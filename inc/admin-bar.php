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
		'sn-quick-purge-caches' => array(
			'action' => 'sn_quick_purge_caches',
			'label'  => '↻ Purge All Caches',
		),
		'sn-quick-clear-overrides' => array(
			'action' => 'sn_quick_clear_overrides',
			'label'  => '⌫ Clear DB Overrides',
		),
		'sn-quick-cf-purge' => array(
			'action' => 'sn_quick_cf_purge',
			'label'  => '☁ Purge Cloudflare',
			// Only shown when CF is configured.
			'guard'  => 'sn_cf_is_configured',
		),
);
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
		'href'  => admin_url( 'themes.php?page=sn-theme-options' ),
		'meta'  => array(
			'title' => 'Signal & Noise — quick actions',
		),
	) );

	foreach ( sn_admin_bar_items() as $node_id => $item ) {
		// Conditional items (e.g., Cloudflare only when configured).
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
		'href'   => admin_url( 'themes.php?page=sn-theme-options' ),
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
		'sn_quick_purge_caches'    => 'sn_handle_quick_purge_caches',
		'sn_quick_clear_overrides' => 'sn_handle_quick_clear_overrides',
		'sn_quick_cf_purge'        => 'sn_handle_quick_cf_purge',
);
	foreach ( $handlers as $action => $callback ) {
		add_action( 'wp_ajax_' . $action, $callback );
	}
} );

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
		'message' => 'Cloudflare not configured — set token + zone first.',
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
 * labels. The only data flowing from server to client is the action
 * name + nonce, both server-controlled. Toast message comes from the
 * AJAX response — also server-controlled, but textContent ensures
 * any future bug there can't escalate to XSS.
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
		if ( ! empty( $item['guard'] ) && is_callable( $item['guard'] ) && ! call_user_func( $item['guard'] ) ) {
			continue;
		}
		$nonces[ $node_id ] = array(
			'action' => $item['action'],
			'nonce'  => wp_create_nonce( $item['action'] ),
		);
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
				e.preventDefault();
				if (link.dataset.snBusy === '1') return;
				link.dataset.snBusy = '1';
				link.textContent = '… ' + originalText.replace(/^\S+\s*/, '');

				const body = new URLSearchParams();
				body.set('action', meta.action);
				body.set('_ajax_nonce', meta.nonce);

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

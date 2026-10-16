<?php
/**
 * Signal & Noise Tools: admin-bar.php, the inline script and style, and
 * their hooks.
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
 * Attach the JS that wires admin-bar clicks to a request + a toast.
 *
 * Inline, on core's own `admin-bar` script handle (wp_add_inline_script,
 * core since 4.5.0), because:
 *   - The script is small (~90 lines)
 *   - Off the shell it needs nonces minted per pageload (can't be
 *     cached as a static asset effectively)
 *   - One fewer HTTP request on every admin/front-end pageview
 *     where the admin bar is shown
 *
 * Core enqueues `admin-bar` in WP_Admin_Bar::initialize() on admin and
 * front end alike and registers admin-bar.js in the footer, so the block
 * prints once, after admin-bar.js, wherever the bar renders. Hooked on
 * admin_enqueue_scripts + wp_enqueue_scripts, where get_current_screen()
 * and is_singular() are both resolvable for the two guards. Before #1623
 * this echoed a raw <script> on the print_footer_scripts hooks.
 *
 * THE SHELL BRANCH (#1623). admin.php?page=openstation is an ordinary
 * admin page: core builds the bar there and OpenStation hides it with CSS
 * unless the per-user admin bar mode says static or dynamic. The shell is
 * a page with no bounded lifetime (memory: a PWA page cannot carry a
 * nonce), so a page-load nonce dies after wp_nonce_tick and every click
 * past that read a red "Done." off the 403's bare -1. On that request
 * (openstation_is_shell_request(), the plugin already reads it in
 * openstation-preferences.php) each node carries `ability` + `input`
 * instead of `nonce`, the shared runner (snt-ability-run, registered at
 * priority 1 on this same hook) is enqueued, and the click goes through
 * window.sntAbilityRun over wp.apiFetch, whose nonce middleware refetches
 * on rest_cookie_invalid_nonce. NEVER route the admin-ajax POST through
 * wp.os.fetch: it stamps X-WP-Nonce on REST URLs only, and the body's
 * _ajax_nonce would still be the page-load one.
 *
 * Security: uses textContent (not innerHTML) when manipulating link
 * labels. Everything flowing from server to client is server-controlled:
 * the action name, the nonce, the ability slug, the contextual postId,
 * and the confirm prose for destructive items. All ride wp_json_encode(),
 * which escapes the closing-tag sequence, so none can break out of the
 * script block (wp_add_inline_script refuses a literal </script> anyway).
 * Toast message comes from the response, also server-controlled, but
 * textContent ensures any future bug there can't escalate to XSS. The
 * confirm string reaches window.confirm() or wp.os.confirm's message
 * attribute, both of which render text, never markup.
 */
function sn_admin_bar_print_script() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! is_admin_bar_showing() ) {
		return;
	}

	// Stable seam: openstation_is_shell_request() (openstation
	// includes/shell-screen.php), guarded because the plugin runs without
	// OpenStation on the front end and on classic installs.
	$shell = function_exists( 'openstation_is_shell_request' ) && openstation_is_shell_request();

	$nodes = array();
	foreach ( sn_admin_bar_items() as $node_id => $item ) {
		$guard_value = null;
		if ( ! empty( $item['guard'] ) && is_callable( $item['guard'] ) ) {
			$guard_value = call_user_func( $item['guard'] );
			if ( ! $guard_value ) {
				continue;
			}
		}
		if ( $shell ) {
			$node = array( 'ability' => $item['ability'] );
			if ( ! empty( $item['input'] ) ) {
				$node['input'] = $item['input'];
			}
			if ( ! empty( $item['done'] ) ) {
				$node['done'] = $item['done'];
			}
		} else {
			$node = array(
				'action' => $item['action'],
				'nonce'  => wp_create_nonce( $item['action'] ),
			);
		}
		// A guard that returns a positive int (e.g. the contextual
		// sn_admin_bar_contextual_post_id) supplies the post_id the JS
		// forwards for this item. Param-less items omit it.
		if ( is_int( $guard_value ) && $guard_value > 0 ) {
			$node['postId'] = $guard_value;
		}
		// Confirmation prose for destructive items. Forwarded to the client
		// because the gate has to be here: the item's href is '#' and the JS owns
		// the request, so a confirm declared in sn_admin_bar_items() that never
		// reaches this config is a confirm that does nothing at all. Asserted
		// end-to-end in tests/admin-bar-quick-actions.php rather than only at the
		// declaration; a one-sided check on the items array would pass while the
		// button fired unconfirmed.
		if ( ! empty( $item['confirm'] ) && is_string( $item['confirm'] ) ) {
			$node['confirm'] = $item['confirm'];
		}
		$nodes[ $node_id ] = $node;
	}

	$config = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nodes'   => $nodes,
	);

	if ( $shell ) {
		wp_enqueue_script( 'snt-ability-run' );
	}

	$js = <<<'JS'
	if (!cfg || !cfg.nodes) return;

	Object.keys(cfg.nodes).forEach(function (nodeId) {
		const link = document.querySelector('#wp-admin-bar-' + nodeId + ' > a.ab-item');
		if (!link) return;
		const meta = cfg.nodes[nodeId];
		// Cache the original label as text so we can restore it
		// without ever touching innerHTML.
		const originalText = link.textContent;
		const plainLabel = originalText.replace(/^\S+\s*/, '');

		link.addEventListener('click', function (e) {
			// preventDefault first and unconditionally: href is '#', so an
			// early return past this point would jump the page to the top.
			e.preventDefault();
			if (link.dataset.snBusy === '1') return;
			// The busy flag is taken BEFORE the gate, not after it: on the
			// shell the confirm is asynchronous and core's bar (z-index 99999)
			// paints above the dialog's scrim (10000), so a second click while
			// the dialog is open would mount a second dialog and a DELETE with
			// no undo would fire twice. Declining hands the flag back.
			link.dataset.snBusy = '1';
			const os = shellOs();
			// Destructive items gate here, BEFORE the label swap: a declined
			// confirm must leave the row untouched and immediately
			// re-clickable, not stuck spinning on a request that never fired.
			// Native confirm() off the shell: it is keyboard-accessible and
			// screen-reader-announced for free. On the shell wp.os.confirm
			// traps focus and restores it to the opener, which is the same
			// property. The prose's first line is the title window.confirm has
			// no slot for; the dialog does.
			let gate = Promise.resolve(true);
			if (typeof meta.confirm === 'string' && meta.confirm !== '') {
				gate = os
					? os.confirm({ title: plainLabel, message: meta.confirm.replace(/^.*\n+/, ''), danger: true })
					: Promise.resolve(window.confirm(meta.confirm));
			}
			gate.then(function (go) {
				if (!go) {
					delete link.dataset.snBusy;
					return;
				}
				link.textContent = '… ' + plainLabel;
				(meta.ability ? runAbility(meta) : runAjax(meta))
					.then(function (r) { toast(r.message, r.ok); })
					.catch(function (err) {
						toast((err && typeof err.message === 'string' && err.message) || 'Network error.', false);
					})
					.finally(function () {
						link.textContent = originalText;
						delete link.dataset.snBusy;
					});
			});
		});
	});

	// wp.os is the OpenStation runtime; its confirm and showToast are Stable
	// (docs/javascript-reference.md). Absent off the shell. Resolved at each
	// call, never captured at load: desktop.min.js is deferred and installs
	// window.wp.os after this block has run.
	function shellOs() {
		return (window.wp && window.wp.os) || null;
	}

	function toast(message, success) {
		const os = shellOs();
		if (os) {
			os.showToast({ message: message });
			return;
		}
		snToast(message, success);
	}

	// Off the shell: admin-ajax with the page-load nonce.
	function runAjax(meta) {
		const body = new URLSearchParams();
		body.set('action', meta.action);
		body.set('_ajax_nonce', meta.nonce);
		// Optional contextual param (e.g. Regen OG Card carries the
		// post ID resolved server-side). Param-less items skip it.
		if (typeof meta.postId === 'number' && meta.postId > 0) {
			body.set('post_id', String(meta.postId));
		}
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
		.then(function (r) {
			return r.json().then(function (data) {
				const payload = data && data.data;
				return {
					ok: !!(r.ok && data && data.success),
					message: (payload && typeof payload.message === 'string') ? payload.message : 'Done.'
				};
			});
		});
	}

	// On the shell: the ability twin through the plugin's one transport.
	// The run path hands the output back bare ({ ok, message, count }).
	function runAbility(meta) {
		const input = Object.assign({}, meta.input || {});
		if (typeof meta.postId === 'number' && meta.postId > 0) {
			input.post_id = meta.postId;
		}
		return window.sntAbilityRun(meta.ability, input).then(function (res) {
			const out = (res && typeof res === 'object') ? res : {};
			let message = (typeof out.message === 'string' && out.message !== '') ? out.message : (meta.done || 'Done.');
			message = message.replace('%d', typeof out.count === 'number' ? String(out.count) : '?');
			return { ok: out.ok !== false, message: message };
		});
	}

	function snToast(message, success) {
		const el = document.createElement('div');
		// textContent, never innerHTML, so a future bug in the
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
JS;

	wp_add_inline_script( 'admin-bar', "(function () {\n\tconst cfg = " . wp_json_encode( $config ) . ";\n" . $js );
}
add_action( 'admin_enqueue_scripts', 'sn_admin_bar_print_script' );
add_action( 'wp_enqueue_scripts',    'sn_admin_bar_print_script' );

/**
 * The one label rule, on core's `admin-bar` style handle (wp_add_inline_style,
 * core since 3.3.0) so it ships with the bar itself on admin and front end.
 * Before #1623 this echoed a raw <style> on the print_styles hooks.
 */
function sn_admin_bar_print_style() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! is_admin_bar_showing() ) {
		return;
	}
	wp_add_inline_style(
		'admin-bar',
		'#wpadminbar #wp-admin-bar-sn-quick > .ab-item .ab-label { font-weight: 600; letter-spacing: 0.04em; }'
	);
}
add_action( 'admin_enqueue_scripts', 'sn_admin_bar_print_style' );
add_action( 'wp_enqueue_scripts',    'sn_admin_bar_print_style' );

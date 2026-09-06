/**
 * Signal & Noise — brush-to-select on the Views-per-day trend (maturity I5).
 * The chart becomes the range control: drag across the sparkline to zoom the
 * dashboard to that window (?sn_range=custom&sn_from&sn_to — validated
 * server-side by snt_analytics_resolve_custom_window, so this only ever
 * builds a URL). Zero dependencies; no-op without a [data-brush-from] chart.
 *
 * IN AN OPENSTATION WINDOW this file ran once, against the window's first
 * paint — a spinner — found no chart and never looked again. It now arms
 * through init( root ): once at load with `document` (the classic page,
 * unchanged) and again on every `snt:paint` the host script dispatches after a
 * repaint. Two things a repaint does that this file has to answer: it REUSES
 * the wrap element (so the listeners must be attached once, tracked in a
 * WeakSet the attribute sync cannot reach), and it REMOVES the selection
 * overlay, which is a child the server never paints — so the overlay is
 * re-attached whenever it has gone missing.
 *
 * And a THIRD thing (#1075): inside a window the zoom cannot navigate.
 * `location.assign` would replace the whole desktop, not the report, so the
 * brush dispatches the host's `go` with the same three params it used to put
 * in the URL. See zoom() below.
 */
(function () {
	'use strict';

	var armed = new WeakSet();
	var overlays = new WeakMap();

	function frac(wrap, evt) {
		var r = wrap.getBoundingClientRect();
		return Math.max(0, Math.min(1, (evt.clientX - r.left) / r.width));
	}

	// The window's start day and its length are re-read from the wrap at event
	// time: a repaint can change the range under a reused element.
	function dayAt(wrap, f) {
		var days = parseInt(wrap.getAttribute('data-brush-days'), 10);
		var from = wrap.getAttribute('data-brush-from');
		var p = from.split('-');
		var base = Date.UTC(+p[0], +p[1] - 1, +p[2]);
		return new Date(base + Math.round(f * (days - 1)) * 86400000).toISOString().slice(0, 10);
	}

	function usable(wrap) {
		var days = parseInt(wrap.getAttribute('data-brush-days'), 10);
		var from = wrap.getAttribute('data-brush-from');
		return !!days && days >= 2 && /^\d{4}-\d{2}-\d{2}$/.test(from);
	}

	/**
	 * The app-window root this chart sits in, or null on the classic page.
	 *
	 * The framework's window template is
	 * `<div class="os-app" data-os-app="<id>" …>` (desktop-mode
	 * includes/framework/wordpress.php), and both hosts declare a `go` action.
	 *
	 * @param {Element} el Any element inside the chart.
	 * @return {Element|null}
	 */
	function hostRoot(el) {
		return typeof el.closest === 'function' ? el.closest('.os-app[data-os-app]') : null;
	}

	/**
	 * Zoom to [from, to].
	 *
	 * ON THE CLASSIC PAGE this navigates, which is what it has always done: the
	 * range lives in the URL and the server re-reads it. IN A WINDOW there is
	 * no URL to change and `location.assign` would replace the WHOLE DESKTOP
	 * with a wp-admin page — the brush is the one control on this screen that
	 * moves the window by script instead of by a link the server rewrote. So it
	 * dispatches the same `go` the range pills dispatch, through a hidden
	 * carrier the runtime's delegated click listener reads: the runtime binds
	 * one listener on the app root and walks up from the event target to find
	 * `os-action` (app-runtime.min.js, `Bt`), so a synthetic click on a node
	 * inside the root IS a dispatch. The carrier is removed on the next tick,
	 * after the runtime has read its args.
	 *
	 * @param {Element} wrap The chart wrap.
	 * @param {string}  from Y-m-d.
	 * @param {string}  to   Y-m-d.
	 */
	function zoom(wrap, from, to) {
		var root = hostRoot(wrap);
		if (root) {
			var carrier = document.createElement('a');
			carrier.setAttribute('os-action', 'go');
			carrier.setAttribute('os-arg-sn_range', 'custom');
			carrier.setAttribute('os-arg-sn_from', from);
			carrier.setAttribute('os-arg-sn_to', to);
			carrier.hidden = true;
			root.appendChild(carrier);
			carrier.click();
			window.setTimeout(function () {
				if (carrier.parentNode) { carrier.parentNode.removeChild(carrier); }
			}, 0);
			return;
		}
		var url = new URL(window.location.href);
		url.searchParams.set('sn_range', 'custom');
		url.searchParams.set('sn_from', from);
		url.searchParams.set('sn_to', to);
		window.location.assign(url.toString());
	}

	function bind(wrap, sel) {
		var start = null;
		wrap.addEventListener('pointerdown', function (e) {
			if (!usable(wrap)) { return; }
			start = frac(wrap, e);
			if (wrap.setPointerCapture) { wrap.setPointerCapture(e.pointerId); }
			sel.style.display = 'block';
			e.preventDefault();
		});
		wrap.addEventListener('pointermove', function (e) {
			if (start === null) { return; }
			var f = frac(wrap, e);
			sel.style.left = (Math.min(start, f) * 100) + '%';
			sel.style.width = (Math.abs(f - start) * 100) + '%';
		});
		wrap.addEventListener('pointerup', function (e) {
			if (start === null) { return; }
			var lo = Math.min(start, frac(wrap, e));
			var hi = Math.max(start, frac(wrap, e));
			start = null;
			sel.style.display = 'none';
			sel.style.width = '0';
			if (hi - lo < 0.02) { return; } // a click, not a brush
			zoom(wrap, dayAt(wrap, lo), dayAt(wrap, hi));
		});
	}

	/**
	 * Arm the brush chart inside `root`, if it has one.
	 *
	 * @param {Element|Document} root Subtree to arm. Defaults to `document`.
	 */
	function init(root) {
		var scope = root || document;
		var wrap = scope.querySelector('.sn-spark-wrap[data-brush-from]');
		if (!wrap || !usable(wrap)) { return; }

		var sel = overlays.get(wrap);
		if (!sel) {
			sel = document.createElement('div');
			sel.className = 'sn-brush-sel';
			overlays.set(wrap, sel);
		}
		// The overlay is a child the server does not paint, so a repaint drops
		// it. Re-attaching the SAME node keeps the listeners' reference valid.
		if (sel.parentNode !== wrap) {
			wrap.appendChild(sel);
		}

		if (armed.has(wrap)) { return; }
		armed.add(wrap);
		bind(wrap, sel);
	}

	init(document);

	// assets/os-host.js dispatches this on `document` after every window paint,
	// with the painted root in detail.root. The classic page never fires it.
	document.addEventListener('snt:paint', function (e) {
		init((e.detail && e.detail.root) || document);
	});
})();

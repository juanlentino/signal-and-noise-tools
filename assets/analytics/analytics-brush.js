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
			var url = new URL(window.location.href);
			url.searchParams.set('sn_range', 'custom');
			url.searchParams.set('sn_from', dayAt(wrap, lo));
			url.searchParams.set('sn_to', dayAt(wrap, hi));
			window.location.assign(url.toString());
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

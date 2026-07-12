/**
 * Signal & Noise — brush-to-select on the Views-per-day trend (maturity I5).
 * The chart becomes the range control: drag across the sparkline to zoom the
 * dashboard to that window (?sn_range=custom&sn_from&sn_to — validated
 * server-side by snt_analytics_resolve_custom_window, so this only ever
 * builds a URL). Zero dependencies; no-op without a [data-brush-from] chart.
 */
(function () {
	'use strict';
	var wrap = document.querySelector('.sn-spark-wrap[data-brush-from]');
	if (!wrap) { return; }
	var days = parseInt(wrap.getAttribute('data-brush-days'), 10);
	var from = wrap.getAttribute('data-brush-from');
	if (!days || days < 2 || !/^\d{4}-\d{2}-\d{2}$/.test(from)) { return; }
	var start = null;
	var sel = document.createElement('div');
	sel.className = 'sn-brush-sel';
	wrap.appendChild(sel);
	function frac(evt) {
		var r = wrap.getBoundingClientRect();
		return Math.max(0, Math.min(1, (evt.clientX - r.left) / r.width));
	}
	function dayAt(f) {
		var p = from.split('-');
		var base = Date.UTC(+p[0], +p[1] - 1, +p[2]);
		return new Date(base + Math.round(f * (days - 1)) * 86400000).toISOString().slice(0, 10);
	}
	wrap.addEventListener('pointerdown', function (e) {
		start = frac(e);
		if (wrap.setPointerCapture) { wrap.setPointerCapture(e.pointerId); }
		sel.style.display = 'block';
		e.preventDefault();
	});
	wrap.addEventListener('pointermove', function (e) {
		if (start === null) { return; }
		var f = frac(e);
		sel.style.left = (Math.min(start, f) * 100) + '%';
		sel.style.width = (Math.abs(f - start) * 100) + '%';
	});
	wrap.addEventListener('pointerup', function (e) {
		if (start === null) { return; }
		var lo = Math.min(start, frac(e));
		var hi = Math.max(start, frac(e));
		start = null;
		sel.style.display = 'none';
		sel.style.width = '0';
		if (hi - lo < 0.02) { return; } // a click, not a brush
		var url = new URL(window.location.href);
		url.searchParams.set('sn_range', 'custom');
		url.searchParams.set('sn_from', dayAt(lo));
		url.searchParams.set('sn_to', dayAt(hi));
		window.location.assign(url.toString());
	});
})();

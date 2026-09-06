/**
 * Signal & Noise Tools — cache freshness dot.
 *
 * Runs on the plugin dashboard. For each cache-critical route, fetches the
 * canonical URL and a cache-busted variant and compares TWO staleness markers
 * from each HTML body:
 *   - the combined-CSS hash (sn-styles-<hash>.css), and
 *   - the render-epoch meta (<meta name="sn-render-epoch">, theme v10.23.0).
 * A route is stale when EITHER marker differs canonical(edge)-vs-busted(fresh
 * origin). The epoch catches render changes the CSS hash can't — e.g. an
 * Additional-CSS edit, which lands in global-styles-inline-css, not the combined
 * stylesheet file. Aggregates into the "Caches" glance card (#snt-freshness-card).
 *
 * Vantage: the admin browser resolves to Cloudflare and sees the true public
 * cache state. The edge caches HTML with no logged-in bypass, so a logged-in
 * admin still sees the public cached copy. Fetches omit credentials + bypass the
 * browser cache so only the EDGE state is measured.
 *
 * IN AN OPENSTATION WINDOW this file is loaded once, when the window opens on a
 * root that holds nothing but a spinner — so it found no card and did nothing,
 * for the life of the window. It now arms through init( root ), called once at
 * load with `document` (the classic page, unchanged) and again on every
 * `snt:paint` the host script dispatches after a repaint.
 */
(function () {
	var cfg = window.sntFreshness;
	if (!cfg || !Array.isArray(cfg.routes) || !cfg.routes.length) { return; }

	// The guard is an ATTRIBUTE on purpose, and it is the one case where that
	// is right: the window's runtime removes every attribute the server does
	// not paint (zt, offset 26198 of app-runtime.min.js) at the same moment it
	// restores the card's "Checking…" placeholder. So the marker is cleared by
	// exactly the event that undid this file's work, and survives the extra
	// no-op pass our own writes schedule. A property or WeakSet would survive
	// the repaint too — and leave the placeholder on screen forever.
	var ARMED = 'data-snt-freshness-armed';

	var HASH_RE  = /sn-styles-([a-f0-9]{12})\.css/;
	var EPOCH_RE = /<meta[^>]+name=["']sn-render-epoch["'][^>]+content=["'](\d+)["']/i;

	function markerOf(text, re) {
		var m = (text || '').match(re);
		return m ? m[1] : null;
	}

	function fetchText(url) {
		return fetch(url, { credentials: 'omit', cache: 'no-store' })
			.then(function (r) { return r.ok ? r.text() : null; })
			.catch(function () { return null; });
	}

	// 'fresh' when every comparable marker matches canonical(edge) vs busted (fresh
	// origin); 'stale' when any differs; 'unknown' when a fetch fails or neither
	// marker is present to compare.
	function checkRoute(url) {
		var bust = url + (url.indexOf('?') === -1 ? '?' : '&') + 'x=' + Date.now();
		return Promise.all([fetchText(url), fetchText(bust)]).then(function (both) {
			var canonHtml = both[0], freshHtml = both[1];
			if (canonHtml === null || freshHtml === null) { return 'unknown'; }
			var markers = [HASH_RE, EPOCH_RE], compared = 0, stale = false;
			for (var i = 0; i < markers.length; i++) {
				var canon = markerOf(canonHtml, markers[i]);
				var fresh = markerOf(freshHtml, markers[i]);
				if (canon === null || fresh === null) { continue; }
				compared++;
				if (canon !== fresh) { stale = true; }
			}
			if (compared === 0) { return 'unknown'; }
			return stale ? 'stale' : 'fresh';
		});
	}

	function render(card, results) {
		var valueEl = card.querySelector('.sn-glance-card__value');
		var total = results.length;
		var fresh = results.filter(function (r) { return r === 'fresh'; }).length;
		var stale = results.filter(function (r) { return r === 'stale'; }).length;
		var unknown = results.filter(function (r) { return r === 'unknown'; }).length;
		var kind = null, text = '', value;

		// Primary metric: how many cache-critical routes are verified fresh (N/M),
		// shown consistently. The pill carries the actionable state.
		if (unknown === total) {
			value = 'Unknown';
		} else if (stale > 0) {
			kind = 'warn';
			value = fresh + '/' + total + ' fresh';
			text = stale + ' stale · purge needed';
		} else {
			kind = 'ok';
			value = fresh + '/' + total + ' fresh';
			text = unknown > 0 ? unknown + ' unknown' : 'all fresh';
		}

		if (valueEl) { valueEl.textContent = value; }

		var pill = card.querySelector('.sn-pill');
		if (kind) {
			if (!pill) {
				pill = document.createElement('span');
				if (valueEl) { valueEl.insertAdjacentElement('afterend', pill); }
				else { card.appendChild(pill); }
			}
			pill.className = 'sn-pill sn-pill--' + kind;
			pill.textContent = text;
		}
	}

	// Find the card INSIDE the given root: the desktop document holds every
	// open window, so getElementById would reach a second window's card.
	function cardIn(scope) {
		if (typeof scope.getElementById === 'function') { return scope.getElementById(cfg.cardId); }
		var candidates = scope.querySelectorAll('[id]');
		for (var i = 0; i < candidates.length; i++) {
			if (candidates[i].id === cfg.cardId) { return candidates[i]; }
		}
		return null;
	}

	/**
	 * Measure the routes and fill the card inside `root`, at most once per
	 * paint. The marker is written BEFORE the fetches, so the no-op pass our
	 * own writes schedule cannot start a second round trip.
	 */
	function init(root) {
		var scope = root || document;
		var card = cardIn(scope);
		if (!card) { return; } // only the dashboard has the card
		if (card.hasAttribute(ARMED)) { return; }
		card.setAttribute(ARMED, '1');
		Promise.all(cfg.routes.map(checkRoute)).then(function (results) {
			render(card, results);
		});
	}

	init(document);

	// A window repaints this leaf on every action, and the paint is a morph:
	// nothing here re-runs on its own. assets/os-host.js dispatches snt:paint
	// on `document` after every pass, with the painted root in detail.root.
	// The classic page never fires it, so its behaviour is unchanged.
	document.addEventListener('snt:paint', function (e) {
		init((e.detail && e.detail.root) || document);
	});
})();

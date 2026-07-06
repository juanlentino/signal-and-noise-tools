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
 */
(function () {
	var cfg = window.sntFreshness;
	if (!cfg || !Array.isArray(cfg.routes) || !cfg.routes.length) { return; }
	var card = document.getElementById(cfg.cardId);
	if (!card) { return; } // only the dashboard has the card

	var valueEl = card.querySelector('.sn-glance-card__value');
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

	function render(results) {
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

	Promise.all(cfg.routes.map(checkRoute)).then(render);
})();

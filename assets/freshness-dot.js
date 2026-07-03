/**
 * Signal & Noise Tools — cache freshness dot.
 *
 * Runs on the plugin dashboard. For each cache-critical route, fetches the
 * canonical URL and a cache-busted variant, extracts the combined-CSS hash
 * (sn-styles-<hash>.css) from each, and compares. A mismatch means the edge is
 * serving a stale render for that route. Aggregates into the "Caches" glance
 * card (#snt-freshness-card).
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
	var HASH_RE = /sn-styles-([a-f0-9]{12})\.css/;

	function hashOf(text) {
		var m = (text || '').match(HASH_RE);
		return m ? m[1] : null;
	}

	function fetchText(url) {
		return fetch(url, { credentials: 'omit', cache: 'no-store' })
			.then(function (r) { return r.ok ? r.text() : null; })
			.catch(function () { return null; });
	}

	// 'fresh' when the canonical (edge) hash matches the cache-busted (fresh
	// origin) hash; 'stale' when they differ; 'unknown' when a fetch/parse fails.
	function checkRoute(url) {
		var bust = url + (url.indexOf('?') === -1 ? '?' : '&') + 'x=' + Date.now();
		return Promise.all([fetchText(url), fetchText(bust)]).then(function (both) {
			var canon = hashOf(both[0]);
			var fresh = hashOf(both[1]);
			if (!canon || !fresh) { return 'unknown'; }
			return canon === fresh ? 'fresh' : 'stale';
		});
	}

	function render(results) {
		var stale = results.filter(function (r) { return r === 'stale'; }).length;
		var unknown = results.filter(function (r) { return r === 'unknown'; }).length;
		var kind = null, text = '', value;

		if (stale > 0) { kind = 'warn'; value = stale + ' stale'; text = 'purge needed'; }
		else if (unknown === results.length) { value = 'Unknown'; }
		else { kind = 'ok'; value = 'Fresh'; text = results.length + '/' + results.length; }

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

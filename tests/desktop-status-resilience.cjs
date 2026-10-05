/* Real widget + runner execution; only DOM, timers and API transport are faked. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.attrs = {}; this.text = ''; this.style = {}; }
  setAttribute(k, v) { this.attrs[k] = v; }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  removeAttribute(k) { delete this.attrs[k]; }
  addEventListener(t, fn) { (this.handlers = this.handlers || {})[t] = fn; }
  appendChild(n) { this.children.push(n); Object.defineProperty(n, "parentNode", {value: this, writable: true, configurable: true}); return n; }
  querySelector() { return null; }
  remove() { if (this.parentNode) this.parentNode.removeChild(this); }
  insertBefore(n, before) { const i = this.children.indexOf(before); this.children.splice(i < 0 ? this.children.length : i, 0, n); }
  removeChild(n) { this.children.splice(this.children.indexOf(n), 1); }
  get firstChild() { return this.children[0]; }
  set textContent(t) { this.text = t; this.children = []; }
  get textContent() { return this.text + this.children.map(n => n.textContent).join(' '); }
}
const nodes = n => [n, ...n.children.flatMap(nodes)];
const details = n => nodes(n).map(e => e.attrs['aria-label'] || '').filter(Boolean).join(' ');
const styles = n => String(n.attrs.style || '') + n.children.map(styles).join(' ');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
function harness(extraData) {
  let now = Date.parse('2026-09-08T12:00:00Z'), nextId = 0;
  const timers = new Map(), calls = [];
  const window = {
    AbortController,
    // SN Systems reads health and cron from the localize; both all clear here,
    // so its verdict turns on the uptime poll the fixtures drive.
    snDesktopData: { healthSummary: {passed: 8, total: 8, all_passed: true, skipped: [], flagged: []},
      cronSummary: {total: 85, sn_count: 30, orphans: 0, next: {hook: 'sn_queue_tick', in_s: 300}, health: {ok: true}}, ...(extraData || {}) },
    sntAbilityRunData: { verbs: { 'signal-noise/get-deploy-status': 'GET', 'signal-noise/uptime-status': 'GET',
      'signal-noise/get-rss-stats': 'GET', 'signal-noise/content-queue': 'GET', 'signal-noise/cache-freshness': 'GET' } },
    setTimeout(fn, delay) { const id = ++nextId; timers.set(id, {fn, at: now + delay}); return id; },
    clearTimeout(id) { timers.delete(id); },
    setInterval(fn, delay) { const id = ++nextId; timers.set(id, {fn, at: now + delay, repeat: delay}); return id; },
    clearInterval(id) { timers.delete(id); },
    addEventListener() {}, removeEventListener() {},
    wp: { apiFetch(opts) { return new Promise((resolve, reject) => calls.push({opts, resolve, reject})); } }
  };
  // A document with a visibility state the fixture can flip (#1603).
  const listeners = new Map();
  const document = { hidden: false, createElement: tag => new Element(tag), createElementNS: (ns, tag) => new Element(tag),
    addEventListener(t, fn) { listeners.set(t, [...(listeners.get(t) || []), fn]); },
    removeEventListener(t, fn) { listeners.set(t, (listeners.get(t) || []).filter(f => f !== fn)); },
    dispatch(t) { (listeners.get(t) || []).forEach(fn => fn()); } };
  class Clock extends Date { constructor(...a) { super(...(a.length ? a : [now])); } static now() { return now; } }
  const context = vm.createContext({window, document, Date: Clock, Promise, Error, Math, Number, Array, Object, AbortController});
  for (const name of ['snt-ability-run.js', 'desktop-mode-widget.js', 'desktop-mode-widget-health.js',
    'desktop-mode-widget-queue.js', 'desktop-mode-widget-anchors.js', 'desktop-mode-widget-views.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'), context, {filename: name});
  }
  return {window, document, calls, timers, async tick(ms) {
    const end = now + ms;
    for (;;) {
      const entry = [...timers].filter(([,t]) => t.at <= end).sort((a,b) => a[1].at-b[1].at)[0];
      if (!entry) break;
      const [id,t] = entry; now = t.at; timers.delete(id);
      if (t.repeat) timers.set(id, {...t, at: now + t.repeat});
      t.fn(); await flush();
    }
    now = end; await flush();
  }};
}
const fixtures = [
  {id: 'sn-deploy-status', period: 60000, good: {theme: {current: '12.18.10', state: 'ok'}, plugin: {current: '13.107.3', state: 'ok'}}, marker: '12.18.10'}
];
// SN Uptime folded into SN Systems (sn-health): the uptime poll lives there now,
// painted as one verdict line, so it has its own block below rather than the
// deploy card's footer-and-cue fixture loop.
const systems = {id: 'sn-health', period: 120000, good: {configured: true, fetched_at: '2026-09-08T11:59:30Z',
  rows: [{name: 'example.test', level: 'ok', status: 'up', availability: 100, response_ms: 106}, {name: 'heartbeat', level: 'ok', status: 'up', availability: 99.98, response_ms: null}]}};
const pollerPair = [fixtures[0], systems];
// The deploy card paints its polled reading into its first child; the Check
// for updates button (moved from Quick Actions) sits beside it, never repainted.
const view = root => root.firstChild;
async function run() {
  // The third argument can cancel but cannot override the annotation verb/path.
  const h = harness(), controller = new AbortController();
  h.window.sntAbilityRun('uptime-status', {detail: true}, {signal: controller.signal, method: 'DELETE', path: '/bad'});
  assert.equal(h.calls[0].opts.signal, controller.signal, 'runner must forward cancellation signal');
  assert.equal(h.calls[0].opts.method, 'GET');
  assert.match(h.calls[0].opts.path, /input%5Bdetail%5D=true/);
  assert.ok(!h.calls[0].opts.path.includes('/bad'));
  if (process.argv.includes('--runner-only')) { console.log('PASS: runner cancellation/verb/input contract'); return; }
  for (const f of fixtures) {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets[f.id](root);
    await flush();
    x.calls[0].resolve(f.good); await flush();
    assert.match(root.textContent, new RegExp(f.marker));
    const goodCard = view(root).firstChild, goodText = root.textContent, goodStyles = styles(root);
    await x.tick(f.period);
    assert.equal(view(root).firstChild, goodCard, 'ordinary refresh must not replace the rendered card');
    assert.equal(root.textContent, goodText, 'ordinary refresh is silent, including recency metadata');
    assert.equal(styles(root), goodStyles, 'ordinary refresh must not recolor retained data');
    x.calls[1].reject({code: 'sn_mcp_read_rate_limited', message: 'Rate limited', data: {status: 429, retry_after: 180}}); await flush();
    assert.match(root.textContent, new RegExp(f.marker), 'last successful data survives 429');
    assert.doesNotMatch(root.textContent, /stale|unavailable|retry after|Rate limited/i, 'failure details do not consume a row');
    assert.match(details(root), /Current status unavailable: Rate limited/);
    const cue = nodes(root).find(n => n.attrs['aria-label']);
    assert.equal(cue.tag, 'span');
    assert.equal(cue.attrs.role, 'img');
    assert.equal(cue.attrs.tabindex, '0', 'failure details are keyboard discoverable');
    assert.equal(cue.title, cue.attrs['aria-label']);
    assert.equal(view(root).children.length, 2, 'failed refresh keeps the card and adds a failure footer');
    assert.doesNotMatch(goodText, /Last successful refresh|Last good reading|\d{4}-\d\d-\d\dT/, 'a current reading carries no recency footer and no raw timestamp');
    assert.match(root.textContent, /Last good reading (just now|\d+ (min|h) ago) · retrying/, 'failure says in words how old the kept reading is');
    assert.doesNotMatch(styles(root), /#3fb950/, 'stale data must not look currently green');
    { const before = root.textContent; await x.tick(120000); assert.notEqual(root.textContent, before, 'the last-good age is kept current while the failure footer shows'); assert.match(root.textContent, /Last good reading \d+ min ago/); }
    await x.tick(59999); assert.equal(x.calls.length, 2, 'no retry before data.retry_after');
    await x.tick(1); assert.equal(x.calls.length, 3);
    x.calls[2].resolve(f.good); await flush();
    assert.doesNotMatch(root.textContent, /stale|unavailable/i, 'successful recovery clears failure state');
    assert.equal(details(root), '', 'successful recovery removes the accessible failure cue');
    assert.doesNotMatch(root.textContent, /Last good reading|Last successful refresh/, 'success removes the failure footer');
    await x.tick(f.period); assert.equal(x.calls.length, 4, 'success resets ordinary cadence');
    await x.tick(f.period * 3); assert.equal(x.calls.length, 4, 'slow request never overlaps another poll');
    assert.doesNotMatch(root.textContent, /stale|refreshing|updating/i, 'pending refresh has no progress narration');
    stop(); assert.equal(x.calls[3].opts.signal.aborted, true, 'teardown aborts pending fetch');
    x.calls[3].resolve(f.good); await flush(); await x.tick(1000000);
    assert.equal(root.textContent, ''); assert.equal(x.timers.size, 0); assert.equal(x.calls.length, 4);
  }
  // Contract 13: the Core row paints beside theme/plugin, same glyph vocabulary.
  {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-deploy-status'](root); await flush();
    x.calls[0].resolve({theme: {current: '1', state: 'ok'}, plugin: {current: '2', state: 'ok'},
      core: {current: '7.1.2', latest: '7.1.3', state: 'behind', offer: 'point', reason: 'A point release is waiting.'}}); await flush();
    const all = nodes(root), label = all.find(n => n.text === 'Core');
    assert.ok(label, 'a Core label renders');
    const grid = label.parentNode, i = grid.children.indexOf(label);
    assert.equal(grid.children[i + 1].text, '7.1.2 · behind (point)', 'the Core row shows current and behind (point)');
    assert.equal(grid.children[i + 2].text, '↑', 'behind paints the amber arrow');
    assert.match(grid.children[i + 2].title, /point release is waiting/, 'the reason rides the glyph');
    stop();
    const y = harness(), r2 = new Element('div');
    const stop2 = y.window.desktopModeWidgets['sn-deploy-status'](r2); await flush();
    y.calls[0].resolve({theme: {current: '1', state: 'ok'}, plugin: {current: '2', state: 'ok'}}); await flush();
    assert.ok(!nodes(r2).some(n => n.text === 'Core'), 'an older payload without core paints no Core row');
    stop2();
    // 19.8.0: an unchecked core (update check missing from the cache) reads muted, not '?'.
    const z = harness(), r3 = new Element('div');
    const stop3 = z.window.desktopModeWidgets['sn-deploy-status'](r3); await flush();
    z.calls[0].resolve({theme: {current: '1', state: 'ok'}, plugin: {current: '2', state: 'ok'},
      core: {current: '7.1.2', latest: '7.1.2', state: 'unknown', offer: '', reason: 'WordPress\'s core update check is not in the cache right now.'}}); await flush();
    const l3 = nodes(r3).find(n => n.text === 'Core'), g3 = l3.parentNode, j = g3.children.indexOf(l3);
    assert.equal(g3.children[j + 1].text, '7.1.2 · update check not cached', 'unchecked core says so');
    assert.equal(g3.children[j + 2].text, '–', 'unchecked core paints a muted dash, not ?');
    assert.match(g3.children[j + 2].title, /not in the cache/, 'the reason rides the glyph');
    stop3();
    // No version means the status module itself failed: keep the red '?', no sentence.
    const w = harness(), r4 = new Element('div');
    const stop4 = w.window.desktopModeWidgets['sn-deploy-status'](r4); await flush();
    w.calls[0].resolve({theme: {current: '1', state: 'ok'}, plugin: {current: '2', state: 'ok'},
      core: {current: '', latest: '', state: 'unknown', offer: '', reason: 'core status module not loaded'}}); await flush();
    const l4 = nodes(r4).find(n => n.text === 'Core'), g4 = l4.parentNode, k = g4.children.indexOf(l4);
    assert.equal(g4.children[k + 1].text, '—', 'a versionless unknown says nothing about the cache');
    assert.equal(g4.children[k + 2].text, '?', 'a versionless unknown keeps the red ?');
    stop4();
  }
  // Good -> pending -> good changes values and recency only after success.
  for (const f of fixtures) {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets[f.id](root); await flush();
    x.calls[0].resolve(f.good); await flush();
    const snapshot = JSON.stringify(root), card = view(root).firstChild;
    await x.tick(f.period);
    assert.equal(JSON.stringify(root), snapshot, 'no DOM attributes, children, styles or text change while pending');
    assert.equal(view(root).firstChild, card);
    const next = JSON.parse(JSON.stringify(f.good));
    if (next.theme) { next.theme.current = '12.18.11'; next.last_deploy = '2 minutes ago'; }
    else { next.rows[0].name = 'changed.test'; }
    x.calls[1].resolve(next); await flush();
    assert.match(root.textContent, /12\.18\.11|changed\.test/, 'successful poll updates content');
    assert.equal(details(root), '');
    if (next.theme) assert.match(root.textContent, /Last deploy: 2 minutes ago/, 'useful deploy recency stays');
    stop();
  }
  // First-load failures, backoff, missing runner, abort rejection, remount.
  for (const f of fixtures) {
    const x = harness(), root = new Element('div');
    let stop = x.window.desktopModeWidgets[f.id](root); await flush();
    x.calls[0].reject({message: '<script>not HTML</script>', data: {status: 429, retry_after: '1'}}); await flush();
    assert.match(root.textContent, /Status unavailable/);
    assert.doesNotMatch(root.textContent, /Retry after|No successful refresh/);
    assert.ok(!root.textContent.includes('<script>'), 'raw error detail is not visible card content');
    assert.match(details(root), /No successful refresh yet/);
    assert.ok(details(root).includes('<script>not HTML</script>'), 'error details stay literal text, not markup');
    assert.doesNotMatch(styles(root), /#3fb950/);
    assert.equal(nodes(root).filter(n => n.attrs.role === 'img').length, 1, 'first failure has one accessible cue');
    await x.tick(f.period); assert.equal(x.calls.length, 2);
    x.calls[1].reject(new Error('network')); await flush();
    await x.tick(f.period * 2 - 1); assert.equal(x.calls.length, 2);
    await x.tick(1); assert.equal(x.calls.length, 3, 'second failure doubles backoff');
    x.calls[2].resolve({}); await flush();
    assert.match(details(root), /Invalid .* response/, 'malformed first load is not a successful empty card');
    await x.tick(f.period * 4); assert.equal(x.calls.length, 4);
    x.calls[3].reject({message: 'bad hint', data: {retry_after: 1e300}}); await flush();
    assert.match(details(root), /bad hint/, 'unrepresentable retry hint cannot crash failure handling');
    assert.equal([...x.timers.values()].filter(t => !t.repeat).length, 1, 'bad retry hint retains bounded backoff (one pending poll; the age ticker is an interval)');
    stop(); await x.tick(1000000); assert.equal(x.calls.length, 4);
    stop = x.window.desktopModeWidgets[f.id](root); await flush();
    assert.equal(x.calls.length, 5, 'remount has fresh state');
    stop(); x.calls[4].reject(Object.assign(new Error('aborted'), {name: 'AbortError'})); await flush();
    assert.equal(root.textContent, ''); assert.equal(x.timers.size, 0);
    const y = harness(), absent = new Element('div');
    const runner = y.window.sntAbilityRun; delete y.window.sntAbilityRun;
    const cleanup = y.window.desktopModeWidgets[f.id](absent); await flush();
    assert.match(absent.textContent, /unavailable/);
    y.window.sntAbilityRun = runner; await y.tick(f.period);
    y.calls[0].resolve(f.good); await flush(); assert.match(absent.textContent, new RegExp(f.marker));
    cleanup();
    // Teardown before the first microtask never even starts the request.
    const z = harness(), empty = new Element('div');
    z.window.desktopModeWidgets[f.id](empty)(); await flush();
    assert.equal(z.calls.length, 0); assert.equal(z.timers.size, 0);
  }
  // A partially malformed deploy payload cannot replace the retained snapshot.
  for (const bad of [{theme: []}, {theme: fixtures[0].good.theme},
    {theme: fixtures[0].good.theme, plugin: []},
    {theme: {current: 123, state: 'ok'}, plugin: fixtures[0].good.plugin}]) {
    const x = harness(), root = new Element('div'), f = fixtures[0];
    const stop = x.window.desktopModeWidgets[f.id](root); await flush();
    x.calls[0].resolve(f.good); await flush();
    await x.tick(f.period); x.calls[1].resolve(bad); await flush();
    assert.match(root.textContent, /12\.18\.10/, 'malformed deploy retains theme version');
    assert.match(root.textContent, /13\.107\.3/, 'malformed deploy retains plugin version');
    assert.match(details(root), /Invalid deploy status response/);
    assert.match(root.textContent, /Last good reading /, 'failure says how old the kept reading is');
    await x.tick(f.period);
    x.calls[2].resolve({theme: {current: '', state: 'unknown'}, plugin: {current: '13.107.3', state: 'unknown'}}); await flush();
    assert.doesNotMatch(root.textContent, /Invalid deploy status response|Stale/,
      'legitimate unknown package status remains a successful response');
    stop();
  }
  // SN Systems: one verdict line when all is up, the last good uptime reading
  // kept through a 429 (and the server's retry_after honored), the monitor
  // named only once it is down, and a malformed row never poisoning the kept reading.
  {
    const x = harness(), root = new Element('div'), f = systems;
    const stop = x.window.desktopModeWidgets[f.id](root); await flush();
    assert.match(root.textContent, /Checking…/, 'the verdict waits for the uptime read');
    x.calls[0].resolve(f.good); await flush();
    assert.match(root.textContent, /All systems normal/);
    assert.match(root.textContent, /2 of 2 up · 99\.99% over 30 days · average 106 ms/, 'the first uptime row condenses the monitors: mean 30-day uptime and mean response time');
    assert.doesNotMatch(root.textContent, /▲|▼/, 'no change on the uptime row: the uptime data carries no prior period');
    assert.doesNotMatch(root.textContent, /example\.test|heartbeat/, 'all up: one line, no row per monitor');
    await x.tick(f.period); assert.equal(x.calls.length, 2);
    x.calls[1].reject({code: 'sn_mcp_read_rate_limited', message: 'Rate limited', data: {status: 429, retry_after: 600}}); await flush();
    assert.match(root.textContent, /2 of 2 up/, 'last good uptime survives a 429');
    assert.match(root.textContent, /Last check failed: Rate limited/);
    assert.doesNotMatch(root.textContent, /All systems normal/, 'a kept reading is not a current all clear');
    await x.tick(599999); assert.equal(x.calls.length, 2, 'no retry before data.retry_after');
    await x.tick(1); assert.equal(x.calls.length, 3);
    x.calls[2].resolve({configured: true, rows: [{name: 'example.test', level: 'alert', status: 'down'}, {name: 'heartbeat', level: 'ok', status: 'up'}]}); await flush();
    assert.match(root.textContent, /1 down/, 'the verdict says what is wrong');
    assert.match(root.textContent, /example\.test/, 'a monitor that is down is named');
    assert.doesNotMatch(root.textContent, /Last check failed/, 'success clears the failure');
    await x.tick(f.period);
    x.calls[3].resolve({configured: true, rows: [null]}); await flush();
    assert.match(root.textContent, /example\.test/, 'invalid row preserves last good uptime data');
    assert.match(root.textContent, /Invalid uptime response/);
    stop(); await x.tick(1000000);
    assert.equal(root.textContent, ''); assert.equal(x.timers.size, 0); assert.equal(x.calls.length, 4);
    // Teardown before the first microtask never even starts the request.
    const z = harness();
    z.window.desktopModeWidgets[f.id](new Element('div'))(); await flush();
    assert.equal(z.calls.length, 0); assert.equal(z.timers.size, 0);
  }
  // Independent widgets share the actual runner without multiplying each
  // other's polls: over ten minutes deploy=11, uptime (SN Systems)=6 including first load.
  {
    const x = harness(); let answered = 0;
    const stops = pollerPair.map(f => x.window.desktopModeWidgets[f.id](new Element('div')));
    const answer = async () => { for (; answered < x.calls.length; answered++) {
      const c = x.calls[answered]; c.resolve(pollerPair[c.opts.path.includes('uptime-status') ? 1 : 0].good);
    } await flush(); };
    await flush(); await answer();
    for (let minute = 0; minute < 10; minute++) { await x.tick(60000); await answer(); }
    assert.equal(x.calls.filter(c => c.opts.path.includes('get-deploy-status')).length, 11);
    assert.equal(x.calls.filter(c => c.opts.path.includes('uptime-status')).length, 6);
    stops.forEach(stop => stop()); assert.equal(x.timers.size, 0);
  }
  // Recipe 2 (#1603): a hidden tab polls nothing; reveal after the period
  // costs exactly one call per widget, a quick flip costs none.
  {
    const x = harness(); let answered = 0;
    const stops = pollerPair.map(f => x.window.desktopModeWidgets[f.id](new Element('div')));
    const answer = async () => { for (; answered < x.calls.length; answered++) {
      const c = x.calls[answered]; c.resolve(pollerPair[c.opts.path.includes('uptime-status') ? 1 : 0].good);
    } await flush(); };
    await flush(); await answer(); assert.equal(x.calls.length, 2);
    x.document.hidden = true; x.document.dispatch('visibilitychange');
    await x.tick(5 * 60000); assert.equal(x.calls.length, 2, 'a hidden tab polls no ability');
    x.document.hidden = false; x.document.dispatch('visibilitychange'); await x.tick(0);
    assert.equal(x.calls.filter(c => c.opts.path.includes('get-deploy-status')).length, 2, 'reveal after the period: one deploy call');
    assert.equal(x.calls.filter(c => c.opts.path.includes('uptime-status')).length, 2, 'reveal after the period: one uptime call');
    await answer();
    x.document.hidden = true; x.document.dispatch('visibilitychange'); await x.tick(1000);
    x.document.hidden = false; x.document.dispatch('visibilitychange'); await x.tick(0);
    assert.equal(x.calls.length, 4, 'a quick tab flip costs no call');
    await x.tick(60000); await answer(); assert.equal(x.calls.length, 5, 'polling resumed on reveal');
    stops.forEach(stop => stop()); assert.equal(x.timers.size, 0);
    x.document.dispatch('visibilitychange'); assert.equal(x.timers.size, 0, 'teardown drops the listener');
  }
  // Recipe 2 for the interval poller (queue; RSS folded into SN Traffic, which does not poll) (#1603
  // repair): a source pin cannot tell a no-op stopPolling from a real one,
  // so each is driven through the same hidden stretch, reveal and quick flip.
  const pollers = [
    {id: 'sn-queue', period: 60000, good: {next: [], published: []}}
  ];
  for (const f of pollers) {
    const x = harness(), root = new Element('div'); let answered = 0;
    const answer = async () => { for (; answered < x.calls.length; answered++) x.calls[answered].resolve(f.good); await flush(); };
    const stop = x.window.desktopModeWidgets[f.id](root); await flush(); await answer();
    assert.equal(x.calls.length, 1, f.id + ': first load is one call');
    x.document.hidden = true; x.document.dispatch('visibilitychange');
    await x.tick(5 * f.period); assert.equal(x.calls.length, 1, f.id + ': a hidden tab polls no ability');
    x.document.hidden = false; x.document.dispatch('visibilitychange'); await x.tick(0);
    assert.equal(x.calls.length, 2, f.id + ': reveal after the period costs one call'); await answer();
    x.document.hidden = true; x.document.dispatch('visibilitychange'); await x.tick(1000);
    x.document.hidden = false; x.document.dispatch('visibilitychange'); await x.tick(0);
    assert.equal(x.calls.length, 2, f.id + ': a quick tab flip costs no call');
    await x.tick(f.period); await answer(); assert.equal(x.calls.length, 3, f.id + ': polling resumed on reveal');
    stop(); await x.tick(10 * f.period); assert.equal(x.calls.length, 3, f.id + ': teardown stops the poll');
    assert.equal(x.timers.size, 0, f.id + ': teardown clears every timer');
  }
  // 2026-10-05: the extra Systems and Provenance rows (local reads, localized).
  {
    const extra = {statusExtra: {
      systems: {edge: {day: 'Oct 4', total: 12, visitor: 2, prior: 15}, cron: {fires: 40, failed: 2, failing: ['sn_queue_tick', 'snt_alerts_hourly', 'sn_x']}, cache: {last_purge: Date.parse('2026-09-08T09:00:00Z') / 1000, fresh: 'stale', headline: '1 stale page'}},
      provenance: {integrity: {fleet: 50, checked: 40, clean: 39, failing: 1, keys: 'keys_missing'}, rights: {month: 'September', text: '8 waiting for a Bitcoin block', attention: false, last_posted: Date.parse('2026-09-05T12:00:00Z') / 1000}, zenodo: {minted: 7, total: 9}}}};
    const x = harness(extra), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
    x.calls[0].resolve({configured: true, rows: [{name: 'Home', level: 'ok', response_ms: 120, incidents_30d: 1}, {name: 'Feed', level: 'ok', response_ms: 340, incidents_30d: 0}]}); await flush();
    const t = root.textContent;
    assert.match(t, /Incidents · 30 days 1/, 'incidents over 30 days across the monitors');
    assert.match(t, /Slowest Feed · 340 ms/, 'the slowest monitor, by name');
    assert.match(t, /5xx · Oct 4 12 · 2 seen by visitors · 3 fewer than the day before/, 'edge 5xx, those a visitor received, against the day before, in words');
    assert.match(t, /Last 24 hours 40 runs recorded · 2 failed/, 'cron runs recorded and recorded failures over 24 hours');
    assert.match(t, /queue_tick failed/, 'a failing job is named');
    assert.match(t, /\+1 more failed/, 'and the list is capped');
    assert.match(t, /Last full purge 3h ago/, 'the last full purge');
    assert.match(t, /Edge freshness 1 stale page/, 'edge freshness after the last check');
    assert.match(t, /3 to look at/, 'a recorded cron failure, 5xx seen by visitors and a stale edge are each something to look at');
    stop();
    const y = harness(extra), proot = new Element('div');
    const pstop = y.window.desktopModeWidgets['sn-anchors'](proot); await flush();
    for (const c of y.calls) {
      if (c.opts.path.includes('anchor-status')) c.resolve({pending: [], recording: [], confirmed: 50, total: 50, pages: {confirmed: 6, total: 6}});
      else c.reject(new Error('not in this fixture'));
    }
    await flush();
    const p = proot.textContent;
    assert.match(p, /Integrity checks 39 of 50 pass · 1 failing · 10 not checked yet/, 'integrity checks that pass, failing, and subjects not reached yet (never counted as passing)');
    assert.match(p, /Rights evidence · September 8 waiting for a Bitcoin block/, 'where the newest rights-evidence month stands');
    assert.match(p, /Provenance Integrity checks/, 'the block has its heading');
    assert.match(p, /Signing key the key file is missing/, 'a fleet-level key finding gets its own row');
    assert.doesNotMatch(p, /verify|Signatures/, 'the row claims what the sweep measures, not a signature re-verify');
    assert.match(p, /Last posted 3d ago/, 'when a record was last posted, in the Systems card\'s form');
    assert.match(p, /DOIs 7 of 9 minted/, 'DOIs minted');
    pstop();
    const z = harness(), zroot = new Element('div');
    const zstop = z.window.desktopModeWidgets['sn-health'](zroot); await flush();
    z.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    assert.match(zroot.textContent, /No complete day in the edge rollup yet/, 'no extra payload: the section says so, never a 0');
    assert.doesNotMatch(zroot.textContent, /Incidents|Slowest|Last 24 hours/, 'rows with no source are left out');
    zstop();
    const v = harness({statusExtra: {systems: {cache: {last_purge: 0, fresh: 'pending', headline: 'Purge dispatched, verifying'}}, provenance: {}}}), vroot = new Element('div');
    const vstop = v.window.desktopModeWidgets['sn-health'](vroot); await flush();
    v.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok', incidents_30d: 0}, {name: 'b', level: 'ok', incidents_30d: null}]}); await flush();
    assert.doesNotMatch(vroot.textContent, /All systems normal/, 'Codex on 8a300aa: a purge still verifying is never an all-clear');
    assert.match(vroot.textContent, /Incidents · 30 days 0 · 1 of 2 monitors read/, 'Codex on 8a300aa: a partial incident count says it is partial');
    vstop();
    const w = harness({statusExtra: {systems: {cron: {fires: 12, failed: 0, failing: []}}, provenance: {}}}), wroot = new Element('div');
    const wstop = w.window.desktopModeWidgets['sn-health'](wroot); await flush();
    w.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    assert.match(wroot.textContent, /Last 24 hours 12 runs recorded/, 'runs recorded');
    assert.doesNotMatch(wroot.textContent, /0 failed/, 'no recorded failure is not painted as "0 failed": a fatal run leaves no row');
    wstop();
  }
  // The derived rows leave out what they cannot compute, never a 0.
  {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
    x.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}, {name: 'b', level: 'ok', availability: null, response_ms: undefined}]}); await flush();
    assert.match(root.textContent, /Monitors 2 of 2 up /, 'no availability and no response time: the row is only the count');
    assert.doesNotMatch(root.textContent, /over 30 days|average|0 ms|NaN/);
    stop();
  }
  // SN Provenance: the top crawler family's share, with its change in points
  // against the prior 30 days, the arrow hidden and the direction in words.
  {
    const mount = async (readers) => {
      const x = harness(), root = new Element('div');
      const stop = x.window.desktopModeWidgets['sn-anchors'](root); await flush();
      for (const c of x.calls) {
        if (c.opts.path.includes('anchor-status')) c.resolve({pending: [], recording: [], confirmed: 50, total: 50, pages: {confirmed: 6, total: 6}});
        else if (c.opts.path.includes('machine-readers')) c.resolve(readers);
        else c.reject(new Error('not in this fixture'));
      }
      await flush();
      return {root, stop};
    };
    const base = {ok: true, days: 30, total: 200, families: [{family: 'unclassified-machine', hits: 88}], ai_training: 40, ai_rights: 19};
    let m = await mount({...base, top_family: {family: 'unclassified-machine', share: 44, prior_share: 41}});
    assert.match(m.root.textContent, /Top crawler family unclassified-machine, 44%▲ up  3 pts/, 'the top family, its share and its change in points');
    const arrow = nodes(m.root).find(n => n.text === '▲');
    assert.equal(arrow.attrs['aria-hidden'], 'true', 'the arrow is hidden from assistive tech');
    assert.equal(nodes(m.root).find(n => n.text === 'up').className, 'screen-reader-text', 'the direction is said in words');
    assert.match(m.root.textContent, /Fetched the rights files directly 19/);
    m.stop();
    m = await mount({...base, top_family: {family: 'unclassified-machine', share: 44, prior_share: null}});
    assert.match(m.root.textContent, /unclassified-machine, 44%/);
    assert.doesNotMatch(m.root.textContent, /▲|▼/, 'no prior window read: no change shown');
    m.stop();
    m = await mount({...base, families: [], total: 0});
    assert.doesNotMatch(m.root.textContent, /Top crawler family/, 'no reads: the row is left out, never 0%');
    m.stop();
  }
  // Codex round 2: an anchor read still pending is loading, not an error.
  {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-anchors'](root); await flush();
    const mr = x.calls.find(c => c.opts.path.includes('machine-readers'));
    const as = x.calls.find(c => c.opts.path.includes('anchor-status'));
    mr.resolve({ok: true, days: 30, total: 10, families: []}); await flush();
    assert.match(root.textContent, /Loading anchor status…/, 'readers first: the anchor part says it is loading');
    assert.ok(!nodes(root).some(n => n.attrs.role === 'alert'), 'no alert while anchor-status is pending');
    assert.doesNotMatch(root.textContent, /Sweep now|unavailable/, 'no Sweep and no error while pending');
    assert.match(root.textContent, /Machine reads 10/, 'the readers paint meanwhile');
    as.reject(new Error('boom')); await flush();
    assert.ok(nodes(root).some(n => n.attrs.role === 'alert' && /boom/.test(n.textContent)), 'the alert only after anchor-status rejects');
    stop();
  }
  // SN Systems: the uptime read is aborted at teardown, and the count is red
  // only when a monitor is down (amber for paused, maintenance, pending).
  {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
    const sig = x.calls[0].opts.signal;
    assert.ok(sig, 'the uptime read carries an abort signal');
    stop(); assert.equal(sig.aborted, true, 'teardown aborts the in-flight uptime read');
    const tone = async (rows) => {
      const y = harness(), r = new Element('div');
      const s2 = y.window.desktopModeWidgets['sn-health'](r); await flush();
      y.calls[0].resolve({configured: true, rows}); await flush();
      const v = nodes(r).find(n => /^\d+ of \d+ up/.test(n.text));
      s2(); return v.attrs.style || '';
    };
    assert.match(await tone([{name: 'a', level: 'ok'}, {name: 'b', level: 'warn'}]), /color:#d29922/, 'a paused or maintenance shortfall is amber');
    assert.match(await tone([{name: 'a', level: 'alert'}, {name: 'b', level: 'warn'}]), /color:#ff9d94/, 'a down monitor is red');
  }
  // Codex round 3: a read from before a Sweep never repaints over the newer one.
  {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-anchors'](root); await flush();
    const find = (from, what) => x.calls.slice(from).find(c => c.opts.path.includes(what));
    const oldReaders = find(0, 'machine-readers');
    find(0, 'anchor-status').resolve({pending: [], recording: [], confirmed: 5, total: 5}); await flush();
    const sweep = nodes(root).find(n => n.tag === 'button' && /Sweep now/.test(n.textContent));
    const before = x.calls.length;
    sweep.handlers.click(); await flush();
    find(before, 'anchor-sweep').resolve({ok: true, upgraded: 1, still_pending: 0}); await flush();
    find(before, 'machine-readers').resolve({ok: true, days: 30, total: 2}); await flush();
    find(before + 1, 'anchor-status').resolve({pending: [], recording: [], confirmed: 6, total: 6}); await flush();
    assert.match(root.textContent, /Machine reads 2/);
    oldReaders.resolve({ok: true, days: 30, total: 1}); await flush();
    assert.match(root.textContent, /Machine reads 2/, 'the pre-Sweep readers answer is dropped');
    assert.doesNotMatch(root.textContent, /Machine reads 1/);
    assert.match(root.textContent, /6 notes/, 'the newer anchor reading stands');
    stop();
  }
  // Codex round 3: SN Traffic polls its payload every five minutes (SN RSS
  // Subscribers' rate), hidden tabs poll nothing, a failure keeps the last
  // good reading and backs off, and teardown aborts the read in flight.
  {
    const x = harness(), root = new Element('div');
    const good = n => ({days: [{date: 'd', views: 1}, {date: 'e', views: 2}], total: n, delta_pct: null, groups: []});
    const stop = x.window.desktopModeWidgets['sn-site-views'](root); await flush();
    const traffic = () => x.calls.filter(c => c.opts.path.includes('site-views'));
    assert.equal(traffic().length, 1, 'one read at mount');
    traffic()[0].resolve(good(100)); await flush();
    assert.match(root.textContent, /100/);
    await x.tick(5 * 60000 - 1); assert.equal(traffic().length, 1, 'no read before five minutes');
    await x.tick(1); assert.equal(traffic().length, 2, 'the payload is read again at five minutes');
    traffic()[1].reject({message: 'Rate limited', data: {status: 429, retry_after: 900}}); await flush();
    assert.match(root.textContent, /100/, 'last good reading kept on failure');
    assert.doesNotMatch(root.textContent, /unavailable/);
    await x.tick(899999); assert.equal(traffic().length, 2, 'no retry before retry_after');
    await x.tick(1); assert.equal(traffic().length, 3);
    traffic()[2].resolve(good(140)); await flush();
    assert.match(root.textContent, /140/, 'a later read repaints');
    x.document.hidden = true; x.document.dispatch('visibilitychange');
    await x.tick(30 * 60000); assert.equal(traffic().length, 3, 'a hidden tab reads nothing');
    x.document.hidden = false; x.document.dispatch('visibilitychange'); await x.tick(0);
    assert.equal(traffic().length, 4, 'reveal after the period catches up with one read');
    const sig = traffic()[3].opts.signal;
    stop(); assert.equal(sig.aborted, true, 'teardown aborts the read in flight');
    await x.tick(60 * 60000); assert.equal(traffic().length, 4); assert.equal(x.timers.size, 0, 'teardown clears every timer');
  }
  console.log('PASS: runner and both widgets — stale/429/recovery/backoff, malformed data, cross-widget cadence, abort/cleanup/remount');
}
run().catch(e => { console.error(e); process.exitCode = 1; });

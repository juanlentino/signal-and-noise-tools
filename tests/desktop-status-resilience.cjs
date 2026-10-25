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
  // Only plain attribute selectors ([a="b"][c="d"]) match; anything else is
  // null, as before, so the cards' other lookups keep their fallbacks.
  querySelector(sel) {
    if (!/^(\[[\w-]+="[^"]*"\])+$/.test(sel)) return null;
    const want = [...sel.matchAll(/\[([\w-]+)="([^"]*)"\]/g)];
    return nodes(this).slice(1).find(n => want.every(([, k, v]) => n.attrs[k] === v)) || null;
  }
  contains(n) { return nodes(this).includes(n); }
  focus() { focused.el = this; }
  remove() { if (this.parentNode) this.parentNode.removeChild(this); }
  insertBefore(n, before) { const i = this.children.indexOf(before); this.children.splice(i < 0 ? this.children.length : i, 0, n); }
  removeChild(n) { this.children.splice(this.children.indexOf(n), 1); }
  get firstChild() { return this.children[0]; }
  set textContent(t) { this.text = t; this.children = []; }
  get textContent() { return this.text + this.children.map(n => n.textContent).join(' '); }
}
const nodes = n => [n, ...n.children.flatMap(nodes)];
// The focused element of the harness in use (document.activeElement).
const focused = {el: null};
const details = n => nodes(n).map(e => e.attrs['aria-label'] || '').filter(Boolean).join(' ');
const styles = n => String(n.attrs.style || '') + n.children.map(styles).join(' ');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
// The machine-reader vocabulary, read through PHP so fixtures carry the real words.
const L = JSON.parse(require('node:child_process').execFileSync('php', ['-r', 'define("ABSPATH","/"); function __($s,$d=null){return $s;} function number_format_i18n($n){return number_format((float)$n);} require ' + JSON.stringify(path.join(__dirname, '../inc/machine-readers-impostor-copy.php')) + '; echo json_encode(snt_mr_reader_labels());']).toString());
function harness(extraData, opts) {
  let now = Date.parse('2026-09-08T12:00:00Z'), nextId = 0;
  const timers = new Map(), calls = [], side = [];
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
    // SN Systems' owner-only re-read of its page-load lines (2026-10-10) rides
    // beside the uptime poll; it gets its own list so the poll's positions hold.
    wp: { apiFetch(opts) { return new Promise((resolve, reject) => (String(opts.path).includes('/desktop/systems') ? side : calls).push({opts, resolve, reject})); } }
  };
  // A document with a visibility state the fixture can flip (#1603).
  const listeners = new Map();
  focused.el = null;
  const document = { get activeElement() { return focused.el; }, set activeElement(n) { focused.el = n; }, hidden: false, createElement: tag => new Element(tag), createElementNS: (ns, tag) => new Element(tag),
    addEventListener(t, fn) { listeners.set(t, [...(listeners.get(t) || []), fn]); },
    removeEventListener(t, fn) { listeners.set(t, (listeners.get(t) || []).filter(f => f !== fn)); },
    dispatch(t) { (listeners.get(t) || []).forEach(fn => fn()); } };
  class Clock extends Date { constructor(...a) { super(...(a.length ? a : [now])); } static now() { return now; } }
  // Zero jitter (assets/snt-poll-cadence.js): the fixtures pin exact waits.
  const NoJitterMath = Object.create(Math); NoJitterMath.random = () => 0;
  const context = vm.createContext({window, document, Date: Clock, Promise, Error, Math: NoJitterMath, Number, Array, Object, AbortController});
  // The card kit only where a fixture asks: its bars and dots are spans the
  // fake textContent joins with spaces, which would move every other regex.
  for (const name of [...((opts && opts.kit) ? ['desktop-mode-card-kit.js'] : []), ...((opts && opts.calendar) ? ['desktop-mode-queue-calendar.js'] : []), 'snt-poll-cadence.js', 'snt-ability-run.js', 'desktop-mode-widget.js', 'desktop-mode-widget-health.js',
    'desktop-mode-widget-queue.js', 'desktop-mode-widget-anchors.js', 'desktop-mode-widget-views.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'), context, {filename: name});
  }
  return {window, document, calls, side, timers, async tick(ms) {
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
  rows: [{name: 'example.test', level: 'ok', status: 'up', availability: 100, response_ms: 106, median_ms: 106, max_ms: 28400, max_region: 'Asia', max_at: '2026-09-08T07:00:00Z'}, {name: 'heartbeat', kind: 'heartbeat', level: 'ok', status: 'up', availability: 99.98, response_ms: null, median_ms: null, max_ms: null}]}};
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
    assert.equal(g4.children[k + 1].text, 'not read', 'a versionless unknown says the version was not read (an em dash until 2026-10-12), and nothing about the cache');
    assert.equal(g4.children[k + 2].text, '?', 'a versionless unknown keeps the red ?');
    stop4();
    // 2026-10-11 (owner, on 2026.10.2: "the contract of the Remote MCP is weird"):
    // the row is the version alone; a match lives in the check's title, and only
    // a mismatch says the contract, as one amber line naming both sides.
    // A pre-0.5.0 worker reports no contract (contract_live '', match false):
    // the line says '?' for the live side, never 0 or a blank.
    for (const [match, live, glyph] of [[true, '16', '✓'], [false, '15', '↑'], [false, '', '↑']]) {
      const c = harness(), rc = new Element('div');
      const stopc = c.window.desktopModeWidgets['sn-deploy-status'](rc); await flush();
      c.calls[0].resolve({theme: {current: '1', state: 'ok'}, plugin: {current: '2', state: 'ok'}, workers: [
        {id: 'sn-remote-mcp', label: 'Remote MCP', live: '2.2.0', latest: '2.2.0', state: 'ok', contract_live: live, contract_expected: '16', contract_match: match},
        {id: 'sn-analytics', label: 'Analytics', live: '1.25.0', latest: '1.25.0', state: 'ok'}]}); await flush();
      const lc = nodes(rc).find(n => n.text === 'Remote MCP'), gc = lc.parentNode, kc = gc.children.indexOf(lc);
      assert.equal(gc.children[kc + 1].text, '2.2.0', 'the Remote MCP row is its version alone');
      assert.equal(gc.children[kc + 2].text, glyph, match ? 'a matching contract keeps the check' : 'a contract mismatch is amber');
      if (match) {
        assert.match(String(gc.children[kc + 2].title), /contract 16 matches this plugin/, 'the match lives in the check\'s title');
        assert.doesNotMatch(rc.textContent, /contract/, 'a match paints no contract text');
      } else {
        const line = nodes(rc).find(n => n.text === `Remote MCP contract ${live || '?'}; this plugin expects 16.`);
        assert.ok(line && /#d29922/.test(line.attrs.style || ''), 'a mismatch is one amber line naming both sides');
      }
      const la = nodes(rc).find(n => n.text === 'Analytics');
      assert.equal(la.parentNode.children[la.parentNode.children.indexOf(la) + 1].text, '1.25.0', 'a worker with no contract says nothing about one');
      stopc();
    }
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
    assert.match(details(root), /sent an incomplete/, 'malformed first load is not a successful empty card, and says so in words');
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
    assert.match(details(absent), /status reader did not load; reload the desktop/, 'a missing runner is named in words');
    assert.doesNotMatch(details(absent) + absent.textContent, /sntAbilityRun/, 'no function name reaches the reader');
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
    assert.match(details(root), /The site sent an incomplete deploy status/);
    assert.match(root.textContent, /Last good reading /, 'failure says how old the kept reading is');
    await x.tick(f.period);
    x.calls[2].resolve({theme: {current: '', state: 'unknown'}, plugin: {current: '13.107.3', state: 'unknown'}}); await flush();
    assert.doesNotMatch(root.textContent, /incomplete deploy status|Stale/,
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
    assert.match(root.textContent, /Monitors 2 of 2 up Availability · 30 days 99\.99% Response · 24 h median 106 ms /, '2026-10-14 (owner): one figure per row, labelled like Incidents and Slowest; the response figure is the median, never the mean');
    assert.doesNotMatch(root.textContent, / · 99\.99%| over 30 days| over 24 h/, 'nothing is chained onto the Monitors row any more');
    assert.match(root.textContent, /Slowest 28 s from Asia · 5h ago/, 'the slowest single check, in words: how long, where it ran, when; one monitor needs no name');
    assert.doesNotMatch(root.textContent, /average/, 'no mean is painted anywhere');
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
    assert.match(root.textContent, /The site sent an incomplete uptime reading/);
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
    x.calls[0].resolve({configured: true, rows: [{name: 'Home', level: 'ok', median_ms: 120, max_ms: 900, max_region: 'the US', incidents_30d: 1}, {name: 'Feed', level: 'ok', median_ms: 340, max_ms: 4200, max_region: 'Europe', incidents_30d: 0}]}); await flush();
    const t = root.textContent;
    assert.match(t, /Incidents · 30 days 1/, 'incidents over 30 days across the monitors');
    assert.match(t, /Slowest Feed · 4\.2 s from Europe/, 'the slowest check across monitors names its monitor; no time read, none painted');
    assert.match(t, /Response · 24 h medians 120 ms to 340 ms/, 'several monitors: their medians as a range, never a mean of medians called a median');
    assert.match(t, /5xx · Oct 4 12 Visitors, direct 2 Day before 15/, 'edge 5xx: the count, the visitors among them and the day before, as short rows that fit beside Cache');
    assert.doesNotMatch(t, /Your dashboard/, 'no dashboard count from the payload: no split row');
    assert.match(t, /Last 24 hours 40 runs recorded · 2 failed/, 'cron runs recorded and recorded failures over 24 hours');
    // The page-load lines are re-read beside the poll, and the answer repaints them.
    assert.equal(x.side.length, 1, 'SN Systems re-reads its page-load lines once per poll');
    x.side[0].resolve({healthSummary: {passed: 8, total: 8, all_passed: true, skipped: [], flagged: []}, cronSummary: extra.statusExtra ? {total: 85, sn_count: 30, orphans: 0, next: {hook: 'sn_queue_tick', in_s: 300}, health: {ok: true}} : {},
      statusExtra: {systems: {edge: {day: 'Oct 5', total: 4, visitor: 0, prior: 12}, cron: {fires: 41, failed: 0, failing: []}, cache: extra.statusExtra.systems.cache}, provenance: {}}}); await flush();
    assert.match(root.textContent, /5xx · Oct 5 4 Visitors, direct 0 Day before 12/, 'a re-read moves the edge line without a reload');
    assert.match(root.textContent, /Last 24 hours 41 runs recorded/, 'and the cron day line');
    assert.doesNotMatch(root.textContent, /not re-read/, 'a re-read that answered says nothing about itself');
    // 2026-10-12 audit: a re-read that fails keeps the lines and says so, as
    // a failed uptime check does, instead of passing for current.
    await x.tick(systems.period);
    x.calls[1].resolve({configured: true, rows: [{name: 'Home', level: 'ok', response_ms: 120, incidents_30d: 1}]});
    x.side[1].reject(new Error('Failed to fetch')); await flush();
    assert.match(root.textContent, /5xx · Oct 5 4/, 'a failed re-read keeps the lines it had');
    assert.match(root.textContent, /Health, Cron, Edge and Cache not re-read; showing the \d{1,2}:\d\d( [AP]M)? reading/, 'and says they are the last reading, with its time');
    // Review on #2023: every line the failed re-read feeds is named, the
    // Analytics, Watches and Credentials lines too when the card shows them.
    await x.tick(systems.period);
    x.calls[2].resolve({configured: true, rows: [{name: 'Home', level: 'ok', response_ms: 120, incidents_30d: 1}]});
    x.side[2].resolve({healthSummary: {passed: 8, total: 8, all_passed: true, skipped: [], flagged: []}, cronSummary: {total: 85, sn_count: 30, orphans: 0, next: {hook: 'sn_queue_tick', in_s: 300}, health: {ok: true}}, statusExtra: {systems: {edge: {day: 'Oct 5', total: 4, visitor: 0, prior: 12}, cron: {fires: 41, failed: 0, failing: []}, cache: extra.statusExtra.systems.cache,
      analytics2: {ok: true, days: 5, since: 'Oct 5', last: 'Oct 8', reset: '', frozen: false, bad: false, why: ''}, watches: [{id: 'a', label: 'a watch'}], credentials: {keyring: [{id: 'site_secret', verdict: 'refused'}], cloudflare: null}}, provenance: {}}}); await flush();
    await x.tick(systems.period);
    x.calls[3].resolve({configured: true, rows: [{name: 'Home', level: 'ok', response_ms: 120, incidents_30d: 1}]});
    x.side[3].reject(new Error('Failed to fetch')); await flush();
    assert.match(root.textContent, /Health, Cron, Edge, Cache, Analytics, Watches and Credentials not re-read; showing the /, 'a failed re-read names every line it feeds that the card shows');
    assert.doesNotMatch(root.textContent, /All systems normal/, 'a kept reading is not a current all clear');
    await x.tick(systems.period);
    x.calls[4].resolve({configured: true, rows: [{name: 'Home', level: 'ok', response_ms: 120, incidents_30d: 1}]});
    x.side[4].resolve({healthSummary: {passed: 8, total: 8, all_passed: true, skipped: [], flagged: []}, cronSummary: {total: 85, sn_count: 30, orphans: 0, next: {hook: 'sn_queue_tick', in_s: 300}, health: {ok: true}}, statusExtra: {systems: {edge: {day: 'Oct 5', total: 4, visitor: 0, prior: 12}, cron: {fires: 41, failed: 0, failing: []}, cache: extra.statusExtra.systems.cache}, provenance: {}}}); await flush();
    assert.doesNotMatch(root.textContent, /not re-read/, 'the next good re-read clears the line');
    assert.match(t, /queue_tick failed/, 'a failing job is named');
    assert.match(t, /\+1 more failed/, 'and the list is capped');
    assert.match(t, /Purged 3h ago/, 'the last full purge');
    assert.match(t, /Freshness 1 stale page/, 'edge freshness after the last check');
    assert.match(t, /3 to look at: queue_tick \+2 more/, 'a recorded cron failure, edge 5xx and a stale edge are each something to look at (Codex on 3104cf9: forwarded visitor 5xx read as Worker rows); the headline names the first by its amber row and counts the rest (2026-10-16)');
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
    assert.match(p, /DOIs 7 of 9/, 'DOIs minted, of all');
    assert.doesNotMatch(p, /Provenance Integrity checks[^]*DOIs/, '2026-10-10: DOIs left the Provenance block for the Internet Archive pair');
    assert.doesNotMatch(p, /Deposits failed/, 'no failed deposit: no line');
    pstop();
    // 2026-10-05: each link closes the section it opens.
    const lk = harness({...extra, pages: {provenance: '/prov', machine_readers: '/mr'}}), lroot = new Element('div');
    const lstop = lk.window.desktopModeWidgets['sn-anchors'](lroot); await flush();
    for (const c of lk.calls) {
      if (c.opts.path.includes('anchor-status')) c.resolve({pending: [], recording: [], confirmed: 50, total: 50, pages: {confirmed: 6, total: 6}});
      else if (c.opts.path.includes('machine-readers')) c.resolve({ok: true, days: 30, total: 120});
      else c.reject(new Error('not in this fixture'));
    }
    await flush();
    const parentOf = text => nodes(lroot).find(n => n.children.some(c => 'a' === c.tag && c.text === text));
    const heads = box => box ? box.children.filter(c => 'heading' === c.attrs.role).map(c => c.text).join() : '';
    assert.equal(heads(parentOf('Open Provenance')), 'Provenance', 'Open Provenance sits in the Provenance section');
    const mrParent = parentOf('Machine readers in S&N Analytics');
    assert.match(heads(mrParent), /Machine readers/, 'the Machine readers link sits in the Machine readers section');
    lstop();
    const n0 = harness(), n0root = new Element('div');
    const n0stop = n0.window.desktopModeWidgets['sn-health'](n0root); await flush();
    n0.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    assert.doesNotMatch(n0root.textContent, /Edge|Cache/, 'no owner payload (another role): no Edge or Cache section at all');
    n0stop();
    const z = harness({statusExtra: {systems: {}, provenance: {}}}), zroot = new Element('div');
    const zstop = z.window.desktopModeWidgets['sn-health'](zroot); await flush();
    z.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    assert.match(zroot.textContent, /No complete day in the edge rollup yet/, 'no extra payload: the section says so, never a 0');
    assert.doesNotMatch(zroot.textContent, /All systems normal/, 'Codex on 5aa3fce: an edge source with no measurement is never an all-clear');
    assert.match(zroot.textContent, /No purge recorded yet/, 'no purge record: said');
    assert.match(zroot.textContent, /3 not measured/, 'Codex on 3104cf9 and b872a9f: no cache evidence, no edge day and no cron-history read are each not measured');
    assert.doesNotMatch(zroot.textContent, /Incidents|Slowest/, 'rows with no source are left out');
    zstop();
    const v = harness({statusExtra: {systems: {cache: {last_purge: 0, fresh: 'pending', headline: 'Purge dispatched, verifying'}}, provenance: {}}}), vroot = new Element('div');
    const vstop = v.window.desktopModeWidgets['sn-health'](vroot); await flush();
    v.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok', incidents_30d: 0}, {name: 'b', level: 'ok', incidents_30d: null}]}); await flush();
    assert.doesNotMatch(vroot.textContent, /All systems normal/, 'Codex on 8a300aa: a purge still verifying is never an all-clear');
    assert.match(vroot.textContent, /Incidents · 30 days 0 · 1 of 2 monitors read/, 'Codex on 8a300aa: a partial incident count says it is partial');
    vstop();
    const q = harness({statusExtra: {systems: {}, provenance: {}}}), qroot = new Element('div');
    const qstop = q.window.desktopModeWidgets['sn-health'](qroot); await flush();
    q.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok', median_ms: 100, max_ms: 150}, {name: 'b', level: 'ok', median_ms: 300, max_ms: 700}, {name: 'c', level: 'ok', median_ms: null, max_ms: null}]}); await flush();
    assert.match(qroot.textContent, /Slowest b · 700 ms · 2 of 3 monitors timed/, 'Codex on 5aa3fce: the slowest of a partial read says how many were compared; no region read, none painted');
    qstop();
    const eq = harness({statusExtra: {systems: {}, provenance: {}}}), eqroot = new Element('div');
    const eqstop = eq.window.desktopModeWidgets['sn-health'](eqroot); await flush();
    eq.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok', median_ms: 120, max_ms: 150}, {name: 'b', level: 'ok', median_ms: 120, max_ms: 31000}]}); await flush();
    assert.match(eqroot.textContent, /Response · 24 h median 120 ms /, 'equal medians print one value, never "120 to 120"');
    assert.doesNotMatch(eqroot.textContent, /120 ms to 120 ms|120 to 120/, 'no degenerate range');
    eqstop();
    const sec = harness({statusExtra: {systems: {}, provenance: {}}}), secroot = new Element('div');
    const secstop = sec.window.desktopModeWidgets['sn-health'](secroot); await flush();
    sec.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok', median_ms: 1500, max_ms: 31000}]}); await flush();
    assert.match(secroot.textContent, /Response · 24 h median 1\.5 s /, 'a median past a second reads in seconds, the same as Slowest');
    secstop();
    const hb = harness({statusExtra: {systems: {edge: {day: 'Oct 4', failed: true}}, provenance: {}}}), hbroot = new Element('div');
    const hbstop = hb.window.desktopModeWidgets['sn-health'](hbroot); await flush();
    hb.calls[0].resolve({configured: true, rows: [{name: 'a', kind: 'monitor', level: 'ok', median_ms: 100, max_ms: 200}, {name: 'b', kind: 'monitor', level: 'ok', median_ms: 300, max_ms: 600}, {name: 'beat', kind: 'heartbeat', level: 'ok', median_ms: null, max_ms: null}]}); await flush();
    assert.match(hbroot.textContent, /Slowest b · 600 ms/, 'the slowest check of two timed monitors');
    assert.match(hbroot.textContent, /Response · 24 h medians 100 ms to 300 ms/, 'the range');
    assert.doesNotMatch(hbroot.textContent, /of 3 monitors timed/, 'Codex on b872a9f: a heartbeat is not counted as an untimed monitor');
    assert.match(hbroot.textContent, /The 5xx read for Oct 4 failed/, 'Codex on b872a9f: a failed newest day is said, not replaced by an older one');
    assert.match(hbroot.textContent, /Last 24 hours could not be read/, 'Codex on b872a9f: a failed cron-history read is said');
    assert.doesNotMatch(hbroot.textContent, /All systems normal/, 'and neither is an all-clear');
    hbstop();
    const w = harness({statusExtra: {systems: {cron: {fires: 12, failed: 0, failing: []}}, provenance: {}}}), wroot = new Element('div');
    const wstop = w.window.desktopModeWidgets['sn-health'](wroot); await flush();
    w.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    assert.match(wroot.textContent, /Last 24 hours 12 runs recorded/, 'runs recorded');
    {
      const r = harness({statusExtra: {systems: {cron: {fires: 2471, failed: 0, failing: []}}, provenance: {}}}), rroot = new Element('div');
      const rstop = r.window.desktopModeWidgets['sn-health'](rroot); await flush();
      r.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
      assert.match(rroot.textContent, /Last 24 hours 2,471 runs recorded/, 'runs recorded are grouped');
      rstop();
    }
    assert.doesNotMatch(wroot.textContent, /0 failed/, 'no recorded failure is not painted as "0 failed": a fatal run leaves no row');
    wstop();
    // 2026-10-05: a yellow line names where it is fixed; paused and verifying are not faults.
    const links = n => nodes(n).filter(e => 'a' === e.tag).map(e => e.text + '|' + e.href);
    const credit = {label: 'Time-relative drift', reason: 'Bad Request (400) - Your credit balance is too low to access the Anthropic API.'};
    const fx = harness({pages: {health: '/h', cron: '/c', cloudflare: '/cf'}, healthSummary: {passed: 17, total: 18, skipped: [credit], flagged: []},
      statusExtra: {systems: {edge: {day: 'Oct 4', total: 18, prior: 27}, cron: {fires: 9, failed: 0, failing: []}, cache: {last_purge: Date.parse('2026-09-08T11:58:00Z') / 1000, fresh_time: Date.parse('2026-09-08T11:58:00Z') / 1000, fresh: 'pending', headline: 'Purge dispatched, verifying'}}, provenance: {}}}), fxroot = new Element('div');
    const fxstop = fx.window.desktopModeWidgets['sn-health'](fxroot); await flush();
    fx.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    const ft = fxroot.textContent;
    assert.match(ft, /Time-relative drift paused: AI credit out/, 'a check refused for credit is said, as paused');
    assert.doesNotMatch(ft, /could not run/, 'and is not counted as a check that could not run');
    assert.match(ft, /1 to look at: 5xx Oct 4 · 1 verifying · 1 paused/, 'the 5xx stays to look at; a purge verifying within its window and a paused check are said apart');
    assert.doesNotMatch(ft, /not measured/, 'a purge two minutes old is verifying, not unmeasured');
    const fl = links(fxroot);
    assert.ok(fl.includes('Open Cloudflare|/cf'), 'the 5xx line links to where the paths are');
    assert.ok(fl.includes('Open Anthropic billing|https://console.anthropic.com/settings/billing'), 'the paused check links to where it is fixed');
    assert.ok(!fl.some(l => l.startsWith('Open Cron')), 'a section with nothing in the headline gets no fix link');
    assert.equal(fl.filter(l => l.startsWith('Open Health')).length, 1, 'Open Health appears once, in the Health section, never again on the bottom row');
    const hp = nodes(fxroot).find(n => n.children.some(c => 'a' === c.tag && c.text === 'Open Health'));
    assert.ok(hp && hp.children.some(c => 'heading' === c.attrs.role && 'Health' === c.text), 'and it closes the Health section');
    assert.match(ft, /opens in a new tab/, 'an off-site link says it opens a new tab');
    // 2026-10-12 (batch 10): measured live, an off-site link opened a new browser tab and every S&N link
    // was taken by the shell's link interceptor into the native window. Both halves hold only while the
    // anchors stay this shape: target=_blank + noopener off-site, and no target on an S&N link (a
    // same-origin new tab relaunches the installed app instead of opening a window).
    const anchors = nodes(fxroot).filter(e => 'a' === e.tag);
    const offsite = anchors.filter(e => /^https?:/.test(e.href));
    assert.ok(offsite.length > 0 && offsite.every(a => '_blank' === a.target && /\bnoopener\b/.test(a.rel || '')), 'every off-site link opens a new tab, with noopener');
    assert.ok(anchors.length > offsite.length && anchors.filter(a => !offsite.includes(a)).every(a => !a.target), 'an S&N link carries no target, so the shell opens it as the native window');
    // Codex on #1927: a keyboard user on a section link keeps focus through the two-minute repaint.
    const fl0 = nodes(fxroot).find(n => 'a' === n.tag && n.text === 'Open Cloudflare');
    fl0.tagName = 'A';
    fx.document.activeElement = fl0;
    const saved = {contains: Element.prototype.contains, focus: Element.prototype.focus};
    Element.prototype.contains = function(n) { return nodes(this).includes(n); };
    Element.prototype.querySelectorAll = function(sel) { return 'a' === sel ? nodes(this).filter(n => 'a' === n.tag).map(n => (n.tagName = 'A', n)) : []; };
    Element.prototype.focus = function() { fx.document.activeElement = this; };
    await fx.tick(2 * 60 * 1000 + 1000);
    fx.calls[fx.calls.length - 1].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    const now = fx.document.activeElement;
    assert.ok(now && now !== fl0 && 'Open Cloudflare' === now.text && nodes(fxroot).includes(now), 'focus moves to the rebuilt link, not to nowhere');
    Object.assign(Element.prototype, saved); delete Element.prototype.querySelectorAll;
    fxstop();
    const sp = harness({statusExtra: {systems: {edge: {day: 'Oct 4', total: 18, prior: 27, dashboard: 14}}, provenance: {}}}), sproot = new Element('div');
    const spstop = sp.window.desktopModeWidgets['sn-health'](sproot); await flush();
    sp.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    assert.match(sproot.textContent, /5xx · Oct 4 18 Day before 27/, 'the count stays whole');
    assert.match(sproot.textContent, /Your dashboard 14/, 'and says how much of it was the dashboard');
    spstop();
    const old = harness({pages: {cloudflare: '/cf'}, statusExtra: {systems: {cache: {last_purge: Date.parse('2026-09-08T11:59:00Z') / 1000, fresh_time: Date.parse('2026-09-08T11:00:00Z') / 1000, fresh: 'pending', headline: 'Purge dispatched, verifying'}}, provenance: {}}}), oldroot = new Element('div');
    const oldstop = old.window.desktopModeWidgets['sn-health'](oldroot); await flush();
    old.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    assert.doesNotMatch(oldroot.textContent, /verifying ·|\d verifying/, 'an hour-old pending report is not in progress any more, even when a manual purge just moved the ledger (Codex on #1925)');
    assert.match(oldroot.textContent, /not measured/, 'it is unmeasured, and amber');
    oldstop();
    const fut = harness({statusExtra: {systems: {cache: {last_purge: 0, fresh_time: Date.parse('2026-09-08T13:00:00Z') / 1000, fresh: 'pending', headline: 'Purge dispatched, verifying'}}, provenance: {}}}), futroot = new Element('div');
    const futstop = fut.window.desktopModeWidgets['sn-health'](futroot); await flush();
    fut.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    assert.doesNotMatch(futroot.textContent, /\d verifying/, 'Codex on #1925: a report an hour in the future is broken timing, not verifying');
    assert.match(futroot.textContent, /3 not measured/, 'it is unmeasured, beside the empty edge and cron reads');
    futstop();
    const mix = harness({pages: {health: '/h'}, healthSummary: {passed: 16, total: 18, skipped: [credit], flagged: [{label: 'Broken links', count: 2}]}}), mixroot = new Element('div');
    const mixstop = mix.window.desktopModeWidgets['sn-health'](mixroot); await flush();
    mix.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
    const ml = links(mixroot);
    assert.ok(ml.includes('Open Health|/h') && ml.some(l => l.startsWith('Open Anthropic billing')), 'Codex on #1925: a finding beside a paused check gets the Health link and keeps the billing link');
    mixstop();
  }
  // 2026-10-25: a job that failed once and has run fine since is said, not looked at.
  {
    const x = harness({statusExtra: {systems: {cron: {fires: 2924, failed: 2, failing: ['sn_still_bad'], recovered: ['sn_prov_orphan_sweep']}}, provenance: {}}}), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
    x.calls[0].resolve({configured: true, rows: [{name: 'Home', level: 'ok', median_ms: 120, max_ms: 900, max_region: 'the US', incidents_30d: 0}]}); await flush();
    const t = root.textContent;
    assert.match(t, /Last 24 hours 2,924 runs recorded · 2 failed/, 'every recorded failure still counts in the day line');
    assert.match(t, /prov_orphan_sweep failed, ran fine since/, 'a recovered job is named with what happened after');
    assert.match(t, /still_bad failed/, 'a job still failing is named as failed');
    assert.match(t, /to look at: still_bad/, 'and only the job still failing is something to look at');
    assert.doesNotMatch(t, /to look at: prov_orphan_sweep/, 'the recovered job never leads the headline');
    stop();
  }
  {
    const x = harness({statusExtra: {systems: {cron: {fires: 2924, failed: 1, failing: [], recovered: ['sn_prov_orphan_sweep']}}, provenance: {}}}), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
    x.calls[0].resolve({configured: true, rows: [{name: 'Home', level: 'ok', median_ms: 120, max_ms: 900, max_region: 'the US', incidents_30d: 0}]}); await flush();
    const t = root.textContent;
    assert.match(t, /prov_orphan_sweep failed, ran fine since/, 'only recovered jobs: said');
    assert.doesNotMatch(t, /to look at: prov_orphan_sweep|to look at: Last 24 hours/, 'and nothing in cron is something to look at');
    stop();
  }
  // 2026-10-10: S&N jobs of all, the visitor 5xx, Edge beside Cache, the
  // analytics 2.0 verdict, watches due and failing credentials.
  {
    const clean = {edge: {day: 'Oct 9', total: 0, visitor: 0, prior: 0}, cron: {fires: 5, failed: 0, failing: []}, cache: {last_purge: Date.parse('2026-09-08T09:00:00Z') / 1000, fresh: 'fresh', headline: 'All fresh'}};
    const mount = async (systems, opts) => {
      const x = harness({pages: {analytics: '/an', credentials: '/cr', cloudflare: '/cf'}, statusExtra: {systems: {...clean, ...systems}, provenance: {}}}, opts), root = new Element('div');
      const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
      x.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}]}); await flush();
      return {root, stop, t: root.textContent, links: nodes(root).filter(e => 'a' === e.tag).map(e => e.text + '|' + e.href), verdict: nodes(root).find(n => 'status' === n.attrs.role)};
    };
    const valueOf = (root, text) => nodes(root).find(n => 'span' === n.tag && n.text === text);
    let m = await mount({analytics2: {ok: true, days: 5, since: 'Oct 5', last: 'Oct 8', reset: '', frozen: false, bad: false, why: ''}, watches: [], credentials: {keyring: [], cloudflare: null}});
    assert.match(m.t, /S&N jobs · 30 of 85 scheduled next: queue_tick/, 'the cron headline counts S&N jobs of every scheduled event');
    assert.match(m.t, /5xx · Oct 9 0 Day before 0/, 'a day with no 5xx says 0');
    assert.doesNotMatch(m.t, /Visitors/, 'and no "visitors 0 of 0": a day with no 5xx has no visitor row');
    assert.match(m.t, /Cutover check clean since Oct 5 · last exact day Oct 8/, 'the analytics 2.0 verdict: clean since when, and the last exact day (a sampled day after it is neither counted nor a reset)');
    assert.doesNotMatch(m.t, /Watch|Credential|Cloudflare API token/, 'nothing due and nothing failing: no line and no section');
    assert.equal(m.verdict.text, 'All systems normal', 'a good verdict adds nothing to the headline');
    m.stop();
    m = await mount({analytics2: {ok: true, days: 1, since: 'Oct 9', last: 'Oct 9', reset: '', frozen: true, bad: false, why: ''}});
    assert.match(m.t, /Cutover check clean since Oct 9 · last exact day Oct 9 · frozen/, 'a frozen good verdict says frozen');
    m.stop();
    m = await mount({analytics2: {ok: false, days: 0, since: 'Oct 10', reset: 'Oct 9', frozen: false, bad: false, why: 'no complete day has matched since the mismatch before 2026-10-10'}});
    assert.match(m.t, /Cutover check reset Oct 9 · no exact day since/, 'a reset says the day, in plain words');
    assert.doesNotMatch(String(valueOf(m.root, 'reset Oct 9 · no exact day since').attrs.style), /#d29922/, 'a reset nothing on the site can fix is said, never amber');
    assert.equal(m.verdict.text, 'All systems normal', 'and stays out of the headline');
    m.stop();
    m = await mount({analytics2: {ok: false, days: 0, since: 'Oct 5', reset: '', frozen: false, bad: false, why: ''}});
    assert.match(m.t, /Cutover check not verified yet · no exact day so far/, 'never verified: said');
    m.stop();
    m = await mount({analytics2: {ok: false, days: 0, since: 'Oct 5', reset: '', frozen: true, bad: true, why: 'stopped'}});
    assert.match(m.t, /Cutover check legacy write stopped on a bad verdict/, 'the frozen bad verdict is said');
    assert.match(m.verdict.text, /^1 to look at: Cutover check$/, 'and is something to look at, named');
    assert.ok(m.links.includes('Open Analytics|/an'), 'its amber line links to where it is read');
    m.stop();
    m = await mount({analytics2: null});
    assert.match(m.t, /Cutover check no verdict stored yet/, 'no verdict stored: said, never a 0');
    m.stop();
    m = await mount({});
    assert.doesNotMatch(m.t, /Analytics 2.0/, 'an older payload without the key paints no line');
    m.stop();
    m = await mount({watches: [{id: 'a', label: 'wave-4 tool retirement read'}, {id: 'b', label: 'WordPress 7.2'}, {id: 'c', label: 'third'}]});
    assert.match(m.t, /Watch due: wave-4 tool retirement read/, 'a watch due is named');
    assert.match(m.t, /\+1 more due/, 'and the list is capped');
    assert.equal(m.verdict.text, '3 watches due', 'the headline says how many, not as a fault');
    m.stop();
    m = await mount({credentials: {keyring: [{id: 'zenodo_token', verdict: 'refused'}], cloudflare: {status: 'active', expires: 'Oct 20'}}});
    assert.match(m.t, /Credential zenodo_token refused/, 'a failing credential is named, never its value');
    assert.match(m.t, /Cloudflare API token expires Oct 20/, 'a token expiring within 14 days is said');
    assert.match(m.verdict.text, /^2 to look at: Credential zenodo_token \+1 more$/, 'each is something to look at; the first named, the rest counted');
    assert.ok(m.links.includes('Open Credentials|/cr') && m.links.includes('Open Cloudflare|/cf'), 'each amber line links to its fix');
    m.stop();
    m = await mount({credentials: {keyring: [], cloudflare: {status: 'expired', expires: ''}}});
    assert.match(m.t, /Cloudflare API token expired/, 'a token not active says its status');
    m.stop();
    // Edge beside Cache (the card kit's pair), stacked without the kit.
    const boxOf = (root, title) => { const head = nodes(root).find(n => 'heading' === n.attrs.role && n.text === title); return head && head.parentNode; };
    m = await mount({}, {kit: true});
    const edgeBox = boxOf(m.root, 'Edge'), cacheBox = boxOf(m.root, 'Cache');
    assert.equal(edgeBox.parentNode, cacheBox.parentNode, 'Edge and Cache share one row');
    assert.match(String(edgeBox.parentNode.attrs.style), /display:flex;flex-wrap:wrap/, 'as the kit\'s pair');
    assert.doesNotMatch(String(edgeBox.attrs.style || ''), /border-top/, 'a paired column draws no hairline of its own');
    // 2026-10-11: columns share the row equally and wrap their own text; one
    // long value ("Purge dispatched, verifying") must not stack the pair.
    assert.equal(edgeBox.style.flex, '1 1 0', 'a column grows from zero, never from its content width');
    assert.match(String(edgeBox.style.minWidth), /^min\(100%, \d+px\)$/, 'and stacks only below a fixed column width');
    m.stop();
    m = await mount({});
    assert.match(String(boxOf(m.root, 'Edge').attrs.style), /border-top/, 'without the kit each stacks with its own hairline');
    m.stop();
    m = await mount({edge: {day: 'Oct 9', total: 18, prior: 27}});
    assert.match(m.t, /5xx · Oct 9 18 Day before 27/, 'a day stored before who-asked paints no visitor count, never a 0');
    m.stop();
    // 2026-10-14: a day whose every 5xx the installs that day explain (WordPress
    // maintenance 503s on the owner's own open tabs) is said in words, in the
    // text color, and is not something to look at.
    m = await mount({edge: {day: 'Oct 13', total: 5, visitor: 3, prior: 20, installs: 2, explained: true}});
    assert.match(m.t, /5xx · Oct 13 5 · 2 installs that day/, 'an explained day says the installs beside the count');
    assert.doesNotMatch(String(valueOf(m.root, '5 · 2 installs that day').attrs.style), /#d29922/, 'in the text color, never amber');
    assert.equal(m.verdict.text, 'All systems normal', 'and stays out of the headline');
    m.stop();
    m = await mount({edge: {day: 'Oct 13', total: 1, prior: 0, installs: 1, explained: true}});
    assert.match(m.t, /5xx · Oct 13 1 · 1 install that day/, 'one install is singular');
    m.stop();
    m = await mount({edge: {day: 'Oct 13', total: 9, prior: 20, installs: 2, explained: false}});
    assert.match(m.t, /5xx · Oct 13 9 Day before 20/, 'past the allowance (or a front-end page): the bare count, as before');
    assert.match(String(valueOf(m.root, '9').attrs.style), /#d29922/, 'amber');
    assert.equal(m.verdict.text, '1 to look at: 5xx Oct 13', 'and to look at, named by the amber row\'s own label (2026-10-16, owner: "1 to look at" did not say what)');
    assert.match(m.t, /5xx · Oct 13 9/, 'the row it names carries the same words');
    m.stop();
  }
  // SN Provenance (2026-10-10): DOIs beside the Internet Archive, a failed
  // deposit, and the read time.
  {
    const mount = async (zenodo, opts) => {
      const x = harness({pages: {zenodo: '/z'}, statusExtra: {systems: {}, provenance: {zenodo}, at: Date.parse('2026-09-08T11:58:00Z') / 1000}}, opts), root = new Element('div');
      const stop = x.window.desktopModeWidgets['sn-anchors'](root); await flush();
      for (let i = 0; i < 3; i++) {
        for (const c of x.calls.splice(0)) {
          if (c.opts.path.includes('anchor-status')) c.resolve({pending: [], recording: [], confirmed: 50, total: 50, pages: {confirmed: 6, total: 6}});
          else if (c.opts.path.includes('archive-status')) c.resolve({ok: true, configured: true, run: {state: 'done'}, pending: 0, captures: {captured: 40, waiting: 2}});
          else c.reject(new Error('not in this fixture'));
        }
        await flush();
      }
      const span = text => nodes(root).find(n => 'span' === n.tag && n.text === text);
      return {root, stop, span, t: root.textContent, links: nodes(root).filter(e => 'a' === e.tag).map(e => e.text + '|' + e.href)};
    };
    let m = await mount({minted: 7, total: 9, failed: 2}, {kit: true});
    const ia = m.span('Archive').parentNode.parentNode, doi = m.span('DOIs').parentNode.parentNode;
    assert.equal(ia.parentNode, doi.parentNode, 'DOIs sit beside the Internet Archive');
    assert.match(String(ia.parentNode.attrs.style), /display:flex;flex-wrap:wrap/, 'as the kit\'s pair');
    assert.match(m.t, /Deposits failed 2/, 'failed deposits are said');
    assert.match(String(m.span('2').attrs.style), /#d29922/, 'in amber');
    assert.ok(m.links.includes('Open Zenodo|/z'), 'with a link to where they are fixed');
    const stamp = nodes(m.root).find(n => /^Read at /.test(n.text));
    assert.ok(stamp, 'a Read at foot, from the payload time');
    assert.equal(stamp.attrs['aria-live'], 'off', 'never announced');
    m.stop();
    m = await mount({minted: 9, total: 9, failed: 0});
    assert.doesNotMatch(m.t, /Deposits failed|Open Zenodo/, 'no failed deposit: no line and no link');
    assert.match(String(m.span('DOIs').parentNode.parentNode.attrs.style), /border-top/, 'without the kit the DOIs stack under their own hairline');
    m.stop();
  }
  // The derived rows leave out what they cannot compute, never a 0.
  {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
    x.calls[0].resolve({configured: true, rows: [{name: 'a', level: 'ok'}, {name: 'b', level: 'ok', availability: null, response_ms: undefined, median_ms: null, max_ms: null}]}); await flush();
    assert.match(root.textContent, /Monitors 2 of 2 up Availability · 30 days not read Response · 24 h not read /, 'no availability and no response time: each row says not read, never a 0');
    assert.doesNotMatch(root.textContent, /average|median|Slowest|0 ms|NaN/);
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
    assert.match(m.root.textContent, /Top crawler family unclassified-machine, 44%▲ up  3 points/, 'the top family, its share and its change in points, said as points');
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
    // 2026-10-18: the evidence as rows. Short labels from the payload's one
    // vocabulary (inc/machine-readers-impostor-copy.php, read here through PHP
    // so the fixture is the real words), nested parts indented under the part
    // of the bar they sit in, the long description in the row's title.
    const rows = r => nodes(r).filter(n => n.attrs.role === 'listitem').map(n => (n.attrs['data-depth'] || '0') + '|' + n.children[0].textContent.trim() + '|' + n.children[1].text);
    const owner = {...base, total: 121968, labels: L, top_family: {family: 'anthropic', share: 17, prior_share: null},
      edge_verified: {verified: 10260, unverified: 26359, not_measured: 15848, first_party: 69501, vendor_verified: 0, control_token: 524},
      ai_training_by_purpose: 1719, verification: {ai_training_by_purpose: {claimed: 1719, verified: 83, verified_matched: 80, vendor_verified: 0, unverified: 1500, unverifiable: 136}},
      ai_training_title: '1,719 claimed, credited to their vendor',
      impersonations: [{agent: 'google-extended', token: 'Google-Extended', hits: 524}],
      impostors: [{agent: 'anthropic-claudebot', hits: 8892, reasons: {cf_directory: 8892}}, {agent: 'bytedance-bytespider', hits: 2591, reasons: {off_home_network: 2087, rotator_network: 504}}],
      impostor_reasons: [{reason: 'cf_directory', hits: 8892}, {reason: 'off_home_network', hits: 2087}, {reason: 'rotator_network', hits: 504}]};
    m = await mount(owner);
    assert.deepEqual(rows(m.root), ['0|Machine reads|121,968', '0|Verified|10,260', '1|by Cloudflare|10,260', '1|by the vendor’s published ranges|0',
      '0|Named themselves, not verified|26,359', '1|failed a check|11,483', '2|Cloudflare’s check|8,892', '2|outside the vendor’s network|2,087', '2|network posing as many crawlers|504',
      '1|control token (Google-Extended)|524', '0|Not measured|15,848', '0|This site’s own tools|69,501', '0|AI-training reads|83 verified',
      '0|Fetched the rights files directly|19', '0|Top crawler family|anthropic, 17%'], 'one short label and one number per row, nested under the part of the bar each sits in');
    const row = label => nodes(m.root).find(n => n.attrs.role === 'listitem' && n.children[0].textContent.trim() === label);
    assert.equal(row('Cloudflare’s check').attrs.title, L.cf_directory.title, 'the full sentence is the row\'s title');
    assert.equal(row('failed a check').attrs.title, L.failed_check.title);
    assert.equal(row('AI-training reads').attrs.title, '1,719 claimed, credited to their vendor', 'the claimed figure moves to the title; the row says the verified figure');
    assert.match(styles(row('network posing as many crawlers')), /padding-left:36px/, 'two levels in, indented twice');
    assert.match(styles(row('by Cloudflare')), /padding-left:18px/, 'one level in, indented once');
    assert.doesNotMatch(styles(row('by Cloudflare').children[1]), /font-weight:600/, 'a nested figure is muted, not bold');
    assert.match(styles(row('Verified').children[1]), /font-weight:600/, 'a part of the bar stays bold');
    const strip = nodes(m.root).find(n => n.className === 'snt-evidence');
    assert.ok(strip && strip.attrs['aria-hidden'] === 'true', 'with a decorative evidence strip');
    assert.deepEqual(strip.children.map(c => c.attrs['data-part']), ['verified_matched', 'verified', 'unverified', 'unverifiable'], 'segments in order, zero parts dropped');
    m.stop();
    // The zero rule: a part of the bar always shows; the vendor row once its
    // field is measured (null is not measured); a zero reason, a zero token
    // count and no impostors show nothing.
    m = await mount({...owner, edge_verified: {...owner.edge_verified, vendor_verified: null, control_token: 0}, impostors: [], impostor_reasons: [], impersonations: []});
    assert.deepEqual(rows(m.root).slice(0, 5), ['0|Machine reads|121,968', '0|Verified|10,260', '1|by Cloudflare|10,260', '0|Named themselves, not verified|26,359', '0|Not measured|15,848'], 'vendor ranges not measured, no impostors, no tokens: no rows for them');
    m.stop();
    // No identity split (a capped read): the impostor and token rows stand on their own.
    m = await mount({...owner, edge_verified: undefined});
    assert.deepEqual(rows(m.root).slice(0, 6), ['0|Machine reads|121,968', '0|failed a check|11,483', '1|Cloudflare’s check|8,892', '1|outside the vendor’s network|2,087', '1|network posing as many crawlers|504', '0|control token (Google-Extended)|524'], 'without the split, the same rows one level up');
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
    // Counts carry thousands separators (2026-10-11: 107882 read as a run of digits).
    {
      const big = harness(), broot = new Element('div');
      const bstop = big.window.desktopModeWidgets['sn-anchors'](broot); await flush();
      big.calls.find(c => c.opts.path.includes('machine-readers')).resolve({ok: true, days: 30, total: 107882, families: [], labels: L, edge_verified: {verified: 13447, unverified: 44156, not_measured: 50279}, ai_training: 8351, ai_rights: 1024}); await flush();
      assert.match(broot.textContent, /Machine reads 107,882/, 'the total is grouped');
      assert.match(broot.textContent, /Verified 13,447/, 'the parts are grouped');
      assert.match(broot.textContent, /Declared AI-training reads 8,351/, 'AI-training reads are grouped');
      assert.match(broot.textContent, /Fetched the rights files directly 1,024/, 'rights fetches are grouped');
      bstop();
    }
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
  // 2026-10-11: beside the views, the presumed-human visitor-days the beacon
  // scored likely automated: subtracted from nothing, unknown never a 0.
  for (const [la, want] of [
    [{visitor_days: 3, scored_visitor_days: 41, measured_days: 9, unmeasured_days: 5}, /Likely automated, not subtracted: 3 of 41 scored visitor-days, 9 of 14 days measured/],
    [{visitor_days: 1200, scored_visitor_days: 4100, measured_days: 14, unmeasured_days: 0}, /Likely automated, not subtracted: 1,200 of 4,100 scored visitor-days(?!,)/],
    [{visitor_days: null, scored_visitor_days: null, measured_days: 0, unmeasured_days: 14}, /Likely automated: not measured yet/],
    [undefined, null],
  ]) {
    const x = harness(), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-site-views'](root); await flush();
    x.calls.find(c => c.opts.path.includes('site-views')).resolve({days: [{date: 'd', views: 1}, {date: 'e', views: 2}], total: 3, delta_pct: null, groups: [], likely_automated: la}); await flush();
    if (want) { assert.match(root.textContent, want); assert.doesNotMatch(root.textContent, /Likely automated: 0 of/, 'unknown is never painted as 0'); }
    else assert.doesNotMatch(root.textContent, /Likely automated/, 'an older payload paints no line');
    stop();
  }
  // 23.8.0: SN Queue pairs Scheduled with Drafts under the card kit's
  // hairline, and paints the amber gap line only when the payload has a gap.
  {
    const x = harness({pages: {scheduled: 'https://x.test/scheduled'}}, {kit: true}), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-queue'](root); await flush();
    const payload = gap => ({next: [{title: 'Soon', when: 'Tomorrow 9:38 AM'}], published: [], scheduled_total: 6,
      runs_to: {label: 'Nov 30'}, drafts: 4, gap});
    x.calls[0].resolve(payload({from: '2026-09-25', to: '2026-10-08', from_label: 'Sep 25', to_label: 'Oct 8'})); await flush();
    const pair = nodes(root).find(n => /border-top/.test(n.attrs.style || '') && n.children.length === 2);
    assert.ok(pair, 'Scheduled and Drafts share one card-kit row');
    assert.equal(pair.children[0].textContent, '6 scheduled · to Nov 30', 'a payload without a runway keeps the depth line');
    assert.equal(pair.children[1].textContent, '4 drafts');
    // 2026-10-12, seen live at about 265 wide: each group keeps to one line
    // at its own width (one row when both fit, else a clean stack).
    assert.deepEqual([pair.children[0].style.whiteSpace, pair.children[0].style.flex, pair.children[1].style.whiteSpace, pair.children[1].style.flex],
      ['nowrap', '1 1 auto', 'nowrap', '0 0 auto'], 'the depth line never breaks beside the drafts count');
    // Phase 2, item 6 (2026-10-14): the runway sentence replaces the depth
    // line (the same row, no new one), the count rides beside the drafts as
    // the secondary text, and the sentence may wrap (at 265 wide it is wider
    // than the card) while the counts keep to one line.
    {
      const r = harness({}, {kit: true}), rroot = new Element('div');
      const rstop = r.window.desktopModeWidgets['sn-queue'](rroot); await flush();
      r.calls[0].resolve({...payload(null), runway: {through: '2027-01-25', days: 103, text: 'Covered through Jan 25, 2027 (103 days)'}}); await flush();
      const rp = nodes(rroot).find(n => /border-top/.test(n.attrs.style || '') && n.children.length === 2);
      assert.equal(rp.children[0].textContent, 'Covered through Jan 25, 2027 (103 days)', 'the runway is the row\'s first group');
      assert.equal(rp.children[1].textContent, '6 scheduled · 4 drafts', 'the count is secondary, beside the drafts');
      assert.notEqual(rp.children[0].style.whiteSpace, 'nowrap', 'the sentence wraps rather than spill past a narrow card');
      assert.equal(rp.children[1].style.whiteSpace, 'nowrap');
      assert.doesNotMatch(rroot.textContent, /· to Nov 30/, 'one statement of how far the queue runs, not two');
      await r.tick(60000);
      r.calls[1].resolve({...payload(null), drafts: null, scheduled_total: undefined, runway: {through: null, days: null, text: 'Not covered: nothing is scheduled.'}}); await flush();
      assert.match(rroot.textContent, /Not covered: nothing is scheduled\./, 'an empty queue is said in words');
      assert.match(rroot.textContent, /Scheduled count not read/, 'an unread count is said in words, never 0');
      // Review on #2058: an empty queue is said once. The headline already
      // says "Nothing scheduled", so no runway line and no "0 scheduled".
      await r.tick(60000);
      r.calls[2].resolve({next: [], published: [], scheduled_total: 0, drafts: 2, runway: {through: null, days: null, text: 'Not covered: nothing is scheduled.'}}); await flush();
      assert.equal((rroot.textContent.match(/Nothing scheduled|nothing is scheduled/gi) || []).length, 1, 'nothing scheduled, said once (got: ' + rroot.textContent + ')');
      assert.doesNotMatch(rroot.textContent, /0 scheduled/, 'and no "0 scheduled" beside it');
      assert.match(rroot.textContent, /2 drafts/, 'the drafts count stays');
      rstop();
    }
    const gapLine = nodes(root).find(n => /^Gap: /.test(n.text));
    assert.ok(gapLine, 'a gap paints its line');
    assert.equal(gapLine.text, 'Gap: no note Sep 25 to Oct 8');
    assert.match(gapLine.attrs.style, /#d29922/, 'the gap line is amber');
    assert.equal(gapLine.tag, 'a'); assert.equal(gapLine.href, 'https://x.test/scheduled', 'the amber line links to where the queue is fixed');
    // 2026-10-10: a planned gap (the holidays) is marked once and the line goes.
    const btn = nodes(root).find(n => 'button' === n.tag && 'Planned' === n.text);
    assert.ok(btn, 'the gap line offers "Planned"');
    assert.match(btn.attrs['aria-label'], /^Planned: mark the gap no note Sep 25 to Oct 8 as intended$/, 'its name starts with its visible word');
    btn.handlers.click();
    const post = x.calls[1];
    assert.equal(post.opts.path, '/signal-noise/v1/desktop/queue-gap-planned');
    assert.equal(post.opts.method, 'POST');
    assert.equal(JSON.stringify(post.opts.data), JSON.stringify({from: '2026-09-25', to: '2026-10-08'}), 'it records exactly this gap');
    post.resolve({ok: true}); await flush();
    assert.doesNotMatch(root.textContent, /Gap:/, 'once recorded, the line goes');
    await x.tick(60000);
    x.calls[2].resolve(payload(null)); await flush();
    assert.doesNotMatch(root.textContent, /Gap:/, 'no gap, no line');
    assert.doesNotMatch(styles(root), /#d29922/, 'and no amber');
    assert.match(root.textContent, /4 drafts/);
    await x.tick(60000);
    x.calls[3].resolve({...payload(null), drafts: null}); await flush();
    assert.doesNotMatch(root.textContent, /Drafts/, 'an uncounted drafts total is left out, never painted as 0');
    assert.match(root.textContent, /6 scheduled/);
    // 2026-10-12 audit: a failed poll keeps the last reading and says when it
    // was read; a missing count is not read, never 0; the arrow is not read out.
    const open = nodes(root).find(n => 'a' === n.tag && /^Open Scheduled/.test(n.textContent));
    assert.ok(open && open.text === 'Open Scheduled' && /gap:4px/.test(open.attrs.style) && open.children.some(c => '→' === c.text && 'true' === c.attrs['aria-hidden']), 'Open Scheduled is spaced from its arrow by the flex gap (a trailing space is dropped in a flex item), and the arrow is hidden from a screen reader');
    await x.tick(60000);
    x.calls[4].reject(new Error('Failed to fetch')); await flush();
    // 2026-10-14: no answer is a brief miss first; the line below is the fourth.
    assert.match(root.textContent, /Updating, retrying\./, 'no answer is retried quietly first');
    await x.tick(5000); x.calls[5].reject(new Error('Failed to fetch')); await flush();
    await x.tick(15000); x.calls[6].reject(new Error('Failed to fetch')); await flush();
    await x.tick(30000); x.calls[7].reject(new Error('Failed to fetch')); await flush();
    assert.match(root.textContent, /Soon/, 'a failed poll keeps the last good card');
    assert.match(root.textContent, /Couldn.t read the queue: the site did not answer; showing the \d{1,2}:\d\d( [AP]M)? reading\./, 'and says it is the last reading, with its time and why');
    assert.doesNotMatch(root.textContent, /Queue read failed|Failed to fetch/, 'no developer words on the card');
    // Review on #2023: the line says the real failure, not a guess at it.
    await x.tick(70000);
    x.calls[8].reject({code: 'sn_mcp_read_rate_limited', message: 'Rate limited', data: {status: 429}}); await flush();
    assert.match(root.textContent, /Couldn.t read the queue: Rate limited \(HTTP 429\); showing the /, 'a refusal from the site is passed through in its own words, not "did not answer"');
    // 2026-10-19: a second failed read doubles the wait (120 s), as the siblings do.
    await x.tick(60000); assert.equal(x.calls.length, 9, 'a second failure is not retried at the 60 s rate');
    await x.tick(60000);
    x.calls[9].resolve({next: [], published: [], drafts: 2}); await flush();
    assert.doesNotMatch(root.textContent, /0 scheduled|showing the/, 'a payload without a scheduled count is not painted as 0, and a good read clears the line');
    assert.match(root.textContent, /Scheduled count not read/);
    stop();
    // Phase 2 (2026-10-12): the cadence warnings, one line each with its word
    // first, Planned on gaps only, three on the card and the rest counted;
    // a payload with the rule paints no second gap definition.
    {
      const c = harness({pages: {scheduled: 'https://x.test/scheduled'}}, {kit: true}), croot = new Element('div');
      const cstop = c.window.desktopModeWidgets['sn-queue'](croot); await flush();
      const cadence = [
        {kind: 'cluster', from: '2026-10-20', to: '2026-10-21', days: 1, count: 2, text: 'Cluster: 2 notes Oct 20 and Oct 21'},
        {kind: 'gap', from: '2026-10-25', to: '2026-11-04', days: 12, count: 0, text: 'Gap: no note Oct 25 to Nov 4 (12 days between notes)'},
        {kind: 'gap', from: '2026-11-10', to: '2026-11-19', days: 11, count: 0, text: 'Gap: no note Nov 10 to Nov 19 (11 days between notes)'},
        {kind: 'gap', from: '2026-12-01', to: '2026-12-09', days: 10, count: 0, text: 'Gap: no note Dec 1 to Dec 9 (10 days between notes)'}];
      c.calls[0].resolve({next: [{title: 'Soon', when: 'Tomorrow 9:38 AM'}], published: [], scheduled_total: 6, runs_to: {label: 'Dec 30'}, drafts: 0,
        gap: {from: '2026-09-25', to: '2026-10-08', from_label: 'Sep 25', to_label: 'Oct 8'}, cadence}); await flush();
      const lines = nodes(croot).filter(n => 'a' === n.tag && /^(Cluster|Gap): /.test(n.text));
      assert.deepEqual(lines.map(n => n.text), cadence.slice(0, 3).map(w => w.text), 'three cadence lines, each the server\'s sentence with its word first');
      assert.ok(lines.every(n => n.href === 'https://x.test/scheduled' && /#d29922/.test(n.attrs.style)), 'each links to Scheduled; amber is the second cue');
      assert.doesNotMatch(croot.textContent, /Sep 25/, 'the cadence rule replaces the single gap: no second definition on one card');
      assert.match(croot.textContent, /\+1 more in Scheduled/, 'the fourth is counted, never dropped');
      const btns = nodes(croot).filter(n => 'button' === n.tag && 'Planned' === n.text);
      assert.equal(btns.length, 2, 'Planned on the two gap lines, never on a cluster');
      btns[0].handlers.click();
      assert.equal(JSON.stringify(c.calls[1].opts.data), JSON.stringify({from: '2026-10-25', to: '2026-11-04'}), 'Planned records exactly that gap\'s empty days');
      cstop();
    }
    // Phase 2, item 2 (2026-10-13): each draft row says its suggested slot
    // in words, with the reason as the row's tooltip; an older payload
    // (no slot) paints the title alone, never a 0 or a dash.
    {
      const d = harness({}, {kit: true}), droot = new Element('div');
      const dstop = d.window.desktopModeWidgets['sn-queue'](droot); await flush();
      const text = 'Suggested: Mon, Oct 26 at 9:00 AM; fills the Oct 25 to Nov 4 gap. A suggestion only: nothing is scheduled.';
      d.calls[0].resolve({next: [], published: [], scheduled_total: 4, drafts: 2, draft_list: [
        {title: 'Idea', edit_url: 'https://x.test/e/1', slot: {day: '2026-10-26', time: '9:00 AM', label: 'suggested: Mon, Oct 26', reason: 'fills the Oct 25 to Nov 4 gap', text}},
        {title: 'Old', edit_url: 'https://x.test/e/2'}]}); await flush();
      const rows = nodes(droot).filter(n => 'div' === n.tag && n.children.length === 2 && 'a' === n.children[0].tag && /\/e\/[12]$/.test(n.children[0].href));
      assert.equal(rows.length, 2, 'both drafts are listed, each a link to its editor');
      assert.equal(rows[0].children[1].text, '', 'the title row carries no slot: the title keeps the whole row');
      const box = nodes(droot).find(n => 'div' === n.tag && n.getAttribute && n.getAttribute('title') === text);
      assert.ok(box && box.children[0] === rows[0], 'the reason rides as the tooltip of the draft and its slot line');
      assert.equal(box.children[1].tag, 'p'); assert.equal(box.children[1].text, 'suggested: Mon, Oct 26', 'the slot is said in words on its own line under the title');
      assert.match(box.children[1].attrs.style, /font-size:11px/, 'at the 11px floor');
      assert.ok(!nodes(droot).some(n => n.getAttribute && n.getAttribute('title') && n.children[0] === rows[1]), 'a draft with no slot in the payload gets no slot line');
      assert.doesNotMatch(droot.textContent, /scheduled for|Schedule/, 'never worded as done, and no control to schedule');
      dstop();
    }
    // No good reading yet: the failure is a sentence, not a function name.
    const q = harness(), qroot = new Element('div');
    const runner = q.window.sntAbilityRun; delete q.window.sntAbilityRun;
    const qstop = q.window.desktopModeWidgets['sn-queue'](qroot); await flush();
    assert.match(qroot.textContent, /^Couldn.t read the queue: the S&N status reader did not load; reload the desktop\.$/);
    q.window.sntAbilityRun = runner; qstop();
  }
  // 2026-10-12, the owner's redesign: SN Queue's coming weeks as a calendar.
  {
    const x = harness({pages: {scheduled: 'https://x.test/scheduled'}}, {kit: true, calendar: true}), root = new Element('div');
    const stop = x.window.desktopModeWidgets['sn-queue'](root); await flush();
    const days = ['2026-10-13', '2026-10-16', '2026-10-20', '2026-10-24', '2026-10-28', '2026-11-01', '2026-11-05', '2026-11-09', '2026-11-09', '2026-11-09', '2026-11-09', '2026-11-09', '2026-11-13', '2026-11-17', '2026-11-21', '2026-11-21', '2026-11-25', '2027-01-25'];
    const scheduled = days.map((day, i) => ({title: 'Note ' + i, day, time: (i % 12 + 1) + ':00 PM', edit_url: 'https://x.test/e/' + i}));
    const payload = extra => ({next: scheduled.slice(0, 4), published: [{title: 'Old', when: '2 days ago'}], scheduled_total: 18, drafts: 2, draft_more: 1,
      runs_to: {label: 'Jan 25, 2027'}, gap: null, scheduled, today: '2026-10-12', week_start: '2026-10-12',
      planned: [{from: '2026-11-14', to: '2026-11-16'}], draft_list: [{title: 'Idea', edit_url: 'https://x.test/e/idea'}], ...(extra || {})});
    x.calls[0].resolve(payload()); await flush();
    const cells = () => nodes(root).filter(n => 'gridcell' === n.attrs.role);
    const cellOf = label => cells().find(n => n.attrs['aria-label'].startsWith(label));
    // A day's marks: its dots, plus N for a "+N" past three (review on #2031).
    const marksOf = n => { const row = n.children.find(c => 'true' === c.attrs['aria-hidden'] && /min-height:5px/.test(c.attrs.style || ''));
      return row.children.reduce((k, c) => k + (/border:2\.5px/.test(c.attrs.style || '') ? 1 : Number((/^\+(\d+)$/.exec(c.text) || [0, 0])[1])), 0); };
    const dotsOf = marksOf;
    assert.equal(cells().length, 42, 'six weeks of seven days, Monday first');
    assert.match(cells()[0].attrs['aria-label'], /^Mon, Oct 12, today/, 'the grid starts on the Monday of this week, in the server\'s days');
    // Every note in the window gets a dot, across all six weeks (the sketch drew only the first four).
    const inWindow = scheduled.filter(r => r.day <= '2026-11-22');
    assert.equal(cells().reduce((n, c) => n + dotsOf(c), 0), inWindow.length, 'every scheduled note in the window is a dot, not only the four of next');
    for (const r of inWindow) assert.ok(dotsOf(cells().find(c => c.attrs['aria-label'].includes(r.title))) >= 1, r.day + ' has its dot');
    assert.equal(dotsOf(cellOf('Sat, Nov 21')), 2, 'two notes on one day, two dots');
    const nov9 = cellOf('Mon, Nov 9').children.find(c => /min-height:5px/.test(c.attrs.style || ''));
    assert.deepEqual([nov9.children.filter(c => /border:2\.5px/.test(c.attrs.style || '')).length, nov9.children[nov9.children.length - 1].text], [2, '+3'], 'five notes on one day: two dots and "+3", never three dots for five');
    assert.match(cellOf('Mon, Nov 9').attrs['aria-label'], /5 notes: /, 'and the name counts all five');
    // The first of a month: the month tag, the number and the dot together.
    const nov1 = cellOf('Sun, Nov 1');
    assert.deepEqual(nov1.children.slice(0, 2).map(n => n.text), ['Nov', '1'], 'the 1st carries its month tag AND its number');
    assert.equal(dotsOf(nov1), 1, 'and its dot');
    assert.equal(cellOf('Mon, Nov 2').children.length, 2, 'any other day is a number and a dot row, no tag');
    // Today: one cell, a solid border (survives forced colors), and said in words.
    const today = cells().filter(n => /solid currentColor/.test(n.attrs.style));
    assert.equal(today.length, 1, 'today\'s marker is on exactly one cell');
    assert.equal(nodes(root).filter(n => /text-decoration:underline/.test(n.attrs.style || '')).length, 1, 'and one underlined number, a shape forced colors keeps');
    assert.match(today[0].attrs['aria-label'], /^Mon, Oct 12, today, nothing scheduled$/);
    assert.ok(cells().every(n => !/solid currentColor/.test(n.attrs.style) || n === today[0]));
    assert.ok(nodes(root).filter(n => /border:2\.5px solid/.test(n.attrs.style || '')).length === inWindow.length - 3, 'dots are drawn with borders, which forced colors keeps');
    // The next-up day keeps a shape of its own once another day is selected (review on #2031).
    const ticks = nodes(root).filter(n => /position:absolute;top:2px;right:2px;width:0;height:0;border:2px solid/.test(n.attrs.style || ''));
    assert.equal(ticks.length, 1, 'one corner mark, drawn with a border');
    assert.ok(cellOf('Tue, Oct 13').children.includes(ticks[0]), 'on the next-up day');
    // Names carry the date and the titles.
    assert.equal(cellOf('Tue, Oct 13').attrs['aria-label'], 'Tue, Oct 13, next up, 1 note: Note 0 at 1:00 PM');
    assert.match(cellOf('Sun, Nov 15').attrs['aria-label'], /planned gap, nothing scheduled$/, 'a planned day says so');
    assert.match(cellOf('Sun, Nov 15').attrs.style, /dashed/, 'and wears the quiet dashed mark');
    assert.match(root.textContent, /Dashed days: a gap you marked planned/, 'the mark has a text twin');
    // The box: the next-up day by default, with the title, time and link.
    const box = nodes(root).find(n => 'polite' === n.attrs['aria-live']);
    assert.match(box.textContent, /^Next up · Tue, Oct 13/, 'the box opens on the next-up day');
    assert.ok(nodes(box).some(n => 'a' === n.tag && 'Note 0' === n.text && 'https://x.test/e/0' === n.href), 'with the note linked to its editor');
    assert.doesNotMatch(root.textContent, /\bThen\b/, 'no Then list: its notes are on the grid');
    assert.equal(nodes(root).filter(n => /^Next up/.test(n.text)).length, 1, 'Next up is said once, in the box');
    assert.equal(cells().filter(n => '0' === n.attrs.tabindex).length, 1, 'one tab stop (roving tabindex)');
    assert.equal(cellOf('Tue, Oct 13').attrs.tabindex, '0');
    // Past the grid, drafts and the figures that stay.
    assert.match(root.textContent, /\+2 more through Jan 25, 2027/, 'notes past the grid are counted through the last date');
    assert.ok(nodes(root).some(n => 'a' === n.tag && 'Idea' === n.text && 'https://x.test/e/idea' === n.href), 'each draft is listed by title, linked to its editor');
    assert.match(root.textContent, /Drafts[\s\S]*\+1 more/, 'and the drafts not listed are counted');
    assert.match(root.textContent, /18 scheduled · to Jan 25, 2027/); assert.match(root.textContent, /2 drafts/); assert.match(root.textContent, /Just published[\s\S]*Old/);
    // Seven columns that can never overflow: equal shares of whatever width the card has.
    const rows = nodes(root).filter(n => 'row' === n.attrs.role);
    assert.ok(rows.length === 6 && rows.every(r => /grid-template-columns:repeat\(7,minmax\(0,1fr\)\)/.test(r.attrs.style)), 'each week is seven minmax(0,1fr) columns');
    assert.ok(cells().every(n => /min-width:0;/.test(n.attrs.style) && !/(^|;)width:/.test(n.attrs.style)), 'no cell has a fixed width');
    // At the narrowest card OpenStation allows (min_width 240): 240 less the border, the body's and the card's padding.
    const narrow = (240 - 2 - 32 - 32 - 6 * 2) / 7;
    assert.ok(narrow > 23 && narrow + 2 >= 24, 'at min_width a column is ' + narrow.toFixed(1) + 'px, its targets 24px apart (WCAG 2.5.8 spacing)');
    // Click and keyboard select a day; the selection survives the poll.
    cellOf('Tue, Oct 20').handlers.click();
    assert.match(box.textContent, /^Tue, Oct 20 Note 2/, 'a click shows that day\'s notes');
    const grid = nodes(root).find(n => 'grid' === n.attrs.role);
    let prevented = 0;
    grid.handlers.keydown({key: 'ArrowRight', preventDefault() { prevented++; }});
    assert.match(box.textContent, /^Wed, Oct 21 Nothing scheduled$/, 'ArrowRight moves to the next day');
    assert.equal(cellOf('Wed, Oct 21').attrs.tabindex, '0'); assert.equal(prevented, 1);
    grid.handlers.keydown({key: 'ArrowUp', preventDefault() { prevented++; }});
    assert.match(box.textContent, /^Wed, Oct 14/, 'ArrowUp moves a week');
    grid.handlers.keydown({key: 'ArrowUp', preventDefault() { prevented++; }});
    assert.equal(prevented, 2, 'at the grid\'s edge the key is left to the page');
    assert.match(box.textContent, /^Wed, Oct 14/, 'and the day stays');
    grid.handlers.keydown({key: 'End', preventDefault() { prevented++; }});
    assert.match(box.textContent, /^Sun, Oct 18/, 'End goes to the week\'s Sunday');
    cellOf('Tue, Oct 20').handlers.click();
    const before = cells()[0];
    await x.tick(60000); x.calls[1].resolve(payload()); await flush();
    assert.equal(cells()[0], before, 'the same reading is not repainted');
    await x.tick(60000); x.calls[2].resolve(payload({drafts: 3})); await flush();
    assert.notEqual(cells()[0], before, 'a changed reading repaints');
    assert.equal(cellOf('Tue, Oct 20').attrs['aria-selected'], 'true', 'and keeps the selected day');
    assert.match(nodes(root).find(n => 'polite' === n.attrs['aria-live']).textContent, /^Tue, Oct 20/);
    // An EMPTY day the user picks survives a changed poll, and focus comes back to it (review on #2031).
    nodes(root).find(n => 'grid' === n.attrs.role).handlers.keydown({key: 'ArrowRight', preventDefault() {}});
    assert.equal(x.document.activeElement, cellOf('Wed, Oct 21'), 'the key moved focus to the empty day');
    const oldFocus = x.document.activeElement;
    await x.tick(60000); x.calls[3].resolve(payload({drafts: 4})); await flush();
    assert.equal(cellOf('Wed, Oct 21').attrs['aria-selected'], 'true', 'a picked empty day is kept across a changed reading');
    assert.match(nodes(root).find(n => 'polite' === n.attrs['aria-live']).textContent, /^Wed, Oct 21 Nothing scheduled$/);
    assert.ok(x.document.activeElement !== oldFocus && x.document.activeElement === cellOf('Wed, Oct 21'), 'and focus is handed to the rebuilt cell, not lost');
    // A failure keeps the calendar with its words, and the focus.
    await x.tick(60000); x.calls[4].reject(new Error('Failed to fetch')); await flush();
    assert.equal(cells().length, 42); assert.match(root.textContent, /Updating, retrying\./);
    assert.equal(x.document.activeElement, cellOf('Wed, Oct 21'), 'a brief miss repaint gives focus back');
    await x.tick(5000); x.calls[5].reject({code: 'internal_server_error', message: 'Internal error', data: {status: 500}}); await flush();
    assert.equal(cells().length, 42); assert.match(root.textContent, /showing the .* reading/);
    assert.equal(x.document.activeElement, cellOf('Wed, Oct 21'), 'the failure repaint gives focus back too');
    stop();
    // An untouched card follows the next-up day: the default is never stored as a pick (review on #2031).
    const u = harness({}, {calendar: true}), uroot = new Element('div');
    const ustop = u.window.desktopModeWidgets['sn-queue'](uroot); await flush();
    u.calls[0].resolve(payload()); await flush();
    assert.match(nodes(uroot).find(n => 'polite' === n.attrs['aria-live']).textContent, /^Next up · Tue, Oct 13/);
    const published = scheduled.slice(1);
    await u.tick(60000); u.calls[1].resolve(payload({scheduled: published, next: published.slice(0, 4)})); await flush();
    assert.match(nodes(uroot).find(n => 'polite' === n.attrs['aria-live']).textContent, /^Next up · Fri, Oct 16 Note 1/, 'once Oct 13 publishes, the box moves to the new next-up day');
    ustop();
    // Overdue: the word is kept, and a note missed before this week is named above the grid (review on #2031).
    const o = harness({}, {calendar: true}), oroot = new Element('div');
    const ostop = o.window.desktopModeWidgets['sn-queue'](oroot); await flush();
    const od = [{title: 'Missed', day: '2026-10-09', when: 'Overdue', time: '9:00 AM', edit_url: 'https://x.test/e/m'},
      {title: 'This morning', day: '2026-10-12', when: 'Overdue', time: '8:00 AM'}, {title: 'Soon', day: '2026-10-14', when: 'Wed 9:00 AM', time: '9:00 AM'}];
    o.calls[0].resolve({next: od, published: [], scheduled_total: 3, scheduled: od, today: '2026-10-12', week_start: '2026-10-12', planned: [], draft_list: []}); await flush();
    const missLine = nodes(oroot).find(n => /^1 overdue: $/.test(n.text));
    assert.ok(missLine && nodes(missLine).some(n => 'a' === n.tag && 'Missed' === n.text && 'https://x.test/e/m' === n.href), 'a note missed before this week is named and linked: "1 overdue: Missed"');
    assert.match(missLine.attrs.style, /#d29922/, 'in the gap line\'s amber');
    assert.match(nodes(oroot).find(n => 'polite' === n.attrs['aria-live']).textContent, /^Next up · Fri, Oct 9 Missed Overdue$/, 'the box names the overdue next-up with "Overdue", not a time');
    const om = nodes(oroot).find(n => 'gridcell' === n.attrs.role && /^Mon, Oct 12/.test(n.attrs['aria-label']));
    assert.match(om.attrs['aria-label'], /This morning, overdue$/, 'a day\'s name says overdue too');
    om.handlers.click();
    assert.match(nodes(oroot).find(n => 'polite' === n.attrs['aria-live']).textContent, /This morning Overdue$/);
    ostop();
    // A next-up past the grid: the box still names it, and Then notes past the grid stay listed.
    const far = harness({}, {calendar: true}), froot = new Element('div');
    const fstop = far.window.desktopModeWidgets['sn-queue'](froot); await flush();
    const late = [{title: 'Far', day: '2027-02-01', time: '9:00 AM'}, {title: 'Farther', day: '2027-02-05', time: '9:00 AM'}];
    far.calls[0].resolve({next: late, published: [], scheduled_total: 2, scheduled: late, today: '2026-10-12', week_start: '2026-10-12', planned: [], draft_list: [], runs_to: {label: 'Feb 5, 2027'}}); await flush();
    assert.match(froot.textContent, /Next up · Mon, Feb 1 Far/, 'a next-up past the grid is named in the box');
    assert.match(froot.textContent, /Later Farther/, 'a Then note past the grid stays reachable by name');
    assert.equal(nodes(froot).filter(n => 'gridcell' === n.attrs.role && '0' === n.attrs.tabindex).length, 1, 'and the grid keeps one tab stop');
    fstop();
  }
  // Review on #2023: a confirm that rejects (the overlay bundle failing to
  // load) must not leave Clear DB overrides busy forever.
  {
    const x = harness(), root = new Element('div');
    x.window.wp.os = { confirm: () => Promise.reject(new Error('the overlay bundle did not load')) };
    const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
    const btn = nodes(root).find(n => 'button' === n.tag && 'Clear DB overrides' === n.text);
    assert.ok(btn, 'the card offers Clear DB overrides');
    btn.handlers.click(); await flush();
    assert.equal(btn.getAttribute('aria-busy'), null, 'a confirm that rejects clears the busy state, so the button works again');
    stop();
  }
  // 2026-10-14: WordPress maintenance mode (a plugin or theme install) answers
  // 503 for a few seconds, and the owner's open desktop saw every card flip to
  // its error state. A 503 or a request that never got an answer is brief: the
  // card keeps its reading as it was, says "Updating, retrying." in words, and
  // retries at 5 s, 15 s and 30 s. Only a fourth miss shows the error state, and
  // the next try then waits the card's ordinary period, never Retry-After's 600 s.
  {
    const maint = {code: 'wp_die', message: 'Briefly unavailable for scheduled maintenance. Check back in a minute.', data: {status: 503}};
    const offline = {code: 'fetch_error', message: 'You are probably offline.'};
    const hinted = {...maint, data: {status: 503, retry_after: 600}};
    // SN Deploy: the ladder, then the existing footer and cue.
    {
      const f = fixtures[0], x = harness(), root = new Element('div');
      const stop = x.window.desktopModeWidgets[f.id](root); await flush();
      x.calls[0].resolve(f.good); await flush();
      const goodCard = view(root).firstChild, cardStyles = styles(goodCard);
      await x.tick(f.period); x.calls[1].reject(maint); await flush();
      assert.match(root.textContent, new RegExp(f.marker), 'a 503 keeps the reading');
      assert.match(root.textContent, /Updating, retrying\./, 'a 503 says in words that it is retrying');
      assert.doesNotMatch(root.textContent, /Last good reading|unavailable/i, 'a 503 is not the error state');
      assert.equal(details(root), '', 'a 503 adds no failure cue');
      assert.equal(view(root).firstChild, goodCard, 'a 503 does not repaint the card');
      assert.equal(styles(goodCard), cardStyles, 'a 503 recolors nothing: the reading stays as it was');
      const note = nodes(root).find(n => n.text === 'Updating, retrying.');
      assert.doesNotMatch(note.attrs.style || '', /#d29922|#ff9d94|#c9503f|#f85149/, 'the note is not red or amber');
      assert.equal(note.attrs.role, undefined, 'the note is not an alert');
      await x.tick(4999); assert.equal(x.calls.length, 2, 'no retry before 5 s');
      await x.tick(1); assert.equal(x.calls.length, 3, 'first retry at 5 s');
      x.calls[2].reject(offline); await flush();
      assert.match(root.textContent, /Updating, retrying\./, 'a network error is brief too');
      await x.tick(14999); assert.equal(x.calls.length, 3);
      await x.tick(1); assert.equal(x.calls.length, 4, 'second retry at 15 s');
      x.calls[3].reject(maint); await flush();
      await x.tick(29999); assert.equal(x.calls.length, 4);
      await x.tick(1); assert.equal(x.calls.length, 5, 'third retry at 30 s');
      x.calls[4].reject(hinted); await flush();
      assert.match(root.textContent, /Last good reading (just now|\d+ min ago) · retrying/, 'a fourth miss shows the existing error state');
      assert.match(details(root), /Current status unavailable: Briefly unavailable/);
      assert.doesNotMatch(root.textContent, /Updating, retrying\./, 'the error state replaces the quiet note');
      await x.tick(f.period - 1); assert.equal(x.calls.length, 5);
      await x.tick(1); assert.equal(x.calls.length, 6, 'then the ordinary period, never Retry-After 600 s');
      x.calls[5].resolve(f.good); await flush();
      assert.doesNotMatch(root.textContent, /Updating|Last good reading/, 'success clears both');
      await x.tick(f.period); x.calls[6].reject(maint); await flush();
      await x.tick(5000); assert.equal(x.calls.length, 8, 'success resets the ladder');
      stop(); assert.equal(x.timers.size, 0);
      // A 503 before any reading keeps the loading line, never the error state.
      const y = harness(), r2 = new Element('div');
      const stop2 = y.window.desktopModeWidgets[f.id](r2); await flush();
      y.calls[0].reject(maint); await flush();
      assert.match(r2.textContent, /Loading deploy status… Updating, retrying\./);
      assert.doesNotMatch(r2.textContent, /Status unavailable/);
      stop2();
    }
    // SN Systems: the uptime rows stay as read, with the note as a plain row.
    {
      const f = systems, x = harness(), root = new Element('div');
      const stop = x.window.desktopModeWidgets[f.id](root); await flush();
      x.calls[0].resolve(f.good); await flush();
      await x.tick(f.period); x.calls[1].reject(maint); await flush();
      assert.match(root.textContent, /All systems normal/, 'a 503 is not a fault in the verdict');
      assert.match(root.textContent, /Monitors 2 of 2 up/);
      assert.match(root.textContent, /Updating, retrying\./);
      assert.doesNotMatch(root.textContent, /Last check failed/);
      await x.tick(4999); assert.equal(x.calls.length, 2);
      await x.tick(1); assert.equal(x.calls.length, 3); x.calls[2].reject(offline); await flush();
      await x.tick(15000); assert.equal(x.calls.length, 4); x.calls[3].reject(maint); await flush();
      await x.tick(30000); assert.equal(x.calls.length, 5); x.calls[4].reject(hinted); await flush();
      assert.match(root.textContent, /Last check failed: Briefly unavailable/, 'a fourth miss shows the existing error state');
      assert.doesNotMatch(root.textContent, /Updating, retrying\./);
      await x.tick(f.period - 1); assert.equal(x.calls.length, 5);
      await x.tick(1); assert.equal(x.calls.length, 6, 'then the ordinary period');
      x.calls[5].resolve(f.good); await flush();
      assert.doesNotMatch(root.textContent, /Updating|Last check failed/);
      stop();
    }
    // SN Traffic: the same figures, the note gone once a read answers the same.
    {
      const x = harness(), root = new Element('div');
      const good = {days: [{date: 'd', views: 1}, {date: 'e', views: 2}], total: 100, delta_pct: null, groups: []};
      const stop = x.window.desktopModeWidgets['sn-site-views'](root); await flush();
      const traffic = () => x.calls.filter(c => c.opts.path.includes('site-views'));
      traffic()[0].resolve(good); await flush();
      await x.tick(5 * 60000); traffic()[1].reject(maint); await flush();
      assert.match(root.textContent, /100/);
      assert.match(root.textContent, /Updating, retrying\./);
      await x.tick(4999); assert.equal(traffic().length, 2);
      await x.tick(1); assert.equal(traffic().length, 3, 'first retry at 5 s, not the 5-minute period');
      traffic()[2].resolve(good); await flush();
      assert.doesNotMatch(root.textContent, /Updating/, 'an unchanged answer still clears the note');
      stop();
    }
    // SN Queue (interval poller): the same ladder beside its tick.
    {
      const x = harness(), root = new Element('div');
      const stop = x.window.desktopModeWidgets['sn-queue'](root); await flush();
      x.calls[0].resolve({next: [{title: 'Soon', when: 'Tomorrow 9:38 AM'}], published: []}); await flush();
      await x.tick(60000); assert.equal(x.calls.length, 2); x.calls[1].reject(maint); await flush();
      assert.match(root.textContent, /Soon/);
      assert.match(root.textContent, /Updating, retrying\./);
      assert.doesNotMatch(root.textContent, /Couldn.t read/, 'a 503 is not the error state');
      await x.tick(4999); assert.equal(x.calls.length, 2);
      await x.tick(1); assert.equal(x.calls.length, 3); x.calls[2].reject(offline); await flush();
      await x.tick(15000); assert.equal(x.calls.length, 4); x.calls[3].reject(maint); await flush();
      await x.tick(30000); assert.equal(x.calls.length, 5); x.calls[4].reject(maint); await flush();
      assert.match(root.textContent, /Couldn.t read the queue/, 'a fourth miss shows the existing error state');
      assert.doesNotMatch(root.textContent, /Updating, retrying\./);
      await x.tick(10000); assert.equal(x.calls.length, 5, 'no extra retry after the ladder');
      await x.tick(60000); assert.equal(x.calls.length, 6, 'the ordinary tick reads again');
      x.calls[5].resolve({next: [{title: 'Soon', when: 'Tomorrow 9:38 AM'}], published: []}); await flush();
      assert.doesNotMatch(root.textContent, /Updating|Couldn.t read/);
      stop(); assert.equal(x.timers.size, 0, 'teardown clears the retry timer');
    }
    // Review on #2060: SN Systems' lines re-read answering 503 before the uptime
    // read must not paint "not re-read" and then take it back (the verdict is a
    // live region: it would be announced twice). It waits for the uptime read.
    {
      const lines = {healthSummary: {passed: 8, total: 8, all_passed: true, skipped: [], flagged: []}, cronSummary: {total: 85, sn_count: 30, orphans: 0, next: {hook: 'sn_queue_tick', in_s: 300}, health: {ok: true}}, statusExtra: {systems: {edge: {day: 'Oct 5', total: 4, visitor: 0, prior: 12}, cron: {fires: 41, failed: 0, failing: []}}, provenance: {}}};
      const x = harness({statusExtra: lines.statusExtra}), root = new Element('div');
      const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
      x.side[0].resolve(lines); x.calls[0].resolve(systems.good); await flush();
      const round = async (n, err) => { const shown = root.textContent; x.side[n].reject(err); await flush();
        assert.equal(root.textContent, shown, 'a lines miss landing first paints nothing until the uptime read settles (round ' + n + ')');
        x.calls[n].reject(err); await flush(); };
      await x.tick(systems.period); await round(1, maint);
      assert.match(root.textContent, /Updating, retrying\./);
      assert.doesNotMatch(root.textContent, /not re-read/, 'a brief lines miss rides the uptime retry quietly');
      await x.tick(5000); await round(2, maint);
      await x.tick(15000); await round(3, maint);
      await x.tick(30000); x.side[4].reject(maint); await flush(); x.calls[4].reject(maint); await flush();
      assert.match(root.textContent, /not re-read/, 'once the ladder is spent the lines say they are the last reading');
      assert.match(root.textContent, /Last check failed/);
      stop();
    }
    // Review on #2060: the guards no other pin reaches.
    {
      const C = harness().window.sntPollCadence;
      assert.equal(C.brief({code: 'invalid_json', message: 'The response is not a valid JSON response.'}), true, 'an HTML page through wp.apiFetch (no status) is brief');
      assert.equal(C.brief({code: 'invalid_json', data: {status: 403}}), false, 'an HTML 403 whose status is known is not');
      assert.equal(C.brief({code: 'sn_mcp_read_rate_limited', data: {status: 429, retry_after: 60}}), false, 'a 429 is not brief');
      assert.equal(C.briefWait(maint, 3), 0, 'the ladder has three steps');
    }
    {
      // SN Traffic: a spent ladder never honors the 600 s hint; after a hard
      // failure a 503 is not brief until a good read.
      const x = harness(), root = new Element('div');
      const good = {days: [{date: 'd', views: 1}, {date: 'e', views: 2}], total: 100, delta_pct: null, groups: []};
      const stop = x.window.desktopModeWidgets['sn-site-views'](root); await flush();
      const traffic = () => x.calls.filter(c => c.opts.path.includes('site-views'));
      traffic()[0].resolve(good); await flush();
      await x.tick(5 * 60000); traffic()[1].reject(maint); await flush();
      await x.tick(5000); traffic()[2].reject(maint); await flush();
      await x.tick(15000); traffic()[3].reject(maint); await flush();
      await x.tick(30000); traffic()[4].reject(hinted); await flush();
      assert.doesNotMatch(root.textContent, /Updating/);
      await x.tick(5 * 60000 - 1); assert.equal(traffic().length, 5);
      await x.tick(1); assert.equal(traffic().length, 6, 'the period, not Retry-After 600 s');
      traffic()[5].resolve(good); await flush();
      await x.tick(5 * 60000); traffic()[6].reject({code: 'internal_server_error', message: 'Internal error', data: {status: 500}}); await flush();
      await x.tick(5 * 60000); traffic()[7].reject(maint); await flush();
      assert.doesNotMatch(root.textContent, /Updating/, 'a 503 after a hard failure is not brief');
      await x.tick(30000); assert.equal(traffic().length, 8, 'and is not retried in seconds');
      stop();
    }
    {
      // SN Systems: the same after a hard failure.
      const x = harness(), root = new Element('div');
      const stop = x.window.desktopModeWidgets['sn-health'](root); await flush();
      x.calls[0].resolve(systems.good); await flush();
      await x.tick(systems.period); x.calls[1].reject({code: 'internal_server_error', message: 'Internal error', data: {status: 500}}); await flush();
      await x.tick(systems.period); x.calls[2].reject(maint); await flush();
      assert.doesNotMatch(root.textContent, /Updating/, 'a 503 after a hard failure is not brief');
      assert.match(root.textContent, /Last check failed: Briefly unavailable/);
      await x.tick(30000); assert.equal(x.calls.length, 3, 'and is not retried in seconds');
      stop();
    }
    {
      // SN Queue: teardown mid-ladder drops the retry; a read that misses
      // after the tab was hidden arms no retry while hidden.
      const q = {next: [{title: 'Soon', when: 'Tomorrow 9:38 AM'}], published: []};
      const x = harness(), root = new Element('div');
      const stop = x.window.desktopModeWidgets['sn-queue'](root); await flush();
      x.calls[0].resolve(q); await flush();
      await x.tick(60000); x.calls[1].reject(maint); await flush();
      stop(); assert.equal(x.timers.size, 0, 'teardown mid-ladder clears the pending retry');
      await x.tick(60000); assert.equal(x.calls.length, 2);
      const y = harness(), r2 = new Element('div');
      const stop2 = y.window.desktopModeWidgets['sn-queue'](r2); await flush();
      y.calls[0].resolve(q); await flush();
      await y.tick(60000); assert.equal(y.calls.length, 2);
      y.document.hidden = true; y.document.dispatch('visibilitychange');
      y.calls[1].reject(maint); await flush();
      assert.equal(y.timers.size, 0, 'a miss landing after hide arms no retry');
      await y.tick(10 * 60000); assert.equal(y.calls.length, 2, 'a hidden tab reads nothing');
      stop2();
    }
  }
  console.log('PASS: runner and both widgets — stale/429/recovery/backoff, malformed data, cross-widget cadence, abort/cleanup/remount');
}
run().catch(e => { console.error(e); process.exitCode = 1; });

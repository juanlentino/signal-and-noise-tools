/* Real widget + runner execution; only DOM, timers and API transport are faked. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.attrs = {}; this.text = ''; }
  setAttribute(k, v) { this.attrs[k] = v; }
  appendChild(n) { this.children.push(n); return n; }
  insertBefore(n, before) { const i = this.children.indexOf(before); this.children.splice(i < 0 ? this.children.length : i, 0, n); }
  removeChild(n) { this.children.splice(this.children.indexOf(n), 1); }
  get firstChild() { return this.children[0]; }
  set textContent(t) { this.text = t; this.children = []; }
  get textContent() { return this.text + this.children.map(n => n.textContent).join(' '); }
}
const styles = n => String(n.attrs.style || '') + n.children.map(styles).join(' ');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
function harness() {
  let now = Date.parse('2026-09-08T12:00:00Z'), nextId = 0;
  const timers = new Map(), calls = [];
  const window = {
    AbortController,
    snDesktopData: {},
    sntAbilityRunData: { verbs: { 'signal-noise/get-deploy-status': 'GET', 'signal-noise/uptime-status': 'GET' } },
    setTimeout(fn, delay) { const id = ++nextId; timers.set(id, {fn, at: now + delay}); return id; },
    clearTimeout(id) { timers.delete(id); },
    setInterval(fn, delay) { const id = ++nextId; timers.set(id, {fn, at: now + delay, repeat: delay}); return id; },
    clearInterval(id) { timers.delete(id); },
    wp: { apiFetch(opts) { return new Promise((resolve, reject) => calls.push({opts, resolve, reject})); } }
  };
  class Clock extends Date { constructor(...a) { super(...(a.length ? a : [now])); } static now() { return now; } }
  const context = vm.createContext({window, document: { createElement: tag => new Element(tag) }, Date: Clock, Promise, Error, Math, AbortController});
  for (const name of ['snt-ability-run.js', 'desktop-mode-widget.js', 'desktop-mode-widget-uptime.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'), context, {filename: name});
  }
  return {window, calls, timers, async tick(ms) {
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
  {id: 'sn-deploy-status', period: 60000, good: {theme: {current: '12.18.10', state: 'ok'}, plugin: {current: '13.107.3', state: 'ok'}}, marker: '12.18.10'},
  {id: 'sn-uptime', period: 120000, good: {configured: true, fetched_at: '2026-09-08T11:59:30Z', rows: [{name: 'example.test', level: 'ok', status: 'up'}]}, marker: 'example.test'}
];
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
    await x.tick(f.period);
    x.calls[1].reject({code: 'sn_mcp_read_rate_limited', message: 'Rate limited', data: {status: 429, retry_after: 180}}); await flush();
    assert.match(root.textContent, new RegExp(f.marker), 'last successful data survives 429');
    assert.match(root.textContent, /stale/i);
    assert.doesNotMatch(styles(root), /#3fb950/, 'stale data must not look currently green');
    assert.match(root.textContent, /2026-09-08/, 'stale message timestamps last success');
    await x.tick(179999); assert.equal(x.calls.length, 2, 'no retry before data.retry_after');
    await x.tick(1); assert.equal(x.calls.length, 3);
    x.calls[2].resolve(f.good); await flush();
    assert.doesNotMatch(root.textContent, /stale|unavailable/i, 'successful recovery clears failure state');
    await x.tick(f.period); assert.equal(x.calls.length, 4, 'success resets ordinary cadence');
    await x.tick(f.period * 3); assert.equal(x.calls.length, 4, 'slow request never overlaps another poll');
    assert.match(root.textContent, /stale/i, 'pending refresh does not leave old data looking current');
    stop(); assert.equal(x.calls[3].opts.signal.aborted, true, 'teardown aborts pending fetch');
    x.calls[3].resolve(f.good); await flush(); await x.tick(1000000);
    assert.equal(root.textContent, ''); assert.equal(x.timers.size, 0); assert.equal(x.calls.length, 4);
  }
  // First-load failures, backoff, missing runner, abort rejection, remount.
  for (const f of fixtures) {
    const x = harness(), root = new Element('div');
    let stop = x.window.desktopModeWidgets[f.id](root); await flush();
    x.calls[0].reject({message: '<script>not HTML</script>', data: {status: 429, retry_after: '1'}}); await flush();
    assert.match(root.textContent, /No successful refresh yet/);
    assert.doesNotMatch(styles(root), /#3fb950/);
    assert.equal(root.firstChild.attrs.role, 'status', 'failure notice is first and announced');
    await x.tick(f.period); assert.equal(x.calls.length, 2);
    x.calls[1].reject(new Error('network')); await flush();
    await x.tick(f.period * 2 - 1); assert.equal(x.calls.length, 2);
    await x.tick(1); assert.equal(x.calls.length, 3, 'second failure doubles backoff');
    x.calls[2].resolve({}); await flush();
    assert.match(root.textContent, /Invalid .* response/, 'malformed first load is not a successful empty card');
    await x.tick(f.period * 4); assert.equal(x.calls.length, 4);
    x.calls[3].reject({message: 'bad hint', data: {retry_after: 1e300}}); await flush();
    assert.match(root.textContent, /bad hint/, 'unrepresentable retry hint cannot crash failure handling');
    assert.equal(x.timers.size, 1, 'bad retry hint retains bounded backoff');
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
    const firstStamp = root.textContent.match(/Last successful refresh: ([^\s]+)/)[1];
    await x.tick(f.period); x.calls[1].resolve(bad); await flush();
    assert.match(root.textContent, /12\.18\.10/, 'malformed deploy retains theme version');
    assert.match(root.textContent, /13\.107\.3/, 'malformed deploy retains plugin version');
    assert.match(root.textContent, /Invalid deploy status response/);
    assert.ok(root.textContent.includes(firstStamp), 'failure preserves successful timestamp');
    await x.tick(f.period);
    x.calls[2].resolve({theme: {current: '', state: 'unknown'}, plugin: {current: '13.107.3', state: 'unknown'}}); await flush();
    assert.doesNotMatch(root.textContent, /Invalid deploy status response|Stale/,
      'legitimate unknown package status remains a successful response');
    stop();
  }
  // A malformed row must not poison the retained successful snapshot.
  {
    const x = harness(), root = new Element('div'), f = fixtures[1];
    const stop = x.window.desktopModeWidgets[f.id](root); await flush();
    x.calls[0].resolve(f.good); await flush(); await x.tick(f.period);
    x.calls[1].resolve({configured: true, rows: [null]}); await flush();
    assert.match(root.textContent, /example.test/, 'invalid row preserves last good uptime data');
    assert.match(root.textContent, /unavailable/); stop();
  }
  // Independent widgets share the actual runner without multiplying each
  // other's polls: over ten minutes deploy=11, uptime=6 including first load.
  {
    const x = harness(); let answered = 0;
    const stops = fixtures.map(f => x.window.desktopModeWidgets[f.id](new Element('div')));
    const answer = async () => { for (; answered < x.calls.length; answered++) {
      const c = x.calls[answered]; c.resolve(fixtures[c.opts.path.includes('uptime-status') ? 1 : 0].good);
    } await flush(); };
    await flush(); await answer();
    for (let minute = 0; minute < 10; minute++) { await x.tick(60000); await answer(); }
    assert.equal(x.calls.filter(c => c.opts.path.includes('get-deploy-status')).length, 11);
    assert.equal(x.calls.filter(c => c.opts.path.includes('uptime-status')).length, 6);
    stops.forEach(stop => stop()); assert.equal(x.timers.size, 0);
  }
  console.log('PASS: runner and both widgets — stale/429/recovery/backoff, malformed data, cross-widget cadence, abort/cleanup/remount');
}
run().catch(e => { console.error(e); process.exitCode = 1; });

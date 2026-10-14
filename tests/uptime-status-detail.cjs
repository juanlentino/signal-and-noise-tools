/* Runs the real assets/uptime-status.js detail table under a minimal fake DOM. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.attrs = {}; this.text = ''; this.className = ''; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  hasAttribute(k) { return k in this.attrs; }
  appendChild(n) { this.children.push(n); return n; }
  set textContent(t) { this.text = t; this.children = []; }
  get textContent() { return this.text + this.children.map((n) => n.textContent).join(' '); }
}
const all = (n) => [n, ...n.children.flatMap(all)];
const NOW = Date.parse('2026-10-13T12:00:00Z');
async function run() {
  const mount = new Element('div');
  mount.attrs = { 'data-sn-uptime-status': '', 'data-sn-uptime-detail': '' };
  let resolve;
  const document = {
    readyState: 'complete',
    createElement: (t) => new Element(t),
    addEventListener() {},
    querySelectorAll: () => [mount],
    querySelector: (s) => (s === '[data-sn-uptime-detail]' ? mount : null),
  };
  class FakeDate extends Date { static now() { return NOW; } }
  FakeDate.parse = Date.parse;
  const window = { sntAbilityRun: () => new Promise((r) => { resolve = r; }) };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/uptime-status.js'), 'utf8'), { window, document, Date: FakeDate });
  resolve({ configured: true, incidents: [], rows: [
    { name: 'Home', kind: 'monitor', status: 'up', level: 'ok', median_ms: 21, max_ms: 28400, max_region: 'Asia', max_at: '2026-10-13T09:06:00Z' },
    { name: 'Feed', kind: 'monitor', status: 'up', level: 'ok', median_ms: 1500, max_ms: 900, max_region: null, max_at: 'not a time' },
    { name: 'Beat', kind: 'heartbeat', status: 'up', level: 'ok', median_ms: null, max_ms: null },
  ] });
  await new Promise((r) => setImmediate(r));
  const rows = all(mount).filter((n) => n.tag === 'tr').slice(1).map((tr) => tr.children.map((td) => td.textContent));
  assert.equal(rows.length, 3, 'three rows painted');
  assert.equal(rows[0][4], '21 ms', 'median under a second in ms');
  assert.equal(rows[0][5], '28 s from Asia, 2h ago', 'slowest: seconds, region, hours FLOORED like the desktop card (2.9h is 2h ago)');
  assert.equal(rows[1][4], '1.5 s', 'a median past a second reads in seconds, the same as Slowest');
  assert.equal(rows[1][5], '900 ms', 'an unparseable max_at leaves the time out, never "NaNh ago"');
  assert.equal(rows[2][4], 'not read', 'no median: not read');
  assert.equal(rows[2][5], 'not read', 'no slowest check: not read');
  assert.ok(!/NaN|undefined|null/.test(mount.textContent), 'no NaN, undefined or null painted');
  console.log('PASS: uptime detail table');
}
run().catch((e) => { console.error(e); process.exit(1); });

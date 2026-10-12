'use strict';
// 2026.10.6 post-install: Connections > Credentials painted "Nothing to show."
// in all three tables while the classic page listed every row. The runtime
// assigns os-prop-* BEFORE it loads a kit tag that is not defined yet, so on
// a cold open `data` and `columns` land as own properties of a plain element;
// after the upgrade they shadow OsTable's setters and the table never sees
// its rows. The real assets/os-kit.js, run in a VM, must move every such own
// property through the class setter on the next snt:paint, once the tag is
// defined, and leave a correctly upgraded element alone.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const listeners = {};
const document = { addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); } };
const defined = new Map();
const waiting = new Map();
const customElements = {
  get: (tag) => defined.get(tag),
  whenDefined: (tag) => (defined.has(tag) ? Promise.resolve() : new Promise((resolve) => { waiting.set(tag, resolve); })),
};
const window = { document, customElements };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'os-kit.js'), 'utf8'), { window, document, customElements, console, JSON, Promise });

// A kit component: `data` and `columns` are accessors, as on OsTable.
class FakeTable {
  constructor() { this.localName = 'os-table'; this.attributes = []; this.painted = []; this._data = []; this._columns = []; }
  get data() { return this._data; }
  set data(v) { this._data = Array.isArray(v) ? v.slice() : []; this.painted.push('data'); }
  get columns() { return this._columns; }
  set columns(v) { this._columns = v; this.painted.push('columns'); }
}
const ROWS = [{ credential: 'Site secret' }, { credential: 'Cloudflare API token' }];
const COLS = [{ key: 'credential' }];

// What the runtime leaves behind on a cold open: a plain element carrying own
// `data`/`columns`, later given the class prototype by the upgrade.
function shadowed() {
  const el = { localName: 'os-table', attributes: [{ name: 'os-prop-data', value: JSON.stringify(ROWS) }, { name: 'os-prop-columns', value: JSON.stringify(COLS) }, { name: 'empty', value: 'Nothing to show.' }] };
  el.data = ROWS;
  el.columns = COLS;
  Object.setPrototypeOf(el, FakeTable.prototype);
  el._data = []; el._columns = []; el.painted = [];
  return el;
}
const paint = (els) => (listeners['snt:paint'] || []).forEach((fn) => fn({ detail: { root: { querySelectorAll: () => els } } }));
const tick = () => new Promise((r) => setImmediate(r));

(async () => {
  assert.ok((listeners['snt:paint'] || []).length > 0, 'os-kit.js listens for snt:paint');

  // Tag already defined at paint time: repaired at once.
  defined.set('os-table', FakeTable);
  const a = shadowed();
  assert.equal(a.data.length, 2, 'precondition: the own property shadows the setter');
  assert.equal(a._data.length, 0, 'precondition: the component itself holds no rows');
  paint([a]);
  assert.equal(Object.prototype.hasOwnProperty.call(a, 'data'), false, 'the own data property is gone');
  assert.equal(a._data.length, 2, 'the rows went through the setter');
  assert.equal(a._columns.length, 1, 'the columns went through the setter');

  // An element the runtime fed correctly is not touched.
  const b = new FakeTable();
  b.attributes = [{ name: 'os-prop-data', value: '[]' }];
  b.data = ROWS; b.painted = [];
  paint([b]);
  assert.deepEqual(b.painted, [], 'an upgraded element with no own property is left alone');

  // Tag not defined yet at paint time: repaired when it is.
  defined.delete('os-table');
  const c = shadowed();
  paint([c]);
  assert.equal(c._data.length, 0, 'nothing happens before the tag is defined');
  defined.set('os-table', FakeTable);
  waiting.get('os-table')();
  await tick();
  assert.equal(c._data.length, 2, 'the rows reach the component once the tag is defined');

  console.log('PASS: a kit property assigned before its tag was defined reaches the component');
})().catch((e) => { console.error(e); process.exit(1); });

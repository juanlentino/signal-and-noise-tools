'use strict';
// 2026.10: a note's "Updated" line (theme) links to
// /verify?note=<uid>&compare_a=N&compare_b=M#compare. The real
// assets/js/prov-verify-diff.js runs in a VM against a fake /verify page, with
// the real prov-verify-core.js beside it. Good parameters fill the form, run
// the compare and land focus on the result; every hostile or out-of-range one
// leaves the page exactly as a bare ?note= link leaves it, and nothing from the
// URL is ever written into the page as markup.
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const UID = '9f0abe29-71ed-4b6d-b3a3-ad00f06b2fc3';
const BASE = 'https://example.test/wp-json/signal-noise/v1/credential';
const src = (f) => fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', f), 'utf8');
const b64 = (o) => Buffer.from(JSON.stringify(o), 'utf8').toString('base64');
const cred = (v) => ({ validFrom: '2026-10-0' + v, proof: { signedPayloadB64: b64({ content: 'the words of version ' + v }) }, evidence: [{ version: v, anchor: { status: 'confirmed', block: 900000 + v } }] });
const settle = () => new Promise((r) => setTimeout(r, 20));

let pass = 0;
let fail = 0;
function ok(cond, msg) {
  if (cond) { pass++; console.log(`  PASS: ${msg}`); } else { fail++; console.log(`  FAIL: ${msg}`); }
}

/** One /verify page load. latest = the note's newest version (0: no credential at all). */
async function load({ search = '', note = UID, latest = 5, hidden = false, movedFocus = false } = {}) {
  const t = { made: [], markup: [], fetched: [], focused: [], clicked: [] };
  class El {
    constructor(tag) { this.tag = tag; this.kids = []; this.attrs = {}; this.text = ''; this.value = ''; this.hidden = false; this.on = {}; }
    appendChild(k) { this.kids.push(k); return k; }
    set textContent(s) { this.kids = []; this.text = String(s); }
    get textContent() { return this.text + this.kids.map((k) => k.textContent).join(''); }
    set innerHTML(h) { t.markup.push(h); }
    insertAdjacentHTML(p, h) { t.markup.push(h); }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
    removeAttribute(n) { delete this.attrs[n]; }
    addEventListener(type, fn) { this.on[type] = fn; }
    focus() { document.activeElement = this; t.focused.push(this); }
    click() { t.clicked.push(this); }
  }
  const root = new El('div');
  root.attrs = { 'data-credential-base': BASE, 'data-note': note };
  const box = new El('div');
  box.hidden = hidden;
  const form = new El('form');
  const out = new El('div');
  const ids = { 'sn-compare-uid': new El('input'), 'sn-compare-a': new El('input'), 'sn-compare-b': new El('input'), 'sn-tab-compare': new El('button') };
  ids['sn-compare-uid'].value = note;
  ids['sn-compare-a'].value = '1';
  ids['sn-compare-b'].value = '2';
  const bySel = { '[data-role="compare"]': box, '[data-role="compare-form"]': form, '[data-role="compare-out"]': out };
  root.querySelector = box.querySelector = (s) => bySel[s] || null;
  const body = new El('body');
  const document = {
    body,
    activeElement: movedFocus ? ids['sn-compare-uid'] : body,
    querySelector: (s) => ('.sn-verify' === s ? root : null),
    getElementById: (id) => ids[id] || null,
    createElement: (tag) => { const e = new El(tag); t.made.push(e); return e; },
    createTextNode: (s) => ({ textContent: String(s) }),
  };
  const fetch = (url) => {
    t.fetched.push(url);
    const m = /\?v=(\d+)$/.exec(url);
    const v = m ? Number(m[1]) : latest;
    const found = latest > 0 && v >= 1 && v <= latest;
    return Promise.resolve({ ok: found, json: () => Promise.resolve(cred(v)) });
  };
  const ctx = { console, URLSearchParams, atob, TextDecoder, fetch, document, location: { search } };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(src('prov-verify-core.js'), ctx);
  vm.runInContext(src('prov-verify-diff.js'), ctx);
  await settle();
  return Object.assign(t, { out, form, ids, has: (tag) => t.made.some((e) => e.tag === tag) });
}

/** The page as a bare ?note= link leaves it: nothing fetched, filled or said, focus untouched. */
function untouched(p, why) {
  ok(0 === p.fetched.length && '1' === p.ids['sn-compare-a'].value && '2' === p.ids['sn-compare-b'].value
    && '' === p.out.textContent && 0 === p.focused.length && 0 === p.markup.length, `${why}: ignored silently, the page stays as today`);
}

(async () => {
  console.log('Arrival with the last two versions');
  let p = await load({ search: `?note=${UID}&compare_a=4&compare_b=5` });
  ok(`${BASE}/${UID}` === p.fetched[0], 'the range comes from the note\'s latest credential, fetched first');
  ok(p.fetched.includes(`${BASE}/${UID}?v=4`) && p.fetched.includes(`${BASE}/${UID}?v=5`) && 3 === p.fetched.length, 'then both versions, nothing else: ' + p.fetched.join(' '));
  ok('4' === p.ids['sn-compare-a'].value && '5' === p.ids['sn-compare-b'].value, 'the form shows the versions it compared');
  ok(p.has('del') && p.has('ins'), 'the word diff rendered (del and ins runs)');
  ok(/^v4 .*v5 /.test(p.out.textContent), 'each side is labeled with its version');
  ok(1 === p.focused.length && p.out === p.focused[0] && '-1' === p.out.getAttribute('tabindex'), 'focus lands on the compare output');
  ok(0 === p.clicked.length, 'the Compare tab is left alone when #compare already shows its panel');
  ok(0 === p.markup.length && p.made.every((e) => ['p', 'div', 'span', 'del', 'ins'].includes(e.tag)), 'nothing was written as markup, and only the diff\'s own elements were made');

  p = await load({ search: '?compare_a=4&compare_b=5', hidden: true });
  ok(1 === p.clicked.length && p.ids['sn-tab-compare'] === p.clicked[0], 'no #compare in the link: the Compare tab is selected, so the result is not hidden');
  p = await load({ search: '?compare_a=5&compare_b=4' });
  ok(p.fetched.includes(`${BASE}/${UID}?v=5`) && 1 === p.focused.length, 'a reversed pair is a valid compare, as the form allows');
  p = await load({ search: '?compare_a=4&compare_b=5', movedFocus: true, hidden: true });
  ok(p.has('ins') && 0 === p.focused.length && 0 === p.clicked.length, 'a reader who already moved focus is not pulled away; the compare still renders');

  console.log('Hostile and malformed parameters');
  const bad = ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '1e3', '-1', '0', '1.5', '0x10', '+4', '04', ' 4', '4 ', '４', '1234567', '', 'NaN', 'Infinity'];
  for (const v of bad) {
    untouched(await load({ search: `?compare_a=${encodeURIComponent(v)}&compare_b=5` }), `compare_a=${JSON.stringify(v)}`);
    untouched(await load({ search: `?compare_a=4&compare_b=${encodeURIComponent(v)}` }), `compare_b=${JSON.stringify(v)}`);
  }
  untouched(await load({ search: '?compare_a=4&compare_b=4' }), 'the same version twice');
  untouched(await load({ search: '?compare_a=4' }), 'one version missing');
  untouched(await load({ search: '' }), 'no compare parameters at all');
  untouched(await load({ search: '?from=4&to=5' }), 'names the page does not use');
  for (const n of ['', 'not-a-uid', '"><script>alert(1)</script>', UID + 'x']) {
    untouched(await load({ search: '?compare_a=4&compare_b=5', note: n }), `data-note=${JSON.stringify(n)}`);
  }

  console.log('Valid shape, outside the note\'s range');
  for (const [a, b] of [['4', '6'], ['9', '5'], ['6', '7']]) {
    p = await load({ search: `?compare_a=${a}&compare_b=${b}` });
    ok(1 === p.fetched.length && '1' === p.ids['sn-compare-a'].value && '' === p.out.textContent && 0 === p.focused.length,
      `versions ${a} and ${b} of a five-version note: only the range was read, nothing filled or said`);
  }
  p = await load({ search: '?compare_a=1&compare_b=2', latest: 0 });
  ok(1 === p.fetched.length && '' === p.out.textContent && 0 === p.focused.length, 'a stale link (the note has no credential now): nothing filled or said');

  console.log('The form itself, unchanged');
  p = await load({ search: '' });
  p.ids['sn-compare-a'].value = '2';
  p.ids['sn-compare-b'].value = '3';
  p.form.on.submit({ preventDefault() {} });
  await settle();
  ok(p.fetched.includes(`${BASE}/${UID}?v=2`) && p.fetched.includes(`${BASE}/${UID}?v=3`) && p.has('ins'), 'a typed compare fetches and diffs as before');
  ok(0 === p.focused.length, 'a typed compare does not move focus (the reader is already in the form)');
  p.ids['sn-compare-b'].value = '2';
  p.form.on.submit({ preventDefault() {} });
  ok(/two different version numbers/.test(p.out.textContent), 'the form still refuses the same version twice, in words');

  console.log(`\nResult: ${pass} passed, ${fail} failed.`);
  process.exit(fail > 0 ? 1 : 0);
})();

'use strict';
// 2026-10-12 audit: a notice the reader closed stays closed across repaints.
// os-notice's close sets `hidden`; the morph strips every attribute the paint
// did not carry, so a watch tick (Provenance polls every 30 seconds) brought a
// closed "Saved" back. The real assets/os-host.js runs in a VM against a fake
// document: the reader closes a notice, the morph strips `hidden`, the paint's
// os-app-effect puts it back; a new answer (a save, a refusal) or a different
// notice shows again.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Notice {
  constructor(tone, text) { this.attrs = { tone }; this.textContent = text; }
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  get hidden() { return 'hidden' in this.attrs; }
  set hidden(v) { if (v) this.attrs.hidden = ''; else delete this.attrs.hidden; }
  closest() { return root; }
}

const listeners = {};
let notices = [new Notice('success', ' Settings saved. ')];
const root = {
  nodeType: 1,
  matches: () => true,
  querySelector: () => null,
  querySelectorAll: (sel) => (sel === 'os-notice' ? notices : []),
};
const document = {
  addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
  querySelector: () => null,
  querySelectorAll: () => [],
};
const window = { document, setTimeout: () => 0, requestAnimationFrame: () => 0, WeakMap, Map, Set };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'os-host.js'), 'utf8'), { window, document, WeakMap, Map, Set, console });

const fire = (type, event) => (listeners[type] || []).forEach((fn) => fn(event));
const paint = (effect) => { notices.forEach((n) => { n.hidden = false; }); fire('os-app-effect', { target: root, detail: { effect: Object.assign({ type: 'snt-paint' }, effect || {}) } }); }; // the morph strips `hidden`, then the effect
const close = (n) => { n.hidden = true; fire('os-notice-dismiss', { target: n, detail: {} }); };

assert.ok((listeners['os-notice-dismiss'] || []).length === 1, 'the host listens for a closed notice once');

const [saved] = notices;
close(saved);
paint();
assert.ok(saved.hidden, 'a closed notice stays closed when a watch tick repaints the same one');
paint();
assert.ok(saved.hidden, 'and on the tick after that');

paint({ saved: true, answered: 'sn_save_identity' });
assert.ok(!saved.hidden, 'a new save answers with its notice again, even in the same words');

close(saved);
notices = [new Notice('danger', 'Nothing was saved.')];
paint({ refused: true });
assert.ok(!notices[0].hidden, 'a different notice shows');

const refusal = notices[0];
close(refusal);
notices = [];
paint();
notices = [refusal];
paint();
assert.ok(!refusal.hidden, 'a notice that left the page and came back is new');

fire('os-app-effect', { target: root, detail: { effect: { type: 'other' } } });
console.log('PASS: a closed notice stays closed across repaints');

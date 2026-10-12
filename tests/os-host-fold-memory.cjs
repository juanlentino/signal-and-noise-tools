'use strict';
// 2026-10-12: an earned fold keeps the reader's toggle across repaints. The
// real assets/os-host.js runs in a VM against a fake document: the reader
// opens a fold, the morph strips the attribute, the paint's os-app-effect
// puts it back; a fold the reader closed stays closed while the page's
// verdict (data-snt-open) is unchanged, and reopens when it changes; a fold
// nobody touched follows the paint.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Fold {
  constructor(id, open) { this.attrs = { 'data-snt-fold': id, 'data-snt-open': open ? '1' : '0' }; if (open) this.attrs.open = ''; }
  verdict(open) { this.attrs['data-snt-open'] = open ? '1' : '0'; if (open) this.attrs.open = ''; else delete this.attrs.open; } // a paint that changes its answer
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  hasAttribute(n) { return n in this.attrs; }
  toggleAttribute(n, on) { if (on) this.attrs[n] = ''; else delete this.attrs[n]; return on; }
}

const listeners = {};
const folds = [new Fold('connect-a-client', false), new Fold('scheduled-events', true), new Fold('untouched', false)];
const root = {
  nodeType: 1,
  matches: () => true,
  querySelector: () => null,
  querySelectorAll: (sel) => (sel === 'os-disclosure[data-snt-fold]' ? folds : []),
};
const document = {
  addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
  querySelector: () => null,
  querySelectorAll: () => [],
};
const window = { document, setTimeout: () => 0, requestAnimationFrame: () => 0, WeakMap, Map, Set };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'os-host.js'), 'utf8'), { window, document, WeakMap, Map, Set, console });

const fire = (type, event) => (listeners[type] || []).forEach((fn) => fn(event));
const toggle = (fold, open) => { fold.toggleAttribute('open', open); fire('os-disclosure-toggle', { target: fold, detail: { open } }); };
const paint = () => fire('os-app-effect', { target: root, detail: { effect: { type: 'snt-paint' } } });

assert.ok((listeners['os-disclosure-toggle'] || []).length === 1, 'the host listens for the fold toggle once');

const [connect, events, untouched] = folds;
toggle(connect, true);
delete connect.attrs.open; // the morph: the paint did not carry `open`
paint();
assert.ok(connect.hasAttribute('open'), 'a fold the reader opened is open again after the repaint');

toggle(events, false);
events.attrs.open = ''; // the morph: the paint still says open, the same verdict
paint();
assert.ok(!events.hasAttribute('open'), 'a fold the reader closed stays closed while the page says the same thing');
events.verdict(false); // the late event is fixed: the page now paints it closed
paint();
assert.ok(!events.hasAttribute('open'), 'the page changed its answer: the fold follows the paint (closed)');
events.verdict(true); // a new late event: the page asks for the reader again
paint();
assert.ok(events.hasAttribute('open'), 'a new reason to look reopens a fold the reader once closed');

untouched.attrs.open = '';
paint();
assert.ok(untouched.hasAttribute('open'), 'a fold the reader never touched follows the paint');
delete untouched.attrs.open;
paint();
assert.ok(!untouched.hasAttribute('open'), 'and closes with the paint');

fire('os-app-effect', { target: root, detail: { effect: { type: 'other' } } });
console.log('PASS: earned folds keep the reader\'s toggle across repaints');

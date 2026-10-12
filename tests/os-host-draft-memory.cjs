'use strict';
// 2026-10-12 audit: text the reader typed survives a repaint. The real
// assets/os-host.js runs in a VM against a fake document. The morph puts
// every control the reader is not in back to the painted value; the host
// puts the reader's draft back while the server still paints the value the
// draft was typed over, and drops it when the server paints a new one, when
// the field leaves the leaf, or when the window shows another leaf.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Field {
  constructor(tag, name, value) { this.nodeName = tag; this.attrs = { name }; this.value = value; if (tag.startsWith('OS-')) this.attrs.value = value; }
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  closest() { return root; }
  contains(x) { return x === this; }
  morph(painted) { if (document.activeElement === this) return; this.value = painted; if (this.nodeName.startsWith('OS-')) this.attrs.value = painted; }
}

const listeners = {};
let fields = [];
const leaf = { attrs: { 'data-snt-leaf': 'workflow' }, getAttribute(n) { return this.attrs[n] || null; } };
const root = {
  nodeType: 1,
  matches: () => true,
  querySelector: (sel) => (sel === '.snt-leaf' ? leaf : null),
  querySelectorAll: (sel) => (sel.includes('os-text-field[name]') ? fields : []),
};
const document = {
  activeElement: null,
  addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
  querySelector: () => null,
  querySelectorAll: () => [],
};
const window = { document, setTimeout: () => 0, requestAnimationFrame: () => 0, WeakMap, Map, Set };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'os-host.js'), 'utf8'), { window, document, WeakMap, Map, Set, console });

const fire = (type, event) => (listeners[type] || []).forEach((fn) => fn(event));
const paint = (values) => { fields.forEach((f, i) => f.morph(values[i])); fire('os-app-effect', { target: root, detail: { effect: { type: 'snt-paint' } } }); };
const type = (f, v) => { f.value = v; if (f.nodeName.startsWith('OS-')) f.attrs.value = v; fire('input', { target: f }); };

const title = new Field('OS-TEXT-FIELD', 'workflow_title', 'Old title');
const intro = new Field('TEXTAREA', 'workflow_intro', 'Old intro');
fields = [title, intro];
paint(['Old title', 'Old intro']);

type(title, 'Typed title');
type(intro, 'Typed intro');
paint(['Old title', 'Old intro']); // a list's Edit: the same stored values
assert.equal(title.value, 'Typed title', 'a kit field keeps the typed text across a repaint that paints the same stored value');
assert.equal(intro.value, 'Typed intro', 'a native field does too');

paint(['Typed title', 'Old intro']); // the title was saved; the intro was not
paint(['Typed title', 'Old intro']);
assert.equal(title.value, 'Typed title', 'a saved value stands');
assert.equal(intro.value, 'Typed intro', 'an unsaved one is still the draft');

paint(['Server title', 'Server intro']); // the server now stores something else
assert.equal(title.value, 'Server title', 'a new stored value wins over the draft');
assert.equal(intro.value, 'Server intro', 'for every field');
paint(['Server title', 'Server intro']);
assert.equal(intro.value, 'Server intro', 'and the dropped draft never comes back');

const item = new Field('OS-TEXT-FIELD', 'workflow_item', '');
fields = [title, intro, item];
paint(['Server title', 'Server intro', '']);
type(item, 'half an item');
fields = [title, intro]; // Cancel closes the editor
paint(['Server title', 'Server intro']);
fields = [title, intro, item];
paint(['Server title', 'Server intro', '']);
assert.equal(item.value, '', 'Cancel drops what was typed in the editor it closed');

type(title, 'Focused draft');
document.activeElement = title;
paint(['Server title', 'Server intro', '']); // a watch tick while the reader types
assert.equal(title.value, 'Focused draft', 'the field the reader is in is left alone');
document.activeElement = null;
paint(['Server title', 'Server intro', '']);
assert.equal(title.value, 'Focused draft', 'and its draft survives the next paint after the reader leaves it');

leaf.attrs['data-snt-leaf'] = 'resume';
paint(['Server title', 'Server intro', '']);
assert.equal(title.value, 'Server title', 'another leaf in the window drops the drafts');
console.log('PASS: typed drafts survive repaints until the server paints a new value');

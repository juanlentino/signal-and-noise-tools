'use strict';
// 2026-10-12 audit: text the reader typed survives a repaint. The real
// assets/os-host.js runs in a VM against a fake document that dispatches in
// phases: capture listeners first, then the field's own handler (a kit field
// sets its value there, as os-text-field's _onInput does), then bubble
// listeners. A draft goes back while the server still paints the value it
// was typed over; it is dropped when the server paints a new one, when the
// field leaves the leaf, when the window shows another leaf, and when its
// form was submitted and the paint does not report a refusal.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let fields = [];
let forms = [];
class Form {
  constructor(tag, action) { this.nodeName = tag; this.action = action; }
  getAttribute(n) { return n === 'os-action' ? 'post' : null; }
  querySelector() { return { value: this.action }; }
  closest(sel) { return sel.includes('snt-app') ? root : null; }
}
class Field {
  constructor(tag, name, value, form, type) {
    this.nodeName = tag; this.attrs = { name }; this.value = value; this.form = form;
    if (type) { this.attrs.type = type; this.type = type; }
    if (tag.startsWith('OS-')) this.attrs.value = value;
  }
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  closest(sel) { return sel === 'form, os-form' ? this.form : root; }
  contains(x) { return x === this; }
  morph(painted) { if (document.activeElement === this) return; this.value = painted; if (this.nodeName.startsWith('OS-')) this.attrs.value = painted; }
}

const listeners = { capture: {}, bubble: {} };
const leaf = { attrs: { 'data-snt-leaf': 'redirects' }, getAttribute(n) { return this.attrs[n] || null; } };
const root = {
  nodeType: 1,
  matches: () => true,
  querySelector: (sel) => (sel === '.snt-leaf' ? leaf : null),
  querySelectorAll: (sel) => (sel === 'form, os-form' ? forms : sel.includes('os-text-field[name]') ? fields : []),
};
const document = {
  activeElement: null,
  addEventListener: (type, fn, capture) => { const b = capture ? listeners.capture : listeners.bubble; (b[type] = b[type] || []).push(fn); },
  querySelector: () => null,
  querySelectorAll: () => [],
};
const window = { document, setTimeout: () => 0, requestAnimationFrame: () => 0, WeakMap, Map, Set };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'os-host.js'), 'utf8'), { window, document, WeakMap, Map, Set, console });

const dispatch = (type, event, during) => {
  (listeners.capture[type] || []).forEach((fn) => fn(event));
  if (during) during();
  (listeners.bubble[type] || []).forEach((fn) => fn(event));
};
const paint = (values, refused) => {
  fields.forEach((f, i) => f.morph(values[i]));
  dispatch('os-app-effect', { target: root, detail: { effect: { type: 'snt-paint', refused: !!refused } } });
};
// A keystroke: a native field has its value before the event; a kit field sets it in its own handler.
const type = (f, v) => {
  if (!f.nodeName.startsWith('OS-')) { f.value = v; dispatch('input', { target: f }); return; }
  dispatch('input', { target: f }, () => { f.value = v; f.attrs.value = v; });
};
const pick = (f, v) => dispatch('os-pick', { target: f, detail: { value: v } }, () => { f.value = v; f.attrs.value = v; });
const submit = (form) => dispatch(form.nodeName === 'FORM' ? 'submit' : 'os-form-submit', { target: form });

// Redirects: the rules list, then the add form (kit), then a native form.
const add = new Form('OS-FORM', 'sn_redirect_add');
const from = new Field('OS-TEXT-FIELD', 'source', '', add);
const to = new Field('OS-TEXT-FIELD', 'target', '', add);
const kind = new Field('OS-SELECT', 'type', '301', add);
const key = new Field('OS-TEXT-FIELD', 'key_value', '', add, 'password');
const note = new Field('TEXTAREA', 'note', 'Old note', new Form('FORM', 'sn_note_save'));
forms = [add, note.form];
fields = [from, to, kind, key, note];
paint(['', '', '301', '', 'Old note']);

type(from, '/old-page');
type(to, '/new-page');
pick(kind, '302');
type(key, 'sk-SECRET');
type(note, 'Typed note');
paint(['', '', '301', '', 'Old note']); // a list's Edit: nothing stored changed
assert.equal(from.value, '/old-page', 'a kit field keeps the whole typed text (recorded after the field set it, not a keystroke late)');
assert.equal(kind.value, '302', 'a pick in os-select is kept (os-pick)');
assert.equal(note.value, 'Typed note', 'a native field keeps its text');
assert.equal(key.value, '', 'a password field is never kept');

// An editor form opens ABOVE the add form: the add form's keys do not shift.
const edit = new Form('OS-FORM', 'sn_redirect_update');
const editTarget = new Field('OS-TEXT-FIELD', 'target', '/x', edit);
forms = [edit, add, note.form];
fields = [editTarget, from, to, kind, key, note];
paint(['/x', '', '', '301', '', 'Old note']);
assert.equal(to.value, '/new-page', 'an editor opening above the add form leaves its drafts in place (keys are per form)');

// A refused save keeps the form's drafts; one that took drops them even when the form repaints blank.
submit(add);
paint(['/x', '', '', '301', '', 'Old note'], true);
assert.equal(from.value, '/old-page', 'a refused save keeps what was typed');
submit(add);
paint(['/x', '', '', '301', '', 'Old note'], false);
assert.equal(from.value, '', 'a save that took drops the add form\'s drafts, though it repaints blank');
assert.equal(kind.value, '301', 'every field of the submitted form');
assert.equal(note.value, 'Typed note', 'another form\'s draft is untouched by that save');
paint(['/x', '', '', '301', '', 'Old note']);
assert.equal(from.value, '', 'and the dropped draft never comes back');

// A new stored value wins; a field that leaves the leaf drops its draft.
paint(['/x', '', '', '301', '', 'Server note']);
assert.equal(note.value, 'Server note', 'a new stored value wins over the draft');
type(editTarget, '/half');
forms = [add, note.form];
fields = [from, to, kind, key, note];
paint(['', '', '301', '', 'Server note']);
forms = [edit, add, note.form];
fields = [editTarget, from, to, kind, key, note];
paint(['/x', '', '', '301', '', 'Server note']);
assert.equal(editTarget.value, '/x', 'Cancel drops what was typed in the editor it closed');

// The field the reader is in is left alone, and keeps its draft after.
type(note, 'Focused draft');
document.activeElement = note;
paint(['/x', '', '', '301', '', 'Server note']);
assert.equal(note.value, 'Focused draft', 'the field the reader is in is left alone');
document.activeElement = null;
paint(['/x', '', '', '301', '', 'Server note']);
assert.equal(note.value, 'Focused draft', 'and its draft survives the next paint after the reader leaves it');

leaf.attrs['data-snt-leaf'] = 'webhooks';
paint(['/x', '', '', '301', '', 'Server note']);
assert.equal(note.value, 'Server note', 'another leaf in the window drops the drafts');
console.log('PASS: typed drafts survive repaints until the server paints a new value');

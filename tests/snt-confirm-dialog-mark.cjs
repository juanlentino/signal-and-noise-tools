'use strict';
// 2026-10-12 audit: the shell's confirm dialog is S&N's when the reader opened
// it from an S&N window, and only then. The real assets/snt-confirm.js runs in
// a VM against a fake document: a press or a key in an S&N window, then the
// shell appends <os-confirm-dialog> to <body> (what wp.os.confirm and a kit
// os-confirm trigger both do), and the dialog carries the mark os-app.css
// keys the readable ink on; a dialog opened from anywhere else does not.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Node {
  constructor(name, inSnt) { this.nodeName = name; this.attrs = {}; this.inSnt = !!inSnt; }
  setAttribute(n, v) { this.attrs[n] = String(v); }
  hasAttribute(n) { return n in this.attrs; }
  closest(sel) { return sel === '.snt-app[data-os-app]' && this.inSnt ? {} : null; }
}
const listeners = {};
let observed = null;
let target = null;
class MutationObserver {
  constructor(fn) { this.fn = fn; }
  observe(node, opts) { observed = this; target = { node, opts }; }
}
const body = new Node('BODY');
const document = {
  body,
  addEventListener: (type, fn, capture) => { (listeners[type] = listeners[type] || []).push({ fn, capture }); },
};
const window = { document };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'snt-confirm.js'), 'utf8'), { window, document, MutationObserver, Promise, setTimeout, console });

assert.ok(observed && target.node === body && target.opts.childList, 'the script watches <body> for the dialog the shell appends');
const fire = (type, t) => (listeners[type] || []).forEach((l) => l.fn({ type, target: t }));
const append = (node) => { observed.fn([{ addedNodes: [node] }]); return node; };

fire('pointerdown', new Node('OS-BUTTON', true));
const fromWindow = append(new Node('OS-CONFIRM-DIALOG'));
assert.ok(fromWindow.hasAttribute('data-snt-dialog'), 'a dialog opened by a press in an S&N window is marked');

fire('pointerdown', new Node('BUTTON', false));
const elsewhere = append(new Node('OS-CONFIRM-DIALOG'));
assert.ok(!elsewhere.hasAttribute('data-snt-dialog'), 'a dialog opened from another window is not');

fire('keydown', new Node('OS-TEXT-FIELD', true));
assert.ok(append(new Node('OS-CONFIRM-DIALOG')).hasAttribute('data-snt-dialog'), 'Enter in an S&N form opens a marked dialog too');
const toast = append(new Node('OS-TOAST'));
assert.ok(!toast.hasAttribute('data-snt-dialog'), 'only the confirm dialog is marked');
assert.ok((listeners.pointerdown || []).every((l) => l.capture) && (listeners.keydown || []).every((l) => l.capture), 'the press is read on capture, before any handler can stop it');
console.log('PASS: the shell confirm dialog is marked only when an S&N window opened it');

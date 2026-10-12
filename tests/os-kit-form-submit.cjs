'use strict';
// 2026-10-12: a second Save of one <os-form>. Content > Workflow is ONE form
// (two forms each posted the other's stored values over what was typed) with
// a Save beside each group of fields. Such a button is `data-snt-form-submit`
// and carries no os-action, so the runtime ignores it; the real
// assets/os-kit.js, run in a VM against a fake document, must submit the
// enclosing os-form, once, and nothing else.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const listeners = {};
const document = { addEventListener: (type, fn, capture) => { (listeners[type] = listeners[type] || []).push(fn); } };
const window = { document };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'os-kit.js'), 'utf8'), { window, document, console, JSON });

class Node {
  constructor(tag, attrs, parent) { this.tag = tag; this.attrs = attrs || {}; this.parent = parent || null; }
  hasAttribute(n) { return n in this.attrs; }
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  matches(sel) {
    if (sel === '[data-snt-form-submit]') return this.hasAttribute('data-snt-form-submit');
    if (sel === 'os-form') return this.tag === 'os-form';
    if (sel === '.snt-go[data-snt-tab]') return false;
    return false;
  }
  closest(sel) { for (let n = this; n; n = n.parent) { if (n.matches(sel)) return n; } return null; }
}

let submits = 0;
const form = new Node('os-form', { 'os-action': 'post' });
form.submit = () => { submits++; };
const save = new Node('os-button', { 'data-snt-form-submit': '' }, new Node('os-cluster', {}, form));
const label = new Node('span', {}, save); // a click lands on the label inside the button
const plain = new Node('os-button', {}, form); // an ordinary button in the same form
const loose = new Node('os-button', { 'data-snt-form-submit': '' }, null); // not in a form

const click = (target) => {
  let prevented = false;
  (listeners.click || []).forEach((fn) => fn({ target, preventDefault: () => { prevented = true; } }));
  return prevented;
};

assert.ok(click(label), 'a click inside a data-snt-form-submit button is handled');
assert.equal(submits, 1, 'and submits the enclosing os-form exactly once');
click(plain);
assert.equal(submits, 1, 'an ordinary button in the form submits nothing');
click(loose);
assert.equal(submits, 1, 'a submit button outside any os-form does nothing');
save.attrs.disabled = '';
click(save);
assert.equal(submits, 1, 'a disabled one does nothing');
console.log('PASS: a data-snt-form-submit button submits its own os-form');

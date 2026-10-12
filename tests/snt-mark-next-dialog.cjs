'use strict';
// Review on #2023: two of S&N's own danger confirms open the shell's dialog
// from outside an S&N window (SN Systems' Clear DB overrides, the command
// palette's SN: Full reset), so snt-confirm.js never saw the press and the
// label stayed white on #ff5a5a. assets/desktop-mode.js (always on the shell)
// now offers sntMarkNextDialog(): the caller arms it, and the next
// <os-confirm-dialog> appended to <body> is marked, once. Both callers are
// run here for real: the command's run() and the card's button.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Node {
  constructor(name) { this.nodeName = name; this.attrs = {}; }
  setAttribute(n, v) { this.attrs[n] = String(v); }
  hasAttribute(n) { return n in this.attrs; }
}
let observers = [];
class MutationObserver {
  constructor(fn) { this.fn = fn; this.on = false; }
  observe() { this.on = true; observers.push(this); }
  disconnect() { this.on = false; }
}
const append = (node) => { observers.filter((o) => o.on).forEach((o) => o.fn([{ addedNodes: [node] }])); return node; };
const confirms = [];
const ctx = {
  console, Promise, MutationObserver, setTimeout: () => 0,
  location: { origin: 'https://example.test', href: 'https://example.test/wp-admin/' },
  document: { readyState: 'complete', body: new Node('BODY'), addEventListener() {}, querySelectorAll: () => [] },
  snDesktopData: { pages: {} },
  commands: {},
};
ctx.window = ctx;
ctx.wp = { os: {
  registerCommand: (c) => { ctx.commands[c.slug] = c; },
  confirm: (o) => { confirms.push({ danger: o.danger, armed: observers.some((ob) => ob.on) }); return Promise.resolve(false); },
  toast() {},
} };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'desktop-mode.js'), 'utf8'), ctx);

(async () => {
  assert.equal(typeof ctx.sntMarkNextDialog, 'function', 'desktop-mode.js offers sntMarkNextDialog on the shell');
  ctx.sntMarkNextDialog();
  const first = append(new Node('OS-CONFIRM-DIALOG'));
  const second = append(new Node('OS-CONFIRM-DIALOG'));
  assert.ok(first.hasAttribute('data-snt-dialog'), 'the next dialog appended is marked');
  assert.ok(!second.hasAttribute('data-snt-dialog'), 'and only that one: the mark is one-shot');
  ctx.sntMarkNextDialog();
  assert.ok(!append(new Node('OS-TOAST')).hasAttribute('data-snt-dialog'), 'a toast in between is not marked');
  assert.ok(append(new Node('OS-CONFIRM-DIALOG')).hasAttribute('data-snt-dialog'), 'the armed mark waits for the dialog itself');

  observers = [];
  const reset = ctx.commands['sn-cmd-full-reset'];
  assert.ok(reset, 'the Full reset command is registered');
  await reset.run();
  assert.deepEqual(confirms.pop(), { danger: true, armed: true }, 'SN: Full reset arms the mark before it opens its danger confirm');

  // SN Systems' Clear DB overrides, through the card's own button.
  const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'desktop-mode-widget-health.js'), 'utf8');
  const at = src.indexOf('function confirmAction(');
  const body = src.slice(at, src.indexOf('\n\t}\n', at) + 3);
  const fn = vm.runInContext('(' + body + ')', ctx);
  observers = [];
  await fn('Remove the rows?');
  assert.deepEqual(confirms.pop(), { danger: true, armed: true }, 'Clear DB overrides arms the mark before it opens its danger confirm');
  console.log('PASS: S&N danger confirms opened outside a window mark their dialog');
})().catch((e) => { console.error(e); process.exitCode = 1; });

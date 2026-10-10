/* The real assets/snt-pulse.js under fake timers, focus and transport. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let now = 0, nextId = 0;
const timers = new Map(), calls = [], docL = new Map(), focusL = [];
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
const document = { hidden: false,
  addEventListener(t, fn) { docL.set(t, [...(docL.get(t) || []), fn]); },
  removeEventListener(t, fn) { docL.set(t, (docL.get(t) || []).filter(f => f !== fn)); } };
const window = {
  setTimeout(fn, d) { const id = ++nextId; timers.set(id, { fn, at: now + d }); return id; },
  clearTimeout(id) { timers.delete(id); },
  wp: { apiFetch(o) { return new Promise((resolve, reject) => calls.push({ o, resolve, reject })); } },
  sntPollCadence: { focused: true, wait(ms) { return this.focused ? ms : Math.max(ms, 300000); },
    onFocusChange(cb) { focusL.push(cb); return () => focusL.splice(focusL.indexOf(cb), 1); } },
};
class Clock extends Date { static now() { return now; } }
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../assets/snt-pulse.js'), 'utf8'),
  vm.createContext({ window, document, Date: Clock, Promise, Error, Math, Object }), { filename: 'snt-pulse.js' });
async function tick(ms) {
  const end = now + ms;
  for (;;) {
    const e = [...timers].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
    if (!e) break;
    now = e[1].at; timers.delete(e[0]); e[1].fn(); await flush();
  }
  now = end; await flush();
}
const answer = async (stamps) => { calls[calls.length - 1].resolve(stamps); await flush(); };

(async () => {
  const P = window.sntPulse;
  assert.equal(calls.length, 0, 'no subscriber, no request');
  let content = 0, deploy = 0;
  const offC = P.on('content', () => content++);
  const offD = P.on('deploy', () => deploy++);
  assert.equal(calls.length, 1, 'the first subscriber reads at once');
  assert.equal(calls[0].o.path, '/signal-noise/v1/desktop/pulse');
  await answer({ content: 'a', deploy: 'x' });
  assert.equal(content + deploy, 0, 'the first answer is the baseline and fires nothing');

  await tick(19999); assert.equal(calls.length, 1, 'focused: nothing before 20 s');
  await tick(1); assert.equal(calls.length, 2, 'focused: a read every 20 s');
  await answer({ content: 'b', deploy: 'x' });
  assert.equal(content, 1, 'a moved content stamp fires the content subscribers');
  assert.equal(deploy, 0, 'and only them');

  window.sntPollCadence.focused = false;
  focusL.forEach(cb => cb()); await flush();
  assert.equal(calls.length, 2, 'focus leaving does not read');
  await tick(20000); assert.equal(calls.length, 2, 'visible but unfocused: slows to the 5-minute cadence');
  window.sntPollCadence.focused = true;
  focusL.forEach(cb => cb()); await flush();
  assert.equal(calls.length, 3, 'back in focus: reads at once, so a change made elsewhere shows on return');
  await answer({ content: 'b', deploy: 'y' });
  assert.equal(deploy, 1, 'a moved deploy stamp fires the deploy subscribers');
  focusL.forEach(cb => cb()); await flush();
  assert.equal(calls.length, 3, 'focus moving again within 5 s does not read again (blur into an OpenStation frame)');

  document.hidden = true; (docL.get('visibilitychange') || []).forEach(f => f()); await tick(600000);
  assert.equal(calls.length, 3, 'hidden: no reads');
  document.hidden = false; (docL.get('visibilitychange') || []).forEach(f => f()); await flush();
  assert.equal(calls.length, 4, 'shown again: reads at once');
  calls[3].reject(new Error('down')); await flush();
  await tick(20000); assert.equal(calls.length, 4, 'a failure doubles the wait');
  await tick(20000); assert.equal(calls.length, 5, 'and reads again after it');
  await answer({ content: 'b', deploy: 'y' });

  offC(); offD();
  assert.equal(timers.size, 0, 'the last unsubscribe clears the timer');
  assert.equal((docL.get('visibilitychange') || []).length + focusL.length, 0, 'and drops its listeners');
  await tick(600000); assert.equal(calls.length, 5, 'no subscriber, no more requests');
  process.stdout.write('PASS: pulse baseline, per-stamp fire, cadence, focus return, floor, hidden pause, backoff, teardown\n');
})().catch((e) => { process.stdout.write(String(e && e.stack || e) + '\n'); process.exit(1); });

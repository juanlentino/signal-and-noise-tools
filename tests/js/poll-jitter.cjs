/* The real assets/snt-poll-cadence.js jitter, under a stubbed Math.random. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let r = 0;
const M = Object.create(Math); M.random = () => r;
const window = { addEventListener() {}, removeEventListener() {}, setTimeout() {}, clearTimeout() {} };
window.top = window;
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../assets/snt-poll-cadence.js'), 'utf8'),
  vm.createContext({ window, document: { hidden: false, hasFocus: () => true }, Math: M, Number, String }), { filename: 'snt-poll-cadence.js' });
const C = window.sntPollCadence;
assert.equal(typeof C.jitter, 'function', 'the cadence offers jitter');
const S = C.SPREAD_MS;
assert.ok(S >= 1000 && S <= 10000, 'the spread is seconds, not minutes');
r = 0;   assert.equal(C.jitter(0), 0, 'jitter never shortens a wait');
r = 0;   assert.equal(C.jitter(300000), 300000, 'and adds nothing at the bottom of the draw');
r = 0.5; assert.equal(C.jitter(0), S / 2, 'a due-now read lands inside the spread, so a herd on reveal fans out');
r = 0.5; assert.equal(C.jitter(300000), 300000 + S / 2, 'the spread is added, not scaled: a 0 ms wait still spreads');
r = 0.999999; assert.ok(C.jitter(60000) < 60000 + S, 'and stays under the spread');
process.stdout.write('PASS: poll jitter adds 0 to SPREAD_MS to every wait\n');

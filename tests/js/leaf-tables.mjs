/** Browser regression for the #1624 port (17.9.0): snt_kit_table() slot cells in
 * the REAL <os-table> (built from OPENSTATION_PATH, which should be checked out
 * at the release the site runs), the REAL assets/health-suggest-actions.js and
 * assets/os-kit-stack.js. The PHP leaf tests pin which controls each cell holds;
 * this pins that the component renders them, the scripts still reach them, and
 * a table stacks on the phone stamp and stays stacked through a morph.
 *
 * Run: OPENSTATION_PATH=/path/at/v1.1.11 node tests/js/leaf-tables.mjs
 * (PLAYWRIGHT_PATH / ESBUILD_PATH when packages do not resolve normally.)
 */
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const esbuild = require(process.env.ESBUILD_PATH || 'esbuild');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const shell = process.env.OPENSTATION_PATH || path.resolve(root, '../openstation');
const build = (entry, globalName) => esbuild.buildSync({ entryPoints: [path.join(shell, entry)], bundle: true, write: false, format: 'iife', globalName }).outputFiles[0].text;
const kit = build('src/ui/components/index.ts', 'fixtureKit');
const bindings = build('src/app-runtime/bindings.ts', 'fixtureBindings');
const markup = execFileSync('php', [path.join(root, 'tests/fixtures/leaf-tables.php')], { encoding: 'utf8' });
const read = f => readFileSync(path.join(root, f), 'utf8');

let pass = 0, fail = 0;
const ok = (c, m) => { if (c) { pass++; console.log('PASS: ' + m); } else { fail++; console.log('FAIL: ' + m); } };

const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = []; const warns = [];
page.on('pageerror', e => errors.push(e.message));
page.on('console', m => { if (m.type() === 'warning') warns.push(m.text()); });
await page.setContent(`<!doctype html><html data-os-mode="desktop"><body>${markup}</body></html>`);
await page.addScriptTag({ content: kit });
await page.addScriptTag({ content: bindings });
await page.evaluate(() => {
	window.wp = { i18n: { __: s => s }, apiFetch: () => new Promise(() => {}) }; // the script bails without apiFetch
	window.sntCalls = [];
	window.sntAbilityRun = (slug, input) => { window.sntCalls.push({ slug, input }); return new Promise(() => {}); };
	fixtureBindings.applyProps(document.body, new WeakMap());
});
await page.addScriptTag({ content: read('assets/health-suggest-actions.js') });
await page.addScriptTag({ content: read('assets/os-kit-stack.js') });
await page.waitForTimeout(200);

console.log('Group 1: the component renders every slot cell');
const slots = await page.evaluate(() => [...document.querySelectorAll('os-table')].map(t => {
	const inShadow = [...t.shadowRoot.querySelectorAll('slot[name]')];
	return { id: t.id, slots: inShadow.length, filled: inShadow.filter(s => s.assignedElements().length === 1).length, cells: t.querySelectorAll(':scope > .snt-cell').length };
}));
for (const t of slots) ok(t.slots > 0 && t.slots === t.filled && t.filled === t.cells, `${t.id}: ${t.filled} of ${t.slots} slots filled by exactly one cell each (${t.cells} cells), no blank row`);

console.log('\nGroup 2: the scripts still reach the controls');
await page.click('os-table#health [data-attachment-id="78"]');
await page.waitForTimeout(100);
const calls = await page.evaluate(() => window.sntCalls);
ok(calls.length === 1 && /alt/.test(calls[0].slug) && String(calls[0].input.attachment_id ?? calls[0].input.id ?? JSON.stringify(calls[0].input)).includes('78'), 'Health: Suggest in a bare slot cell resolves its cell and calls the ability for attachment 78');
ok(!warns.some(w => w.includes('no action cell')), 'no "no action cell" warning');
await page.evaluate(() => { window.sntAbilityRun = () => Promise.resolve({}); });
await page.click('os-table#migrations [data-fingerprint="fp2"]');
await page.waitForTimeout(100);
const dismissed = await page.evaluate(() => { const c = document.querySelector('os-table#migrations [data-fingerprint="fp1"]').closest('.snt-cell'); const d = [...document.querySelectorAll('os-table#migrations .snt-cell')].find(x => x.textContent.includes('Dismissed')); return { one: !!d, other: !!c }; });
ok(dismissed.one && dismissed.other, 'Block Migrations: Dismiss marks its own cell Dismissed and leaves the other row alone');
const form = await page.evaluate(() => [...new FormData(document.getElementById('tagsform')).entries()]);
ok(JSON.stringify(form) === JSON.stringify([['sn_tag_into', '1']]), 'Tags: the slotted radio is still a field of the form, one group across rows');

console.log('\nGroup 3: stacking follows the phone stamp');
const stacked = () => page.evaluate(() => [...document.querySelectorAll('os-table')].map(t => t.hasAttribute('stacked')));
ok((await stacked()).every(s => !s), 'desktop: no table is stacked');
await page.evaluate(() => document.documentElement.setAttribute('data-os-mode', 'mobile'));
await page.waitForTimeout(50);
ok((await stacked()).every(s => s), 'phone stamp: every marked table is stacked');
await page.evaluate(() => document.getElementById('health').removeAttribute('stacked')); // what a morph does
await page.waitForTimeout(50);
ok((await stacked())[0] === true, 'a morph that strips stacked is undone');
await page.evaluate(() => document.documentElement.setAttribute('data-os-mode', 'desktop'));
await page.waitForTimeout(50);
ok((await stacked()).every(s => !s), 'back on the desk: the grid returns');

ok(errors.length === 0, 'no page errors: ' + errors.join(' | '));
await browser.close();
console.log(`\nResult: ${pass} passed, ${fail} failed.`);
process.exit(fail > 0 ? 1 : 0);

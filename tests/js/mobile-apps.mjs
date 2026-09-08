/** Local browser regression: real PHP painters, CSS and sibling OS components.
 * No network, WP database, dispatch server, service worker or installed PWA.
 * Prerequisites: installed playwright + esbuild (paths via environment).
 * node tests/js/mobile-apps.mjs; artifacts default to /tmp/snt-mobile.
 *
 * WHAT THIS FILE CANNOT SEE. The fixture below hand-builds the window as
 * `.fixture-window{height:100%;display:flex;flex-direction:column}` +
 * `.fixture-content{flex:1;min-height:0}` — a synthetic height chain that is
 * correct BY CONSTRUCTION. The shipped chain is thirteen elements deep
 * (.os-shell -> .os-shell__body -> .os-area -> .os-window -> __titlebar/__tabs/
 * __body -> os-stack -> os-tabpanel -> .os-app -> .snt-app ->
 * .snt-report-scroll -> .snt-view), and v13.107.2 was a break INSIDE it:
 * native Analytics reports rendered blank because the window body height did
 * not survive the tab stack and panel wrappers. This suite shipped green in
 * v13.107.0 with 46 cases and 800+ assertions and could not have caught it —
 * not for want of assertions, but because the fixture substitutes a healthy
 * copy of the very thing that broke.
 *
 * So: this file measures the APP'S OWN CSS given a sound container. Anything
 * about the shell integration belongs in tests/js/mobile-shell-geometry.mjs,
 * which mounts the real wrappers and traces all thirteen selectors. Adding a
 * case here does NOT extend shell coverage; it inherits this blind spot.
 */
import { createRequire } from 'node:module';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const esbuild = require(process.env.ESBUILD_PATH || 'esbuild');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const shell = process.env.OPENSTATION_PATH || path.resolve(root, '../openstation');
const out = process.env.MOBILE_ARTIFACTS || '/tmp/snt-mobile';
mkdirSync(out, { recursive: true });
const bundle = esbuild.buildSync({ entryPoints: [path.join(shell, 'src/ui/components/index.ts')], bundle: true, write: false, format: 'iife' }).outputFiles[0].text;
const bindings = esbuild.buildSync({ entryPoints: [path.join(shell, 'src/app-runtime/bindings.ts')], bundle: true, write: false, format: 'iife', globalName: 'fixtureBindings' }).outputFiles[0].text;
const css = (file) => readFileSync(file, 'utf8');
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
const results = [];
const sizes = [[360,360,360,false],[844,360,844,false],[320,740,320,true],[390,844,390,true],[430,932,430,true],[844,390,844,true],[768,1024,768,false],[1024,768,1024,false],[1280,900,1280,false],[1440,900,540,false], ...[599,600,601,619,620,621,639,640,641,819,820,821,900].map(w=>[1440,900,w,false])];
for (const [width, height, appWidth, mobile] of sizes) {
 for (const app of ['home', 'analytics']) {
  const page = await browser.newPage({ viewport: {width,height}, hasTouch: mobile, isMobile: mobile });
  page.setDefaultTimeout(3000);
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  const markup = execFileSync('php',[path.join(root,'tests/fixtures/mobile-apps.php'),app],{encoding:'utf8'});
  await page.setContent(`<!doctype html><html data-os-mode="${mobile?'mobile':'desktop'}"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="os-active"><style>html,body{margin:0;height:100%;overflow:hidden;background:var(--os-ui-bg,#1a1721)}*{box-sizing:border-box}.fixture-window{width:${appWidth}px;height:100%;display:flex;flex-direction:column;container-type:inline-size}.fixture-content{flex:1;min-height:0;overflow:hidden} </style><style>${css(path.join(shell,'assets/css/variables.css'))}\n${css(path.join(shell,'assets/css/app-runtime.css'))}\n${css(path.join(root,'assets/analytics/analytics-admin.css'))}\n${css(path.join(root,'assets/os-app.css'))}\n${css(path.join(root,`apps/sn-${app==='home'?'dashboard':'analytics'}/sn-${app==='home'?'dashboard':'analytics'}.css`))}</style><div class="fixture-window"><os-tabs value="main"><os-tab value="main">${app}</os-tab><os-tab value="other">Other report</os-tab></os-tabs><div class="fixture-content">${markup}</div></div></body></html>`);
  await page.addScriptTag({content:bundle});
  await page.addScriptTag({content:bindings});
  if(app==='analytics') await page.addScriptTag({content:css(path.join(root,'apps/sn-analytics/native-tables.js'))});
  await page.waitForTimeout(150);
  const facts = await page.evaluate((app)=>{
   const rect = el => {const r=el.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height,right:r.right,bottom:r.bottom}};
   const q=s=>document.querySelector(s); const host=q('.snt-app');
   const outer=q('.snt-report-scroll');
   const scroll=q(app==='home'?'.snt-home__main':outer && getComputedStyle(outer).overflowY==='auto'?'.snt-report-scroll':'.snt-view');
   const f={host:rect(host),scroll:rect(scroll),scrollHeight:scroll.scrollHeight,scrollWidth:scroll.scrollWidth,clientWidth:scroll.clientWidth};
   if(app==='home') { f.brand=rect(q('.snt-home__brand'));f.labels=[...document.querySelectorAll('.snt-home__action-label')].map(e=>getComputedStyle(e).display);f.actions=[...document.querySelectorAll('.snt-home__action')].map(rect); }
   else {f.filters=[...document.querySelectorAll('.snt-filter')].map(rect);f.toolbar=rect(q('.snt-report-toolbar'));f.dates=[...document.querySelectorAll('input[type=date]')].map(rect); f.charts=[...document.querySelectorAll('.snt-view svg')].map(rect);f.tables=document.querySelectorAll('os-table').length;}
   scroll.scrollTop=scroll.scrollHeight;f.scrolled=scroll.scrollTop;
   return f;
  },app);
  const checks={noHorizontalOverflow:facts.scrollWidth<=facts.clientWidth+1,bodyUsable:facts.scroll.h>=Math.min(180,height/2),canScroll:facts.scrolled>0,noErrors:errors.length===0};
  if(app==='home') {checks.labelsVisible=facts.labels.every(x=>x!=='none');checks.brandFits=facts.brand.w>=150;}
  else {checks.filtersCompact=facts.filters.every(r=>r.h<100);checks.datesFit=facts.dates.every(r=>r.right<=appWidth+1&&r.w>100);checks.nativeTables=facts.tables===2;}
  if(appWidth<=640 && app==='analytics') checks.dateTouchSize=facts.dates.every(r=>r.h>=44);
  if(appWidth<=430 && app==='home') checks.pulseDensity=await page.locator('.snt-home__pulse').first().evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length===2);
  await page.screenshot({path:path.join(out,`${app}-${width}-${appWidth}-bottom.png`)});
  await page.evaluate(()=>document.querySelectorAll('.snt-home__main,.snt-view,.snt-report-scroll').forEach(e=>e.scrollTop=0));
  await page.screenshot({path:path.join(out,`${app}-${width}-${appWidth}.png`)});
  const scrollSelector=app==='home'?'.snt-home__main':await page.locator('.snt-report-scroll').evaluate(e=>getComputedStyle(e).overflowY==='auto')?'.snt-report-scroll':'.snt-view';
  const scrollBox=await page.locator(scrollSelector).boundingBox();
  const x=Math.round(scrollBox.x+scrollBox.width/2), y=Math.round(scrollBox.y+Math.min(scrollBox.height-30,120));
  if(mobile) {
   const cdp=await page.context().newCDPSession(page);
   await cdp.send('Input.synthesizeScrollGesture',{x,y,yDistance:-240,gestureSourceType:'touch'});
   await cdp.detach();
  } else { await page.mouse.move(x,y);await page.mouse.wheel(0,240); }
  await page.waitForTimeout(200);
  checks.scrollGesture=await page.locator(scrollSelector).evaluate(e=>e.scrollTop>0);
  await page.locator(scrollSelector).evaluate(e=>e.scrollTop=0);
  // Exercise real component keyboard/touch behavior, without a dispatch server.
  await page.evaluate(()=>{
   window.fixtureClicks=[]; window.fixtureSubmit=null; window.fixtureRefresh=[];
   const root=document.querySelector('.snt-app');
   root.addEventListener('click',e=>{
    const trigger=fixtureBindings.findTrigger(e.target,e.type,root);
    if(trigger){const binding=fixtureBindings.readBinding(trigger,e);if(binding.action==='refresh')window.fixtureRefresh.push(binding);}
   });
   document.addEventListener('click',e=>{const a=e.composedPath().find(n=>n?.tagName==='A');if(a){e.preventDefault();window.fixtureClicks.push(a.getAttribute('href'));}});
   document.addEventListener('os-form-submit',e=>{e.preventDefault();window.fixtureSubmit=e.detail.values;});
  });
  try {
   const refresh=page.locator('os-button[os-action="refresh"]').getByRole('button');
   checks.refreshUnique=await refresh.count()===1;
   checks.refreshAccessibleName=await page.getByRole('button',{name:app==='home'?'Refresh S&N Home':'Refresh',exact:true}).count()===1;
   await refresh.focus();await refresh.press('Enter');await refresh.press('Space');
   if(mobile) await refresh.tap(); else await refresh.click();
   checks.refreshBinding=await page.evaluate(()=>window.fixtureRefresh.length===3&&window.fixtureRefresh.every(b=>b.action==='refresh'&&b.bind===null&&b.confirm===null));
   checks.refreshTouchSize=await refresh.evaluate(e=>e.getBoundingClientRect().height>=44);
   const tab=page.locator('os-tab[value="other"]');
   if(mobile) await tab.tap(); else await tab.click();
   checks.tabTouchOrClick=await page.locator('os-tabs').getAttribute('value')==='other';
   await tab.press('ArrowLeft');
   checks.tabKeyboard=await page.locator('os-tabs').getAttribute('value')==='main';
   if(app==='home') {
    const links=page.locator('.snt-home__actions a');
    for(let i=0;i<await links.count();i++) { const link=links.nth(i);await link.focus();await link.press('Enter');if(mobile) await link.tap(); }
    checks.allActionsReachable=await page.evaluate(n=>window.fixtureClicks.length===n, mobile?12:6);
    checks.actionTouchSize=facts.actions.every(r=>r.h>=44);
   } else {
    await page.locator('.snt-filter--range').getByRole('combobox').click();
    await page.locator('.snt-filter--range').getByRole('option',{name:'Last 14 days',exact:true}).click();
    checks.rangeSelect=await page.locator('.snt-filter--range').getAttribute('value')==='14';
    await page.locator('input[type="date"][name="sn_from"]').fill('2026-09-02');
    await page.locator('input[type="date"][name="sn_to"]').fill('2026-09-06');
    await page.locator('.snt-custom-range').getByRole('button',{name:'Apply',exact:true}).click();
    checks.dateSubmit=await page.evaluate(()=>window.fixtureSubmit?.sn_from==='2026-09-02'&&window.fixtureSubmit?.sn_to==='2026-09-06');
    const expand=page.getByRole('button',{name:'View all 12',exact:true});await expand.click();
    checks.tableExpand=await page.locator('os-table').first().evaluate(e=>e.data.length===12);
    checks.chartsFit=facts.charts.length>0&&facts.charts.every(r=>r.w>0&&r.right<=appWidth+1);
    const matrix=page.locator('snt-analytics-table').filter({has:page.locator('.snt-native-matrix')});
    await matrix.scrollIntoViewIfNeeded();
    const matrixBefore=await matrix.evaluate(e=>({width:e.clientWidth,extent:e.scrollWidth}));
    if(matrixBefore.extent>matrixBefore.width) {
     await matrix.focus();await matrix.press('ArrowRight');await page.waitForTimeout(150);
     checks.matrixKeyboardScroll=await matrix.evaluate(e=>e.scrollLeft>0);
    }
    checks.matrixRetainsColumns=await page.locator('.snt-native-matrix tbody td').count()===8;
   }
  } catch(error) {checks.interaction=false;errors.push(error.message);}
  results.push({app,width,height,appWidth,mobile,checks,facts,errors});
  await page.close();
 }
}
await browser.close();
writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));
const failures=results.flatMap(r=>Object.entries(r.checks).filter(([,v])=>!v).map(([k])=>`${r.app} ${r.width}/${r.appWidth}: ${k}`));
console.log(JSON.stringify({cases:results.length,failures,artifacts:out},null,2));
process.exitCode=failures.length?1:0;

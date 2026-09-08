/** Local shell-CSS geometry regression, not a booted shell or installed PWA.
 * Real Campaigns route + IndexNow painter; synthetic composite kept as stress coverage.
 * Same dependency env as mobile-apps.mjs; WINDOW_ARTIFACTS controls evidence output.
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
const out = process.env.WINDOW_ARTIFACTS || '/tmp/snt-window-geometry';
mkdirSync(out, { recursive: true });
const read = file => {
 // Negative control: render the two audited CSS files from a released ref,
 // without checking out/reverting the working tree or replacing real painters.
 const relative=path.relative(root,file).split(path.sep).join('/');
 if(process.env.RESPONSIVE_CSS_REF && ['assets/os-app.css','apps/sn-analytics/sn-analytics.css'].includes(relative)) {
  return execFileSync('git',['show',`${process.env.RESPONSIVE_CSS_REF}:${relative}`],{cwd:root,encoding:'utf8'});
 }
 return readFileSync(file,'utf8');
};
const bundle = esbuild.buildSync({ entryPoints: [path.join(shell, 'src/ui/components/index.ts')], bundle: true, write: false, format: 'iife' }).outputFiles[0].text;
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
// Browser W/H and window W/H are four independent inputs. Include viewport-only
// controls, short wide desktop windows, phone/tablet, and container boundaries.
const sizes = [
 [1440,900,844,360], [1440,580,844,360], [1100,900,844,360],
 [1440,900,900,360], [1440,900,360,360], [1440,900,820,360],
 [1440,900,821,360], [1440,900,822,360], [1440,900,823,360],
 [1440,900,900,580], [1440,900,900,659], [1440,900,900,650],
 [1440,900,900,660], [1440,900,900,661], [1440,900,1280,860],
 [390,844,390,760], [430,932,430,848], [844,390,844,360],
 [768,1024,768,900], [1024,768,1024,680],
 ...[599,600,601,639,640,641].map(w => [1440,900,w,700]),
];
const apps = ['analytics-composite', 'analytics-campaigns', 'home', 'connections-indexnow', 'site-performance'];
const results = [];
try {
 for (const [width,height,appWidth,appHeight] of sizes) {
  for (const app of apps) {
   const analytics = app.startsWith('analytics');
   const page = await browser.newPage({ viewport: { width, height } });
   const errors = []; page.on('pageerror', e => errors.push(e.message));
   // No remote resources, even if a painter accidentally starts emitting one.
   await page.route('**/*', route => route.abort());
   const args = app === 'analytics-campaigns' ? ['tests/openstation-app-analytics.php', '--fixture-campaigns']
    : app === 'analytics-composite' || app === 'home' ? ['tests/fixtures/mobile-apps.php', analytics ? 'analytics' : 'home']
    : ['tests/fixtures/responsive-leaf.php', app];
   const markup = execFileSync('php', args.map((arg,i) => i ? arg : path.join(root,arg)), { encoding: 'utf8' });
   const sheets = [path.join(shell,'assets/css/variables.css'), path.join(shell,'assets/css/window-chrome.css'), path.join(shell,'assets/css/app-runtime.css'), path.join(root,'assets/analytics/analytics-admin.css'), path.join(root,'assets/os-app.css'), path.join(root,`apps/sn-${analytics?'analytics':'dashboard'}/sn-${analytics?'analytics':'dashboard'}.css`)];
   // Matches the main native-window ancestor chain. Native tabs are static shell
   // markup, NOT os-tabs, and no shell navigation/resize binding is simulated.
   await page.setContent(`<!doctype html><html data-os-mode="desktop"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="os-active"><style>html,body{margin:0;height:100%;overflow:hidden}*{box-sizing:border-box}${sheets.map(read).join('\n')}</style><div class="os-window os-window--native os-window--focused" style="width:${appWidth}px;height:${appHeight}px;left:0;top:0"><div class="os-window__titlebar">S&N ${analytics?'Analytics':'Home'} · local fixture</div><div class="os-window__tabs"><div class="os-window__tab os-window__tab--active">${analytics?'Campaigns':'Home'}</div><div class="os-window__tab">Other report</div></div><div class="os-window__body os-window__body--native"><div class="os-app">${markup}</div></div></div></body></html>`);
   await page.addScriptTag({ content: bundle });
   if (analytics) await page.addScriptTag({ content: read(path.join(root,'apps/sn-analytics/native-tables.js')) });
   await page.waitForTimeout(120);
   const facts = await page.evaluate(({analytics,app}) => {
    const q = s => document.querySelector(s);
    const rect = e => { const r=e.getBoundingClientRect(); return {left:r.left,top:r.top,right:r.right,bottom:r.bottom,width:r.width,height:r.height}; };
    // Intersect every clipping ancestor, not just the target's own rectangle.
    const visible = e => {
     const r = rect(e); let left=Math.max(0,r.left),top=Math.max(0,r.top),right=Math.min(innerWidth,r.right),bottom=Math.min(innerHeight,r.bottom);
     for(let p=e.parentElement || e.getRootNode().host;p;p=p.parentElement || p.getRootNode().host) {
      const c=getComputedStyle(p),b=rect(p);
      if(/auto|scroll|hidden|clip/.test(c.overflowX)){left=Math.max(left,b.left+p.clientLeft);right=Math.min(right,b.left+p.clientLeft+p.clientWidth);}
      if(/auto|scroll|hidden|clip/.test(c.overflowY)){top=Math.max(top,b.top+p.clientTop);bottom=Math.min(bottom,b.top+p.clientTop+p.clientHeight);}
     }
     return {left,top,right,bottom,width:Math.max(0,right-left),height:Math.max(0,bottom-top)};
    };
    window.fixtureVisible=visible;
    const body=q('.os-window__body'),host=q('.snt-app'),outer=q('.snt-report-scroll');
    const scroll=analytics ? (getComputedStyle(outer).overflowY==='auto'?outer:q('.snt-view')) : q(app==='home'?'.snt-home__main':'.snt-dashboard-body');
    scroll.dataset.geometryScroll='true';
    const report=analytics?q('.snt-view'):scroll;
    const f={body:rect(body),host:rect(host),scroll:visible(scroll),initialReport:visible(report),bodyWidth:body.clientWidth,bodyExtent:body.scrollWidth,scrollWidth:scroll.clientWidth,scrollExtent:scroll.scrollWidth,overflow:outer?getComputedStyle(outer).overflowY:null};
    if(analytics && scroll===outer) scroll.scrollTop=report.getBoundingClientRect().top-scroll.getBoundingClientRect().top;
    f.reachableReport=visible(report);
    scroll.scrollTop=scroll.scrollHeight;
    f.scrollTop=scroll.scrollTop; f.scrollHeight=scroll.scrollHeight;
    const end=report.lastElementChild;
    f.end=rect(end);f.endVisible=visible(end);f.clip=visible(scroll);
    f.endReachable=f.end.bottom<=f.clip.bottom+1 && f.end.bottom>f.clip.top && f.endVisible.width>0;
    if(app==='analytics-campaigns') f.completeRoute=host.dataset.sntView==='campaigns' && q('.sn-an-sep')?.textContent.startsWith('Campaign attribution:') && document.querySelectorAll('os-table').length===2;
    if(app==='connections-indexnow') {
     const link=q('.snt-kv__v a'),code=link.querySelector('os-code');
     const range=document.createRange();range.selectNodeContents(code);
     const selection=getSelection();selection.removeAllRanges();selection.addRange(range);
     f.url={href:link.getAttribute('href'),text:code.textContent,selected:selection.toString(),whiteSpace:getComputedStyle(code.shadowRoot.querySelector('code')).whiteSpace,value:rect(link.closest('.snt-kv__v'))};
     selection.removeAllRanges();
    }
    scroll.scrollTop=0;
    return f;
   },{analytics,app});
   const checks = {
    heightChain: Math.abs(facts.host.height-facts.body.height)<1,
    noHorizontalOverflow: facts.bodyExtent<=facts.bodyWidth+1 && facts.scrollExtent<=facts.scrollWidth+1,
    visibleScrollport: facts.scroll.height>=Math.min(180,facts.body.height/2),
    reachableReport: facts.reachableReport.height>=Math.min(180,facts.body.height/2),
    endReachable: facts.endReachable,
    noErrors: errors.length===0,
   };
   if (analytics) {
    checks.scrolls= facts.scrollTop>0;
    // Preserve the wide/tall desktop's fixed controls, not a universal outer scroll.
    if(appWidth===1280 && appHeight===860) checks.fixedDesktopToolbar=facts.overflow==='hidden';
   }
   if(app==='analytics-campaigns') checks.completeRoute=facts.completeRoute;
   if(app==='connections-indexnow') {
    checks.urlPreserved=facts.url.href===facts.url.text && facts.url.selected===facts.url.text;
    checks.urlValueFits=facts.url.value.right<=facts.body.right+1;
   }
   const scroll=page.locator('[data-geometry-scroll]');
   if(facts.scrollTop>0) {
    await page.mouse.move(facts.scroll.left+facts.scroll.width/2,facts.scroll.top+Math.min(100,facts.scroll.height/2));
    await page.mouse.wheel(0,240);await page.waitForTimeout(120);
    checks.wheelScroll=await scroll.evaluate(e=>e.scrollTop>0);
   }
   const id=`${app}-${width}x${height}-${appWidth}x${appHeight}`;
   await scroll.evaluate(e=>e.scrollTop=0);
   await page.screenshot({path:path.join(out,`${id}-top.png`)});
   await scroll.evaluate(e=>e.scrollTop=e.scrollHeight);
   await page.screenshot({path:path.join(out,`${id}-bottom.png`)});
   if(app==='analytics-campaigns') {
    facts.lastRows=[];
    const tables=page.locator('os-table');
    for(let i=0;i<await tables.count();i++) {
     const table=tables.nth(i);
     await table.evaluate(e=>e.scrollToRow(e.data.length-1));
     await page.waitForTimeout(60);
     const row=await table.evaluate(e=>{
      const last=e.shadowRoot.querySelector('tbody tr:last-child');
      return {count:e.data.length,text:last.textContent,visible:window.fixtureVisible(last),height:last.getBoundingClientRect().height};
     });
     facts.lastRows.push(row);
     checks[`table${i}LastRowReachable`]=row.count===25 && row.visible.height>=row.height-1 && row.visible.width>0;
    }
    await page.screenshot({path:path.join(out,`${id}-last-row.png`)});
   }
   results.push({app,width,height,appWidth,appHeight,checks,facts,errors});
   writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));
   await page.close();
  }
 }
} finally { await browser.close(); }
const failures=results.flatMap(r=>Object.entries(r.checks).filter(([,v])=>!v).map(([key])=>`${r.app} ${r.width}x${r.height}/${r.appWidth}x${r.appHeight}: ${key}`));
console.log(JSON.stringify({cases:results.length,expected:sizes.length*apps.length,checks:results.reduce((n,r)=>n+Object.keys(r.checks).length,0),failures,artifacts:out},null,2));
process.exitCode=failures.length || results.length!==sizes.length*apps.length ? 1 : 0;

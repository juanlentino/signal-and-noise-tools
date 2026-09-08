/** Real mobile shell CSS/ancestor geometry, not booted WordPress or installed PWA.
 * Same dependency env as window-geometry.mjs. MOBILE_ARTIFACTS saves traces.
 * RESPONSIVE_CSS_REF compares released styles without changing the working tree.
 */
import { createRequire } from 'node:module';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const require=createRequire(import.meta.url);
const pw=require(process.env.PLAYWRIGHT_PATH || 'playwright');
const esbuild=require(process.env.ESBUILD_PATH || 'esbuild');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const shell=process.env.OPENSTATION_PATH || path.resolve(root,'../openstation');
const out=process.env.MOBILE_ARTIFACTS || '/tmp/snt-mobile-shell';
mkdirSync(out,{recursive:true});
const read=f=>readFileSync(f,'utf8');
const pluginCSS=f=>process.env.RESPONSIVE_CSS_REF ? execFileSync('git',['show',`${process.env.RESPONSIVE_CSS_REF}:${f}`],{cwd:root,encoding:'utf8'}) : read(path.join(root,f));
const css=['variables','desktop','window-chrome','app-runtime','mobile'].map(f=>read(path.join(shell,`assets/css/${f}.css`))).join('\n')+'\n'+['assets/analytics/analytics-admin.css','assets/os-app.css','apps/sn-analytics/sn-analytics.css'].map(pluginCSS).join('\n');
const bundle=esbuild.buildSync({entryPoints:[path.join(shell,'src/ui/components/index.ts')],bundle:true,write:false,format:'iife'}).outputFiles[0].text;
const markup=execFileSync('php',[path.join(root,'tests/openstation-app-analytics.php'),'--fixture-campaigns'],{encoding:'utf8'});
const browser=await pw[process.env.BROWSER_ENGINE || 'chromium'].launch({headless:true,...(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{})});
const measureGeometry=()=>{
     const q=s=>document.querySelector(s);
     const rect=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,bottom:r.bottom,right:r.right};};
     const visible=e=>{
      const r=e.getBoundingClientRect();let l=Math.max(0,r.left),t=Math.max(0,r.top),b=Math.min(innerHeight,r.bottom),right=Math.min(innerWidth,r.right);
      for(let p=e.parentElement;p;p=p.parentElement){const c=getComputedStyle(p),r=p.getBoundingClientRect();if(/auto|hidden|clip|scroll/.test(c.overflowY)){t=Math.max(t,r.top+p.clientTop);b=Math.min(b,r.top+p.clientTop+p.clientHeight);}if(/auto|hidden|clip|scroll/.test(c.overflowX)){l=Math.max(l,r.left+p.clientLeft);right=Math.min(right,r.left+p.clientLeft+p.clientWidth);}}
      return {width:Math.max(0,right-l),height:Math.max(0,b-t)};
     };
     const trace=['.os-shell','.os-shell__body','.os-area','.os-window','.os-window__titlebar','.os-window__tabs','.os-window__body','os-stack','os-tabpanel:not([hidden])','os-tabpanel:not([hidden]) > .os-app','.snt-app','.snt-report-scroll','.snt-view'].map(selector=>{const e=q(selector),c=getComputedStyle(e);return {selector,...rect(e),heightCSS:c.height,blockSize:c.blockSize,flex:c.flex,contain:c.containerType,display:c.display,overflow:c.overflow,visible:visible(e)};});
     const outer=q('.snt-report-scroll'),report=q('.snt-view'),refresh=q('.snt-report-refresh');
     const scroll=getComputedStyle(outer).overflowY==='auto'?outer:report;
     scroll.scrollTop=0;const controls=visible(refresh);
     scroll.scrollTop=report.getBoundingClientRect().top-scroll.getBoundingClientRect().top;
     const reportVisible=visible(report);
     scroll.scrollTop=scroll.scrollHeight;const end=visible(report.lastElementChild),scrolled=scroll.scrollTop;
     scroll.scrollTop=0;
     const hiddenPanels=[...document.querySelectorAll('os-tabpanel[hidden]')];
     const hiddenPanelsStayHidden=hiddenPanels.length>0 && hiddenPanels.every(e=>getComputedStyle(e).display==='none' && e.getBoundingClientRect().height===0);
     return {trace,controls,reportVisible,end,scrolled,hiddenPanelsStayHidden,completeRoute:q('.snt-app').dataset.sntView==='campaigns',errors:[]};
    };
writeFileSync(path.join(out,'measure.js'),`JSON.stringify((${measureGeometry.toString()})())`);
const results=[];
try {
 for(const [width,height] of [[390,844],[430,932],[844,390],[768,1024],[1024,768],[1440,900]]) {
  for(const display of ['browser','standalone']) {
   const page=await browser.newPage({viewport:{width,height},reducedMotion:'reduce'});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',r=>r.abort());
   await page.setContent(`<!doctype html><html data-os-mode="mobile" data-os-display="${display}"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="os-active os-admin-bar-hidden"><style>html,body{margin:0;height:100%;overflow:hidden}*{box-sizing:border-box}${css}</style><div id="os-shell" class="os-shell" data-os-mobile-state="app"><header class="os-mobile-top">S&N Analytics</header><div class="os-shell__body"><div id="os-area" class="os-area"><div class="os-window os-window--native os-window--focused os-window--maximized" style="width:${Math.min(900,width-18)}px;height:360px;left:0;top:0"><div class="os-window__titlebar">S&N Analytics</div><div class="os-window__tabs"><div class="os-window__tab">Overview</div><div class="os-window__tab">Content</div><div class="os-window__tab os-window__tab--active">Campaigns</div></div><div class="os-window__body os-window__body--native"><os-stack gap="12" padding="0"><os-tabpanel for="main" hidden><div class="os-app" data-os-app="sn-analytics"></div></os-tabpanel><os-tabpanel for="campaigns"><div class="os-app" data-os-app="sn-analytics">${markup}</div></os-tabpanel></os-stack></div></div></div></div><nav class="os-mobile-tabs">Home · Signal & Noise · S&N Home · Open apps</nav></div></body></html>`);
   await page.addScriptTag({content:bundle});
   await page.addScriptTag({content:read(path.join(root,'apps/sn-analytics/native-tables.js'))});
   writeFileSync(path.join(out,`${width}x${height}-${display}.html`),await page.content());
   for(const mode of ['mobile','desktop','mobile']) {
    await page.evaluate(mode=>document.documentElement.dataset.osMode=mode,mode);
    await page.evaluate(async()=>{
     await Promise.all([...document.querySelectorAll('*')].filter(e=>e.localName.startsWith('os-')).map(async e=>{await customElements.whenDefined(e.localName);await e.updateComplete;}));
     await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    });
    const facts=await page.evaluate(measureGeometry);
    // Desktop transitions keep restored 900px width; only assert mobile width
    // clipping here, while the desktop window matrix covers independent sizes.
    const checks={hiddenPanelsStayHidden:facts.hiddenPanelsStayHidden,nonzeroHost:facts.trace.find(x=>x.selector==='.snt-app').height>100,visibleControls:facts.controls.height>=40 && facts.controls.width>0,visibleReport:facts.reportVisible.height>80 && facts.reportVisible.width>0,reportEnd:facts.end.height>0,scrolls:facts.scrolled>0,route:facts.completeRoute,noErrors:errors.length===0};
    if(mode==='mobile')checks.hiddenTitlebar=facts.trace.find(x=>x.selector==='.os-window__titlebar').display==='none';
    results.push({width,height,display,mode,checks,facts,errors});
    writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));
    if(mode==='mobile')await page.screenshot({path:path.join(out,`${width}x${height}-${display}.png`)});
   }
   await page.close();
  }
 }
}finally{await browser.close();}
const failures=results.flatMap((r,i)=>Object.entries(r.checks).filter(([,v])=>!v).map(([k])=>`${i}:${r.width}x${r.height}/${r.display}/${r.mode}:${k}`));
console.log(JSON.stringify({cases:results.length,failures,artifacts:out},null,2));
process.exitCode=failures.length?1:0;

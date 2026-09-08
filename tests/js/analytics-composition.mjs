/** Real Overview route + real toolkit bindings/morph; PHP fixture readers, no WP/network.
 * ANALYTICS_ARTIFACTS output directory; FIXTURE_ROOT can point at a baseline checkout.
 */
import { createRequire } from 'node:module';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const esbuild = require(process.env.ESBUILD_PATH || 'esbuild');
const root = process.env.FIXTURE_ROOT || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const shell = process.env.OPENSTATION_PATH || path.resolve(root, '../openstation');
const out = process.env.ANALYTICS_ARTIFACTS || '/tmp/snt-analytics-composition';
mkdirSync(out, {recursive:true});
const read = p => readFileSync(p, 'utf8');
const bundle = name => esbuild.buildSync({entryPoints:[path.join(shell, name)],bundle:true,write:false,format:'iife',globalName:name.includes('bindings')?'fixtureBindings':name.includes('morph')?'fixtureMorph':'fixtureKit'}).outputFiles[0].text;
const kit = bundle('src/ui/components/index.ts'), bindings = bundle('src/app-runtime/bindings.ts'), morph = bundle('src/app-runtime/morph.ts');
const css = ['variables','desktop','window-chrome','app-runtime','mobile'].map(f=>read(path.join(shell,`assets/css/${f}.css`))).join('\n')+'\n'+['assets/analytics/analytics-admin.css','assets/uptime-status.css','assets/os-app.css','apps/sn-analytics/sn-analytics.css'].map(f=>read(path.join(root,f))).join('\n');
const render = (warning, state={}, action='', args={}) => JSON.parse(execFileSync('php',[path.join(root,'tests/openstation-app-analytics.php'),'--fixture-overview','--json',...(warning?['--warning']:[])],{encoding:'utf8',env:{...process.env,SNT_FIXTURE_STATE:JSON.stringify(state),SNT_FIXTURE_ACTION:action,SNT_FIXTURE_ARGS:JSON.stringify(args)}}));
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH});
const results=[];
const cases=[[390,844,390,700,true],[430,932,430,790,true],[844,390,844,270,true],[768,1024,740,860,false],[1440,900,540,560,false],[1440,900,1000,700,false],[1440,900,1280,740,false],[1440,900,360,360,false],[1440,900,821,360,false]];
try {
for (const [width,height,appWidth,appHeight,mobile] of cases) {
 for (const warning of [false,true]) {
  const id=`${width}x${height}-${appWidth}x${appHeight}-${warning?'warning':'noforecast'}`;
  const page=await browser.newPage({viewport:{width,height},hasTouch:mobile,isMobile:mobile,reducedMotion:'reduce'});
  page.setDefaultTimeout(4000);
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',r=>r.abort());
  const initial=render(warning);
  await page.exposeFunction('fixtureDispatch', (state,action,args)=>render(warning,state,action,args));
  await page.setContent(`<!doctype html><html data-os-mode="${mobile?'mobile':'desktop'}" data-os-display="${mobile?'standalone':'browser'}"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="os-active os-admin-bar-hidden"><style>html,body{margin:0;height:100%;overflow:hidden}*{box-sizing:border-box}${css}</style><div id="os-shell" class="os-shell" data-os-mobile-state="app">${mobile?'<header class="os-mobile-top"><div class="os-mobile-top__identity"><h1 class="os-mobile-top__title">S&N Analytics</h1></div><div class="os-mobile-top__controls"><button class="os-mobile-top__button os-mobile-top__close" aria-label="Close app">×</button></div></header>':''}<div class="os-shell__body"><div id="os-area" class="os-area"><div class="os-window os-window--native os-window--focused ${mobile?'os-window--maximized':''}" style="width:${appWidth}px;height:${appHeight}px;left:0;top:0"><div class="os-window__titlebar">S&N Analytics</div><div class="os-window__tabs">${['Overview','Content','Campaigns','Posts','Technology','Geography','Engagement','Sessions','Quality','Search','Events','Traffic & edge','Login defense'].map((label,i)=>`<div class="os-window__tab ${i===0?'os-window__tab--active':''}">${label}</div>`).join('')}</div><div class="os-window__body os-window__body--native"><os-stack gap="12" padding="0"><os-tabpanel for="main"><div class="os-app" data-os-app="sn-analytics">${initial.html}</div></os-tabpanel><os-tabpanel for="other" hidden><div class="os-app" data-os-app="sn-analytics"></div></os-tabpanel></os-stack></div></div></div></div>${mobile?'<nav class="os-mobile-tabs" aria-label="Primary">'+['Home','Signal & Noise','S&N Home','S&N Analytics','Open apps'].map((label,i)=>`<button class="os-mobile-tabs__item" ${i===3?'aria-current="page"':''}><span class="os-mobile-tabs__icon" aria-hidden="true">${i===4?'1':'◇'}</span><span class="os-mobile-tabs__label">${label}</span></button>`).join('')+'</nav>':''}</div></body></html>`);
  for (const content of [kit,bindings,morph,read(path.join(root,'apps/sn-analytics/native-tables.js'))]) await page.addScriptTag({content});
  // Deterministic transport only: production Uptime painter and lazy detail.
  await page.evaluate(()=>{ window.fixtureUptimeCalls=[];window.sntAbilityRun=async(name,input)=>{window.fixtureUptimeCalls.push({name,input});return {configured:true,rows:[{name:'Fixture site',kind:'monitor',status:'Up',level:'ok',availability:99.98,availability_90d:99.97,response_ms:120},{name:'Fixture rollup',kind:'heartbeat',status:'Up',level:'ok'}],incidents:[]};}; });
  await page.addScriptTag({content:read(path.join(root,'assets/uptime-status.js'))});
  await page.evaluate(async state=>{
   window.fixtureState=state;window.fixtureEvents=[];window.fixtureExports=[];window.fixturePending=Promise.resolve();
   const mount=document.querySelector('os-tabpanel:not([hidden]) > .os-app');
   for (const type of ['click','os-pick','os-form-submit']) mount.addEventListener(type,e=>{
    const trigger=fixtureBindings.findTrigger(e.target,e.type,mount);if(!trigger)return;
    const b=fixtureBindings.readBinding(trigger,e);e.preventDefault();
    window.fixturePending=window.fixturePending.then(async()=>{
     if(b.bind)window.fixtureState[b.bind]=fixtureBindings.boundValue(b.args);
     window.fixtureEvents.push(b);
     const response=await window.fixtureDispatch(window.fixtureState,b.action,b.args);
     window.fixtureState=response.state;fixtureMorph.morphChildren(mount,response.html);
     document.dispatchEvent(new CustomEvent('snt:paint',{detail:{root:mount}}));
     await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    });
   });
   mount.addEventListener('submit',e=>{e.preventDefault();window.fixtureExports.push(Object.fromEntries(new FormData(e.target,e.submitter)));});
   await Promise.all([...document.querySelectorAll('*')].filter(e=>e.localName.startsWith('os-')).map(async e=>{await customElements.whenDefined(e.localName);await e.updateComplete;}));
   await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
  },initial.state);
  const checks={};
  const facts=await page.evaluate(()=>{
   const q=s=>document.querySelector(s), rect=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom};};
   const outer=q('.snt-report-scroll'), report=q('.snt-view'), scroll=getComputedStyle(outer).overflowY==='auto'?outer:report;
   const signal=q('.sn-an-headline summary .sn-an-signal');
   const f={host:rect(q('.snt-app')),toolbar:rect(q('.snt-report-toolbar')),headline:rect(q('.sn-an-headline')),signal:rect(signal),signalDisplay:getComputedStyle(signal).display,signalRadius:getComputedStyle(signal).borderRadius,summary:rect(q('.sn-an-headline summary')),scroll:rect(scroll),scrollWidth:scroll.scrollWidth,clientWidth:scroll.clientWidth,signalText:signal.innerText,period:q('.sn-an-headline-lead').innerText,targets:[...q('.snt-report-toolbar').querySelectorAll('os-select,os-segment,os-button')].map(e=>({tag:e.localName,...rect(e.shadowRoot.querySelector('button'))})),exportTargets:[...q('.snt-export').querySelectorAll('button')].map(rect),hiddenPanel:q('os-tabpanel[hidden]').getBoundingClientRect().height};
   scroll.scrollTop=scroll.scrollHeight;f.scrolled=scroll.scrollTop;f.end=rect(report.lastElementChild);scroll.scrollTop=0;
   return f;
  });
  checks.periodInline=await page.locator('.snt-filter-period').evaluate(e=>{const [a,b]=[...e.children].map(x=>x.getBoundingClientRect());return Math.abs(a.y-b.y)<2&&b.x>a.x;});
  checks.metricsBeforeRoutine=await page.evaluate(w=>{const a=document.querySelector('.snt-native-stats'),b=document.querySelector('.sn-an-headline');return w?!!(b.compareDocumentPosition(a)&Node.DOCUMENT_POSITION_FOLLOWING):!!(a.compareDocumentPosition(b)&Node.DOCUMENT_POSITION_FOLLOWING);},warning);
  checks.chartBeforeRelease=await page.evaluate(()=>{const a=document.querySelector('.sn-an-header-main .sn-spark-wrap'),b=[...document.querySelectorAll('.sn-an-header-main .sn-an-note')].find(x=>x.textContent.includes('releases shipped'));return !!a&&!!b&&!!(a.compareDocumentPosition(b)&Node.DOCUMENT_POSITION_FOLLOWING);});
  checks.completeRail=await page.locator('.sn-an-movers-list li').count()===5&&await page.locator('.sn-an-uptime').count()===1;
  checks.compactPhoneToolbar=facts.host.width>640||facts.toolbar.height<=218;
  checks.boundedDesktopFields=facts.host.width<1000||facts.targets.filter(r=>r.tag==='os-select').every(r=>r.width<=220);
  checks.disclosureAtAction=await page.locator('.sn-an-headline > summary').evaluate(e=>getComputedStyle(e,'::before').content==='none'&&getComputedStyle(e.querySelector('.sn-an-headline-more'),'::before').content!=='none');
  checks.noNestedMetricSurface=await page.locator('.sn-an-header-main os-section').evaluate(e=>getComputedStyle(e.shadowRoot.querySelector('[part="body"]')).backgroundColor==='rgba(0, 0, 0, 0)');
  checks.balancedHeadlineCards=await page.locator('.sn-an-header-main .snt-native-stat').evaluateAll(es=>{const rows=new Map();for(const e of es){const y=e.getBoundingClientRect().y;rows.set(y,(rows.get(y)||0)+1);}return new Set(rows.values()).size===1;});
  checks.metricGutter=await page.locator('.sn-an-header-main').evaluate(e=>Math.abs(e.getBoundingClientRect().x-e.querySelector('.snt-native-stats').getBoundingClientRect().x)<2);
  checks.emptyInsightDisclosure=await page.locator('.sn-an-headline > summary').evaluate(e=>{const html=e.innerHTML;e.querySelectorAll('.sn-an-signal,.sn-an-headline-more').forEach(n=>n.remove());const content=getComputedStyle(e,'::before').content;e.innerHTML=html;return !['none','normal','""'].includes(content);});
  checks.nativeHeight=facts.host.height>100&&facts.hiddenPanel===0;
  checks.noOverflow=facts.scrollWidth<=facts.clientWidth+1;
  checks.readableSignal=facts.signalDisplay==='block'&&parseFloat(facts.signalRadius)<=10;
  checks.periodRetained=facts.period.includes('178 visitor-days, 117 of them viewless');
  checks.forecastOrWarningRetained=facts.signalText.includes(warning?'71% below':'skill -0.35 over 84 checks');
  checks.exportTargets=facts.exportTargets.every(r=>r.height>=44&&r.width>=60);
  checks.controlTargets=facts.targets.every(r=>r.height>=(r.tag==='os-select'?36:44));
  checks.scrollEnd=facts.scrolled>0&&facts.end.bottom<=facts.scroll.bottom+1;
  checks.groupedFilters=await page.locator('.snt-filter-period').count()===1&&await page.locator('.snt-filter-traffic').count()===1;
  checks.filteredVisible=await page.getByText('117 automated filtered (62 bot · 55 suspect)',{exact:true}).isVisible();
  await page.screenshot({path:path.join(out,id+'.png')});
  writeFileSync(path.join(out,id+'.html'),await page.content());
  // Scroll evidence for content intentionally below the fold, never cropped out.
  for (const [label,selector] of [['insights','.sn-an-headline'],['session','os-section[heading="Session quality"]'],['final','os-section[heading="Exit pages"]']]) {
   const target=page.locator(selector).first();
   if(await target.count()){await target.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(out,id+'-'+label+'.png')});}
  }
  await page.locator('.snt-report-scroll,.snt-view').evaluateAll(es=>es.forEach(e=>e.scrollTop=0));
  // The report must remain reachable by actual gestures, not scrollTop alone.
  const scroll=page.locator(await page.locator('.snt-report-scroll').evaluate(e=>getComputedStyle(e).overflowY==='auto')?'.snt-report-scroll':'.snt-view');
  const box=await scroll.boundingBox();
  const x=Math.round(box.x+box.width/2), y=Math.round(box.y+Math.min(110,box.height/2));
  if(mobile) {
   const cdp=await page.context().newCDPSession(page);
   await cdp.send('Input.synthesizeScrollGesture',{x,y,yDistance:-220,gestureSourceType:'touch'});await cdp.detach();
  }else{await page.mouse.move(x,y);await page.mouse.wheel(0,220);}
  await page.waitForTimeout(160);
  checks.scrollGesture=await scroll.evaluate(e=>e.scrollTop>0);
  await scroll.evaluate(e=>e.scrollTop=0);
  // Real popover interaction, binding->PHP validation->real morph->next interaction.
  try {
   const range=page.locator('.snt-filter--range').getByRole('combobox');
   await range.focus();await range.press('Enter');await range.press('Escape');
   checks.escapeFocus=await range.getAttribute('aria-expanded')==='false'&&await range.evaluate(e=>e.getRootNode().activeElement===e);
   await range.press('Enter');await range.press('ArrowDown');await range.press('Enter');await page.evaluate(()=>window.fixturePending);
   checks.rangeRoundtrip=await page.evaluate(()=>window.fixtureState.range==='14');
   checks.focusAfterMorph=await range.evaluate(e=>e.getRootNode().activeElement===e);
   const compare=page.locator('.snt-filter--compare').getByRole('combobox');
   for(const [label,value] of [['Previous','prev'],['Off','off'],['Year over year','yoy']]) {
    await compare.click();await page.locator('.snt-filter--compare').getByRole('option',{name:label,exact:true}).click();await page.evaluate(()=>window.fixturePending);
    checks['compare-'+value]=await page.evaluate(value=>window.fixtureState.compare===value,value);
   }
   const suspect=page.locator('os-segment[value="suspect"]').getByRole('button');
   await suspect.focus();await suspect.press('Enter');await page.evaluate(()=>window.fixturePending);
   checks.classKeyboard=await page.evaluate(()=>window.fixtureState.class==='suspect');
   const human=page.locator('os-segment[value="human"]').getByRole('button');
   await human.focus();await human.press('Space');await page.evaluate(()=>window.fixturePending);
   checks.classSpace=await page.evaluate(()=>window.fixtureState.class==='human');
   if(mobile)await suspect.tap();else await suspect.click();await page.evaluate(()=>window.fixturePending);
   checks.classRoundtrip=await page.evaluate(()=>window.fixtureState.class==='suspect'&&window.fixtureState.compare==='yoy');
   await range.click();await page.locator('.snt-filter--range').getByRole('option',{name:'Custom range…',exact:true}).click();await page.evaluate(()=>window.fixturePending);
   await page.locator('input[name="sn_from"][type="date"]').fill('2026-09-01');
   await page.locator('input[name="sn_to"][type="date"]').fill('2026-09-07');
   await page.locator('.snt-custom-range').getByRole('button',{name:'Apply',exact:true}).click();await page.evaluate(()=>window.fixturePending);
   checks.customRoundtrip=await page.evaluate(()=>window.fixtureState.range==='custom'&&window.fixtureState.from==='2026-09-01'&&window.fixtureState.to==='2026-09-07'&&window.fixtureState.class==='suspect'&&window.fixtureState.compare==='yoy');
   checks.datesFit=await page.locator('input[type="date"]').evaluateAll(es=>es.every(e=>e.getBoundingClientRect().width>100&&e.getBoundingClientRect().right<=innerWidth));
   const refresh=page.locator('.snt-report-refresh').getByRole('button');
   const before=await page.evaluate(()=>window.fixtureState);
   for(const key of ['Enter','Space']) {await refresh.focus();await refresh.press(key);await page.evaluate(()=>window.fixturePending);}
   if(mobile)await refresh.tap();else await refresh.click();await page.evaluate(()=>window.fixturePending);
   checks.refreshRetainsState=JSON.stringify(before)===JSON.stringify(await page.evaluate(()=>window.fixtureState));
   checks.refreshDispatched=await page.evaluate(()=>window.fixtureEvents.filter(b=>b.action==='refresh').length===3);
   for(const format of ['CSV','JSON'])await page.locator('.snt-export').getByRole('button',{name:format,exact:true}).click();
   checks.exports=await page.evaluate(()=>window.fixtureExports.length===2&&window.fixtureExports.every((v,i)=>v.format===(i?'json':'csv')&&v.sn_range==='custom'&&v.sn_class==='suspect'&&v.sn_from==='2026-09-01'&&v.sn_to==='2026-09-07'&&v._wpnonce==='nonce-sn_theme_options_nonce'&&v.sn_action==='analytics_export'));
   const uptime=page.locator('.sn-an-uptime > summary');await uptime.focus();await uptime.press('Enter');
   await page.waitForFunction(()=>document.querySelector('.sn-uw-table'));
   checks.uptimeDetail=await page.getByText('99.98%',{exact:true}).isVisible()&&await page.getByText('No recent incidents.',{exact:true}).isVisible();
   checks.uptimeDoesNotOverflow=await scroll.evaluate(e=>e.scrollWidth<=e.clientWidth+1);
   const uptimeDetail=page.locator('[data-sn-uptime-lazy-detail]');
   const needsHorizontalScroll=await uptimeDetail.evaluate(e=>e.scrollWidth>e.clientWidth+1);
   await uptimeDetail.focus();await uptimeDetail.press('ArrowRight');await page.waitForTimeout(150);
   checks.uptimeKeyboardScroll=!needsHorizontalScroll||await uptimeDetail.evaluate(e=>e.scrollLeft>0);
   checks.uptimeLastColumnReachable=await page.locator('[data-sn-uptime-lazy-detail]').evaluate(e=>{e.scrollLeft=e.scrollWidth;const last=e.querySelector('th:last-child').getBoundingClientRect(),r=e.getBoundingClientRect();return last.right<=r.right+1&&e.tabIndex===0;});
   await page.screenshot({path:path.join(out,id+'-uptime.png')});
   await uptime.press('Space');
   const summary=page.locator('.sn-an-headline > summary');await summary.focus();await summary.press('Enter');
   checks.insightKeyboard=await page.locator('.sn-an-headline').getAttribute('open')!==null&&await page.getByText('Review acquisition sources before changing publishing cadence.',{exact:false}).isVisible();
   await page.screenshot({path:path.join(out,id+'-expanded.png')});
   await summary.press('Space');checks.insightCloses=await page.locator('.sn-an-headline').getAttribute('open')===null;
  }catch(e){checks.interaction=false;errors.push(e.message);}
  checks.noErrors=errors.length===0;
  results.push({id,checks,facts,errors});writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));await page.close();
 }
}
}finally{await browser.close();}
const failures=results.flatMap(r=>Object.entries(r.checks).filter(([,v])=>!v).map(([k])=>`${r.id}: ${k}`));
console.log(JSON.stringify({cases:results.length,failures,artifacts:out},null,2));process.exitCode=failures.length?1:0;

const {chromium}=require('playwright');
const base='http://127.0.0.1:18000';
(async()=>{let browser;try {
 browser=await chromium.launch({headless:true,executablePath:'/home/dabernathy/.local/bin/chromium',args:['--no-sandbox']});
 const a=await browser.newContext({reducedMotion:'reduce'}),b=await browser.newContext({reducedMotion:'reduce'}); const results=[], revisit=[];
 for(let i=0;i<20;i++){
  const p=await a.newPage(),q=await b.newPage(); await p.goto(base); const start=process.hrtime.bigint(); await p.locator('.primary-button').click(); await p.waitForURL('**/games/*',{timeout:30000});
  const url=p.url(); await p.locator('.role-note').filter({hasText:'Player 1'}).waitFor({timeout:30000}); await q.goto(url); await q.locator('.role-note').filter({hasText:'Player 2'}).waitFor({timeout:30000}); await p.locator('button.pit:not([disabled])').first().waitFor({timeout:30000});
  await p.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))); let ms=Number(process.hrtime.bigint()-start)/1e6; results.push(Math.round(ms)); await p.close(); const returning=await a.newPage(); await returning.goto(url,{waitUntil:'commit'}); await returning.locator('.role-note').filter({hasText:'Player 1'}).waitFor({timeout:30000}); await returning.locator('button.pit:not([disabled])').first().waitFor({timeout:30000}); const revisitMs=await returning.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>resolve(performance.now()))))); revisit.push(Math.round(revisitMs)); console.log('fresh',i+1,JSON.stringify({creation:results.at(-1),revisit:revisit.at(-1)})); await returning.close(); await q.close();
 }
 console.log('RESULTS',JSON.stringify({creation:results,revisit}));
 } catch(e){console.error('FAILED',e.stack);process.exitCode=1} finally{await browser?.close()}})();

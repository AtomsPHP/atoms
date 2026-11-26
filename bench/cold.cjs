const {chromium}=require('playwright');
const {execFileSync}=require('child_process');
const base='http://127.0.0.1:18000', worker='http://127.0.0.1:18787';
const token=execFileSync('./vendor/bin/atoms',['token'],{encoding:'utf8'}).trim();
async function debug(id){const r=await fetch(`${worker}/debug/MancalaGame/${id}/info`,{headers:{Authorization:`Bearer ${token}`}}); if(!r.ok) throw Error(`debug status ${r.status}`); return (await r.json()).info}
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{let browser; try {
 browser=await chromium.launch({headless:true,executablePath:'/home/dabernathy/.local/bin/chromium',args:['--no-sandbox']});
 const a=await browser.newContext({reducedMotion:'reduce'}),b=await browser.newContext({reducedMotion:'reduce'}); const rooms=[];
 for(let i=0;i<20;i++) {
  const p=await a.newPage(),q=await b.newPage(); await p.goto(base); await p.locator('.primary-button').click(); await p.waitForURL('**/games/*',{timeout:30000}); const url=p.url();
  await p.locator('.role-note').filter({hasText:'Player 1'}).waitFor({timeout:30000}); await q.goto(url); await q.locator('.role-note').filter({hasText:'Player 2'}).waitFor({timeout:30000}); await p.locator('button.pit:not([disabled])').first().waitFor({timeout:30000});
  await p.close(); await q.close(); await sleep(200); let id=url.split('/').pop(); rooms.push({id,url}); console.log('prepared',i+1);
 }
 for(const room of rooms) { const info=await debug(room.id); room.before=info.constructions; }
 console.log('Idling all rooms for 30 seconds'); await sleep(30000);
 const results=[];
 for(let i=0;i<rooms.length;i++){
  const room=rooms[i],p=await a.newPage(); await p.goto(room.url,{waitUntil:'commit'}); await p.locator('.role-note').filter({hasText:'Player 1'}).waitFor({timeout:30000}); await p.locator('button.pit:not([disabled])').first().waitFor({timeout:30000});
  const ms=await p.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>resolve(performance.now()))))); const after=await debug(room.id); let evicted=after.constructions>room.before; results.push({ms:Math.round(ms),before:room.before,after:after.constructions,evicted}); console.log('sample',i+1,JSON.stringify(results.at(-1))); await p.close();
 }
 console.log('RESULTS',JSON.stringify(results));
 }catch(e){console.error('FAILED',e.stack);process.exitCode=1} finally {await browser?.close()}})();

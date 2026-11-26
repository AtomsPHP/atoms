const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'/home/dabernathy/.local/bin/chromium',args:['--no-sandbox']});
 const a=await browser.newContext({reducedMotion:'reduce'}),b=await browser.newContext({reducedMotion:'reduce'});
 const p=await a.newPage(), q=await b.newPage();
 for (const page of [p,q]) { page.on('pageerror',e=>console.error('PAGE ERROR',e.message)); page.on('response',r=>{if(r.status()>=400) console.error('HTTP',r.status(),new URL(r.url()).pathname)}); }
 try {
 await p.goto('http://127.0.0.1:18000'); await p.locator('.primary-button').click(); await p.waitForURL('**/games/*',{timeout:30000});
 await p.locator('.role-note').filter({hasText:'Player 1'}).waitFor({timeout:30000}); const url=p.url();
 await q.goto(url); await q.locator('.role-note').filter({hasText:'Player 2'}).waitFor({timeout:30000});
 await p.locator('button.pit:not([disabled])').first().waitFor({timeout:30000}); await p.locator('button.pit:not([disabled])').first().click();
 await q.locator('button.pit:not([disabled])').first().waitFor({timeout:30000});
 await p.reload(); await p.locator('.role-note').filter({hasText:'Player 1'}).waitFor({timeout:30000});
 await q.reload(); await q.locator('.role-note').filter({hasText:'Player 2'}).waitFor({timeout:30000}); await q.locator('button.pit:not([disabled])').first().waitFor({timeout:30000});
 console.log('PASS: separate sessions joined, Player 1 moved, both sessions recovered their original seats, Player 2 can move');
 } catch(e){ console.error('FAIL',e.message); console.error('P:',(await p.locator('body').innerText()).slice(0,1000)); console.error('Q:',(await q.locator('body').innerText()).slice(0,1000)); process.exitCode=1; }
 await browser.close();
})();

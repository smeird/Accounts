// With a local PHP server and Playwright available: node tests/mortgages_browser.cjs
// API state is isolated; projections run the real PHP model without touching the database.
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
    const browser=await chromium.launch({headless:true});
    try{
        const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
        page.on('pageerror',e=>errors.push(e.message));
        const today=new Date().toISOString().slice(0,10);
        let saved={revision:1,plan:{lender:'Example mortgage',balance:120000,balance_date:today,monthly_payment:1000,remaining_months:180,rate:4.5,extra_monthly:0,lump_sum:0,rate_change_month:0,future_rate:4.5,recalculate_on_change:true}},conflict=false;
        await page.route('https://code.highcharts.com/**',route=>route.abort());
        await page.route('**/php_backend/public/**',async route=>{
            const req=route.request(),path=new URL(req.url()).pathname;let json={};
            if(path.endsWith('/current_user.php'))json={username:'Preview'};
            if(path.endsWith('/mortgages.php')){
                if(req.method()==='GET')json=saved;
                if(req.method()==='PUT'){
                    if(conflict)return route.fulfill({status:409,json:{error:'This mortgage changed in another window. Reload before saving.'}});
                    saved={revision:saved.revision+1,plan:req.postDataJSON().plan};json=saved;
                }
                if(req.method()==='POST'){
                    json=JSON.parse(execFileSync('php',['-r','require "php_backend/models/MortgagePlanner.php"; $p=json_decode(stream_get_contents(STDIN),true); try {echo json_encode(MortgagePlanner::forecast(MortgagePlanner::validate($p)));} catch (InvalidArgumentException $e) {echo json_encode(["error"=>$e->getMessage()]);}'],{input:JSON.stringify(req.postDataJSON().plan),encoding:'utf8'}));
                    if(json.error)return route.fulfill({status:400,json});
                }
            }
            return route.fulfill({json});
        });
        await page.goto((process.env.ACCOUNTS_TEST_URL||'http://localhost:8000')+'/frontend/mortgages.html',{waitUntil:'domcontentloaded'});
        await page.waitForFunction(()=>!document.getElementById('mortgage-results').hidden);
        assert.equal(await page.locator('#lender').inputValue(),'Example mortgage');
        assert.match(await page.locator('#mortgage-chart').innerText(),/Chart unavailable/);
        assert.match(await page.locator('#mortgage-status').innerText(),/£120,000/);
        await page.locator('#mortgage-editor > summary').click();
        await page.locator('#extra_monthly').fill('200');
        await page.waitForFunction(()=>!document.getElementById('mortgage-results').hidden && document.getElementById('mortgage-metrics').textContent.includes('£1,200'));
        await page.locator('#lump_sum').fill('10000');
        await page.waitForFunction(()=>!document.getElementById('mortgage-results').hidden);
        const metrics=await page.locator('#mortgage-metrics').innerText();assert.doesNotMatch(metrics,/Not available/);
        await page.locator('#mortgage-save').click();
        await page.waitForFunction(()=>document.getElementById('mortgage-save-status').textContent.startsWith('Saved'));
        assert.equal(saved.plan.extra_monthly,200);assert.equal(saved.plan.lump_sum,10000);
        await page.reload({waitUntil:'domcontentloaded'});
        await page.waitForFunction(()=>!document.getElementById('mortgage-results').hidden);
        assert.equal(await page.locator('#extra_monthly').inputValue(),'200');
        await page.locator('#mortgage-editor > summary').click();
        await page.locator('#remaining_months').fill('120');
        await page.waitForFunction(()=>document.getElementById('remaining_months-note').textContent==='10 years');
        await page.waitForFunction(()=>!document.getElementById('mortgage-results').hidden);
        assert.match(await page.locator('#mortgage-warning').innerText(),/still owed/);
        await page.locator('summary').filter({hasText:'What if'}).click();
        await page.locator('#rate_change_month').fill('25');await page.locator('#future_rate').fill('7');
        await page.waitForFunction(()=>!document.getElementById('mortgage-results').hidden && document.getElementById('mortgage-warning').textContent.includes('payment 25'));
        assert.match(await page.locator('#mortgage-warning').innerText(),/recalculated/);
        await page.locator('#recalculate_on_change').uncheck();
        await page.waitForFunction(()=>!document.getElementById('mortgage-results').hidden && document.getElementById('mortgage-warning').textContent.includes('remain fixed'));
        await page.locator('#lump_sum').fill('130000');
        await page.waitForFunction(()=>document.getElementById('mortgage-status').textContent.includes('cannot exceed'));
        assert.equal(await page.locator('#mortgage-results').isHidden(),true);
        await page.locator('#lump_sum').fill('10000');
        await page.waitForFunction(()=>!document.getElementById('mortgage-results').hidden);
        await page.setViewportSize({width:390,height:844});
        await page.waitForTimeout(400);
        if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)) {
            console.log(await page.evaluate(()=>Array.from(document.querySelectorAll('main, main *')).map(e=>({tag:e.tagName,id:e.id,cls:e.className,w:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right})).filter(e=>e.right>innerWidth+2).slice(0,15)));
        }
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Mobile page has no horizontal overflow');
        const shots=process.env.ACCOUNTS_SCREENSHOT_DIR;
        await page.evaluate(()=>document.querySelector('main').scrollTop=0).catch(()=>{});
        if(shots)await page.screenshot({path:shots+'/mortgages-mobile.png'});
        conflict=true;await page.locator('#mortgage-save').click();
        await page.waitForFunction(()=>!document.getElementById('mortgage-error').hidden);
        assert.match(await page.locator('#mortgage-error').innerText(),/another window/);
        await page.setViewportSize({width:1440,height:1000});
        if(shots)await page.screenshot({path:shots+'/mortgages-desktop.png'});
        conflict=false;
        await page.locator('#mortgage-save').click();
        await page.waitForFunction(()=>document.getElementById('mortgage-save-status').textContent.startsWith('Saved'));
        await page.locator('#professional-theme-toggle').check();
        await page.waitForFunction(()=>document.body.classList.contains('theme-professional'));
        assert.equal(await page.locator('#mortgage-results').isVisible(),true,'Paper theme retains the illustration');
        await page.locator('#mortgage-results').scrollIntoViewIfNeeded();
        if(shots)await page.screenshot({path:shots+'/mortgages-paper.png'});
        assert.deepEqual(errors,[]);
        console.log('PASS: Mortgage previews, overpayments, save/reload, term shortfalls, future rates, fixed-payment option, invalid amounts, mobile layout, chart fallback and conflict handling');
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

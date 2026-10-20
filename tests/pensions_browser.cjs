// Run against a local PHP server with Playwright available. Uses isolated API fixtures;
// forecasts execute the real PHP model, and no application database is modified.
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({headless:true});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:1000}});
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        const today=new Date().toISOString().slice(0,10);
        let saved={revision:1,plan:{age:52,age_as_of:today,retirement_age:67,end_age:95,access_age:57,growth:5,retirement_growth:3,inflation:2.5,state_annual:0,state_age:67,target_monthly:0,pots:[{provider:'Example pension',value:250000,monthly:500,fee:.5,valued_on:today}]}};
        let conflict=false;
        await page.route('https://code.highcharts.com/**',route=>route.abort());
        await page.route('**/php_backend/public/**',async route=>{
            const req=route.request();const path=new URL(req.url()).pathname;
            let json={};
            if(path.endsWith('/current_user.php'))json={username:'Preview'};
            if(path.endsWith('/pensions.php')) {
                if(req.method()==='GET')json=saved;
                if(req.method()==='PUT'){
                    if(conflict)return route.fulfill({status:409,json:{error:'This plan changed in another window. Reload before saving to avoid overwriting it.'}});
                    saved={plan:req.postDataJSON().plan,revision:saved.revision+1};json=saved;
                }
                if(req.method()==='POST'){
                    try {json=JSON.parse(execFileSync('php',['-r','require "php_backend/models/PensionPlanner.php"; $plan=json_decode(stream_get_contents(STDIN),true); try {echo json_encode(PensionPlanner::forecast(PensionPlanner::validate($plan)));} catch (InvalidArgumentException $e) {echo json_encode(["error"=>$e->getMessage()]);}'],{input:JSON.stringify(req.postDataJSON().plan),encoding:'utf8'}));}
                    catch(error){return route.fulfill({status:400,json:{error:'Check retirement and end ages.'}});}
                    if(json.error)return route.fulfill({status:400,json:{error:'Check retirement and end ages.'}});
                }
            }
            return route.fulfill({json});
        });
        await page.goto((process.env.ACCOUNTS_TEST_URL||'http://localhost:8000')+'/frontend/pensions.html',{waitUntil:'domcontentloaded'});
        await page.waitForFunction(()=>!document.getElementById('pension-results').hidden);
        assert.equal(await page.locator('[data-pot-key=provider]').inputValue(),'Example pension','Provider names survive form creation');
        assert.match(await page.locator('#pension-metrics').innerText(),/£250,000/);
        assert.match(await page.locator('#pension-chart').innerText(),/Chart unavailable/);
        await page.locator('#pension-editor > summary').click();
        const before=await page.locator('#pension-metrics').innerText();
        await page.locator('#retirement_age').fill('62');
        await page.waitForFunction(()=>document.getElementById('pension-context').textContent.includes('Retire at 62'));
        assert.notEqual(await page.locator('#pension-metrics').innerText(),before);
        await page.locator('#spending_profile').selectOption('three_phase');
        await page.waitForFunction(()=>!document.getElementById('pension-phase-results').hidden);
        assert.equal(await page.locator('#pension-phases tr').count(),3);
        assert.match(await page.locator('#pension-phases').innerText(),/Go-Go[\s\S]*Slow-Go[\s\S]*No-Go/);
        await page.locator('#slow_go_percent').fill('75');
        await page.waitForFunction(()=>document.getElementById('pension-phases').textContent.includes('75%'));
        await page.locator('#save-plan').click();
        await page.waitForFunction(()=>document.getElementById('save-status').textContent.startsWith('Saved'));
        assert.equal(saved.plan.retirement_age,62);
        assert.equal(saved.plan.spending_profile,'three_phase');
        assert.equal(saved.plan.slow_go_percent,75);
        await page.locator('#add-pot').click();
        await page.locator('.pension-pot:last-child [data-pot-key=provider]').fill('Second provider');
        await page.locator('.pension-pot:last-child [data-pot-key=value]').fill('50000');
        await page.waitForFunction(()=>document.getElementById('pension-status').textContent.startsWith('2 pensions'));
        assert.match(await page.locator('#pension-metrics').innerText(),/£300,000/);
        await page.locator('#save-plan').click();
        await page.waitForFunction(()=>document.getElementById('save-status').textContent.startsWith('Saved'));
        await page.reload({waitUntil:'domcontentloaded'});
        await page.waitForFunction(()=>!document.getElementById('pension-results').hidden);
        assert.equal(await page.locator('.pension-pot').count(),2);
        assert.equal(await page.locator('#spending_profile').inputValue(),'three_phase');
        assert.equal(await page.locator('#slow_go_percent').inputValue(),'75');
        await page.setViewportSize({width:390,height:844});
        await page.waitForTimeout(400);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true,'Mobile canvas has no horizontal overflow');
        const desktopShots=process.env.ACCOUNTS_SCREENSHOT_DIR;
        if(desktopShots)await page.screenshot({path:desktopShots+'/pensions-mobile.png',fullPage:true});
        await page.locator('#pension-editor > summary').click();
        await page.locator('#retirement_age').fill('56');
        await page.waitForFunction(()=>document.getElementById('pension-status').textContent.includes('Check retirement'));
        assert.match(await page.locator('#pension-status').innerText(),/Check retirement/);
        await page.locator('#retirement_age').fill('62');
        await page.waitForFunction(()=>!document.getElementById('pension-results').hidden);
        conflict=true;
        await page.locator('#save-plan').click();
        await page.waitForFunction(()=>!document.getElementById('pension-error').hidden);
        assert.match(await page.locator('#pension-error').innerText(),/another window/);
        await page.setViewportSize({width:1440,height:1000});
        if(desktopShots)await page.screenshot({path:desktopShots+'/pensions-desktop.png',fullPage:true});
        assert.deepEqual(errors,[]);
        console.log('PASS: Pension forms, real forecast recalculation, save/reload, multiple pots, mobile layout, chart fallback, invalid ages and conflict handling');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

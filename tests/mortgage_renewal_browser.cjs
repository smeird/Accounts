const {chromium,webkit}=require('playwright');
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
    const browser=await (process.env.ACCOUNTS_TEST_BROWSER==='webkit'?webkit:chromium).launch({headless:true});
    try{
        const page=await browser.newPage({serviceWorkers:'block',viewport:{width:1440,height:1000}}),errors=[];
        page.on('pageerror',e=>errors.push(e.message));
        const today=new Date().toISOString().slice(0,10),year=Number(today.slice(0,4));
        let saved={revision:1,plan:{lender:'Existing fixed mortgage',balance:120000,balance_date:today,monthly_payment:1000,remaining_months:180,rate:3,extra_monthly:0,lump_sum:0,rate_change_month:0,future_rate:4.5,recalculate_on_change:true,deal_start:(year-3)+today.slice(4),deal_end:(year+2)+today.slice(4),property_value:240000,review_months:6,comparison_deals:[{name:'Manual five-year quote',rate:4.5,fixed_months:60,fee:999,cashback:0,add_fee:false}]}},conflict=false,failed=false;
        const sources=['natwest','hsbc','barclays'].map((id,i)=>({id,name:['NatWest','HSBC','Barclays'][i],url:'https://example.com/',scope:'Synthetic public benchmark',last_success:'2026-10-20T10:00:00Z',last_attempt:'2026-10-20T10:00:00Z',stale:false,due:false,error:null,products:[{id:'test',name:'Five-year fixed <script>',rate:4.25,fixed_months:60,fee:995,cashback:0,max_ltv:75,min_ltv:0,min_loan:5000,max_loan:1e6,restrictions:'Eligibility unverified',incentives:'Standard legal fees'}]}));
        await page.route(/https:\/\/(?:code\.highcharts\.com|cdn\.jsdelivr\.net)\//,r=>process.env.ACCOUNTS_HIGHCHARTS_PATH&&r.request().url().endsWith('/highcharts.js')?r.fulfill({path:process.env.ACCOUNTS_HIGHCHARTS_PATH,contentType:'application/javascript'}):r.abort());
        await page.route('**/php_backend/public/**',async route=>{
            const req=route.request(),path=new URL(req.url()).pathname;let json={};
            if(path.endsWith('/current_user.php'))json={username:'Preview'};
            if(path.endsWith('/mortgage_rates.php')){
                if(req.method()==='POST'&&failed){sources.forEach(s=>{s.error='Synthetic source failure · previous rates retained';s.last_attempt='2026-10-24T10:00:00Z';});}
                json={sources};
            }
            if(path.endsWith('/mortgages.php')){
                if(req.method()==='GET')json=saved;
                if(req.method()==='PUT'){
                    if(conflict)return route.fulfill({status:409,json:{error:'This mortgage changed in another window. Reload before saving.'}});
                    saved={revision:saved.revision+1,plan:req.postDataJSON().plan};json=saved;
                }
                if(req.method()==='POST'){
                    json=JSON.parse(execFileSync('php',['-r','require "php_backend/models/MortgagePlanner.php"; try {echo json_encode(MortgagePlanner::forecast(MortgagePlanner::validate(json_decode(stream_get_contents(STDIN),true))));}catch(Throwable $e){echo json_encode(["error"=>$e->getMessage()]);}'],{input:JSON.stringify(req.postDataJSON().plan),encoding:'utf8'}));
                    if(json.error)return route.fulfill({status:400,json});
                }
            }
            return route.fulfill({json});
        });
        await page.goto((process.env.ACCOUNTS_TEST_URL||'http://127.0.0.1:8018')+'/frontend/mortgages.html',{waitUntil:'domcontentloaded'});
        await page.waitForFunction(()=>!document.getElementById('mortgage-renewal').hidden);
        assert.match(await page.locator('#renewal-deadline').innerText(),/days until.*fixed deal ends/);
        assert.match(await page.locator('#renewal-comparisons').innerText(),/Manual five-year quote/);
        assert.equal(await page.locator('#renewal-comparisons tr').count(),1);
        assert.match(await page.locator('#renewal-payment-baseline').innerText(),/£1,000.00/);
        assert.match(await page.locator('#renewal-payment-changes').innerText(),/Manual five-year quote.*Normal:/s);
        if(process.env.ACCOUNTS_HIGHCHARTS_PATH){
            await page.waitForFunction(()=>!!document.querySelector('#renewal-payment-chart svg'));
            const chart=await page.evaluate(()=>{const c=Highcharts.charts.find(c=>c?.renderTo.id==='renewal-payment-chart');return {values:c.series.map(s=>s.options.data),line:c.yAxis[0].options.plotLines[0].value};});
            assert.equal(chart.values[0][0],1000);assert.equal(chart.line,1000);
            assert.ok(chart.values[1][1]>0);
        }else assert.match(await page.locator('#renewal-payment-chart').innerText(),/Chart unavailable/);
        await page.locator('#extra_monthly').evaluate(el=>{el.value='200';el.dispatchEvent(new Event('input',{bubbles:true}));});
        await page.waitForFunction(()=>document.getElementById('renewal-payment-changes').textContent.includes('With overpayments:'));
        if(process.env.ACCOUNTS_HIGHCHARTS_PATH){
            const amounts=await page.evaluate(()=>Highcharts.charts.find(c=>c?.renderTo.id==='renewal-payment-chart').series.map(s=>s.options.data[1]));
            assert.ok(amounts[2]<amounts[1],'Pre-renewal overpayments reduce the illustrated renewal payment');
        }
        await page.screenshot({path:'/private/tmp/mortgage-payment-visual.png',fullPage:true});
        await page.locator('#mortgage-editor').evaluate(e=>e.open=true);
        await page.locator('#deal_end').fill((year+1)+today.slice(4));
        await page.locator('#deal_end').press('Tab');
        await page.waitForFunction(()=>!document.getElementById('mortgage-renewal').hidden&&document.getElementById('renewal-deadline').textContent.includes('days until')).catch(async error=>{console.log(await page.evaluate(()=>({status:document.getElementById('mortgage-status').textContent,valid:document.getElementById('mortgage-form').checkValidity(),invalid:Array.from(document.querySelectorAll('#mortgage-form :invalid')).map(e=>({id:e.id,value:e.value,message:e.validationMessage})),date:document.getElementById('deal_end').value})));throw error;});
        await page.locator('#mortgage-rate-sources details').first().evaluate(e=>e.open=true);
        await page.locator('#mortgage-rate-sources select').first().selectOption('test');
        await page.locator('#mortgage-rate-sources button').first().click();
        assert.equal(await page.locator('.renewal-deal').count(),2);
        assert.equal(await page.evaluate(()=>AppWeb.hasEdits()),true,'Copied comparisons enter the shared unsaved-edit guard');
        await page.locator('#mortgage-save').click();
        await page.waitForFunction(()=>document.getElementById('mortgage-save-status').textContent.startsWith('Saved'));
        assert.equal(saved.plan.comparison_deals.length,2);
        assert.equal(await page.evaluate(()=>AppWeb.hasEdits()),false,'Successful save clears the shared guard');
        assert.equal(saved.plan.comparison_deals[1].rate,4.25);
        assert.equal(saved.plan.deal_end,(year+1)+today.slice(4));
        await page.reload({waitUntil:'domcontentloaded'});
        await page.waitForFunction(()=>!document.getElementById('mortgage-renewal').hidden);
        assert.equal(await page.locator('.renewal-deal').count(),2);
        await page.locator('#mortgage-editor').evaluate(e=>e.open=true);
        await page.locator('[data-deal-field=rate]').first().fill('4.6');
        assert.equal(await page.evaluate(()=>AppWeb.hasEdits()),true,'Manual comparison fields are tracked by the shared edit guard');
        await page.locator('#mortgage-save').click();
        await page.waitForFunction(()=>document.getElementById('mortgage-save-status').textContent.startsWith('Saved'));
        failed=true;await page.locator('#mortgage-rates-refresh').click();
        await page.waitForFunction(()=>!document.getElementById('mortgage-rates-refresh').disabled);
        await page.locator('#mortgage-rate-sources details').first().evaluate(e=>e.open=true);
        assert.match(await page.locator('#mortgage-rate-sources').innerText(),/Latest check failed/);
        assert.match(await page.locator('#mortgage-rate-sources').innerText(),/20\/10\/2026/);
        assert.equal(saved.plan.comparison_deals.length,2,'Collection never modifies saved comparisons');
        await page.locator('#mortgage-editor').evaluate(e=>e.open=true);
        conflict=true;await page.locator('#review_months').fill('4');await page.locator('#mortgage-save').click();
        await page.waitForFunction(()=>!document.getElementById('mortgage-error').hidden);
        assert.match(await page.locator('#mortgage-error').innerText(),/another window/);
        assert.equal(await page.locator('#review_months').inputValue(),'4');
        conflict=false;await page.locator('#mortgage-save').click();
        await page.waitForFunction(()=>document.getElementById('mortgage-save-status').textContent.startsWith('Saved'));
        await page.mouse.move(0,0);
        await page.evaluate(()=>document.activeElement?.blur());
        await page.setViewportSize({width:390,height:844});
        await page.waitForTimeout(300);
        if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)){
            console.log(await page.evaluate(()=>({width:innerWidth,root:document.documentElement.scrollWidth,body:document.body.scrollWidth,unclipped:Array.from(document.querySelectorAll('body *')).filter(e=>{if(e.getBoundingClientRect().right<=innerWidth+.5)return false;for(let p=e.parentElement;p&&p!==document.body;p=p.parentElement){if(['auto','scroll','hidden','clip'].includes(getComputedStyle(p).overflowX)&&p.getBoundingClientRect().right<=innerWidth+1)return false;}return true;}).map(e=>({tag:e.tagName,id:e.id,cls:e.className,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width,position:getComputedStyle(e).position})).slice(0,25)})));
            await page.screenshot({path:'/private/tmp/renewal-overflow.png'});
        }
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Renewal tools do not overflow phone viewport');
        await page.evaluate(()=>document.querySelector('main').scrollTop=0);
        await page.screenshot({path:'/private/tmp/mortgage-renewal-mobile.png'});
        await page.setViewportSize({width:1440,height:1000});
        await page.locator('#professional-theme-toggle').check();
        await page.waitForFunction(()=>document.body.classList.contains('theme-professional'));
        await page.evaluate(()=>document.querySelector('main').scrollTop=0);
        await page.waitForTimeout(500);
        await page.screenshot({path:'/private/tmp/mortgage-renewal-paper.png'});
        assert.deepEqual(errors,[]);
        console.log('PASS: Renewal deadline, real calculator, editable lender benchmark, save/reload, retained success after failure, conflicts, mobile overflow and Paper layout');
    }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

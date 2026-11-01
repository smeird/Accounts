const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const source=JSON.parse(execFileSync('php',['-r',`require 'php_backend/models/TotalAssets.php'; $p=PensionPlanner::defaults();$p['pots']=[['provider'=>'Example pension','value'=>250000,'monthly'=>500,'fee'=>.5,'valued_on'=>date('Y-m-d')]];$m=MortgagePlanner::defaults();$m['balance']=150000;$m['property_value']=450000;$s=TotalAssets::defaults($p,$m);$s['target_monthly']=2000;echo json_encode(['pension'=>['plan'=>$p,'revision'=>1],'mortgage'=>['plan'=>$m,'revision'=>1],'scenario'=>$s,'revision'=>0,'accounts'=>[['name'=>'Current account','ledger_balance'=>25000,'ledger_balance_date'=>date('Y-m-d')]]]);`],{encoding:'utf8'}));
  let conflict=false;
  await page.route('https://code.highcharts.com/**',r=>process.env.ASSETS_CHART_TEST?r.fulfill({path:r.request().url().includes('/modules/')?'/private/tmp/total-assets-accessibility.js':'/private/tmp/total-assets-highcharts.js',contentType:'text/javascript'}):r.abort());
  await page.route('**/php_backend/public/**',async route=>{
   const req=route.request(),path=new URL(req.url()).pathname;
   if(path.endsWith('/current_user.php'))return route.fulfill({json:{username:'Preview'}});
   if(path.endsWith('/total_assets.php')){
    if(req.method()==='GET')return route.fulfill({json:source});
    if(conflict&&req.method()==='PUT')return route.fulfill({status:409,json:{error:'This scenario changed in another window. Reload before saving.'}});
    const s=req.postDataJSON().scenario;
    const d=JSON.parse(execFileSync('php',['-r',`require 'php_backend/models/TotalAssets.php';$i=json_decode(stream_get_contents(STDIN),true);try{echo json_encode(TotalAssets::forecast($i['s'],$i['p'],$i['m'],25000));}catch(InvalidArgumentException $e){echo json_encode(['error'=>$e->getMessage()]);}`],{input:JSON.stringify({s,p:source.pension.plan,m:source.mortgage.plan}),encoding:'utf8'}));
    if(d.error)return route.fulfill({status:400,json:d});
    if(req.method()==='PUT'){source.scenario=s;source.revision++;d.saved={scenario:s,revision:source.revision};}
    return route.fulfill({json:d});
   }
   return route.fulfill({json:{}});
  });
  await page.goto('http://localhost:8000/frontend/total_assets.html');
  await page.waitForFunction(()=>document.getElementById('assets-status').textContent.startsWith('Current preview'));
  assert.match(await page.locator('#assets-metrics').innerText(),/£575,000/);
  if(process.env.ASSETS_CHART_TEST)await page.waitForSelector('#assets-chart .highcharts-series');else assert.match(await page.locator('#assets-chart').innerText(),/Chart unavailable/);
  assert(await page.locator('#assets-years tr').count()>30);
  assert.equal(await page.locator('#end_age').inputValue(),'90');
  assert.match(await page.locator('#assets-metrics').innerText(),/Retirement income \/ day/);
  await page.locator('#assets-age').fill('0');await page.locator('#assets-age').dispatchEvent('input');
  assert.match(await page.locator('#assets-age-detail').innerText(),/Age 52/);
  await page.locator('#assets-view').selectOption('flows');
  if(process.env.ASSETS_CHART_TEST){assert.equal(await page.evaluate(()=>Highcharts.charts.filter(Boolean)[0].options.chart.type),'column');assert(await page.evaluate(()=>Highcharts.charts.filter(Boolean)[0].series.some(s=>s.name==='Mortgage payments')));}
  await page.locator('#working_income').fill('3500');await page.locator('#working_spending').fill('1200');
  await page.waitForFunction(()=>document.getElementById('assets-age-detail').textContent.includes('£3,500'));
  await page.locator('#assets-view').selectOption('assets');
  await page.locator('#extra_pension').fill('500');
  await page.waitForFunction(()=>document.getElementById('assets-contributions').textContent.includes('£500 extra'));
  await page.locator('#replacement_home').fill('250000');await page.locator('#moving_costs').fill('10000');await page.locator('#downsize').check();
  await page.waitForFunction(()=>document.getElementById('assets-downsize').textContent.includes('£40,000 released'));
  await page.locator('#assets-save').click();await page.waitForFunction(()=>document.getElementById('assets-save-state').textContent==='Scenario saved');
  conflict=true;await page.locator('#assets-save').click();await page.waitForFunction(()=>document.getElementById('assets-error').textContent.includes('changed in another window'));
  await page.evaluate(()=>{document.querySelectorAll('#notification-stack .site-notification').forEach(t=>window.dismissNotification(t));document.querySelector('.app-shell-main').scrollTop=0;});
  await page.waitForTimeout(500);await page.screenshot({path:'/private/tmp/total-assets-desktop.png'});
  if(process.env.ASSETS_CHART_TEST){await page.evaluate(()=>localStorage.setItem('professionalThemeEnabled','true'));await page.reload();await page.waitForSelector('#assets-chart .highcharts-series');await page.screenshot({path:'/private/tmp/total-assets-paper.png'});}

  await page.setViewportSize({width:390,height:844});
  await page.evaluate(()=>{document.getElementById('menu')?.classList.add('hidden');document.querySelector('.app-shell-main').scrollTop=0;});
  if(process.env.ASSETS_CHART_TEST)await page.evaluate(()=>Highcharts.charts.filter(Boolean).forEach(c=>c.reflow()));
  await page.waitForTimeout(500);await page.screenshot({path:'/private/tmp/total-assets-phone.png'});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'No phone document overflow');
  await page.locator('#downsize').uncheck();await page.locator('#retirement_age').fill('40');await page.waitForFunction(()=>document.getElementById('assets-error').textContent.includes('current age'));
  assert.equal(await page.locator('#assets-metrics').innerText(),'','Invalid scenarios clear stale values');
  assert.deepEqual(errors,[]);console.log('Total Assets desktop/phone browser checks passed');
 }finally{await browser.close();}
})();

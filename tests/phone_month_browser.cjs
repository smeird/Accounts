// NODE_PATH=<bundled node_modules> node tests/phone_month_browser.cjs
// For offline chart checks, set HIGHCHARTS_TEST_SOURCE to a local highcharts.js file.
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
(async()=>{
    const browser=await chromium.launch({headless:true});
    try{
        const page=await browser.newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
        const errors=[];page.on('pageerror',error=>errors.push(error.message));
        let empty=false,failChart=false;
        const categories=[{id:1,name:'Home and household costs with a long name',amount:500},{id:2,name:'Groceries',amount:200},{id:3,name:'Travel',amount:100},{id:4,name:'Leisure',amount:80},{id:null,name:'Uncategorised',amount:70},{id:6,name:'Subscriptions',amount:50}];
        await page.route('https://code.highcharts.com/**',route=>failChart?route.abort():process.env.HIGHCHARTS_TEST_SOURCE?route.fulfill({contentType:'application/javascript',body:fs.readFileSync(process.env.HIGHCHARTS_TEST_SOURCE,'utf8')}):route.continue());
        await page.route('https://accounts.test/**',route=>{
            const url=new URL(route.request().url());
            if(url.pathname.includes('/php_backend/public/')){
                if(url.pathname.endsWith('/current_user.php'))return route.fulfill({json:{username:'Preview'}});
                if(url.pathname.endsWith('/search_transactions.php'))return route.fulfill({json:{
                    period:{start:'2026-10-01',end:'2026-10-31',label:'October 2026'},
                    summary:{income:2000,spending:empty?0:1000,cashflow:empty?2000:1000,signed_total:1000},
                    pagination:{page:Number(url.searchParams.get('page'))||1,pages:2,total:70},
                    results:empty?[]:[{id:1,date:'2026-10-20',description:'Example purchase',amount:-50,transfer_id:null,category_name:'Groceries'}],
                    ...(url.searchParams.has('include_categories')?{categories:empty?[]:categories}:{})
                }});
                return route.fulfill({json:[]});
            }
            const file=path.join(root,url.pathname);
            const type=file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':file.endsWith('.html')?'text/html':'application/octet-stream';
            return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({contentType:type,body:fs.readFileSync(file)}):route.fulfill({status:404,body:''});
        });
        await page.goto('https://accounts.test/frontend/phone.html?view=month');
        await page.waitForSelector('.phone-spending-chart svg');
        assert.equal(await page.locator('.phone-spending-key').first().locator('button').count(),4);
        assert.match(await page.locator('.phone-spending-more summary').innerText(),/£120.00.*2 more categories/);
        assert.ok((await page.locator('.phone-spending').boundingBox()).height<=200,'Collapsed graphic stays compact');
        for(const [width,height,dark,large] of [[390,844,false,false],[320,568,true,false],[430,932,false,true],[844,390,true,false]]){
            await page.setViewportSize({width,height});await page.emulateMedia({colorScheme:dark?'dark':'light'});
            await page.evaluate(large=>document.documentElement.classList.toggle('phone-larger-text',large),large);
            assert.ok(await page.evaluate(()=>document.querySelector('main').scrollWidth<=document.querySelector('main').clientWidth),'Phone layout has no horizontal overflow');
            for(const item of await page.locator('.phone-spending-key').first().locator('button').all())assert.ok((await item.boundingBox()).height>=44,'Category links keep touch targets');
        }
        await page.setViewportSize({width:390,height:844});await page.emulateMedia({colorScheme:'light'});
        await page.evaluate(()=>document.documentElement.classList.remove('phone-larger-text'));
        await page.screenshot({path:'/private/tmp/accounts-phone-month.png'});
        await page.locator('.phone-segments button').filter({hasText:'Money in'}).click();
        await page.waitForSelector('.phone-spending-chart svg');
        assert.match(await page.locator('.phone-spending').innerText(),/£500.00/,'Income filter retains full-month composition');
        await page.getByRole('button',{name:'Next',exact:true}).click();
        await page.waitForSelector('.phone-spending-chart svg');
        assert.match(await page.locator('.phone-spending').innerText(),/£500.00/,'Pagination retains full-month composition');
        await page.locator('.phone-spending-more summary').click();
        await page.getByRole('button',{name:/View Uncategorised:/}).click();
        const evidence=new URL(page.url()).searchParams;
        for(const [key,value] of Object.entries({view:'search',start:'2026-10-01',end:'2026-10-31',direction:'spending',transfer_scope:'exclude',ignored_scope:'exclude',dimension:'category',unclassified:'1'}))assert.equal(evidence.get(key),value);
        empty=true;await page.goto('https://accounts.test/frontend/phone.html?view=month');
        await page.getByText('No spending recorded this month.').waitFor();
        assert.equal(await page.locator('.phone-spending-chart').count(),0);
        empty=false;failChart=true;await page.reload();
        await page.getByRole('button',{name:/View Groceries:/}).waitFor();
        assert.ok(await page.getByRole('button',{name:/View Example purchase/}).isVisible(),'Chart failure does not block ledger');
        assert.deepEqual(errors,[]);
        console.log('PASS: Compact phone chart, responsive light/dark/larger text, complete-month scope, exact category evidence, empty state and unavailable-chart fallback');
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

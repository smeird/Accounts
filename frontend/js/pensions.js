(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const api = '../php_backend/public/pensions.php';
    const money = value => new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP',maximumFractionDigits:0}).format(value);
    const daily = value => new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP',maximumFractionDigits:2}).format(value);
    const now = new Date();
    const today = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
    let revision=0, loaded=false, timer, request=0, dirty=false, chart=null, latest=null, controller=null;
    const fields = [
        ['age','Age on the date below',18,90,.01,52,'Your age on the reference date. Decimals are allowed; update this for an accurate forecast.'],
        ['age_as_of','Age reference date',null,null,null,today,'The date on which the age above is correct. The planner advances your age automatically.'],
        ['retirement_age','Retire at age',55,90,1,67,'Move this slider to explore earlier or later retirement.'],
        ['end_age','Make the pot last to age',60,110,1,95,'This is a planning horizon, not a prediction of your lifespan.'],
        ['target_monthly','Optional monthly target (£ today, before tax)',0,1000000,1,0,'Leave at zero to explore income without setting a target. A target enables the earliest-age illustration.'],
        ['growth','Growth before retirement (%)',-10,15,.1,5,'Nominal annual growth before fees. Lower/higher scenarios use two percentage points less/more.'],
        ['retirement_growth','Growth during retirement (%)',-10,15,.1,3,'Nominal annual growth before fees during drawdown.'],
        ['inflation','Annual inflation (%)',0,10,.1,2.5,'Used to compare future pounds with today’s purchasing power.'],
        ['access_age','Confirmed private pension access age',55,75,1,57,'Normally 57 from April 2028. All included pots must be accessible at this age; confirm any exception with each provider.'],
        ['state_annual','State Pension per year (£ today)',0,100000,1,0,'Enter your personal annual forecast, not an assumed full entitlement. Zero excludes State Pension.'],
        ['state_age','State Pension starts at age',60,80,1,67,'Confirm this using the GOV.UK State Pension age checker.']
    ];
    function field(parent, key, title, min, max, step, value, help, range=false) {
        const wrap=document.createElement('div'); wrap.className='pension-field';
        const label=document.createElement('label'); label.htmlFor=key; label.textContent=title;
        const input=document.createElement('input'); input.id=key; input.name=key; input.type=min===null?'date':'number'; input.value=value; input.required=true;
        input.dataset.help=help; input.setAttribute('aria-label',title);
        if(min!==null) {input.min=min; input.max=max; input.step=step;} else input.max=today;
        wrap.append(label);
        if(range) {
            const pair=document.createElement('div'); pair.className='pension-range-pair';
            const slider=document.createElement('input'); slider.type='range'; slider.min=min; slider.max=max; slider.step=step; slider.value=value;
            slider.setAttribute('aria-label',title+' slider'); slider.dataset.help=help;
            slider.addEventListener('input',()=>{input.value=slider.value;});
            input.addEventListener('input',()=>{slider.value=input.value;});
            pair.append(slider,input);wrap.append(pair);input._slider=slider;
        } else wrap.append(input);
        parent.append(wrap);return input;
    }
    fields.forEach((f,i)=>field($(i<5?'pension-controls':'pension-assumptions'),...f,['retirement_age','end_age','growth','retirement_growth','inflation'].includes(f[0])));
    let sequence=0;
    function addPot(pot={provider:'',value:0,monthly:0,fee:.5,valued_on:today}) {
        const card=document.createElement('article');card.className='pension-pot';const n=++sequence;
        [['provider','Provider / pension name',null,null,null,pot.provider,'Use a recognisable name; no policy number is needed.'],
            ['value','Current pot value (£)',0,1e9,.01,pot.value,'Latest valuation from your provider.'],
            ['monthly','Total monthly contribution (£)',0,1e6,.01,pot.monthly,'Total credited to the pot including employer payments and tax relief; no further relief is added.'],
            ['fee','Annual fee (%)',0,5,.01,pot.fee,'Total annual percentage fee. Set zero if your growth assumption already includes fees.'],
            ['valued_on','Valuation date',null,null,null,pot.valued_on,'Keep this current. Values are treated as today’s balances; past growth is not invented.']].forEach(f=>{
                const input=field(card,'pot-'+n+'-'+f[0],...f.slice(1));input.dataset.potKey=f[0];
                if(f[0]==='provider'){input.type='text';input.value=pot.provider;input.maxLength=120;input.removeAttribute('max');}
            });
        const remove=document.createElement('button');remove.type='button';remove.className='pension-remove';remove.textContent='Remove pension';remove.setAttribute('aria-label','Remove this pension pot');
        remove.addEventListener('click',()=>{if(window.confirm('Remove this pension from the plan? Save the plan to make this permanent.')){card.remove();changed();}});
        card.append(remove);$('pension-pots').append(card);$('pension-empty').hidden=true;
    }
    function collect() {
        const plan={};fields.forEach(([key])=>{plan[key]=key==='age_as_of'?$(key).value:Number($(key).value);});
        plan.pots=[...document.querySelectorAll('.pension-pot')].map(card=>{
            const pot={};card.querySelectorAll('[data-pot-key]').forEach(input=>{pot[input.dataset.potKey]=['provider','valued_on'].includes(input.dataset.potKey)?input.value:Number(input.value);});return pot;
        });return plan;
    }
    async function send(method,body,signal) {
        const response=await fetch(api,{method,cache:'no-store',headers:{'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined,signal});
        const data=await response.json();if(!response.ok)throw new Error(data.error||'Pensions request failed.');return data;
    }
    function invalidate(message='Enter valid details to update the forecast.') {
        latest=null;$('pension-results').hidden=true;$('pension-timeline').hidden=true;$('pension-status').textContent=message;
    }
    function changed() {
        if(!loaded)return;
        dirty=true;$('pension-plan-state').textContent='Unsaved preview · the dashboard still uses your saved plan.';request++;controller?.abort();invalidate();$('save-status').textContent='Unsaved changes · dashboard still uses your saved plan.';
        $('pension-empty').hidden=document.querySelectorAll('.pension-pot').length>0;
        clearTimeout(timer);timer=setTimeout(preview,180);
    }
    function cells(body,rows) {
        body.replaceChildren();rows.forEach(values=>{const tr=document.createElement('tr');values.forEach((v,i)=>{const cell=document.createElement(i===0?'th':'td');if(i===0)cell.scope='row';cell.textContent=v;tr.append(cell);});body.append(tr);});
    }
    function drawChart() {
        if(!latest)return;
        if(!window.Highcharts){$('pension-chart').style.minHeight='0';$('pension-chart').textContent='Chart unavailable. Open the yearly figures below to inspect the projection.';return;}
        try {
            $('pension-chart').style.minHeight='300px';
            chart?.destroy(); chart=Highcharts.chart('pension-chart',{chart:{type:'line',backgroundColor:'transparent'},title:{text:null},credits:{enabled:false},xAxis:{title:{text:'Age'}},yAxis:{title:{text:'Pension pot (£ today)'},min:0},tooltip:{valuePrefix:'£',valueDecimals:0},plotOptions:{series:{animation:false}},series:Object.entries(latest.scenarios).map(([name,s])=>({name:name[0].toUpperCase()+name.slice(1),data:s.rows.map(r=>[r.age,r.pot_today]),dashStyle:name==='central'?'Solid':'ShortDash'}))});
        } catch(error){$('pension-chart').textContent='Chart unavailable. Open the yearly figures below.';}
    }
    function render(data,plan) {
        latest=data;const c=data.scenarios.central;
        $('pension-results').hidden=false;$('pension-timeline').hidden=false;$('pension-error').hidden=true;
        $('pension-status').textContent=`${plan.pots.length} pension${plan.pots.length===1?'':'s'} · current age approximately ${data.age_now.toFixed(1)} · ${money(data.contributions)} contributed each month.`;
        $('pension-context').textContent=`Retire at ${plan.retirement_age}; draw down through to age ${plan.end_age}. Future amounts below are in pounds at retirement.`;
        const metrics=[['Recorded pots',money(data.recorded_total),'Kept separate from your bank balances'],['Projected retirement pot',money(c.pot_today),money(c.pot_future)+' in future pounds'],['Monthly income today',money(c.monthly_today),money(c.monthly_future)+' in future pounds'],['Daily income today',daily(c.daily_today),daily(c.daily_future)+' in future pounds']];
        $('pension-metrics').replaceChildren();metrics.forEach(([label,value,note])=>{const div=document.createElement('div');div.className='pension-metric';for(const [tag,text] of [['span',label],['strong',value],['small',note]]){const e=document.createElement(tag);e.textContent=text;div.append(e);}$('pension-metrics').append(div);});
        $('pension-target').textContent=plan.target_monthly>0?(data.earliest_target_age!==null?`The central scenario first meets your ${money(plan.target_monthly)} monthly target at age ${data.earliest_target_age} (whole-year ages tested). This is an illustration, not a guaranteed retirement date.`:`Your ${money(plan.target_monthly)} monthly target is not met at any tested retirement age up to ${Math.min(90,plan.end_age-1)}.`):'No spending target set. Explore the income above, or add an optional monthly target to test a retirement age.';
        const stale=plan.pots.filter(p=>(new Date(today+'T12:00:00')-new Date(p.valued_on+'T12:00:00'))/86400000>90).length;
        $('pension-notes').textContent=(stale?`${stale} valuation(s) are over 90 days old; refresh them for a useful forecast. `:'')+(plan.state_annual>0?`Includes ${money(plan.state_annual)} yearly State Pension from age ${plan.state_age}. Monthly income once State Pension starts is at least ${money(c.monthly_after_state_today)} in today’s money; any State Pension above the illustrated income is not reinvested.`:'State Pension is excluded. Enter your own forecast under assumptions to include it.');
        cells($('pension-scenarios'),Object.entries(data.scenarios).map(([name,s])=>[name[0].toUpperCase()+name.slice(1)+(name==='central'?' (your rates)':name==='lower'?' (growth −2 points)':' (growth +2 points)'),money(s.pot_today),money(s.monthly_today),daily(s.daily_today),money(s.monthly_future)]));
        cells($('pension-years'),c.rows.map(r=>[r.age,money(r.pot_today),money(r.pot_future),money(r.monthly_today),money(r.monthly_future)]));drawChart();
    }
    async function preview() {
        if(!loaded)return;
        if(revision===0&&!document.querySelector('.pension-pot')){invalidate('Add your first pension pot to see an illustration.');return;}
        if(!$('pension-form').checkValidity()){invalidate();return;}
        const token=++request;controller?.abort();controller=new AbortController();
        try {const plan=collect();const data=await send('POST',{plan},controller.signal);if(token===request)render(data,plan);}
        catch(error){if(token===request && error.name!=='AbortError'){invalidate(error.message);}}
    }
    $('pension-form').addEventListener('input',changed);
    $('add-pot').addEventListener('click',()=>{addPot();changed();document.querySelector('.pension-pot:last-child input').focus();});
    $('pension-form').addEventListener('submit',async event=>{
        event.preventDefault();if(!loaded||!$('pension-form').reportValidity())return;
        $('save-plan').disabled=true;const plan=collect();const serialised=JSON.stringify(plan);
        try {const result=await send('PUT',{plan,revision});revision=result.revision;dirty=JSON.stringify(collect())!==serialised;$('save-status').textContent=dirty?'Newer changes remain unsaved.':'Saved · Financial Overview now uses this plan.';$('pension-plan-state').textContent=dirty?'Unsaved preview · newer edits are not saved.':'Saved plan · results use your saved assumptions.';if(!dirty)window.AppWeb?.markSaved($('pension-form'));window.showMessage('Pension plan saved.','success');preview();}
        catch(error){$('pension-error').textContent=error.message;$('pension-error').hidden=false;window.showMessage(error.message,'error');}
        finally{$('save-plan').disabled=false;}
    });
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
    async function load() {
        try {const result=await send('GET');revision=result.revision;$('pension-editor').open=revision===0;$('pension-editor').querySelector('summary').textContent=revision===0?'Set up your plan':'Edit assumptions';$('pension-plan-state').textContent=revision>0?'Saved plan · results use your saved assumptions.':'Start by entering your details, then save your plan.';fields.forEach(([key])=>{$(key).value=result.plan[key];if($(key)._slider)$(key)._slider.value=result.plan[key];});result.plan.pots.forEach(addPot);loaded=true;$('pension-fields').disabled=false;$('pension-main').setAttribute('aria-busy','false');preview();}
        catch(error){$('pension-status').textContent='Pensions could not load. Check Database Health, then reload this page.';$('pension-error').textContent=error.message;$('pension-error').hidden=false;$('pension-main').setAttribute('aria-busy','false');}
    }
    const script=document.createElement('script');script.src='https://code.highcharts.com/highcharts.js';script.async=true;script.onload=()=>{drawChart();const accessibility=document.createElement('script');accessibility.src='https://code.highcharts.com/modules/accessibility.js';accessibility.onload=drawChart;document.head.append(accessibility);};document.head.append(script);
    load();
})();

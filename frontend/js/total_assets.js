(() => {
    'use strict';
    const $=id=>document.getElementById(id), api='../php_backend/public/total_assets.php';
    const money=v=>new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP',maximumFractionDigits:0}).format(v);
    let source,latest,revision=0,dirty=false,sequence=0,timer,charts=[],controller;
    const fields=[
        ['retirement_age','Retire at age',18,90,.1,'assets-controls','Test earlier or later retirement, including an accessible-savings bridge before pension access.'],
        ['end_age','Fund spending until age',60,110,1,'assets-controls','A planning horizon, not a life-expectancy prediction.'],
        ['target_monthly','Monthly living-cost target (£ today)',0,1e6,1,'assets-controls','Before tax; excludes mortgage payments, which the model adds. Go-Go target if your saved pension plan uses phases. Zero turns off target testing.'],
        ['property_value','Current home value (£)',0,1e9,1,'assets-home-controls','Starts from the saved mortgage valuation. Scenario changes do not update the mortgage plan.'],
        ['replacement_home','Replacement home price (£ today)',0,1e9,1,'assets-home-controls','Required to include downsizing. Both homes follow the selected home growth rate.'],
        ['moving_costs','All selling and moving costs (£ today)',0,1e9,1,'assets-home-controls','Include estate agent, legal, purchase taxes, moving and mortgage repayment charges.'],
        ['other_assets','Other liquid assets (£)',0,1e9,1,'assets-home-controls','Savings and investments outside recorded bank accounts and pensions. Exclude property and avoid duplicate holdings.'],
        ['other_debts','Other debts to settle (£)',0,1e9,1,'assets-home-controls','Exclude the mortgage and any debt already reflected in bank balances. Deducted from liquid wealth immediately.'],
        ['extra_pension','Extra monthly pension contribution (£)',0,1e6,1,'assets-future-controls','Hypothetical additional contribution, including any employer payment and tax relief. Starts now, stops at retirement; zero-fee illustrative pot.'],
        ['monthly_saving','Extra monthly cash saving (£)',0,1e6,1,'assets-future-controls','Fixed nominal amount from future earnings, on top of pension contributions and mortgage payments. Starts now and stops at retirement.'],
        ['other_income','Other monthly retirement income (£ today)',0,1e6,1,'assets-future-controls','A separate inflation-linked before-tax income stream to end age. Exclude State Pension already entered in Pensions.'],
        ['income_age','Other income starts at age',18,110,.1,'assets-future-controls','Income begins at this age or retirement, whichever is later.'],
        ['home_growth','Annual nominal home growth (%)',-10,15,.1,'assets-future-controls','Applies to both homes. No increase assumed by default.'],
        ['liquid_growth','Annual nominal liquid-asset return (%)',-10,15,.1,'assets-future-controls','After fees, before tax. No return assumed by default; inflation can erode purchasing power.']
    ];
    fields.forEach(([key,label,min,max,step,parent,help])=>{
        const div=document.createElement('div');div.className='assets-field';
        const l=document.createElement('label');l.htmlFor=key;l.textContent=label;
        const i=document.createElement('input');Object.assign(i,{id:key,name:key,type:'number',min,max,step,required:true});i.setAttribute('aria-label',label);i.dataset.help=help;
        const small=document.createElement('small');small.textContent=help;div.append(l,i,small);$(parent).append(div);
    });
    function collect(){const s={downsize:$('downsize').checked};fields.forEach(([key])=>s[key]=Number($(key).value));return s;}
    function populate(s){fields.forEach(([key])=>$(key).value=s[key]);$('downsize').checked=s.downsize;}
    async function send(method,body,signal){const r=await fetch(api,{method,signal,cache:'no-store',headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify(body):undefined});const d=await r.json();if(!r.ok)throw new Error(d.error||'Request failed.');return d;}
    function cells(id,rows){$(id).replaceChildren();rows.forEach(row=>{const tr=document.createElement('tr');row.forEach((text,i)=>{const td=document.createElement(i===0?'th':'td');if(i===0)td.scope='row';td.textContent=text;tr.append(td);});$(id).append(tr);});}
    function invalidate(message){latest=null;charts.forEach(c=>c.destroy());charts=[];['assets-breakdown','assets-income','assets-years','assets-metrics'].forEach(id=>$(id).replaceChildren());$('assets-chart').textContent='Update the assumptions to see assets.';$('assets-path').textContent='Projection unavailable.';$('assets-verdict').textContent='No current illustration.';$('assets-error').textContent=message;$('assets-error').hidden=false;}
    function draw(){
        if(!latest)return;
        charts.forEach(c=>c.destroy());charts=[];
        if(!window.Highcharts){$('assets-chart').textContent='Chart unavailable. The asset table below contains the same values.';$('assets-path').textContent='Chart unavailable. Open yearly figures below.';return;}
        const n=latest.now,r=latest.projection.retirement;
        $('assets-chart').textContent='';$('assets-path').textContent='';
        const common={chart:{backgroundColor:'transparent'},title:{text:null},credits:{enabled:false},tooltip:{valuePrefix:'£',valueDecimals:0},plotOptions:{series:{animation:false}}};
        charts.push(Highcharts.chart('assets-chart',{...common,chart:{...common.chart,type:'bar'},xAxis:{categories:['Asset structure today','At retirement']},yAxis:{title:{text:'£ in today’s money'}},plotOptions:{series:{animation:false,stacking:'normal'}},series:[
            {name:'Pensions',color:'#6366f1',data:[n.pension,r.pension]},
            {name:'Liquid assets (net of other debts)',color:'#0d9488',data:[n.bank+n.other_assets-n.other_debts,r.liquid]},
            {name:'Home equity',color:'#d97706',data:[n.equity,r.home-r.mortgage]},
            {name:'Mortgage owed (excluded from net wealth)',color:'#94a3b8',data:[n.mortgage,r.mortgage]}
        ]}));
        charts.push(Highcharts.chart('assets-path',{...common,chart:{...common.chart,type:'line'},xAxis:{title:{text:'Age'}},yAxis:{title:{text:'£ in today’s money'}},series:[['pension','Pensions','#6366f1'],['liquid','Liquid assets','#0d9488'],['equity','Home equity','#d97706'],['net','Net assets','#334155']].map(([key,name,color])=>({name,color,data:latest.projection.rows.map(r=>[r.age,r[key]])}))}));
    }
    function render(data,s){
        latest=data;$('assets-error').hidden=true;const n=data.now,r=data.projection.retirement;
        cells('assets-breakdown',[
            ['Pensions',money(n.pension),money(r.pension)],['Bank + other assets − other debts',money(n.bank+n.other_assets-n.other_debts),money(r.liquid)],
            ['Home value',money(n.home),money(r.home)],['Mortgage owed',money(n.mortgage),money(r.mortgage)],['Home equity',money(n.equity),money(r.home-r.mortgage)],
            ['Net assets',money(n.net),money(r.pension+r.liquid+r.home-r.mortgage)]
        ]);
        $('assets-metrics').replaceChildren();[['Net assets today',money(n.net)],['Home equity today',money(n.equity)],['Mortgage owed today',money(n.mortgage)],['Central monthly spending',money(data.scenarios.central.monthly)]].forEach(([label,value])=>{const d=document.createElement('div');d.className='assets-metric';const l=document.createElement('span');l.textContent=label;const v=document.createElement('strong');v.textContent=value;d.append(l,v);$('assets-metrics').append(d);});
        $('assets-downsize').textContent=s.replacement_home>0?`If you downsized now: ${n.downsize_cash>=0?money(n.downsize_cash)+' released':money(-n.downsize_cash)+' additional funding needed'} after mortgage, replacement home and entered costs. ${s.downsize?'At retirement the scenario releases '+money(r.released)+' in today’s money.':'Downsizing is switched off in the retirement scenario.'}`:'Enter a replacement home price and costs to calculate what downsizing could release now. Home equity alone is not spendable cash.';
        $('assets-verdict').textContent=s.target_monthly>0?`${data.projection.funded?'Your central scenario funds':'Your central scenario cannot fund'} ${money(s.target_monthly)} a month from age ${s.retirement_age} to ${s.end_age}${source.pension.plan.spending_profile==='three_phase'?' using your saved spending phases':''}, plus mortgage payments.${data.projection.first_shortfall_age!==null?' First funding shortfall at age '+data.projection.first_shortfall_age+'.':''} ${data.earliest_age!==null?'First whole-year age meeting the target: '+data.earliest_age+'.':'No tested whole-year retirement age meets the target.'} This is an illustration, not a guaranteed date.`:'Set a monthly spending target to test a retirement age. Sustainable income above is before tax, after funding mortgage payments.';
        cells('assets-income',Object.entries(data.scenarios).map(([key,v])=>[key[0].toUpperCase()+key.slice(1),money(v.monthly),s.target_monthly>0?(v.funded?'Yes':'No'):'No target set']));
        $('assets-contributions').textContent=`Future pension contributions to retirement: ${money(r.contributions)} in nominal payments, including ${money(s.extra_pension)} extra per month. These payments do not exist today and are not added to today’s assets. Projected asset values already include contributions and growth.`;
        cells('assets-years',data.projection.rows.map(r=>[r.age,...['pension','liquid','equity','mortgage','net'].map(k=>money(r[k]))]));draw();
    }
    async function preview(){if(!source)return;const token=++sequence;if(!$('assets-form').checkValidity()){invalidate('Check the highlighted assumptions.');return;}const s=collect();controller?.abort();controller=new AbortController();$('assets-status').textContent='Calculating scenario…';try{const d=await send('POST',{scenario:s,source_revisions:[source.pension.revision,source.mortgage.revision]},controller.signal);if(token===sequence){render(d,s);$('assets-status').textContent='Current preview · today’s assets and future assumptions are kept separate.';}}catch(e){if(token===sequence&&e.name!=='AbortError'){invalidate(e.message);$('assets-status').textContent='Scenario needs attention.';}}}
    function changed(){dirty=true;$('assets-save-state').textContent='Unsaved preview';clearTimeout(timer);++sequence;timer=setTimeout(preview,300);}
    $('assets-form').addEventListener('input',changed);
    $('assets-reset').addEventListener('click',()=>{const p=source.pension.plan,m=source.mortgage.plan;populate({retirement_age:Math.max(Math.ceil(latest?.age_now||p.age),p.retirement_age),end_age:p.end_age,target_monthly:p.target_monthly,property_value:m.property_value||0,other_assets:0,other_debts:0,extra_pension:0,monthly_saving:0,other_income:0,income_age:p.state_age,downsize:false,replacement_home:0,moving_costs:0,home_growth:0,liquid_growth:0});changed();});
    $('assets-form').addEventListener('submit',async e=>{e.preventDefault();if(!$('assets-form').reportValidity())return;const s=collect(),serial=JSON.stringify(s);$('assets-save').disabled=true;try{const d=await send('PUT',{scenario:s,revision,source_revisions:[source.pension.revision,source.mortgage.revision]});revision=d.saved.revision;dirty=JSON.stringify(collect())!==serial;$('assets-save-state').textContent=dirty?'Saved; newer edits remain unsaved.':'Scenario saved';if(!dirty){window.AppWeb?.markSaved($('assets-form'));render(d,s);}window.showMessage('Total Assets scenario saved.','success');}catch(error){window.showMessage(error.message,'error');$('assets-error').textContent=error.message;$('assets-error').hidden=false;}finally{$('assets-save').disabled=false;}});
    window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
    async function load(){try{source=await send('GET');revision=source.revision;populate(source.scenario);$('assets-save-state').textContent=revision?'Saved scenario loaded':'New scenario · save to keep assumptions';const p=source.pension.plan,m=source.mortgage.plan;$('assets-sources').textContent=`Pensions: ${source.pension.revision?'saved revision '+source.pension.revision:'not yet saved (no recorded pots)'}. Mortgage: ${source.mortgage.revision?'saved revision '+source.mortgage.revision:'not yet saved (no recorded mortgage)'}, balance date ${m.balance_date}. Pension valuations: ${p.pots.map(v=>v.provider+' '+v.valued_on).join('; ')||'none'}. Inflation ${p.inflation}%; pension access age ${p.access_age}; State Pension ${money(p.state_annual)} a year from ${p.state_age}. Starting values may be old; check dates before relying on them.`;source.accounts.forEach(a=>{const li=document.createElement('li');li.textContent=`${a.name}: ${money(Number(a.ledger_balance||0))} · ${a.ledger_balance_date||'no balance date'}`;$('assets-accounts').append(li);});$('assets-fields').disabled=false;await preview();}catch(e){invalidate(e.message);$('assets-status').textContent='Could not load source plans. Reload to retry.';}finally{document.querySelector('main').setAttribute('aria-busy','false');}}
    const script=document.createElement('script');script.src='https://code.highcharts.com/highcharts.js';script.async=true;script.onload=()=>{draw();const a=document.createElement('script');a.src='https://code.highcharts.com/modules/accessibility.js';a.onload=draw;document.head.append(a);};document.head.append(script);load();
})();

(() => {
    'use strict';
    const $=id=>document.getElementById(id), api='../php_backend/public/mortgages.php';
    const money=v=>v===null?'Not available':new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP',maximumFractionDigits:2}).format(v);
    const date=v=>v?new Date(v+'T12:00:00').toLocaleDateString('en-GB',{month:'short',year:'numeric'}):'Not repaid in this illustration';
    const duration=m=>m===null?'Not repaid in this illustration':m===0?'Already cleared':[Math.floor(m/12)?`${Math.floor(m/12)} year${Math.floor(m/12)===1?'':'s'}`:'',m%12?`${m%12} month${m%12===1?'':'s'}`:''].filter(Boolean).join(' ');
    const now=new Date(),today=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
    let revision=0,loaded=false,dirty=false,serial=0,timer,controller,latest,chart;
    const fields=[
        ['lender','Lender / mortgage name','text',null,null,null,'My mortgage','Use a recognisable name; no account number is needed.'],
        ['balance','Outstanding balance (£)','number',0,1e9,.01,0,'The amount still owed, not the amount originally borrowed.'],
        ['balance_date','Balance as of','date',null,null,null,today,'Forecasts start from this balance date. Update old balances rather than assuming past payments were made.'],
        ['rate','Current annual interest rate (%)','number',0,25,.01,4.5,'Use the mortgage interest rate, not an APRC including fees.'],
        ['remaining_months','Remaining term (months)','number',1,600,1,240,'The contractual term still remaining. The caption translates months into years.'],
        ['monthly_payment','Current normal monthly payment (£)','number',0,1e6,.01,0,'Leave blank or zero to calculate a repayment from the balance, rate and remaining term. Exclude overpayments.'],
        ['extra_monthly','Extra each month (£)','number',0,1e6,1,0,'Paid on top of your normal payment each month until the mortgage clears.'],
        ['lump_sum','One-off overpayment now (£)','number',0,1e9,.01,0,'Reduces the opening balance before the first interest calculation. Cannot exceed the balance.'],
        ['rate_change_month','New rate starts at payment month','number',0,600,1,0,'Zero disables the rate change. Month 1 is the first payment; month 25 gives 24 payments at the old rate.'],
        ['future_rate','New annual interest rate (%)','number',0,25,.01,4.5,'An assumed future rate that remains in place for the rest of the illustration.']
    ];
    fields.forEach(([key,title,type,min,max,step,value,help],index)=>{
        const wrap=document.createElement('div');wrap.className='mortgage-field';
        const label=document.createElement('label');label.htmlFor=key;label.textContent=title;
        const input=document.createElement('input');input.id=key;input.type=type;input.value=value;input.setAttribute('aria-label',title);input.dataset.help=help;
        input.required=key!=='monthly_payment';if(type==='number'){input.min=min;input.max=max;input.step=step;}
        if(type==='date'){input.max=today;input.min='1900-01-01';}if(type==='text')input.maxLength=120;
        wrap.append(label);
        if(['rate','remaining_months','extra_monthly'].includes(key)){
            const pair=document.createElement('div');pair.className='mortgage-range';const slider=document.createElement('input');slider.type='range';slider.min=min;slider.max=key==='extra_monthly'?2000:max;slider.step=step;slider.value=value;slider.setAttribute('aria-label',title+' slider');slider.dataset.help=help;
            slider.addEventListener('input',()=>{input.value=slider.value;});input.addEventListener('input',()=>{if(key==='extra_monthly')slider.max=Math.max(2000,Math.ceil(Number(input.value)/500)*500);slider.value=input.value;});pair.append(slider,input);wrap.append(pair);input._slider=slider;
        }else wrap.append(input);
        const note=document.createElement('output');note.id=key+'-note';note.htmlFor=key;wrap.append(note);
        $(index<6?'mortgage-inputs':index<8?'mortgage-overpayments':'mortgage-rate-inputs').append(wrap);
    });
    function updateNotes(){
        $('remaining_months-note').textContent=duration(Number($('remaining_months').value));
        $('rate_change_month-note').textContent=Number($('rate_change_month').value)===0?'No rate change assumed':`New rate begins ${date(monthDate($('balance_date').value,Number($('rate_change_month').value)))}`;
    }
    function monthDate(start,months){
        if(!/^\d{4}-\d{2}-\d{2}$/.test(start))return null;
        const [year,month,day]=start.split('-').map(Number);const d=new Date(Date.UTC(year,month-1+months,1));d.setUTCDate(Math.min(day,new Date(Date.UTC(d.getUTCFullYear(),d.getUTCMonth()+1,0)).getUTCDate()));return d.toISOString().slice(0,10);
    }
    function collect(){const plan={};fields.forEach(([key,,type])=>{plan[key]=type==='number'?Number($(key).value):$(key).value;});plan.recalculate_on_change=$('recalculate_on_change').checked;return plan;}
    async function send(method,body,signal){const response=await fetch(api,{method,cache:'no-store',headers:{'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined,signal});const data=await response.json();if(!response.ok)throw new Error(data.error||'Mortgage request failed.');return data;}
    function clearResults(text){latest=null;$('mortgage-results').hidden=true;$('mortgage-timeline').hidden=true;$('mortgage-status').textContent=text;}
    function changed(){if(!loaded)return;dirty=true;++serial;controller?.abort();clearResults('Updating your illustration…');$('mortgage-save-status').textContent='Unsaved changes · dashboard still uses the saved mortgage.';updateNotes();clearTimeout(timer);timer=setTimeout(preview,180);}
    function cells(body,rows){body.replaceChildren();rows.forEach(values=>{const tr=document.createElement('tr');values.forEach((v,i)=>{const td=document.createElement(i===0?'th':'td');if(i===0)td.scope='row';td.textContent=v;tr.append(td);});body.append(tr);});}
    function schedule(){if(!latest)return;cells($('mortgage-schedule'),latest[$('mortgage-schedule-mode').value].rows.map(r=>[r.month,r.date,money(r.balance),money(r.interest),money(r.paid)]));}
    function drawChart(){
        if(!latest)return;
        if(!window.Highcharts){$('mortgage-chart').textContent='Chart unavailable. Open the repayment schedule below for the figures.';return;}
        try{chart?.destroy();chart=Highcharts.chart('mortgage-chart',{chart:{type:'line',backgroundColor:'transparent'},title:{text:null},credits:{enabled:false},xAxis:{type:'datetime',title:{text:'Payment date'}},yAxis:{min:0,title:{text:'Outstanding balance (£)'}},tooltip:{valuePrefix:'£',valueDecimals:2},plotOptions:{series:{animation:false}},series:[['normal','Normal payments'],['overpay','With overpayments']].map(([key,name])=>({name,data:latest[key].rows.map(r=>[Date.parse(r.date+'T12:00:00Z'),r.balance])}))});}catch(e){$('mortgage-chart').textContent='Chart unavailable. Open the repayment schedule below.';}
    }
    function render(data,plan){
        latest=data;const a=data.normal,b=data.overpay;
        $('mortgage-results').hidden=false;$('mortgage-timeline').hidden=false;
        $('mortgage-status').textContent=plan.balance>0?`${plan.lender} · ${money(plan.balance)} outstanding as of ${plan.balance_date}.`:'Enter your balance above to explore your repayment mortgage.';
        $('mortgage-context').textContent=`Contractual term ends ${date(data.contract_end)}. ${plan.monthly_payment>0?'Using your entered payment.':'Normal payment is calculated from the balance, rate and remaining term.'} All amounts are future cash payments, without fees.`;
        $('monthly_payment-note').textContent=`Calculated payment to match this term: ${money(data.required_payment)} a month.`;
        const metrics=[['Mortgage-free with overpayments',date(b.payoff_date),b.payoff_months!==null?duration(b.payoff_months)+' from the balance date':'No payoff within the illustration'],['Time saved',data.months_saved===null?'Not available':duration(data.months_saved).replace('Already cleared','No time saved'),'Compared with normal payments'],['Interest saved',money(data.interest_saved),'Excludes fees and early-repayment charges'],['Initial monthly payment',money(b.initial_payment),'Normal payment plus extra monthly payment']];
        $('mortgage-metrics').replaceChildren();metrics.forEach(([label,value,note])=>{const node=document.createElement('div');node.className='mortgage-metric';[['span',label],['strong',value],['small',note]].forEach(([tag,text])=>{const el=document.createElement(tag);el.textContent=text;node.append(el);});$('mortgage-metrics').append(node);});
        const warnings=[];
        if(a.balance_at_term===null||b.balance_at_term===null)warnings.push('The balance becomes too large to illustrate through the contractual term. Review the payment and rate assumptions.');
        else if(a.balance_at_term>0||b.balance_at_term>0)warnings.push(`Balance still owed at the contractual term end: ${money(a.balance_at_term)} with normal payments; ${money(b.balance_at_term)} with overpayments. Any payoff shown after that date would require a lender-agreed extension or another repayment arrangement.`);
        if(a.growing_balance||b.growing_balance)warnings.push('At least one scenario has payments below the interest charged, so the debt grows during those months.');
        if((new Date(today+'T12:00:00')-new Date(plan.balance_date+'T12:00:00'))/86400000>60)warnings.push('Your balance date is over 60 days old. The forecast starts from that date; update it for a current illustration.');
        if(plan.rate_change_month>0)warnings.push(`The rate changes at payment ${plan.rate_change_month}. ${plan.recalculate_on_change?'Normal payments are recalculated separately for each scenario over its remaining contractual term.':'Monthly payments remain fixed; a higher rate can leave a shortfall.'}`);
        $('mortgage-warning').textContent=warnings.join(' ');
        cells($('mortgage-comparison'),[
            ['Payoff date',date(a.payoff_date),date(b.payoff_date)],['Time to payoff',duration(a.payoff_months),duration(b.payoff_months)],
            ['Total interest through payoff',money(a.total_interest),money(b.total_interest)],['Total paid through payoff',money(a.total_paid),money(b.total_paid)],
            ['Balance at contractual term end',money(a.balance_at_term),money(b.balance_at_term)],['Interest through contractual term end',money(a.interest_to_term),money(b.interest_to_term)],
            ['First month interest',money(a.first_interest),money(b.first_interest)],['First month capital repaid',money(a.first_principal),money(b.first_principal)],
            ['Monthly payment after rate change',a.payment_after_change===null?'No change before payoff':money(a.payment_after_change),b.payment_after_change===null?'No change before payoff':money(b.payment_after_change)]
        ]);schedule();drawChart();
    }
    async function preview(){
        if(!loaded)return;
        if(!$('mortgage-form').checkValidity()){clearResults('Enter valid mortgage details to update the illustration.');return;}
        const token=++serial;controller?.abort();controller=new AbortController();const plan=collect();
        try{const data=await send('POST',{plan},controller.signal);if(token===serial)render(data,plan);}catch(e){if(token===serial&&e.name!=='AbortError')clearResults(e.message);}
    }
    $('mortgage-form').addEventListener('input',changed);$('mortgage-schedule-mode').addEventListener('change',schedule);
    $('mortgage-form').addEventListener('submit',async e=>{
        e.preventDefault();if(!loaded||!$('mortgage-form').reportValidity())return;
        $('mortgage-save').disabled=true;const plan=collect(),before=JSON.stringify(plan);
        try{const result=await send('PUT',{plan,revision});revision=result.revision;dirty=JSON.stringify(collect())!==before;$('mortgage-error').hidden=true;$('mortgage-save-status').textContent=dirty?'Newer changes remain unsaved.':'Saved · Financial Overview now uses this mortgage.';if(!dirty)window.AppWeb?.markSaved($('mortgage-form'));window.showMessage('Mortgage saved.','success');preview();}catch(error){$('mortgage-error').textContent=error.message;$('mortgage-error').hidden=false;window.showMessage(error.message,'error');}finally{$('mortgage-save').disabled=false;}
    });
    window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
    async function load(){try{const result=await send('GET');revision=result.revision;fields.forEach(([key])=>{const input=$(key);input.value=result.plan[key];if(input._slider){if(key==='extra_monthly')input._slider.max=Math.max(2000,Math.ceil(input.value/500)*500);input._slider.value=input.value;}});$('recalculate_on_change').checked=result.plan.recalculate_on_change;loaded=true;$('mortgage-fields').disabled=false;$('mortgage-main').setAttribute('aria-busy','false');updateNotes();preview();}catch(e){$('mortgage-status').textContent='Mortgage workspace could not load. Check Database Health, then reload this page.';$('mortgage-error').textContent=e.message;$('mortgage-error').hidden=false;$('mortgage-main').setAttribute('aria-busy','false');}}
    const script=document.createElement('script');script.src='https://code.highcharts.com/highcharts.js';script.async=true;script.onload=()=>{drawChart();const a=document.createElement('script');a.src='https://code.highcharts.com/modules/accessibility.js';a.onload=drawChart;document.head.append(a);};document.head.append(script);
    load();
})();

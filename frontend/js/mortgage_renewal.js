(() => {
    'use strict';
    const $=id=>document.getElementById(id);
    const money=v=>v===null?'Not set':new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(v);
    const stamp=v=>v?new Date(v).toLocaleString('en-GB'):'Never';
    const day=v=>new Date(v+'T12:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});
    const fields=[['deal_start','Fixed deal started','date','','Optional start date, used to show progress through your current fix.'],
        ['deal_end','Fixed deal ends','date','','Use the exact date from your mortgage offer, not an estimated year.'],
        ['property_value','Estimated property value (£)','number',0,'Used only for an illustrative loan-to-value ratio; the lender may use a different valuation.'],
        ['review_months','Start reviewing deals this many months before expiry','number',6,'A personal planning reminder (0–24 months), not a lender application window.']];
    const dealFields=[['name','Lender / comparison name','text','Manual comparison'],['rate','Interest rate (%)','number',4.5],['fixed_months','New fixed period (months)','number',60],['fee','Product fee (£)','number',0],['cashback','Cashback (£)','number',0],['add_fee','Add product fee to borrowing','checkbox',false]];
    let ready=false,sources=[],currentRenewal=null,busy=false,dealSequence=0,paymentChart=null;
    function node(tag,text,cls){const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el;}
    function notifyChange(){if(ready)$('mortgage-form').dispatchEvent(new Event('input',{bubbles:true}));}
    fields.forEach(([key,label,type,value,help])=>{
        const wrap=node('div',undefined,'mortgage-field'),l=node('label',label),input=document.createElement('input');l.htmlFor=key;input.id=key;input.type=type;input.value=value;input.dataset.help=help;input.setAttribute('aria-label',label);
        if(type==='number'){input.min=0;input.max=key==='review_months'?24:1e9;input.step=key==='review_months'?1:.01;input.required=true;}
        else{input.min='1900-01-01';input.max='2200-12-31';}
        wrap.append(l,input);$('mortgage-deal-inputs').append(wrap);
    });
    function addDeal(deal={},mark=true){
        if($('renewal-deal-editor').children.length>=12){$('renewal-edit-status').textContent='Keep up to twelve comparisons. Remove one before adding another.';return;}
        if(mark&&ready)window.AppWeb?.beginEdit?.($('mortgage-form'));
        const dealId=++dealSequence;
        const wrap=node('fieldset',undefined,'renewal-deal'),legend=node('legend','Renewal comparison'),grid=node('div',undefined,'mortgage-grid');wrap.append(legend,grid);
        dealFields.forEach(([key,label,type,fallback])=>{
            const field=node('label',label,'mortgage-field'),input=document.createElement('input');input.type=type;input.id='renewal-deal-'+dealId+'-'+key;field.htmlFor=input.id;input.dataset.dealField=key;input.setAttribute('aria-label',label);input.dataset.help=key==='fixed_months'?'Illustration length; check the lender’s actual fixed end date.':key==='add_fee'?'If added to borrowing, the product fee increases payments and the remaining balance.':'Use your quote, or edit this benchmark assumption.';
            if(type==='checkbox')input.checked=deal[key]??fallback;
            else{input.value=deal[key]??fallback;input.required=true;if(type==='text')input.maxLength=160;else{input.min=key==='fixed_months'?1:0;input.max=key==='rate'?25:key==='fixed_months'?120:100000;input.step=key==='fixed_months'?1:.01;}}
            field.append(input);grid.append(field);
        });
        const remove=node('button','Remove comparison');remove.type='button';remove.setAttribute('aria-label','Remove this renewal comparison');remove.addEventListener('click',()=>{window.AppWeb?.beginEdit?.($('mortgage-form'));wrap.remove();notifyChange();});wrap.append(remove);$('renewal-deal-editor').append(wrap);if(mark){$('mortgage-editor').open=true;notifyChange();}
    }
    function collect(){
        const out={};fields.forEach(([key,,type])=>out[key]=type==='number'?Number($(key).value):$(key).value);
        out.comparison_deals=Array.from($('renewal-deal-editor').children).map(wrap=>{const deal={};wrap.querySelectorAll('[data-deal-field]').forEach(input=>deal[input.dataset.dealField]=input.type==='checkbox'?input.checked:input.type==='number'?Number(input.value):input.value);return deal;});return out;
    }
    function load(plan){fields.forEach(([key,,,fallback])=>$(key).value=plan[key]??fallback);$('renewal-deal-editor').replaceChildren();(plan.comparison_deals||[]).forEach(deal=>addDeal(deal,false));ready=true;}
    function clear(){paymentChart?.destroy();paymentChart=null;currentRenewal=null;$('mortgage-renewal').hidden=true;}
    function metric(label,value,note){const box=node('div',undefined,'mortgage-metric');box.append(node('span',label),node('strong',value),node('small',note));return box;}
    function paymentChange(value){return Math.abs(value)<.005?'No change':money(Math.abs(value))+(value>0?' more':' less')+' / month';}
    function drawPayments(){
        paymentChart?.destroy();paymentChart=null;
        const data=currentRenewal,container=$('renewal-payment-chart');
        container.replaceChildren();
        if(!data?.configured)return;
        const deals=data.comparisons||[],hasOverpay=data.balance_with_overpayments!==data.balance;
        $('renewal-payment-baseline').textContent='Current regular payment: '+money(data.current_payment)+' / month';
        const changes=$('renewal-payment-changes');changes.replaceChildren();
        deals.forEach(deal=>{
            const item=node('div',undefined,'renewal-payment-change');
            item.append(node('strong',deal.name),node('span','Normal: '+money(deal.normal.payment)+' · '+paymentChange(deal.normal.monthly_change)));
            if(hasOverpay)item.append(node('span','With overpayments: '+money(deal.overpay.payment)+' · '+paymentChange(deal.overpay.monthly_change)));
            changes.append(item);
        });
        if(!deals.length){container.textContent=data.remaining_months>0?'Add a comparison to see it beside your current payment.':'No remaining contractual term for a new deal.';container.style.height='auto';return;}
        if(!window.Highcharts){container.textContent='Chart unavailable. Monthly payments and changes are shown below.';container.style.height='auto';return;}
        container.style.height=Math.max(280,(deals.length+1)*(hasOverpay?76:54)+110)+'px';
        const colour=getComputedStyle(document.documentElement).getPropertyValue('--brand-color-600').trim()||'#4f46e5';
        const categories=['Current deal',...deals.map(d=>d.name)];
        const series=[{name:'Current regular payment',color:'#64748b',data:[data.current_payment,...deals.map(()=>null)]},
            {name:'Normal payments to renewal',color:colour,data:[null,...deals.map(d=>d.normal.payment)]}];
        if(hasOverpay)series.push({name:'With overpayments to renewal',color:'#0f766e',data:[null,...deals.map(d=>d.overpay.payment)]});
        try{paymentChart=Highcharts.chart(container,{
            chart:{type:'bar',backgroundColor:'transparent',spacingLeft:4,spacingRight:20},title:{text:null},credits:{enabled:false},
            xAxis:{categories,labels:{style:{fontSize:'12px'},useHTML:false}},
            yAxis:{min:0,title:{text:'Monthly payment (£)'},plotLines:[{value:data.current_payment,color:'#64748b',width:2,dashStyle:'Dash',zIndex:5}]},
            legend:{enabled:true},accessibility:{description:'Monthly payments at renewal compared with the current regular payment of '+money(data.current_payment)+'. Full values and changes follow the chart.'},
            tooltip:{useHTML:false,formatter:function(){const value=this.y,change=value-data.current_payment;return this.series.name+': '+money(value)+'; '+paymentChange(change);}},
            plotOptions:{series:{animation:false,groupPadding:.15,dataLabels:{enabled:true,format:'£{point.y:,.0f}',style:{textOutline:'none',fontWeight:'normal'}},borderWidth:0},bar:{borderRadius:0,centerInCategory:true}},
            series
        });}catch(e){container.style.height='auto';container.textContent='Chart unavailable. Monthly payments and changes are shown below.';}
    }
    function render(data,plan){
        currentRenewal=data;
        if(!data?.configured){$('mortgage-renewal').hidden=true;renderSources();return;}
        $('mortgage-renewal').hidden=false;
        const countdown=data.days_remaining<0?`Your fixed deal ended ${-data.days_remaining} days ago.`:data.days_remaining===0?'Your fixed deal ends today.':`${data.days_remaining} days until your fixed deal ends.`;
        let progress='';if(plan.deal_start){const elapsed=Math.max(0,Date.now()-Date.parse(plan.deal_start+'T00:00:00'));const total=Date.parse(plan.deal_end+'T00:00:00')-Date.parse(plan.deal_start+'T00:00:00');progress=` ${Math.min(100,Math.round(elapsed/total*100))}% through the recorded fixed period.`;}
        $('renewal-deadline').textContent=`${countdown} ${day(data.deal_end)}.${progress} ${data.review_due?'Review date reached':'Start your review on '+day(data.review_date)}.`;
        $('renewal-metrics').replaceChildren(metric('Balance at the comparison date',money(data.balance),day(data.projection_date)),metric('With planned overpayments',money(data.balance_with_overpayments),'Reduced balance before selecting a new deal'),metric('Illustrative loan to value',data.ltv===null?'Add property value':data.ltv.toFixed(2)+'%',data.ltv_with_overpayments===null?'Valuation is not lender-confirmed':data.ltv_with_overpayments.toFixed(2)+'% with overpayments'));
        $('renewal-explanation').textContent=`Uses ${data.elapsed_payments} payments at your current rate from the recorded balance date, leaving ${data.remaining_months} contractual months. Renewal payments below exclude continuing overpayments. ${data.days_remaining<0?'The fixed deal has ended: update your balance and actual current rate; this is not an estimate of a lender’s variable rate. ':''}${data.remaining_months===0?'The contractual term ends before this renewal: no new repayment deal is illustrated. ':''}The separate rate-change experiment does not alter these renewal comparisons.`;
        const body=$('renewal-comparisons');body.replaceChildren();
        (data.comparisons||[]).forEach(deal=>{
            [['normal','Normal payments to renewal'],...(data.balance_with_overpayments!==data.balance?[['overpay','Overpayments to renewal']]:[])].forEach(([key,label])=>{
                const c=deal[key],row=document.createElement('tr');
                [deal.name+' · '+deal.rate+'% · '+deal.fixed_months+' months · '+label,money(c.payment),money(c.monthly_change),money(deal.fee)+(deal.add_fee?' financed':' upfront'),money(c.cash_paid)+' over '+c.months_compared+' months',money(c.financing_cost),money(c.balance_after_fix)].forEach((value,i)=>{const cell=node(i===0?'th':'td',value);if(i===0)cell.scope='row';else{cell.dataset.label=['','New monthly payment','Change / month','Product fee','Cash paid during fix','Financing cost','Balance after fix'][i];cell.setAttribute('aria-label',cell.dataset.label+': '+value);}row.append(cell);});body.append(row);
            });
        });
        if(!body.children.length){const row=node('tr'),cell=node('td',data.remaining_months>0?'Add a manual comparison or copy a lender benchmark below.':'No remaining contractual term for a new deal.');cell.colSpan=7;row.append(cell);body.append(row);}
        drawPayments();renderSources();
    }
    function renderSources(){
        const container=$('mortgage-rate-sources');container.replaceChildren();
        sources.forEach(source=>{
            const details=document.createElement('details');details.className='renewal-source';const summary=node('summary',source.name+' · '+(source.error?'Latest check failed':source.stale?'Rates stale / not collected':'Last check succeeded')+' · last success '+stamp(source.last_success));details.append(summary);
            details.append(node('p',`Last successful update: ${stamp(source.last_success)}. Last attempt: ${stamp(source.last_attempt)}.${source.published_at?' Source file published: '+stamp(source.published_at):''}`));
            if(source.error)details.append(node('p',source.error,'renewal-source-warning'));
            if(source.stale)details.append(node('p','No successful update in the last 48 hours. Treat these figures as stale benchmarks and verify the source.','renewal-source-warning'));
            const a=node('a','Open '+source.name+' source');a.href=source.url;a.target='_blank';a.rel='noopener';details.append(a,node('p',source.scope));
            const ltv=currentRenewal?.ltv,balance=currentRenewal?.balance;
            const list=(source.products||[]).filter(p=>ltv==null||((p.min_ltv??0)<=ltv&&p.max_ltv>=ltv&&(p.min_loan==null||balance>=p.min_loan)&&(p.max_loan==null||balance<=p.max_loan)));
            details.append(node('p',ltv==null?'Enter your property value and fixed-deal end date to narrow these benchmarks by projected LTV.':`${list.length} products within the published LTV/loan bounds for the normal-payment projection. Other conditions remain unchecked.`));
            const select=document.createElement('select');select.setAttribute('aria-label',source.name+' published fixed-rate benchmarks');select.append(new Option('Choose a benchmark to inspect',''));
            list.forEach(p=>select.append(new Option(`${p.name} · ${p.rate}% · up to ${p.max_ltv}% LTV · fee ${money(p.fee)}`,p.id)));
            const info=node('p'),copy=node('button','Add selected benchmark');copy.type='button';copy.disabled=true;copy.setAttribute('aria-label','Add selected '+source.name+' rate as an editable renewal comparison');
            select.addEventListener('change',()=>{const p=list.find(p=>p.id===select.value);copy.disabled=!p;info.textContent=p?`${p.restrictions||'Confirm eligibility with lender'}. ${p.incentives||'Incentives not included unless entered'}. ${p.fixed_until?'Published fixed end date: '+p.fixed_until+'. ':''}Today’s advertised rate is not a renewal offer.`:'';});
            copy.addEventListener('click',()=>{const p=list.find(p=>p.id===select.value);if(!p)return;addDeal({name:(source.name+' '+p.name+' (checked '+source.last_success.slice(0,10)+')').slice(0,160),rate:p.rate,fixed_months:p.fixed_months,fee:p.fee,cashback:p.cashback??0,add_fee:false});$('renewal-edit-status').textContent='Benchmark copied. Review eligibility, dates, fee and cashback before saving.';$('renewal-deal-editor').lastElementChild?.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'center'});});
            details.append(select,info,copy);container.append(details);
        });
    }
    async function request(method='GET',source){const response=await fetch('../php_backend/public/mortgage_rates.php',{method,cache:'no-store',headers:{'Content-Type':'application/json'},body:source?JSON.stringify({source}):undefined});const data=await response.json();if(!response.ok)throw new Error(data.error||'Rates could not be checked.');if(!Array.isArray(data.sources))throw new Error('Lender status unavailable. Manual comparisons remain available.');sources=data.sources;renderSources();}
    async function refresh(ids){
        if(busy)return;busy=true;$('mortgage-rates-refresh').disabled=true;
        const errors=[];
        for(const id of ids){$('mortgage-rates-status').textContent='Checking '+(sources.find(s=>s.id===id)?.name||id)+'…';try{await request('POST',id);}catch(e){errors.push(e.message);}}
        busy=false;$('mortgage-rates-refresh').disabled=false;
        $('mortgage-rates-status').textContent=errors.length?errors.join(' '):'Source status updated. Checks within 12 hours reuse cached results; last success and failures are shown separately.';
    }
    async function startMarkets(){try{await request();$('mortgage-rates-status').textContent='Source status loaded.';const due=sources.filter(s=>s.due).map(s=>s.id);if(due.length)await refresh(due);}catch(e){$('mortgage-rates-status').textContent=e.message;}}
    $('renewal-add-deal').addEventListener('click',()=>addDeal());$('mortgage-rates-refresh').addEventListener('click',()=>refresh(['natwest','hsbc','barclays']));
    window.MortgageRenewalUI={collect,load,render,clear,startMarkets,drawPayments};
})();

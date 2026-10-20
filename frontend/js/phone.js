// Phone essentials: independent presentation, shared financial APIs and a narrowly scoped classification action.
(function () {
    'use strict';
    const filterKeys=['value','amount','min_amount','max_amount','start','end','direction','transfer_scope','ignored_scope','account_id','dimension','dimension_id','dimension_ids','unclassified','include_unclassified','group_id','transaction_ids','description_exact','memo_exact','gaps','evidence','evidence_page','label','spending_only','compare_start','compare_end'];
    const editableFilters=['account_id','direction','start','end','min_amount','max_amount','transfer_scope','ignored_scope'];
    const money=new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP',minimumFractionDigits:2,maximumFractionDigits:2});
    const integer=new Intl.NumberFormat('en-GB');
    function isTransfer(row){return (row.transfer_id!==null&&row.transfer_id!==undefined&&row.transfer_id!=='')||row.is_transfer===true||Number(row.is_transfer)===1;}
    function signed(value){const number=Number(value)||0;return (number>0?'+':'')+money.format(number);}
    function readFilters(params){const result=new URLSearchParams();filterKeys.forEach(key=>{if(params.has(key))result.set(key,params.get(key));});return result;}
    function searchQuery(params,page=1){const query=readFilters(params);query.set('all','1');query.set('page',page);query.delete('recent');return query;}
    function dateLabel(value,options={day:'numeric',month:'short'}){if(!value)return 'Date unavailable';const date=new Date(String(value).slice(0,10)+'T12:00:00');return Number.isNaN(date.getTime())?String(value):date.toLocaleDateString('en-GB',options);}
    function meta(row){return [row.account_name,isTransfer(row)?'Transfer':row.category_name||'Uncategorised'].filter(Boolean).join(' · ');}
    if(typeof module!=='undefined'&&module.exports){module.exports={isTransfer,signed,readFilters,searchQuery,meta};return;}

    const $=id=>document.getElementById(id);
    const main=$('phone-main');
    const state={view:'overview',overview:null,accounts:null,overviewSequence:0,scroll:{overview:0,month:0,search:0},search:{params:new URLSearchParams(),page:1,data:null,key:null,sequence:0,controller:null},month:{filter:'all',page:1,data:null,base:null,key:null,sequence:0,controller:null},detailId:null};
    let booted=false,searchTimer,detailSequence=0,accountsPromise=null,detailOpener=null,filterOpener=null,detailRow=null,detailDirty=false,detailSaving=false,tagSequence=0,tagTimer;
    let monthChart=null,monthChartsReady=null;
    const help={
        overview:'Your account position is the combined bank and credit-card balance, excluding closed accounts. Balances retain their bank-reported dates; loading this screen does not update those balances. Recorded pensions and mortgage debt are separate saved-plan values, not live valuations or a complete net-worth estimate. This month always means the current calendar month. Tap an account, a spending category or a transaction to see its activity.',
        month:'This is the current calendar month, including dates with no activity. Income, spending and net movement exclude transfers and ignored entries. The activity list includes transfers and labels them; ignored entries are hidden. The three headline values and compact spending chart cover the whole month even when you select a list filter. Tap a category for its transactions; expand Other for smaller categories. Fifty transactions load per page; totals cover all matching rows. Tap a row for the original statement detail.',
        search:'Search merchants, notes, categories, tags, groups or signed amounts. Results cover your complete history, with fifty rows per page. Use Filters for dates, accounts, direction and amount limits. The matching total adds every included signed row, including transfers or ignored entries when selected. Linked evidence filters remain active until Reset all filters. Tap a transaction to choose an existing tag and save its linked category and segment. Classification changes only that entry; use the full website to manage tags or rules.'
    };
    function el(tag,className,text){const node=document.createElement(tag);if(className)node.className=className;if(text!==undefined)node.textContent=text;return node;}
    function glyph(name){const node=el('i','fas '+name);node.setAttribute('aria-hidden','true');return node;}
    function button(text,fn,className='phone-text-button',label=text){const node=el('button',className,text);node.type='button';node.setAttribute('aria-label',label);node.addEventListener('click',fn);return node;}
    function section(title,action){const root=el('section','phone-section');const head=el('header','phone-section-header');head.append(el('h2','',title));if(action)head.append(action);root.append(head);return root;}
    function note(text){return el('p','phone-note',text);}
    function loading(host,text='Loading transactions…'){host.replaceChildren(el('p','phone-loading',text));host.setAttribute('aria-busy','true');}
    function error(view,message){if(view!==state.view)return;$('phone-error-copy').textContent=message;$('phone-error').hidden=false;}
    function clearError(){ $('phone-error').hidden=true; }
    async function request(endpoint,signal){
        const response=await fetch('../php_backend/public/'+endpoint,{cache:'no-store',credentials:'same-origin',signal});
        const data=await response.json().catch(()=>({}));
        if(!response.ok)throw new Error(data.error||'Information could not load. Please try again.');return data;
    }
    function routeUrl(view,params=state.search.params,page=state.search.page,transaction=null){
        const url=new URL('phone.html',location.href);url.searchParams.set('view',view);
        if(view==='search'){readFilters(params).forEach((value,key)=>url.searchParams.set(key,value));if(page>1)url.searchParams.set('page',page);}
        if(view==='month'){if(state.month.filter!=='all')url.searchParams.set('activity',state.month.filter);if(state.month.page>1)url.searchParams.set('page',state.month.page);}
        if(transaction)url.searchParams.set('transaction',transaction);return url;
    }
    function navigate(view,params=state.search.params,page=state.search.page,replace=false){
        state.scroll[state.view]=main.scrollTop;
        const url=routeUrl(view,params,page);
        history[replace?'replaceState':'pushState']({phone:true},'',url.href);
        applyRoute();
    }
    function applyRoute(){
        clearTimeout(searchTimer);
        const params=new URLSearchParams(location.search);
        const previousView=state.view;
        state.view=['overview','month','search'].includes(params.get('view'))?params.get('view'):'overview';
        $('phone-title').textContent={overview:'Overview',month:'This month',search:'Search'}[state.view];
        document.title={overview:'Overview',month:'This month',search:'Search'}[state.view]+' · Accounts';
        ['overview','month','search'].forEach(view=>{$('phone-'+view).hidden=view!==state.view;});
        document.querySelectorAll('[data-phone-tab]').forEach(link=>{if(link.dataset.phoneTab===state.view)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');});
        clearError();
        if(state.view==='overview'){
            if(state.overview)renderOverview();else loadOverview();
        }else if(state.view==='month'){
            state.month.filter=['all','spending','income','transfers'].includes(params.get('activity'))?params.get('activity'):'all';state.month.page=Math.max(1,Math.min(100000,Number(params.get('page'))||1));loadMonth();
        }else{
            state.search.params=readFilters(params);state.search.page=Math.max(1,Math.min(100000,Number(params.get('page'))||1));
            $('phone-query').value=state.search.params.get('value')||'';renderScope();loadSearch();
        }
        if(previousView!==state.view)main.scrollTop=state.scroll[state.view]||0;
        const id=params.get('transaction');
        if(id&&/^[1-9]\d*$/.test(id)){if(state.detailId!==id)openDetail(id,false);}else if($('phone-detail').open){$('phone-detail').close();state.detailId=null;}
    }
    function updateLocationPage(view,page){
        if(view==='search'){state.search.page=page;navigate('search',state.search.params,page);}
        else {state.month.page=page;navigate('month');}
        main.scrollTop=0;
    }
    function renderMetrics(summary){
        const root=el('div','phone-metrics');
        [['Money in',summary.income,'income'],['Money out',summary.spending,'spending'],['Net movement',summary.cashflow,'all']].forEach(([label,value,filter])=>{
            const item=button('',()=>{state.month.filter=filter;state.month.page=1;navigate('month');},'phone-metric',`View this month: ${label.toLowerCase()} ${filter==='all'?signed(value):money.format(Number(value)||0)}`);
            item.append(el('span','',label),el('strong',filter==='income'?'phone-income':'',filter==='all'?signed(value):money.format(Number(value)||0)));root.append(item);
        });return root;
    }
    function ledger(rows){
        const root=el('div','phone-transactions');
        if(!rows.length){const empty=el('div','phone-empty');empty.append(el('strong','','No transactions'),el('p','','There is no activity in this view. Try a wider search or another filter.'));root.append(empty);return root;}
        let day=null,list=null;
        rows.forEach(row=>{
            if(day!==row.date){day=row.date;const heading=el('h3','phone-date-heading',dateLabel(day,{weekday:'short',day:'numeric',month:'short',year:'numeric'}));list=el('div','phone-day-list');root.append(heading,list);}
            const item=button('',event=>{detailOpener=event.currentTarget;openDetail(row.id);},'phone-row',`View ${row.description||'transaction'}, ${signed(row.amount)}, ${dateLabel(row.date)}`);
            item.append(el('span','phone-row-main',row.description||row.memo||'Untitled transaction'),el('strong','phone-row-amount'+(Number(row.amount)>0?' phone-income':''),signed(row.amount)),el('span','phone-row-meta',meta(row)));
            list.append(item);
        });return root;
    }
    async function loadOverview(force=false){
        if(state.overview&&!force){renderOverview();return;}
        const sequence=++state.overviewSequence;const host=$('phone-overview');
        if(!state.overview)loading(host,'Loading your financial picture…');else host.setAttribute('aria-busy','true');
        try {
            const data=await request('phone_overview.php');if(sequence!==state.overviewSequence)return;
            state.overview=data;state.accounts=data.accounts;populateAccounts();renderOverview();
        }catch(failure){if(sequence===state.overviewSequence){host.setAttribute('aria-busy','false');if(!state.overview)host.replaceChildren(note('Your financial picture is unavailable.'));error('overview',failure.message+(state.overview?' Displayed figures may be out of date.':''));}}
    }
    function renderOverview(){
        const data=state.overview;if(!data)return;const host=$('phone-overview');host.replaceChildren();host.setAttribute('aria-busy','false');
        const balance=el('section','phone-balance');balance.append(el('p','phone-balance-label','Account position'),el('strong','phone-balance-value',money.format(data.headline.balance)));
        const totals=el('div','phone-account-totals');
        [['Bank accounts',data.account_totals.bank],['Credit cards',data.account_totals.cards]].forEach(([label,value])=>{const item=el('span','',label);item.append(el('strong','',money.format(value)));totals.append(item);});balance.append(totals);host.append(balance);
        const month=section(data.period.label,button('Activity',()=>navigate('month')));month.append(renderMetrics(data.metrics));host.append(month);
        const plans=el('div','phone-recorded-plans');
        const pension=el('div','');pension.append(el('span','phone-caption','Recorded pensions'),el('strong','',data.pensions?.configured?money.format(data.pensions.total):'Not recorded'),el('small','',data.pensions?.valued_from?'Valuations from '+dateLabel(data.pensions.valued_from,{day:'numeric',month:'short',year:'numeric'}):'Saved plan · separate from accounts'));
        const mortgage=el('div','');mortgage.append(el('span','phone-caption','Mortgage balance'),el('strong','',data.mortgage?.configured?money.format(data.mortgage.balance):'Not recorded'),el('small','',data.mortgage?.balance_date?'Saved balance · '+dateLabel(data.mortgage.balance_date,{day:'numeric',month:'short',year:'numeric'}):'Saved plan · separate debt'));plans.append(pension,mortgage);host.append(plans);
        const accounts=section('Accounts');const list=el('div','phone-accounts');
        (data.accounts||[]).filter(account=>!Number(account.closed)).sort((a,b)=>Number(b.balance)-Number(a.balance)).forEach(account=>{
            const row=button('',()=>navigate('search',new URLSearchParams({account_id:account.id,label:account.name,ignored_scope:'include'}),1),'phone-row',`View transactions for ${account.name}`);
            row.append(el('span','phone-row-main',account.name),el('strong','phone-row-amount',money.format(account.balance)),el('span','phone-row-meta',account.ledger_balance_date?'Bank balance dated '+dateLabel(account.ledger_balance_date,{day:'numeric',month:'short',year:'numeric'}):'Bank balance date unavailable'));list.append(row);
        });accounts.append(list.children.length?list:note('No active accounts. Import statements using the full website.'));host.append(accounts);
        if(data.top_categories?.length){const categories=section('Spending this month');const list=el('div','phone-accounts');data.top_categories.forEach(category=>{
            const query=new URLSearchParams({start:data.period.start,end:data.period.end,direction:'spending',transfer_scope:'exclude',ignored_scope:'exclude',dimension:'category',label:category.name});
            if(category.id)query.set('dimension_id',category.id);else query.set('unclassified','1');
            const row=button('',()=>navigate('search',query,1),'phone-row',`View ${category.name} spending transactions`);row.append(el('span','phone-row-main',category.name),el('strong','phone-row-amount',money.format(category.amount)),el('span','phone-row-meta',category.share+'% of spending'));list.append(row);
        });categories.append(list);host.append(categories);}
        const recent=section('Recent transactions',button('Search all',()=>navigate('search')));recent.append(ledger(data.recent||[]));host.append(recent);
        host.append(note('Loaded '+new Date(data.period.generated_at).toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'})+' · Bank balances retain their reported dates. Transfers and ignored entries are excluded from income and spending.'));
    }
    function monthParams(filter,page){const params=new URLSearchParams({all:'1',calendar_month:'1',page:String(page),ignored_scope:'exclude',transfer_scope:'include'});if(filter==='all')params.set('include_categories','1');if(filter==='transfers')params.set('transfer_scope','only');else if(filter!=='all'){params.set('direction',filter);params.set('transfer_scope','exclude');}return params;}
    function renderMonthSpending(base){
        const root=el('section','phone-spending');root.setAttribute('aria-label','Spending by category for the full month');
        root.append(el('h2','','Where the money went'));
        const categories=base.categories||[];
        if(!categories.length){root.append(note('No spending recorded this month.'));return root;}
        const colors=['#168575','#487fbb','#b07832','#9372bc','#7a898f'];
        const top=categories.slice(0,4),rest=categories.slice(4);
        const items=top.map((category,index)=>({...category,color:colors[index]}));
        if(rest.length)items.push({name:'Other',amount:rest.reduce((sum,category)=>sum+Number(category.amount),0),color:colors[4],other:true});
        const chart=el('div','phone-spending-chart');chart.setAttribute('aria-hidden','true');root.append(chart);
        const legend=el('div','phone-spending-key');
        function categoryButton(category,color){
            const query=new URLSearchParams({start:base.period.start,end:base.period.end,direction:'spending',transfer_scope:'exclude',ignored_scope:'exclude',dimension:'category',label:category.name+' · '+base.period.label});
            if(category.id)query.set('dimension_id',category.id);else query.set('unclassified','1');
            const share=Number(base.summary.spending)>0?Math.round(Number(category.amount)/Number(base.summary.spending)*100):0;
            const item=button('',()=>navigate('search',query,1),'phone-spending-item',`View ${category.name}: ${money.format(category.amount)}, ${share}% of monthly spending`);
            const swatch=el('span','phone-spending-swatch');swatch.style.backgroundColor=color;swatch.setAttribute('aria-hidden','true');
            item.append(swatch,el('span','phone-spending-name',category.name),el('span','phone-spending-amount',money.format(category.amount)+' · '+share+'%'));return item;
        }
        top.forEach((category,index)=>legend.append(categoryButton(category,colors[index])));
        root.append(legend);
        if(rest.length){
            const details=el('details','phone-spending-more');
            const summary=el('summary','',`Other · ${money.format(items[4].amount)} · ${rest.length} more ${rest.length===1?'category':'categories'}`);
            const remaining=el('div','phone-spending-key');rest.forEach(category=>remaining.append(categoryButton(category,colors[4])));
            details.append(summary,remaining);root.append(details);
        }
        // The labels and ledger work immediately, even if the optional chart cannot load.
        if(!monthChartsReady)monthChartsReady=window.Highcharts?Promise.resolve(window.Highcharts):new Promise(resolve=>{
            const script=document.createElement('script');script.src='https://code.highcharts.com/highcharts.js';script.onload=()=>resolve(window.Highcharts);script.onerror=()=>resolve(null);document.head.append(script);
        });
        monthChartsReady.then(Highcharts=>{
            if(!chart.isConnected)return;
            if(!Highcharts){chart.hidden=true;return;}
            monthChart=Highcharts.chart(chart,{
                chart:{type:'bar',height:28,backgroundColor:'transparent',margin:0,spacing:[0,0,0,0],animation:false,style:{fontFamily:'inherit'}},
                title:{text:null},credits:{enabled:false},legend:{enabled:false},accessibility:{enabled:false},
                xAxis:{visible:false,categories:['Spending']},yAxis:{visible:false,min:0,max:Number(base.summary.spending),endOnTick:false},
                tooltip:{enabled:false},
                plotOptions:{series:{stacking:'normal',animation:false,borderWidth:0,pointWidth:16,enableMouseTracking:false,states:{inactive:{opacity:1}}}},
                series:items.slice().reverse().map(category=>({name:category.name,color:category.color,data:[Number(category.amount)]}))
            });
        });
        return root;
    }
    async function loadMonth(force=false){
        const month=state.month,key=month.filter+':'+month.page;const host=$('phone-month');
        const sequence=++month.sequence;month.controller?.abort();
        if(month.key===key&&month.data&&!force){renderMonth();return;}
        month.controller=new AbortController();
        if(!month.data)loading(host);else renderMonth(true);
        try {
            const rowsPromise=request('search_transactions.php?'+monthParams(month.filter,month.page),month.controller.signal);
            const basePromise=month.base&&!force?Promise.resolve(month.base):(month.filter==='all'?rowsPromise:request('search_transactions.php?'+monthParams('all',1),month.controller.signal));
            const [data,base]=await Promise.all([rowsPromise,basePromise]);if(sequence!==month.sequence)return;
            // A calendar rollover invalidates the previous month's headline totals.
            if(base.period?.start!==data.period?.start){month.base=null;return loadMonth(true);}
            month.data=data;month.base=base;month.key=key;month.page=data.pagination.page;renderMonth();
        }catch(failure){if(failure.name!=='AbortError'&&sequence===month.sequence){host.setAttribute('aria-busy','false');if(month.key!==key)month.data=null;renderMonth(false);error('month',failure.message);}}
    }
    function renderMonth(pending=false){
        const {data,base,filter}=state.month;const host=$('phone-month');
        if(monthChart){monthChart.destroy();monthChart=null;}
        if(!base){host.replaceChildren(note('Month activity is unavailable.'));return;}
        host.replaceChildren();host.setAttribute('aria-busy',String(pending));
        host.append(el('p','phone-caption',(data||base).period?.label||'Current calendar month'),renderMetrics(base.summary));
        host.append(renderMonthSpending(base));
        const segments=el('div','phone-segments');segments.setAttribute('aria-label','Filter month activity');
        [['all','All'],['spending','Money out'],['income','Money in'],['transfers','Transfers']].forEach(([value,label])=>{const item=button(label,()=>{state.month.filter=value;state.month.page=1;navigate('month');},'');item.setAttribute('aria-pressed',String(filter===value));segments.append(item);});host.append(segments);
        const info=el('div','phone-result-summary');info.setAttribute('role','status');info.append(el('span','',pending?'Updating activity…':data?integer.format(data.pagination.total)+(data.pagination.total===1?' transaction':' transactions'):'Activity unavailable'),el('small','','Headline values cover the full month · ignored entries hidden'));host.append(info);
        if(!pending&&data)host.append(ledger(data.results),pagination(data.pagination,'month'));
    }
    function renderScope(){
        const params=state.search.params,parts=[];
        if(params.get('label'))parts.push(params.get('label'));
        if(params.get('start')||params.get('end'))parts.push((params.get('start')?dateLabel(params.get('start'),{day:'numeric',month:'short',year:'numeric'}):'First record')+' – '+(params.get('end')?dateLabel(params.get('end'),{day:'numeric',month:'short',year:'numeric'}):'latest'));
        if(params.get('account_id')&&!params.get('label')){const account=state.accounts?.find(account=>String(account.id)===params.get('account_id'));parts.push(account?.name||'Account #'+params.get('account_id'));}
        if(params.get('direction')&&params.get('direction')!=='all')parts.push(params.get('direction')==='income'?'Money in':'Money out');
        if(params.has('amount'))parts.push('Amount '+money.format(Number(params.get('amount'))));
        if(params.get('min_amount'))parts.push('Min '+money.format(Number(params.get('min_amount'))));if(params.get('max_amount'))parts.push('Max '+money.format(Number(params.get('max_amount'))));
        if(params.get('transfer_scope')&&params.get('transfer_scope')!=='include')parts.push(params.get('transfer_scope')==='only'?'Transfers only':'No transfers');
        if(params.get('ignored_scope')&&params.get('ignored_scope')!=='exclude')parts.push(params.get('ignored_scope')==='only'?'Ignored only':'Including ignored');
        if(params.has('dimension')||params.has('transaction_ids')||params.has('group_id')||params.has('evidence')||params.has('gaps')||params.has('description_exact')||params.has('memo_exact'))parts.push('Linked evidence');
        const host=$('phone-search-scope');host.replaceChildren();host.hidden=!parts.length;host.append(el('span','',parts.join(' · ')),button('Reset',resetSearch));$('phone-filter-dot').hidden=!parts.length;
    }
    async function loadSearch(force=false){
        const search=state.search,query=searchQuery(search.params,search.page),key=query.toString(),host=$('phone-search-results');
        const sequence=++search.sequence;search.controller?.abort();
        if(query.has('compare_start')||query.has('compare_end')){
            host.replaceChildren(note('This linked search compares two periods. Open the full website to view both sets of evidence.'),button('Open comparison on full website',()=>openDesktop('search.html',search.params),'phone-primary-button'));host.setAttribute('aria-busy','false');return;
        }
        if(search.key===key&&search.data&&!force){renderSearch();return;}
        search.controller=new AbortController();
        loading(host,'Searching transactions…');
        try {
            const data=await request('search_transactions.php?'+query,search.controller.signal);if(sequence!==search.sequence)return;
            search.data=data;search.key=key;search.page=data.pagination.page;renderSearch();
        }catch(failure){if(failure.name!=='AbortError'&&sequence===search.sequence){host.setAttribute('aria-busy','false');host.replaceChildren(note('Search could not complete. Your filters have been kept.'));search.data=null;search.key=null;error('search',failure.message);}}
    }
    function renderSearch(){
        const data=state.search.data;if(!data)return;const host=$('phone-search-results');host.replaceChildren();host.setAttribute('aria-busy','false');
        const info=el('div','phone-result-summary');info.setAttribute('role','status');info.append(el('span','',integer.format(data.pagination.total)+(data.pagination.total===1?' match':' matches')),el('strong','',signed(data.summary.signed_total)),el('small','','Matching total · all included rows across every page'));host.append(info,ledger(data.results),pagination(data.pagination,'search'));
        if(data.evidence){const evidence=el('div','phone-pagination');evidence.append(el('span','',`Import batch ${data.evidence.page} of ${data.evidence.pages}`));[['Previous batch',data.evidence.page-1],['Next batch',data.evidence.page+1]].forEach(([label,page])=>{if(page<1||page>data.evidence.pages)return;evidence.append(button(label,()=>{const params=new URLSearchParams(state.search.params);params.set('evidence_page',page);navigate('search',params,1);},'phone-quiet-button'));});host.append(evidence);}
    }
    function pagination(page,view){
        const root=el('nav','phone-pagination');root.setAttribute('aria-label',view==='month'?'Month activity pages':'Search result pages');
        const previous=button('Previous',()=>updateLocationPage(view,page.page-1),'phone-quiet-button');previous.disabled=page.page<=1;
        const next=button('Next',()=>updateLocationPage(view,page.page+1),'phone-quiet-button');next.disabled=page.page>=page.pages;
        root.append(previous,el('span','',`Page ${page.page} of ${page.pages}`),next);return root;
    }
    function resetSearch(){
        $('phone-query').value='';$('phone-filter-form').reset();$('phone-filter-error').hidden=true;
        if($('phone-filters').open)$('phone-filters').close();navigate('search',new URLSearchParams(),1,true);
    }
    function populateAccounts(){
        if(!state.accounts)return;const select=$('phone-account'),current=select.value||state.search.params.get('account_id')||'';select.replaceChildren();const all=el('option','','All accounts');all.value='';select.append(all);
        state.accounts.forEach(account=>{const option=el('option','',account.name+(Number(account.closed)?' (closed)':''));option.value=account.id;select.append(option);});
        if(current&&![...select.options].some(option=>option.value===current)){const selected=el('option','','Selected account #'+current);selected.value=current;select.append(selected);}select.value=current;
    }
    async function ensureAccounts(){
        if(state.accounts){populateAccounts();return;}if(accountsPromise)return accountsPromise;
        accountsPromise=request('account_dashboard.php').then(accounts=>{state.accounts=accounts;populateAccounts();}).catch(()=>{const option=$('phone-account').options[0];option.textContent='All accounts · list unavailable';}).finally(()=>{accountsPromise=null;});return accountsPromise;
    }
    function openFilters(){
        const form=$('phone-filter-form');form.reset();editableFilters.forEach(name=>{if(state.search.params.has(name)){const field=form.elements.namedItem(name);if(name==='account_id'&&![...field.options].some(option=>option.value===state.search.params.get(name))){const option=el('option','','Selected account #'+state.search.params.get(name));option.value=state.search.params.get(name);field.append(option);}field.value=state.search.params.get(name);}});
        if(state.search.params.has('amount')){form.elements.namedItem('min_amount').value=state.search.params.get('amount');form.elements.namedItem('max_amount').value=state.search.params.get('amount');}
        if(state.search.params.get('spending_only')==='1'){form.elements.namedItem('direction').value='spending';form.elements.namedItem('transfer_scope').value='exclude';}
        $('phone-filter-error').hidden=true;filterOpener=document.activeElement;$('phone-filters').showModal();ensureAccounts();
    }
    function detailField(list,label,value){const row=el('div','');row.append(el('dt','',label),el('dd','',value||'Not assigned'));list.append(row);}
    async function openDetail(id,push=true){
        const dialog=$('phone-detail'),host=$('phone-detail-content');state.detailId=String(id);const sequence=++detailSequence;
        if(push){const url=new URL(location.href);url.searchParams.set('transaction',id);history.pushState({phone:true,phoneDetail:true},'',url.href);}
        host.replaceChildren();const head=el('header','phone-sheet-heading');head.append(el('h2','phone-detail-title','Transaction'),button('',closeDetail,'phone-icon-button','Close transaction details'));head.lastChild.append(glyph('fa-xmark'));head.querySelector('h2').id='phone-detail-title';host.append(head,el('p','phone-loading','Loading transaction…'));
        if(!dialog.open)dialog.showModal();
        try {
            const row=await request('phone_transaction.php?id='+encodeURIComponent(id));if(sequence!==detailSequence)return;detailRow=row;detailDirty=false;
            host.replaceChildren(head);head.querySelector('h2').id='phone-detail-title';head.querySelector('h2').textContent=row.description||'Transaction';
            host.append(el('strong','phone-detail-amount'+(Number(row.amount)>0?' phone-income':''),signed(row.amount)),el('p','phone-detail-date',dateLabel(row.date,{weekday:'long',day:'numeric',month:'long',year:'numeric'})));
            const fields=el('dl','phone-detail-fields');[['Account',row.account_name],['Memo',row.memo||'No memo'],['Tag',row.tag_name],['Category',row.category_name],['Segment',row.segment_name],['Group',row.group_name],['Status',isTransfer(row)?'Transfer · excluded from income and spending':String(row.tag_name).toLowerCase()==='ignore'?'Ignored · excluded from income and spending':'Included in income and spending'],['Reference','#'+row.id]].forEach(([label,value])=>detailField(fields,label,value));if(row.can_classify)host.append(classificationForm(row));else host.append(note('Transfers, ignored entries and system classifications are protected.'));const details=el('details','phone-statement-details');details.append(el('summary','','Statement details'),fields);host.append(details,button('Done',closeDetail,'phone-quiet-button'));
        }catch(failure){if(sequence!==detailSequence)return;host.replaceChildren(head,note(failure.message),button('Try again',()=>openDetail(id,false),'phone-quiet-button'));}
    }
    function classificationForm(row){
        const form=el('form','phone-classification');form.id='phone-classification';form.dataset.preserveEdits='';
        const group=el('fieldset','');const label=el('label','','Classify this transaction');label.htmlFor='phone-tag-query';
        const input=el('input','');input.id='phone-tag-query';input.type='search';input.placeholder='Find an existing tag';input.autocomplete='off';
        input.setAttribute('aria-describedby','phone-tag-preview');
        const chosen=el('input','');chosen.type='hidden';chosen.id='phone-tag-id';chosen.value=row.tag_id||'';
        const results=el('div','phone-tag-results');results.setAttribute('aria-label','Matching tags');
        const preview=el('p','phone-note');preview.id='phone-tag-preview';
        preview.textContent='Current: '+(row.tag_name||'Unassigned')+' · '+(row.category_name||'No category')+' · '+(row.segment_name||'No segment');
        const status=el('p','phone-note');status.id='phone-classification-status';status.setAttribute('role','status');
        const save=el('button','phone-primary-button','Save classification');save.type='submit';save.disabled=true;save.setAttribute('aria-label','Save classification');
        let selection=null;
        input.addEventListener('input',()=>{
            detailDirty=true;selection=null;save.disabled=true;chosen.value='';status.textContent='Choose a matching tag to preview its classification.';clearTimeout(tagTimer);const sequence=++tagSequence;
            results.replaceChildren(note('Searching tags…'));
            tagTimer=setTimeout(async()=>{
                try{const data=await request('tags.php?options=1&classification=1&phone=1&limit=20&q='+encodeURIComponent(input.value.trim()));
                    if(sequence!==tagSequence||!form.isConnected)return;results.replaceChildren();
                    data.tags.forEach(tag=>{const item=button('',()=>{
                        selection=tag;chosen.value=tag.id;input.value=tag.name;detailDirty=true;++tagSequence;results.replaceChildren();
                        preview.textContent=tag.name+' → '+(tag.category_name||'No category')+' → '+(tag.segment_name||'No segment');save.disabled=false;status.textContent='Ready to save.';
                    },'phone-tag-option','Choose '+tag.name);item.append(el('strong','',tag.name),el('span','',[(tag.category_name||'No category'),(tag.segment_name||'No segment')].join(' · ')));results.append(item);});
                    if(!data.tags.length)results.append(note('No matching tags. Try another name.'));
                    else if(data.has_more)results.append(note('Type more to narrow these matches.'));
                }catch(error){if(sequence===tagSequence&&form.isConnected)results.replaceChildren(note(error.message));}
            },250);
        });
        group.append(preview,label,input,chosen,results,save);form.append(group,status,note('Applies to this entry only. Existing tag links supply the category and segment; no merchant rule is created.'));
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(!selection||detailSaving)return;
            detailSaving=true;group.disabled=true;status.textContent='Saving…';
            const captured=window.AppWeb?.capture(form);
            try{
                const response=await fetch('../php_backend/public/phone_transaction.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({transaction_id:row.id,tag_id:selection.id,revision:row.revision,category_id:selection.category_id,segment_id:selection.segment_id})});
                const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'Classification could not be saved. Your selection has been kept.');
                detailDirty=false;window.AppWeb?.markSaved(form,captured);detailRow=data;
                state.overview=null;state.search.key=null;state.month.key=null;state.month.base=null;
                detailSaving=false;await openDetail(row.id,false);
                const saved=$('phone-classification-status');if(saved)saved.textContent='Classification saved.';
                if(state.view==='month')loadMonth(true);else if(state.view==='search')loadSearch(true);else loadOverview(true);
            }catch(error){status.textContent=error.message+' Save again when you are ready.';}
            finally{detailSaving=false;group.disabled=false;}
        });return form;
    }
    function discardDetail(){
        if(detailSaving)return false;
        if(detailDirty&&!confirm('Discard your unsaved classification selection?'))return false;
        detailDirty=false;++tagSequence;clearTimeout(tagTimer);window.AppWeb?.markSaved($('phone-classification'));return true;
    }
    function closeDetail(){
        if(!discardDetail())return;
        ++detailSequence;state.detailId=null;
        if(history.state?.phoneDetail){history.back();}else{const url=new URL(location.href);url.searchParams.delete('transaction');history.replaceState({phone:true},'',url.href);}
        $('phone-detail').close();if(detailOpener?.isConnected)detailOpener.focus({preventScroll:true});
    }
    function refresh(){
        if(detailDirty||detailSaving){const status=$('phone-classification-status');if(status)status.textContent='Your selection has been kept. Save when you are ready.';return;}
        if(!booted){boot();return;}
        clearError();state.month.base=null;
        if(state.view==='overview')loadOverview(true);else if(state.view==='month')loadMonth(true);else loadSearch(true);
        if(state.detailId)openDetail(state.detailId,false);
    }
    document.querySelectorAll('[data-phone-tab]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();if(link.dataset.phoneTab===state.view){main.scrollTo({top:0,behavior:'auto'});return;}navigate(link.dataset.phoneTab);}));
    $('phone-search-form').addEventListener('submit',event=>{event.preventDefault();clearTimeout(searchTimer);const params=new URLSearchParams(state.search.params);const value=$('phone-query').value.trim();if(value)params.set('value',value);else params.delete('value');navigate('search',params,1,true);$('phone-query').blur();});
    $('phone-query').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{const params=new URLSearchParams(state.search.params),value=$('phone-query').value.trim();if(value)params.set('value',value);else params.delete('value');navigate('search',params,1,true);},350);});
    $('phone-filter-button').addEventListener('click',openFilters);
    $('phone-filter-reset').addEventListener('click',resetSearch);
    $('phone-filter-form').addEventListener('submit',event=>{
        event.preventDefault();const form=event.currentTarget,values=new FormData(form),start=values.get('start'),end=values.get('end'),min=values.get('min_amount'),max=values.get('max_amount');
        const message=start&&end&&start>end?'The start date must be before the end date.':min!==''&&max!==''&&Number(min)>Number(max)?'The minimum amount must not exceed the maximum.':'';
        if(message){$('phone-filter-error').textContent=message;$('phone-filter-error').hidden=false;return;}
        const params=new URLSearchParams(state.search.params);params.delete('amount');editableFilters.forEach(name=>{const value=values.get(name);if(value)params.set(name,value);else params.delete(name);});
        if(params.has('spending_only'))params.delete('spending_only');
        $('phone-filters').close();navigate('search',params,1,true);
    });
    $('phone-filters').addEventListener('close',()=>{if(filterOpener?.isConnected)filterOpener.focus({preventScroll:true});});
    $('phone-options-button').addEventListener('click',()=>$('phone-options').showModal());
    document.querySelectorAll('[data-close-dialog]').forEach(item=>item.addEventListener('click',()=>item.closest('dialog').close()));
    $('phone-refresh').addEventListener('click',()=>{$('phone-options').close();refresh();});
    $('phone-retry').addEventListener('click',refresh);
    $('phone-help-button').addEventListener('click',()=>{$('phone-options').close();$('phone-help-copy').textContent=help[state.view];$('phone-help').showModal();});
    function openDesktop(page,params=new URLSearchParams()){
        try{sessionStorage.setItem('accounts-desktop-interface','1');}catch(_){}
        const url=new URL(page,location.href);url.search=readFilters(params).toString();url.searchParams.set('interface','desktop');location.assign(url.href);
    }
    $('phone-desktop').addEventListener('click',event=>{event.preventDefault();openDesktop(state.view==='month'?'monthly_statement.html':state.view==='search'?'search.html':'instant.html',state.view==='search'?state.search.params:new URLSearchParams());});
    try{sessionStorage.removeItem('accounts-desktop-interface');}catch(_){}
    const larger=$('phone-larger-text');try{larger.checked=localStorage.getItem('accounts-phone-larger-text')==='1';}catch(_){}
    document.documentElement.classList.toggle('phone-larger-text',larger.checked);
    larger.addEventListener('change',()=>{document.documentElement.classList.toggle('phone-larger-text',larger.checked);try{localStorage.setItem('accounts-phone-larger-text',larger.checked?'1':'0');}catch(_){}});
    $('phone-detail').addEventListener('cancel',event=>{event.preventDefault();closeDetail();});
    window.addEventListener('popstate',()=>{if(state.detailId&&new URLSearchParams(location.search).get('transaction')!==state.detailId&&!discardDetail()){const url=new URL(location.href);url.searchParams.set('transaction',state.detailId);history.pushState({phone:true,phoneDetail:true},'',url.href);return;}applyRoute();});
    document.addEventListener('app:resume',refresh);
    document.addEventListener('app:session-restored',refresh);
    async function boot(){
        try{await request('current_user.php');booted=true;applyRoute();}catch(failure){error(state.view,failure.message);}
    }
    const dark=matchMedia('(prefers-color-scheme: dark)');
    const updateChrome=()=>document.querySelector('meta[name=theme-color]').setAttribute('content',dark.matches?'#10191d':'#f5f7f8');
    updateChrome();dark.addEventListener('change',updateChrome);
    const keyboardViewport=()=>{
        const viewport=window.visualViewport;
        if(viewport&&viewport.scale===1)document.documentElement.style.setProperty('--phone-keyboard-height',viewport.height+'px');
    };
    keyboardViewport();window.visualViewport?.addEventListener('resize',keyboardViewport);
    boot();
})();

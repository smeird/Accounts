// Turns transaction search results into an answer-first investigative view.
(function () {
    'use strict';

    const money = new Intl.NumberFormat('en-GB', { style:'currency', currency:'GBP', minimumFractionDigits:2, maximumFractionDigits:2 });
    const compactMoney = new Intl.NumberFormat('en-GB', { style:'currency', currency:'GBP', maximumFractionDigits:0 });
    const shortDate = new Intl.DateTimeFormat('en-GB', { day:'numeric', month:'short', year:'numeric' });
    let resultTable = null;
    let resultTableHasComparison = false;
    const structuredKeys=['start','end','direction','transfer_scope','ignored_scope','dimension','dimension_id','dimension_ids','unclassified','include_unclassified','group_id','account_id','transaction_ids','description_exact','memo_exact','compare_start','compare_end','all','label','recent','evidence','evidence_page','spending_only','gaps'];
    const filterFields={start:'filter-start',end:'filter-end',account_id:'filter-account',direction:'filter-direction',transfer_scope:'filter-transfers',ignored_scope:'filter-ignored'};
    let searchSequence=0;
    let latestRows=[];
    let navigationState=history.state?.transactionList || null;
    const mainScroller=document.querySelector('main');
    let listScroll=mainScroller.scrollTop;
    // Track the actual offset as it changes. Besides preserving return position,
    // this keeps Safari's async scroller synchronised when reversing at the end
    // of the ledger/chart workspace (otherwise wheel scrolling can stick there).
    mainScroller.addEventListener('scroll',()=>{listScroll=mainScroller.scrollTop;},{passive:true});

    function byId(id) { return document.getElementById(id); }
    function setText(id, value) { const element = byId(id); if (element) element.textContent = value; }
    function amount(value) { return Number(value) || 0; }
    function dateFor(value) { return new Date(value + (String(value).length === 10 ? 'T12:00:00' : '')); }
    function emptyState(title, copy, icon) {
        const root = document.createElement('div'); root.className = 'transaction-empty';
        const body = document.createElement('div'); body.className = 'transaction-empty__body';
        const glyph = document.createElement('i'); glyph.className = 'fas ' + icon; glyph.setAttribute('aria-hidden', 'true');
        const heading = document.createElement('strong'); heading.textContent = title;
        const paragraph = document.createElement('p'); paragraph.textContent = copy;
        body.append(glyph, heading, paragraph); root.appendChild(body); return root;
    }
    function headerActions() {
        const wrap = document.createElement('div'); wrap.className = 'transaction-header-actions';
        const reports = document.createElement('a'); reports.href = 'report.html'; reports.className = 'transaction-header-link';
        const icon = document.createElement('i'); icon.className = 'fas fa-chart-column'; icon.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span'); label.textContent = 'Build a report'; reports.append(icon, label); wrap.appendChild(reports); return wrap;
    }
    function resetSummary() {
        setText('search-hero-value', '£—'); setText('search-match-count', '—'); setText('search-match-label', 'matches');
        setText('search-income', '£—'); setText('search-spending', '£—'); setText('search-period', '—'); setText('search-period-note', 'Across all dates'); setText('search-transfers', '—');
        setText('search-hero-context', 'Ready to investigate'); setText('search-bucket-label', '—'); setText('search-result-count', '0');
        byId('search-hero-value').classList.remove('transaction-tone--positive', 'transaction-tone--negative');
    }
    function renderSummary(rows, queryLabel) {
        const transfers = rows.filter(row => row.transfer_id !== null && typeof row.transfer_id !== 'undefined');
        const financial = rows.filter(row => row.transfer_id === null || typeof row.transfer_id === 'undefined');
        const incoming = financial.reduce((sum, row) => sum + Math.max(0, amount(row.amount)), 0);
        const spending = financial.reduce((sum, row) => sum + Math.max(0, -amount(row.amount)), 0);
        const net = incoming - spending;
        const dates = rows.map(row => dateFor(row.date)).filter(date => !Number.isNaN(date.getTime())).sort((a,b) => a-b);
        const value = byId('search-hero-value');
        value.classList.remove('transaction-tone--positive', 'transaction-tone--negative');
        if (net > 0) value.classList.add('transaction-tone--positive');
        if (net < 0) value.classList.add('transaction-tone--negative');
        setText('search-hero-value', money.format(net));
        setText('search-match-count', String(rows.length));
        setText('search-match-label', rows.length === 1 ? 'match' : 'matches');
        setText('search-income', compactMoney.format(incoming));
        setText('search-spending', compactMoney.format(spending));
        setText('search-transfers', String(transfers.length));
        setText('search-hero-context', queryLabel || 'All matching fields');
        if (dates.length) {
            const first = dates[0], last = dates[dates.length - 1];
            setText('search-period', first.getFullYear() === last.getFullYear() ? String(first.getFullYear()) : first.getFullYear() + '–' + last.getFullYear());
            setText('search-period-note', shortDate.format(first) + ' to ' + shortDate.format(last));
        } else { setText('search-period', '—'); setText('search-period-note', 'No dated activity'); }
        setText('search-result-count', String(rows.length));
        setText('search-results-copy', rows.length === 1 ? 'One transaction matches your filters.' : rows.length + ' transactions match your filters. Sort a column or open a transaction for detail.');
        setText('search-hero-message', rows.length ? (net < 0 ? compactMoney.format(Math.abs(net)) + ' more left than arrived across these matches.' : net > 0 ? compactMoney.format(net) + ' more arrived than left across these matches.' : 'Matched inflows and outgoings balance exactly.') : 'No transactions matched this combination. Try widening the amount range or simplifying the search term.');
    }
    function updateTableTotal() {
        const footer = byId('search-table-total');
        footer.hidden = !resultTable;
        if (!resultTable) return;
        const rows = resultTable.getData('active');
        const total = rows.reduce((sum, row) => sum + amount(row.amount), 0);
        setText('search-table-total-value', money.format(total));
    }
    function renderTable(rows) {
        const grid = byId('results-grid');
        const hasComparison = rows.some(row=>row._evidence_period);
        if (!rows.length) { if (resultTable) { resultTable.destroy(); resultTable = null; } resultTableHasComparison=false; updateTableTotal(); grid.replaceChildren(emptyState('No matching transactions', 'Try another term or widen the amount range.', 'fa-magnifying-glass')); return; }
        if (resultTable && resultTableHasComparison === hasComparison) { resultTable.setData(rows); return; }
        if (resultTable) resultTable.destroy();
        grid.replaceChildren();
        resultTableHasComparison=hasComparison;
        resultTable = tailwindTabulator(grid, {
            data:rows, initialSort:[{column:'date',dir:'desc'}], layout:'fitDataStretch', responsiveLayout:'collapse', placeholder:'No matching transactions',
            columns:[
                ...(hasComparison?[{ title:'Period', field:'_evidence_period', width:105, responsive:1 }]:[]),
                // API dates are YYYY-MM-DD, whose string order is chronological (no Luxon dependency).
                { title:'Date', field:'date', width:112, sorter:'string' },
                { title:'Account', field:'account_name', minWidth:130, responsive:3 },
                { title:'Description', field:'description', minWidth:190, formatter:function(cell){ const row=cell.getRow().getData(); const link=document.createElement('a'); link.href=window.TransactionNavigation.detailUrl(row.id); link.textContent=cell.getValue() || 'Untitled'; return link; } },
                { title:'Memo', field:'memo', minWidth:150, responsive:2 },
                { title:'Category', field:'category_name', formatter:badgeFormatter('bg-green-200 text-green-800'), responsive:1 },
                { title:'Tag', field:'tag_name', formatter:badgeFormatter('bg-indigo-200 text-indigo-800'), responsive:2 },
                { title:'Group', field:'group_name', formatter:badgeFormatter('bg-purple-200 text-purple-800'), responsive:3 },
                { title:'Segment', field:'segment_name', formatter:badgeFormatter('bg-yellow-200 text-yellow-800'), responsive:3 },
                { title:'Amount', field:'amount', formatter:'money', formatterParams:{symbol:'£',precision:2}, hozAlign:'right', sorter:'number', width:120 }
            ]
        });
        resultTable.on('tableBuilt',()=>{
            if(!navigationState)return;
            const state=navigationState;navigationState=null;
            if(state.sort?.length)resultTable.setSort(state.sort);
            const restorePosition=async()=>{
                if(state.page>1)try{await resultTable.setPage(state.page);}catch(_){}
                requestAnimationFrame(()=>{document.querySelector('main').scrollTop=state.scroll||0;});
            };
            const input=byId('results-grid').previousElementSibling?.querySelector('.modern-table-search-input');
            if(input&&state.query){
                const restored=()=>{resultTable.off('dataFiltered',restored);restorePosition();};
                resultTable.on('dataFiltered',restored);input.value=state.query;input.dispatchEvent(new Event('input',{bubbles:true}));
            }else restorePosition();
        });
        ['tableBuilt', 'dataProcessed', 'dataFiltered'].forEach(event => resultTable.on(event, updateTableTotal));
    }
    function netFor(rows){return rows.reduce((sum,row)=>(row.transfer_id===null||typeof row.transfer_id==='undefined')?sum+amount(row.amount):sum,0);}
    function renderComparison(current,comparison,params){
        const panel=byId('search-comparison');
        if(!comparison){panel.hidden=true;return current;}
        const currentNet=netFor(current),comparisonNet=netFor(comparison);
        panel.hidden=false;
        setText('search-current-net',money.format(currentNet));setText('search-current-count',`${current.length} transaction${current.length===1?'':'s'}`);
        setText('search-comparison-net',money.format(comparisonNet));setText('search-comparison-count',`${comparison.length} transaction${comparison.length===1?'':'s'}`);
        setText('search-comparison-change',money.format(currentNet-comparisonNet));
        setText('search-comparison-copy',`${params.get('start')||'Selected start'} to ${params.get('end')||'selected end'} compared with ${params.get('compare_start')} to ${params.get('compare_end')}.`);
        return current.map(row=>Object.assign({_evidence_period:'Selected'},row)).concat(comparison.map(row=>Object.assign({_evidence_period:'Comparison'},row)));
    }
    function renderFilterContext(params){
        const host=byId('search-filter-context');host.replaceChildren();
        const labels=[];
        if(params.get('label'))labels.push(params.get('label'));
        if(params.get('start')||params.get('end'))labels.push(`${params.get('start')||'First record'} → ${params.get('end')||'Latest record'}`);
        if(params.get('direction'))labels.push(params.get('direction')==='all'?'Income and spending':params.get('direction'));
        if(params.get('gaps')==='1')labels.push('Missing tag or category');
        if(params.get('dimension'))labels.push(`${params.get('dimension')}${params.get('unclassified')==='1'?' · unclassified':''}`);
        if(params.get('dimension_id'))labels.push(`Exact classification #${params.get('dimension_id')}`);
        if(params.get('dimension_ids'))labels.push(`${params.get('dimension_ids').split(',').filter(Boolean).length} classifications`);
        if(params.get('transaction_ids'))labels.push(`${params.get('transaction_ids').split(',').filter(Boolean).length} exact transactions`);
        if(params.get('group_id'))labels.push(`Group #${params.get('group_id')}`);
        if(params.get('account_id'))labels.push(`Account #${params.get('account_id')}`);
        if(params.get('transfer_scope') && params.get('transfer_scope')!=='include')labels.push(`Transfers ${params.get('transfer_scope')}`);
        if(params.get('ignored_scope') && params.get('ignored_scope')!=='exclude')labels.push(`Excluded items ${params.get('ignored_scope')}`);
        labels.forEach(label=>{const chip=document.createElement('span');chip.textContent=label;host.appendChild(chip);});host.hidden=!labels.length;
    }
    function groupSpending(rows) {
        const spending = rows.filter(row => (row.transfer_id === null || typeof row.transfer_id === 'undefined') && amount(row.amount) < 0);
        if (!spending.length) return { label:'No spend', categories:[], values:[] };
        const dates = spending.map(row => dateFor(row.date));
        const span = (Math.max.apply(null, dates) - Math.min.apply(null, dates)) / 86400000;
        let group='day'; if (span > 1095) group='year'; else if (span > 62) group='month';
        const totals = {};
        spending.forEach(row => { const date=String(row.date); const key=group==='year'?date.slice(0,4):group==='month'?date.slice(0,7):date.slice(0,10); totals[key]=(totals[key]||0)+Math.abs(amount(row.amount)); });
        const keys=Object.keys(totals).sort();
        const labels=keys.map(key => group==='month' ? new Date(key+'-02T12:00:00').toLocaleDateString('en-GB',{month:'short',year:'numeric'}) : group==='day' ? shortDate.format(dateFor(key)) : key);
        return { label:group.charAt(0).toUpperCase()+group.slice(1), categories:labels, values:keys.map(key=>Number(totals[key].toFixed(2))) };
    }
    function destroyChart() {
        window.AppCharts?.cancel(byId('results-chart'));
        const chart = window.Highcharts?.charts.find(item => item && item.renderTo.id === 'results-chart');
        if (chart) chart.destroy();
    }
    function renderChart(rows) {
        byId('search-chart-panel').hidden=!rows.some(row=>amount(row.amount)<0 && !row.transfer_id);
        if(byId('search-chart-panel').hidden){destroyChart();return;}
        window.AppCharts?.whenVisible(byId('results-chart'), () => renderSpendingChart(rows));
    }
    function renderSpendingChart(rows) {
        const grouped=groupSpending(rows); setText('search-bucket-label', grouped.label);
        if (!grouped.values.length) { destroyChart(); byId('results-chart').replaceChildren(emptyState('No spending to plot', 'The matched transactions contain no non-transfer outgoings.', 'fa-chart-column')); return; }
        Highcharts.chart('results-chart', {
            chart:{type:'areaspline',backgroundColor:'transparent',spacing:[10,4,2,0],animation:false}, title:{text:null}, credits:{enabled:false}, accessibility:{enabled:true,description:'Spending pattern across the matching transaction evidence.'},
            xAxis:{categories:grouped.categories,lineColor:'rgba(148,163,184,.24)',tickLength:0,labels:{style:{color:'#64748b',fontSize:'10px'}}},
            yAxis:{min:0,title:{text:null},gridLineColor:'rgba(148,163,184,.16)',labels:{style:{color:'#64748b',fontSize:'10px'},formatter:function(){return '£'+Highcharts.numberFormat(this.value,0);}}},
            legend:{enabled:false}, tooltip:{valuePrefix:'£',valueDecimals:2,borderRadius:10},
            plotOptions:{areaspline:{lineWidth:2.5,marker:{enabled:false},fillOpacity:.12,color:getComputedStyle(document.documentElement).getPropertyValue('--brand-color-600').trim()||'#4f46e5'}},
            series:[{name:'Matched spend',data:grouped.values,color:getComputedStyle(document.documentElement).getPropertyValue('--brand-color-600').trim()||'#4f46e5'}]
        });
    }
    function queryLabel(term, min, max) {
        const parts=[]; if(term) parts.push('“'+term+'”'); if(min) parts.push('from '+money.format(min)); if(max) parts.push('to '+money.format(max)); return parts.join(' · ');
    }
    async function runSearch() {
        const term=byId('term').value.trim(), min=byId('min-amount').value, max=byId('max-amount').value;
        const activeParams=new URLSearchParams(location.search);
        Object.entries(filterFields).forEach(([key,id])=>{const value=byId(id).value;if(value)activeParams.set(key,value);else activeParams.delete(key);});
        if(activeParams.has('spending_only')&&(byId('filter-direction').value!=='spending'||byId('filter-transfers').value!=='exclude'))activeParams.delete('spending_only');
        const gaps=byId('filter-gaps').value;
        if(gaps==='gaps'){activeParams.set('gaps','1');}else if(gaps){activeParams.delete('gaps');activeParams.set('dimension',gaps);activeParams.set('unclassified','1');activeParams.delete('dimension_id');activeParams.delete('dimension_ids');}
        const start=activeParams.get('start')||'', end=activeParams.get('end')||'', dimension=activeParams.get('dimension')||'', dimensionId=activeParams.get('dimension_id')||'', unclassified=activeParams.get('unclassified')==='1', spendingOnly=activeParams.get('spending_only')==='1', linkLabel=activeParams.get('label')||'';
        const error=byId('search-error'); error.hidden=true;

        if(byId('filter-start').value && byId('filter-end').value && byId('filter-start').value>byId('filter-end').value){error.textContent='The start date must be before the end date.';error.hidden=false;return;}
        if (min && max && Number(min)>Number(max)) { error.textContent='The minimum amount cannot be greater than the maximum amount.'; error.hidden=false; byId('min-amount').focus(); return; }
        const params=new URLSearchParams(); structuredKeys.forEach(key=>{if(activeParams.has(key))params.set(key,activeParams.get(key));}); if(term)params.set('value',term); if(min)params.set('min_amount',min); if(max)params.set('max_amount',max); if(start)params.set('start',start); if(end)params.set('end',end); if(dimension)params.set('dimension',dimension); if(dimensionId)params.set('dimension_id',dimensionId); if(unclassified)params.set('unclassified','1'); if(spendingOnly)params.set('spending_only','1'); if(linkLabel)params.set('label',linkLabel);
        if(term||min||max||[...params.keys()].some(key=>!['recent','transfer_scope','ignored_scope'].includes(key)))params.delete('recent');
        if(![...params.keys()].some(key=>!['transfer_scope','ignored_scope'].includes(key)))params.set('recent','1');
        const sequence=++searchSequence;
        history.replaceState(history.state,'',location.pathname+'?'+params.toString());
        renderFilterContext(params);
        const submit=byId('search-submit'); submit.disabled=true; submit.querySelector('span').textContent='Searching…'; setText('search-status','Looking across every transaction field…');
        // Keep Tabulator and shared table classes when reusing the existing table.
        byId('results-grid').classList.add('transaction-loading');
        try {
            const response=await fetch('../php_backend/public/search_transactions.php?'+params.toString());
            const payload=await response.json().catch(()=>({})); if(!response.ok) throw new Error(payload.error||'Search could not be completed.');
            if(sequence!==searchSequence)return;
            const rows=Array.isArray(payload.results)?payload.results:[];latestRows=rows;
            byId('search-review').hidden=!rows.some(row=>!row.transfer_id&&(!row.tag_id||!row.category_id));
            renderEvidencePages(payload.evidence,params);
            setText('search-results-copy',payload.recent?'Recent activity (up to 100). Filters search all history.':'Transactions matching your filters.');
            const comparison=Array.isArray(payload.comparison_results)?payload.comparison_results:null;
            const tableRows=renderComparison(rows,comparison,params);
            byId('results-grid').classList.remove('transaction-loading'); renderSummary(rows,linkLabel||queryLabel(term,min,max)||(payload.recent?'Recent activity':'Matching transactions')); renderTable(tableRows); renderChart(rows); setText('search-results-copy',payload.recent?'Recent activity (up to 100). Filters search all history.':'Transactions matching your filters.'); setText('search-status',tableRows.length+' result'+(tableRows.length===1?'':'s')+' found');
        } catch (failure) {
            if(sequence!==searchSequence)return;
            byId('search-review').hidden=true;byId('search-chart-panel').hidden=true;
            if(resultTable){resultTable.destroy();resultTable=null;} resultTableHasComparison=false;
            updateTableTotal();resetSummary();byId('search-comparison').hidden=true;byId('search-evidence-pages').hidden=true;byId('results-grid').classList.remove('transaction-loading'); error.textContent=failure.message||'Search could not be completed.'; error.hidden=false; setText('search-status','Search failed');
            byId('results-grid').replaceChildren(emptyState('Search unavailable', 'Please try again in a moment.', 'fa-triangle-exclamation'));
        } finally { if(sequence===searchSequence){submit.disabled=false; submit.querySelector('span').textContent='Search';} }
    }
    byId('search-form').addEventListener('submit', function(event){event.preventDefault();runSearch();});
    byId('search-clear').addEventListener('click', function(){
        byId('search-form').reset();const input=document.querySelector('.modern-table-search-input');if(input){input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));}history.replaceState(null,'',location.pathname);runSearch();byId('term').focus();
    });
    byId('filter-gaps').addEventListener('change',()=>{
        const params=new URLSearchParams(location.search);['dimension','dimension_id','dimension_ids','unclassified','include_unclassified','gaps'].forEach(key=>params.delete(key));history.replaceState(history.state,'',location.pathname+'?'+params);
    });
    byId('search-review').addEventListener('click',async()=>{
        const button=byId('search-review');button.disabled=true;
        try {
            const rows=(resultTable?.getData('active')||latestRows).filter(row=>!row.transfer_id&&(!row.tag_id||!row.category_id));
            const response=await fetch('../php_backend/public/transaction_review.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids:rows.slice(0,250).map(row=>row.id)})});
            const result=await response.json();if(!response.ok)throw new Error(result.error||'Review could not start.');
            if(!result.token)throw new Error('These transactions no longer have classification gaps. Refresh the list.');
            location.assign(window.TransactionNavigation.detailUrl(result.first_id,{review:result.token,position:'0'}));
        }catch(error){byId('search-error').textContent=error.message;byId('search-error').hidden=false;button.disabled=false;}
    });
    function renderEvidencePages(evidence,params){
        const nav=byId('search-evidence-pages');nav.replaceChildren();nav.hidden=!evidence;
        if(!evidence)return;
        const label=document.createElement('span');label.textContent=`Import page ${evidence.page} of ${evidence.pages} · ${evidence.total} imported transactions`;nav.append(label);
        [['Previous',evidence.page-1],['Next',evidence.page+1]].forEach(([text,page])=>{if(page<1||page>evidence.pages)return;const link=document.createElement('a');const query=new URLSearchParams(params);query.set('evidence_page',page);link.href='search.html?'+query;link.textContent=text;link.className='transaction-button transaction-button--quiet';nav.append(link);});
    }
    // History state contains only list controls and scroll position, never financial rows.
    window.addEventListener('pagehide',()=>history.replaceState({...history.state,transactionList:{scroll:listScroll,query:document.querySelector('.modern-table-search-input')?.value||'',page:resultTable?.getPage()||1,sort:resultTable?.getSorters().map(sort=>({column:sort.field,dir:sort.dir}))||[]}},'',location.href));


    window.updatePageHeader(transactionSearchMain,{actions:headerActions()});
    resetSummary();
    byId('results-grid').replaceChildren(emptyState('Start with a search','Use a name, note, category, tag, group or amount range to investigate activity.','fa-magnifying-glass'));
    byId('results-chart').replaceChildren(emptyState('Waiting for a result set','Matched outgoings will form a time-based spending pattern here.','fa-chart-line'));
    const initial=new URLSearchParams(location.search); byId('term').value=initial.get('value')||''; byId('min-amount').value=initial.get('min_amount')||''; byId('max-amount').value=initial.get('max_amount')||'';
    Object.entries(filterFields).forEach(([key,id])=>{if(initial.has(key))byId(id).value=initial.get(key);});
    if(initial.get('spending_only')==='1'){byId('filter-direction').value='spending';byId('filter-transfers').value='exclude';}
    if(initial.get('gaps')==='1')byId('filter-gaps').value='gaps';
    if(initial.get('unclassified')==='1')byId('filter-gaps').value=initial.get('dimension')||'';
    if(Object.keys(filterFields).some(key=>initial.has(key))||initial.has('dimension')||initial.has('min_amount')||initial.has('max_amount'))document.querySelector('.search-more-filters').open=true;
    const accountSelect=byId('filter-account');
    function retainAccount(value){
        if(value&&![...accountSelect.options].some(option=>option.value===value)){
            const option=document.createElement('option');option.value=value;option.textContent='Selected account #'+value;accountSelect.append(option);
        }
        accountSelect.value=value;
    }
    retainAccount(initial.get('account_id')||'');
    runSearch(); // Primary ledger must not wait for the secondary account catalogue.
    fetch('../php_backend/public/account_dashboard.php').then(response=>{if(!response.ok)throw new Error();return response.json();}).then(accounts=>{
        if(!Array.isArray(accounts))throw new Error('Account list unavailable');
        const current=accountSelect.value;
        accountSelect.replaceChildren();const all=document.createElement('option');all.value='';all.textContent='All accounts';accountSelect.append(all);
        accounts.forEach(account=>{const option=document.createElement('option');option.value=account.id;option.textContent=account.name;accountSelect.append(option);});retainAccount(current);
    }).catch(()=>{accountSelect.options[0].textContent='All accounts · list unavailable';});
})();

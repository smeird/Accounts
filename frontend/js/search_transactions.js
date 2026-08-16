// Turns transaction search results into an answer-first investigative view.
(function () {
    'use strict';

    const money = new Intl.NumberFormat('en-GB', { style:'currency', currency:'GBP', minimumFractionDigits:2, maximumFractionDigits:2 });
    const compactMoney = new Intl.NumberFormat('en-GB', { style:'currency', currency:'GBP', maximumFractionDigits:0 });
    const shortDate = new Intl.DateTimeFormat('en-GB', { day:'numeric', month:'short', year:'numeric' });
    let resultTable = null;

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
        const reports = document.createElement('a'); reports.href = 'report.html'; reports.className = 'transaction-header-link transaction-header-link--primary';
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
        setText('search-results-copy', rows.length === 1 ? 'One transaction matches this investigation.' : rows.length + ' transactions match this investigation. Sort a column or open a transaction for detail.');
        setText('search-hero-message', rows.length ? (net < 0 ? compactMoney.format(Math.abs(net)) + ' more left than arrived across these matches.' : net > 0 ? compactMoney.format(net) + ' more arrived than left across these matches.' : 'Matched inflows and outgoings balance exactly.') : 'No transactions matched this combination. Try widening the amount range or simplifying the search term.');
    }
    function renderTable(rows) {
        const grid = byId('results-grid');
        if (!rows.length) { if (resultTable) { resultTable.destroy(); resultTable = null; } grid.replaceChildren(emptyState('No matching transactions', 'Try another term or widen the amount range.', 'fa-magnifying-glass')); return; }
        if (resultTable) { resultTable.setData(rows); return; }
        grid.replaceChildren();
        resultTable = tailwindTabulator(grid, {
            data:rows, layout:'fitDataStretch', responsiveLayout:'collapse', placeholder:'No matching transactions',
            columns:[
                { title:'Date', field:'date', width:112, sorter:'date' },
                { title:'Description', field:'description', minWidth:190, formatter:function(cell){ const row=cell.getRow().getData(); const link=document.createElement('a'); link.href='transaction.html?id='+encodeURIComponent(row.id); link.textContent=cell.getValue() || 'Untitled'; return link; } },
                { title:'Memo', field:'memo', minWidth:150, responsive:2 },
                { title:'Category', field:'category_name', formatter:badgeFormatter('bg-green-200 text-green-800'), responsive:1 },
                { title:'Tag', field:'tag_name', formatter:badgeFormatter('bg-indigo-200 text-indigo-800'), responsive:2 },
                { title:'Group', field:'group_name', formatter:badgeFormatter('bg-purple-200 text-purple-800'), responsive:3 },
                { title:'Segment', field:'segment_name', formatter:badgeFormatter('bg-yellow-200 text-yellow-800'), responsive:3 },
                { title:'Amount', field:'amount', formatter:'money', formatterParams:{symbol:'£',precision:2}, hozAlign:'right', sorter:'number', width:120 }
            ]
        });
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
    function renderChart(rows) {
        const grouped=groupSpending(rows); setText('search-bucket-label', grouped.label);
        if (!grouped.values.length) { const chart=Highcharts.charts.find(item=>item&&item.renderTo.id==='results-chart'); if(chart) chart.destroy(); byId('results-chart').replaceChildren(emptyState('No spending to plot', 'The matched transactions contain no non-transfer outgoings.', 'fa-chart-column')); return; }
        Highcharts.chart('results-chart', {
            chart:{type:'areaspline',backgroundColor:'transparent',spacing:[10,4,2,0],animation:false}, title:{text:null}, credits:{enabled:false}, accessibility:{enabled:false},
            xAxis:{categories:grouped.categories,lineColor:'rgba(148,163,184,.24)',tickLength:0,labels:{style:{color:'#64748b',fontSize:'10px'}}},
            yAxis:{min:0,title:{text:null},gridLineColor:'rgba(148,163,184,.16)',labels:{style:{color:'#64748b',fontSize:'10px'},formatter:function(){return '£'+Highcharts.numberFormat(this.value,0);}}},
            legend:{enabled:false}, tooltip:{valuePrefix:'£',valueDecimals:2,borderRadius:10},
            plotOptions:{areaspline:{lineWidth:2.5,marker:{enabled:false},fillOpacity:.12,color:getComputedStyle(document.documentElement).getPropertyValue('--brand-color-600').trim()||'#4f46e5'}},
            series:[{name:'Matched spend',data:grouped.values,color:'#4f46e5'}]
        });
    }
    function queryLabel(term, min, max) {
        const parts=[]; if(term) parts.push('“'+term+'”'); if(min) parts.push('from '+money.format(min)); if(max) parts.push('to '+money.format(max)); return parts.join(' · ');
    }
    async function runSearch() {
        const term=byId('term').value.trim(), min=byId('min-amount').value, max=byId('max-amount').value;
        const activeParams=new URLSearchParams(location.search), start=activeParams.get('start')||'', end=activeParams.get('end')||'', dimension=activeParams.get('dimension')||'', dimensionId=activeParams.get('dimension_id')||'', unclassified=activeParams.get('unclassified')==='1', spendingOnly=activeParams.get('spending_only')==='1', linkLabel=activeParams.get('label')||'';
        const error=byId('search-error'); error.hidden=true;
        if (!term && !min && !max && !dimension) { error.textContent='Enter a search term or at least one amount limit.'; error.hidden=false; byId('term').focus(); return; }
        if (min && max && Number(min)>Number(max)) { error.textContent='The minimum amount cannot be greater than the maximum amount.'; error.hidden=false; byId('min-amount').focus(); return; }
        const params=new URLSearchParams(); if(term)params.set('value',term); if(min)params.set('min_amount',min); if(max)params.set('max_amount',max); if(start)params.set('start',start); if(end)params.set('end',end); if(dimension)params.set('dimension',dimension); if(dimensionId)params.set('dimension_id',dimensionId); if(unclassified)params.set('unclassified','1'); if(spendingOnly)params.set('spending_only','1'); if(linkLabel)params.set('label',linkLabel);
        history.replaceState(null,'',location.pathname+'?'+params.toString());
        const submit=byId('search-submit'); submit.disabled=true; submit.querySelector('span').textContent='Searching…'; setText('search-status','Looking across every transaction field…');
        byId('results-grid').className='transaction-table transaction-loading';
        try {
            const response=await fetch('../php_backend/public/search_transactions.php?'+params.toString());
            const payload=await response.json().catch(()=>({})); if(!response.ok) throw new Error(payload.error||'Search could not be completed.');
            const rows=Array.isArray(payload.results)?payload.results:[];
            byId('results-grid').className='transaction-table'; renderSummary(rows,linkLabel||queryLabel(term,min,max)); renderTable(rows); renderChart(rows); setText('search-status',rows.length+' result'+(rows.length===1?'':'s')+' found');
        } catch (failure) {
            byId('results-grid').className='transaction-table'; error.textContent=failure.message||'Search could not be completed.'; error.hidden=false; setText('search-status','Search failed');
            byId('results-grid').replaceChildren(emptyState('Search unavailable', 'Please try again in a moment.', 'fa-triangle-exclamation'));
        } finally { submit.disabled=false; submit.querySelector('span').textContent='Search transactions'; }
    }
    byId('search-form').addEventListener('submit', function(event){event.preventDefault();runSearch();});
    byId('search-clear').addEventListener('click', function(){ byId('search-form').reset(); history.replaceState(null,'',location.pathname); resetSummary(); setText('search-status',''); setText('search-results-copy','Your result set will appear here, ready to sort and inspect.'); if(resultTable){resultTable.destroy();resultTable=null;} byId('results-grid').replaceChildren(emptyState('Start with a search', 'Use a name, note, category, tag or amount range to investigate activity.', 'fa-magnifying-glass')); const chart=Highcharts.charts.find(item=>item&&item.renderTo.id==='results-chart'); if(chart)chart.destroy(); byId('results-chart').replaceChildren(emptyState('Waiting for a result set', 'Matched outgoings will form a time-based spending pattern here.', 'fa-chart-line')); byId('term').focus(); });

    window.updatePageHeader(transactionSearchMain,{actions:headerActions()});
    resetSummary();
    byId('results-grid').replaceChildren(emptyState('Start with a search','Use a name, note, category, tag or amount range to investigate activity.','fa-magnifying-glass'));
    byId('results-chart').replaceChildren(emptyState('Waiting for a result set','Matched outgoings will form a time-based spending pattern here.','fa-chart-line'));
    const initial=new URLSearchParams(location.search); byId('term').value=initial.get('value')||''; byId('min-amount').value=initial.get('min_amount')||''; byId('max-amount').value=initial.get('max_amount')||''; if(initial.toString())runSearch();
})();

(function () {
    const byId = id => document.getElementById(id);
    const currency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 });
    const preciseCurrency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const shortDate = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const params = new URLSearchParams(window.location.search);
    const validPeriods = ['month', 'ytd', 'last12', 'year', 'all', 'custom'];
    const validDimensions = ['category', 'segment', 'group', 'tag'];
    const validComparisons = ['previous_year', 'previous', 'none'];
    const dimensionLabels = { category: 'Category', segment: 'Segment', group: 'Group', tag: 'Tag' };
    const coverageLinks = { category: 'categories.html', segment: 'segments.html', group: 'groups.html', tag: 'tagging.html#catalogue' };
    let availableMonths = [];
    let currentData = null;
    let showAllRows = false;
    let requestController = null;

    function localDate(year, month, day) { return new Date(year, month, day, 12, 0, 0, 0); }
    function today() { const now = new Date(); return localDate(now.getFullYear(), now.getMonth(), now.getDate()); }
    function iso(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`; }
    function parseIso(value) { const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || ''); return match ? localDate(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null; }
    function addDays(date, days) { const next = new Date(date); next.setDate(next.getDate() + days); return next; }
    function lastDayOfMonth(year, month) { return localDate(year, month + 1, 0); }
    function shiftYear(date, offset) {
        const targetYear = date.getFullYear() + offset;
        const lastDay = lastDayOfMonth(targetYear, date.getMonth()).getDate();
        return localDate(targetYear, date.getMonth(), Math.min(date.getDate(), lastDay));
    }
    function inclusiveDays(start, end) { return Math.round((Date.UTC(end.getFullYear(), end.getMonth(), end.getDate()) - Date.UTC(start.getFullYear(), start.getMonth(), start.getDate())) / 86400000) + 1; }
    function setText(id, value) { byId(id).textContent = value; }
    function money(value) { return currency.format(Number(value) || 0); }
    function preciseMoney(value) { return preciseCurrency.format(Number(value) || 0); }
    function titleCase(value) { return value.charAt(0).toUpperCase() + value.slice(1); }

    function state() {
        return {
            period: validPeriods.includes(byId('period-select').value) ? byId('period-select').value : 'ytd',
            dimension: validDimensions.includes(byId('dimension-select').value) ? byId('dimension-select').value : 'category',
            comparison: validComparisons.includes(byId('comparison-select').value) ? byId('comparison-select').value : 'previous_year',
            year: Number(byId('year-select').value) || today().getFullYear(),
            month: byId('month-select').value,
            customStart: byId('start-date').value,
            customEnd: byId('end-date').value
        };
    }

    function availableBounds() {
        if (!availableMonths.length) {
            const now = today();
            return { start: localDate(now.getFullYear(), 0, 1), end: now };
        }
        const ascending = [...availableMonths].sort((left, right) => left.key.localeCompare(right.key));
        const first = ascending[0];
        const last = ascending[ascending.length - 1];
        const now = today();
        const latestEnd = last.year === now.getFullYear() && last.month === now.getMonth() + 1
            ? now
            : lastDayOfMonth(last.year, last.month - 1);
        return { start: localDate(first.year, first.month - 1, 1), end: latestEnd };
    }

    function resolvePeriod(viewState) {
        const now = today();
        const bounds = availableBounds();
        let start;
        let end;
        let label;
        let partial = false;

        if (viewState.period === 'month') {
            const selected = /^(\d{4})-(\d{2})$/.exec(viewState.month || '');
            const year = selected ? Number(selected[1]) : now.getFullYear();
            const month = selected ? Number(selected[2]) - 1 : now.getMonth();
            start = localDate(year, month, 1);
            const naturalEnd = lastDayOfMonth(year, month);
            end = year === now.getFullYear() && month === now.getMonth() ? now : naturalEnd;
            partial = end < naturalEnd;
            label = start.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
        } else if (viewState.period === 'year' || viewState.period === 'ytd') {
            const year = viewState.period === 'ytd' ? now.getFullYear() : viewState.year;
            start = localDate(year, 0, 1);
            const naturalEnd = localDate(year, 11, 31);
            end = year === now.getFullYear() ? now : naturalEnd;
            partial = end < naturalEnd;
            label = viewState.period === 'ytd' ? `${year} year to date` : String(year);
        } else if (viewState.period === 'last12') {
            start = localDate(now.getFullYear() - 1, now.getMonth(), now.getDate());
            start = addDays(start, 1);
            end = now;
            label = 'Last 12 months';
        } else if (viewState.period === 'all') {
            start = bounds.start;
            end = bounds.end;
            label = 'All recorded activity';
        } else {
            start = parseIso(viewState.customStart) || bounds.start;
            end = parseIso(viewState.customEnd) || bounds.end;
            label = `${shortDate.format(start)} to ${shortDate.format(end)}`;
        }

        if (start > end) {
            throw new Error('The start date must be before the end date.');
        }

        let comparisonStart = null;
        let comparisonEnd = null;
        if (viewState.period !== 'all' && viewState.comparison !== 'none') {
            if (viewState.comparison === 'previous_year') {
                comparisonStart = shiftYear(start, -1);
                comparisonEnd = shiftYear(end, -1);
            } else {
                const days = inclusiveDays(start, end);
                comparisonEnd = addDays(start, -1);
                comparisonStart = addDays(comparisonEnd, -(days - 1));
            }
        }

        return { start, end, comparisonStart, comparisonEnd, label, partial };
    }

    function comparisonLabel(range) {
        if (!range.comparisonStart || !range.comparisonEnd) return 'No comparison selected';
        return `${shortDate.format(range.comparisonStart)}–${shortDate.format(range.comparisonEnd)}`;
    }

    function syncContextControls() {
        const period = byId('period-select').value;
        byId('month-control').hidden = period !== 'month';
        byId('year-control').hidden = period !== 'year';
        byId('start-control').hidden = period !== 'custom';
        byId('end-control').hidden = period !== 'custom';
        const comparison = byId('comparison-select');
        comparison.disabled = period === 'all';
        if (period === 'all') comparison.value = 'none';
    }

    function writeUrl(viewState) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('period', viewState.period);
        url.searchParams.set('dimension', viewState.dimension);
        url.searchParams.set('compare', viewState.comparison);
        if (viewState.period === 'year') url.searchParams.set('year', viewState.year);
        if (viewState.period === 'month' && viewState.month) url.searchParams.set('month', viewState.month);
        if (viewState.period === 'custom') {
            if (viewState.customStart) url.searchParams.set('start', viewState.customStart);
            if (viewState.customEnd) url.searchParams.set('end', viewState.customEnd);
        }
        window.history.replaceState({}, '', url);
    }

    function changeCopy(change, metric, hasComparison) {
        if (!hasComparison) return 'No comparison selected';
        const amount = Number(change?.amount) || 0;
        if (metric === 'savings_rate') return `${amount >= 0 ? '+' : ''}${amount.toFixed(1)} points versus comparison`;
        const direction = amount > 0 ? 'more' : amount < 0 ? 'less' : 'the same as';
        if (amount === 0) return 'No change versus comparison';
        return `${money(Math.abs(amount))} ${direction} than comparison`;
    }

    function renderSummary(data, range) {
        const metrics = data.metrics;
        const changes = data.comparison?.changes || {};
        setText('trends-cashflow', money(metrics.cashflow));
        setText('trends-income', money(metrics.income));
        setText('trends-spending', money(metrics.spending));
        setText('trends-rate', `${Number(metrics.savings_rate).toFixed(1)}%`);
        setText('trends-cashflow-story', metrics.cashflow >= 0
            ? `Income finished ${money(metrics.cashflow)} ahead of spending across ${range.label.toLowerCase()}.`
            : `Spending finished ${money(Math.abs(metrics.cashflow))} ahead of income across ${range.label.toLowerCase()}.`);
        setText('trends-cashflow-change', changeCopy(changes.cashflow, 'cashflow', !!data.comparison));
        setText('trends-income-change', changeCopy(changes.income, 'income', !!data.comparison));
        setText('trends-spending-change', changeCopy(changes.spending, 'spending', !!data.comparison));
        setText('trends-rate-change', changeCopy(changes.savings_rate, 'savings_rate', !!data.comparison));
        const base=TransactionDrilldown.financial({start:data.period.start,end:data.period.end});
        TransactionDrilldown.linkify('trends-cashflow',{...base,label:`Net cash flow · ${range.label}`},money(metrics.cashflow));
        TransactionDrilldown.linkify('trends-income',{...base,direction:'income',label:`Income · ${range.label}`},money(metrics.income));
        TransactionDrilldown.linkify('trends-spending',{...base,direction:'spending',label:`Spending · ${range.label}`},money(metrics.spending));
        TransactionDrilldown.linkify('trends-rate',{...base,label:`Savings-rate contributors · ${range.label}`},`${Number(metrics.savings_rate).toFixed(1)}%`);
        if(data.comparison){const compare={compare_start:data.comparison.start,compare_end:data.comparison.end};[['trends-cashflow-change','Net cash-flow change','all'],['trends-income-change','Income change','income'],['trends-spending-change','Spending change','spending'],['trends-rate-change','Savings-rate change','all']].forEach(([id,label,direction])=>TransactionDrilldown.linkify(id,{...base,...compare,direction,label},byId(id).textContent));}
    }

    function commonChartOptions() {
        return {
            chart: { backgroundColor: 'transparent', spacing: [10, 8, 8, 8], animation: false },
            title: { text: null }, credits: { enabled: false }, accessibility: { enabled: true, description: 'Interactive financial evidence chart. Select a point to view its contributing transactions.' },
            xAxis: { lineColor: '#e2e8f0', tickColor: '#e2e8f0', labels: { style: { color: '#64748b', fontSize: '10px' } } },
            yAxis: { title: { text: null }, gridLineColor: 'rgba(148,163,184,.16)', labels: { formatter: function () { return '£' + Highcharts.numberFormat(this.value, 0); }, style: { color: '#64748b', fontSize: '10px' } } },
            tooltip: { shared: true, valuePrefix: '£', valueDecimals: 2, borderRadius: 10 },
            legend: { align: 'right', verticalAlign: 'top', itemStyle: { color: '#475569', fontSize: '10px', fontWeight: '700' } }
        };
    }

    function emptyChart(id, title, copy) {
        const previous = Highcharts.charts.find(chart => chart && chart.renderTo.id === id);
        if (previous) previous.destroy();
        const host = byId(id);
        host.replaceChildren();
        const empty = document.createElement('div'); empty.className = 'trends-empty';
        const body = document.createElement('div');
        const icon = document.createElement('i'); icon.className = 'fas fa-chart-column'; icon.setAttribute('aria-hidden', 'true');
        const heading = document.createElement('strong'); heading.textContent = title;
        const paragraph = document.createElement('p'); paragraph.textContent = copy;
        body.append(icon, heading, paragraph); empty.appendChild(body); host.appendChild(empty);
    }

    function renderCashflowChart(data) {
        if (!data.series.some(row => row.income || row.spending)) {
            emptyChart('trends-cashflow-chart', 'No activity in this period', 'Choose another period to see your financial movement.'); return;
        }
        const options = commonChartOptions();
        Highcharts.chart('trends-cashflow-chart', {
            ...options,
            chart: { ...options.chart, zoomType: 'x' },
            xAxis: { ...options.xAxis, categories: data.series.map(row => row.label), tickLength: 0 },
            plotOptions: { series:{cursor:'pointer',point:{events:{click:function(){window.location.href=this.options.drilldown;}}}},column: { borderWidth: 0, borderRadius: 4, groupPadding: .13, pointPadding: .06 }, line: { lineWidth: 2.5, marker: { enabled: false } } },
            series: [
                { type: 'column', name: 'Income', color: '#14b8a6', data: data.series.map(row => ({y:Number(row.income),drilldown:bucketLink(row,data,'income')})) },
                { type: 'column', name: 'Spending', color: '#7c3aed', data: data.series.map(row => ({y:Number(row.spending),drilldown:bucketLink(row,data,'spending')})) },
                { type: 'line', name: 'Net cash flow', color: '#334155', dashStyle: 'ShortDash', data: data.series.map(row => ({y:Number(row.cashflow),drilldown:bucketLink(row,data,'all')})) }
            ]
        });
        setText('trends-grain', `${titleCase(data.period.grain)} view`);
    }

    function topBreakdown(data, count) { return data.breakdown.filter(row => Number(row.amount) > 0).slice(0, count); }
    function bucketRange(row,data){if(data.period.grain==='day')return{start:row.key,end:row.key};if(data.period.grain==='month'){const [year,month]=row.key.split('-').map(Number);return TransactionDrilldown.monthRange(year,month);}return TransactionDrilldown.yearRange(Number(row.key));}
    function bucketLink(row,data,direction){return TransactionDrilldown.url(TransactionDrilldown.financial({...bucketRange(row,data),direction,label:`${row.label} ${direction==='all'?'net movement':direction}`}));}
    function rowOptions(row,data,comparison){const options=TransactionDrilldown.financial({start:comparison?data.comparison.start:data.period.start,end:comparison?data.comparison.end:data.period.end,direction:'spending',dimension:data.dimension,dimension_id:row.id,unclassified:row.unclassified,label:`${row.name} spending${comparison?' · comparison':''}`});if(comparison===null&&data.comparison){options.compare_start=data.comparison.start;options.compare_end=data.comparison.end;}return options;}

    function renderDriversChart(data) {
        const rows = topBreakdown(data, 8);
        if (!rows.length) { emptyChart('trends-drivers-chart', 'No spending drivers', 'There is no expense activity to rank for this period.'); return; }
        const options = commonChartOptions();
        const series = [{ name: 'Selected period', color: '#7c3aed', data: rows.map(row => ({y:Number(row.amount),drilldown:TransactionDrilldown.url(rowOptions(row,data,false))})) }];
        if (data.comparison) series.push({ name: 'Comparison', color: '#c4b5fd', data: rows.map(row => ({y:Number(row.comparison_amount),drilldown:TransactionDrilldown.url(rowOptions(row,data,true))})) });
        Highcharts.chart('trends-drivers-chart', {
            ...options,
            chart: { ...options.chart, type: 'bar', height: Math.max(360, rows.length * 46 + 90) },
            xAxis: { ...options.xAxis, categories: rows.map(row => row.name), tickLength: 0 },
            legend: { ...options.legend, enabled: !!data.comparison },
            tooltip: { ...options.tooltip, shared: true },
            plotOptions: { series:{cursor:'pointer',point:{events:{click:function(){window.location.href=this.options.drilldown;}}}},bar: { borderWidth: 0, borderRadius: 5, groupPadding: .12, dataLabels: { enabled: false } } },
            series
        });
    }

    function renderChangeChart(data) {
        if (!data.comparison) { emptyChart('trends-change-chart', 'Choose a comparison', 'Select a comparison period to reveal the biggest movements.'); return; }
        const rows = data.breakdown.filter(row => Math.abs(Number(row.change)) >= .005).sort((left, right) => Math.abs(Number(right.change)) - Math.abs(Number(left.change))).slice(0, 8);
        if (!rows.length) { emptyChart('trends-change-chart', 'No material movement', 'Spending is unchanged across the compared periods.'); return; }
        const options = commonChartOptions();
        Highcharts.chart('trends-change-chart', {
            ...options,
            chart: { ...options.chart, type: 'bar', height: Math.max(360, rows.length * 46 + 90) },
            xAxis: { ...options.xAxis, categories: rows.map(row => row.name), tickLength: 0 },
            legend: { enabled: false },
            tooltip: { pointFormatter: function () { return `<b>${this.y >= 0 ? '+' : '−'}£${Highcharts.numberFormat(Math.abs(this.y), 2)}</b> versus comparison`; } },
            plotOptions: { series:{cursor:'pointer',point:{events:{click:function(){window.location.href=this.options.drilldown;}}}},bar: { borderWidth: 0, borderRadius: 5, dataLabels: { enabled: true, formatter: function () { return `${this.y >= 0 ? '+' : '−'}£${Highcharts.numberFormat(Math.abs(this.y), 0)}`; }, style: { color: '#475569', fontSize: '10px', textOutline: 'none' } } } },
            series: [{ name: 'Spending change', data: rows.map(row => ({ y: Number(row.change), color: Number(row.change) >= 0 ? '#7c3aed' : '#14b8a6',drilldown:TransactionDrilldown.url(rowOptions(row,data,null)) })) }]
        });
    }

    function renderCoverage(data) {
        const dimension = data.dimension;
        const coverage = data.coverage[dimension] || { percentage: 100, amount: data.metrics.spending };
        const percentage = Number(coverage.percentage) || 0;
        const label = dimensionLabels[dimension].toLowerCase();
        setText('trends-coverage-title', `${percentage.toFixed(1)}% of spending has a ${label}`);
        setText('trends-coverage-copy', percentage >= 99.95
            ? `Your ${label} breakdown covers the full selected period.`
            : `${money(Number(data.metrics.spending) - Number(coverage.amount))} of spending is not yet explained by a ${label}.`);
        byId('trends-coverage-bar').style.width = `${Math.max(0, Math.min(100, percentage))}%`;
        byId('trends-coverage-link').href = coverageLinks[dimension];
        const classifiedIds=data.breakdown.filter(row=>!row.unclassified&&row.id).map(row=>row.id);
        if(classifiedIds.length)TransactionDrilldown.linkify('trends-coverage-title',TransactionDrilldown.financial({start:data.period.start,end:data.period.end,direction:'spending',dimension,dimension_ids:classifiedIds,label:`Classified ${label} spending`}),`${percentage.toFixed(1)}% of spending has a ${label}`);
    }

    function buildSearchLink(row, data) {
        return TransactionDrilldown.url(rowOptions(row,data,false));
    }

    function renderTableRows(data) {
        const body = byId('trends-table-body'); body.replaceChildren();
        const rows = data.breakdown.filter(row => Number(row.amount) > 0 || Number(row.comparison_amount) > 0);
        const visibleRows = showAllRows ? rows : rows.slice(0, 12);
        visibleRows.forEach(row => {
            const tr = document.createElement('tr');
            const nameCell = document.createElement('td');
            const nameWrap = document.createElement('span'); nameWrap.className = 'trends-table-name';
            const dot = document.createElement('span'); dot.className = 'trends-table-dot'; dot.setAttribute('aria-hidden', 'true');
            const name = document.createElement('span'); name.textContent = row.name; nameWrap.append(dot, name); nameCell.appendChild(nameWrap);
            const amount = document.createElement('td'); amount.dataset.label = 'Spending';const amountLink=document.createElement('a');amountLink.className='transaction-drilldown-link';amountLink.href=buildSearchLink(row,data);amountLink.textContent=preciseMoney(row.amount);amount.appendChild(amountLink);
            const share = document.createElement('td'); share.dataset.label = 'Share'; share.textContent = `${Number(row.share).toFixed(1)}%`;
            const change = document.createElement('td'); change.dataset.label = 'Change'; change.className = `trends-table-change ${Number(row.change) > 0 ? 'is-more' : Number(row.change) < 0 ? 'is-less' : ''}`;if(data.comparison){const link=document.createElement('a');link.className='transaction-drilldown-link';link.href=TransactionDrilldown.url(rowOptions(row,data,null));link.textContent=`${Number(row.change)>0?'+':Number(row.change)<0?'−':''}${preciseMoney(Math.abs(Number(row.change)))}`;change.appendChild(link);}else change.textContent='—';
            const count = document.createElement('td'); count.dataset.label = 'Transactions';const countLink=document.createElement('a');countLink.className='transaction-drilldown-link';countLink.href=buildSearchLink(row,data);countLink.textContent=Number(row.transactions).toLocaleString('en-GB');count.appendChild(countLink);
            const openCell = document.createElement('td'); const open = document.createElement('a'); open.className = 'trends-table-open'; open.href = buildSearchLink(row, data); open.setAttribute('aria-label', `Open transactions for ${row.name}`); const icon = document.createElement('i'); icon.className = 'fas fa-arrow-right'; icon.setAttribute('aria-hidden', 'true'); open.appendChild(icon); openCell.appendChild(open);
            tr.append(nameCell, amount, share, change, count, openCell); body.appendChild(tr);
        });
        setText('trends-row-count', `${rows.length} ${rows.length === 1 ? 'driver' : 'drivers'}`);
        const more = byId('trends-show-more'); more.hidden = rows.length <= 12; more.textContent = showAllRows ? 'Show top 12' : `Show all ${rows.length} rows`;
    }

    function render(data, range) {
        currentData = data;
        showAllRows = false;
        renderSummary(data, range); renderCashflowChart(data); renderDriversChart(data); renderChangeChart(data); renderCoverage(data); renderTableRows(data);
        const dimensionLabel = dimensionLabels[data.dimension];
        setText('trends-drivers-title', `Biggest ${dimensionLabel.toLowerCase()} drivers`);
        setText('trends-table-title', `Spending by ${dimensionLabel.toLowerCase()}`);
        setText('trends-dimension-heading', dimensionLabel);
        setText('trends-series-copy', `${range.label} · ${data.metrics.transaction_count.toLocaleString('en-GB')} non-ignored, non-transfer transactions.`);
    }

    async function load() {
        const error = byId('trends-error'); error.hidden = true;
        syncContextControls();
        const viewState = state();
        let range;
        try { range = resolvePeriod(viewState); } catch (failure) { error.textContent = failure.message; error.hidden = false; return; }
        writeUrl(viewState);
        setText('trends-range-summary', `${range.label} · ${shortDate.format(range.start)}–${shortDate.format(range.end)} · ${comparisonLabel(range)}`);
        const partial = byId('trends-partial'); partial.hidden = !range.partial; partial.textContent = range.partial ? `This period is still in progress. Results run through ${shortDate.format(range.end)}, and the comparison uses the same elapsed dates.` : '';

        if (requestController) requestController.abort();
        requestController = new AbortController();
        const query = new URLSearchParams({ start: iso(range.start), end: iso(range.end), dimension: viewState.dimension });
        if (range.comparisonStart && range.comparisonEnd) { query.set('comparison_start', iso(range.comparisonStart)); query.set('comparison_end', iso(range.comparisonEnd)); }
        byId('trends-loading').classList.add('is-visible');
        try {
            const response = await fetch(`../php_backend/public/financial_trends.php?${query.toString()}`, { signal: requestController.signal });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.error || 'Financial Trends could not be loaded.');
            render(payload, range);
        } catch (failure) {
            if (failure.name === 'AbortError') return;
            error.textContent = failure.message || 'Financial Trends could not be loaded.'; error.hidden = false;
        } finally { byId('trends-loading').classList.remove('is-visible'); }
    }

    function populateControls() {
        const now = today();
        const years = [...new Set(availableMonths.map(item => item.year))].sort((a, b) => b - a);
        if (!years.includes(now.getFullYear())) years.unshift(now.getFullYear());
        const yearSelect = byId('year-select'); yearSelect.replaceChildren();
        years.forEach(year => { const option = document.createElement('option'); option.value = String(year); option.textContent = String(year); yearSelect.appendChild(option); });
        const monthSelect = byId('month-select'); monthSelect.replaceChildren();
        availableMonths.slice().sort((left, right) => right.key.localeCompare(left.key)).forEach(item => { const option = document.createElement('option'); option.value = item.key; option.textContent = localDate(item.year, item.month - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }); monthSelect.appendChild(option); });
        if (!monthSelect.options.length) { const option = document.createElement('option'); option.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`; option.textContent = now.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }); monthSelect.appendChild(option); }

        byId('period-select').value = validPeriods.includes(params.get('period')) ? params.get('period') : 'ytd';
        byId('dimension-select').value = validDimensions.includes(params.get('dimension')) ? params.get('dimension') : 'category';
        byId('comparison-select').value = validComparisons.includes(params.get('compare')) ? params.get('compare') : 'previous_year';
        if (years.includes(Number(params.get('year')))) yearSelect.value = params.get('year');
        if ([...monthSelect.options].some(option => option.value === params.get('month'))) monthSelect.value = params.get('month');
        const bounds = availableBounds(); byId('start-date').value = params.get('start') || iso(bounds.start); byId('end-date').value = params.get('end') || iso(bounds.end);
        syncContextControls();
    }

    async function initialise() {
        try {
            const response = await fetch('../php_backend/public/transaction_months.php');
            if (!response.ok) throw new Error('Unable to load available periods');
            const rows = await response.json();
            availableMonths = (Array.isArray(rows) ? rows : []).map(item => ({ year: Number(item.year), month: Number(item.month), key: `${item.year}-${String(item.month).padStart(2, '0')}` })).filter(item => item.year && item.month >= 1 && item.month <= 12);
            populateControls(); await load();
        } catch (failure) { const error = byId('trends-error'); error.textContent = 'Available transaction periods could not be loaded. Please try again.'; error.hidden = false; byId('trends-loading').classList.remove('is-visible'); }
    }

    ['period-select', 'month-select', 'year-select', 'comparison-select', 'dimension-select', 'start-date', 'end-date'].forEach(id => byId(id).addEventListener('change', load));
    byId('trends-refresh').addEventListener('click', load);
    byId('trends-show-more').addEventListener('click', function () { showAllRows = !showAllRows; if (currentData) renderTableRows(currentData); });
    initialise();
})();

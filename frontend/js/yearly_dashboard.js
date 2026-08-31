(function () {
    const currency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 });
    const yearSelect = document.getElementById('year-select');
    const errorEl = document.getElementById('yearly-error');

    function money(value) { return currency.format(Number(value) || 0); }
    function text(id, value) { document.getElementById(id).textContent = value; }
    function clear(element) { while (element.firstChild) element.removeChild(element.firstChild); }
    function changeCopy(value, kind) {
        if (value === null || value === undefined) return 'No prior-year baseline';
        const direction = value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
        return `${Math.abs(value).toFixed(1)}% ${direction} vs prior year`;
    }
    function setChange(id, value, higherIsGood) {
        const el = document.getElementById(id);
        el.textContent = changeCopy(value);
        el.classList.remove('is-good', 'is-bad');
        if (value !== null && value !== 0) el.classList.add((value > 0) === higherIsGood ? 'is-good' : 'is-bad');
    }

    async function populateYears() {
        const response = await fetch('../php_backend/public/transaction_months.php');
        if (!response.ok) throw new Error('Unable to load available years');
        const months = await response.json();
        const years = [...new Set(months.map(item => Number(item.year)).filter(Boolean))].sort((a, b) => b - a);
        const currentYear = new Date().getFullYear();
        if (!years.includes(currentYear)) years.unshift(currentYear);
        clear(yearSelect);
        years.forEach(year => {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            yearSelect.appendChild(option);
        });
        const requested = Number(new URLSearchParams(window.location.search).get('year'));
        yearSelect.value = years.includes(requested) ? String(requested) : String(years[0]);
    }

    function renderChart(months, year) {
        Highcharts.chart('yearly-trend-chart', {
            chart: { type: 'areaspline', backgroundColor: 'transparent', spacing: [8, 4, 4, 4] },
            title: { text: null }, credits: { enabled: false },
            xAxis: { categories: months.map(month => month.label), lineColor: '#e2e8f0', tickLength: 0, labels: { style: { color: '#64748b', fontSize: '10px' } } },
            yAxis: { title: { text: null }, gridLineColor: 'rgba(148,163,184,.16)', labels: { formatter: function () { return '£' + Highcharts.numberFormat(this.value / 1000, 0) + 'k'; }, style: { color: '#64748b', fontSize: '10px' } } },
            legend: { align: 'right', verticalAlign: 'top', symbolRadius: 6, itemStyle: { color: '#475569', fontSize: '10px', fontWeight: '700' } },
            tooltip: { shared: true, valuePrefix: '£', valueDecimals: 0 },
            plotOptions: { series: { cursor:'pointer',marker: { enabled: false }, lineWidth: 2.5,point:{events:{click:function(){window.location.href=this.options.drilldown;}}} }, areaspline: { fillOpacity: .09 } },
            colors: ['#14b8a6', '#8b5cf6'],
            series: [{ name: 'Income', data: months.map(month => ({y:month.income,drilldown:TransactionDrilldown.url(TransactionDrilldown.financial({...TransactionDrilldown.monthRange(year,month.month),direction:'income',label:`${month.label} ${year} income`}))})) }, { name: 'Spending', data: months.map(month => ({y:month.spending,drilldown:TransactionDrilldown.url(TransactionDrilldown.financial({...TransactionDrilldown.monthRange(year,month.month),direction:'spending',label:`${month.label} ${year} spending`}))})) }],
            accessibility: { enabled: true, description: 'Monthly income, spending and net cash-flow chart. Select a point to view its contributing transactions.' }
        });
    }

    function renderMonths(months, year) {
        const host = document.getElementById('yearly-month-strip'); clear(host);
        months.forEach(month => {
            const item = document.createElement(month.income || month.spending ? 'a' : 'div');
            item.className = 'yearly-month' + (month.cashflow < 0 ? ' is-negative' : '') + (!month.income && !month.spending ? ' is-empty' : '');
            if(item.tagName==='A'){item.href=TransactionDrilldown.url(TransactionDrilldown.financial({...TransactionDrilldown.monthRange(year,month.month),label:`${month.label} ${year} net movement`}));item.setAttribute('aria-label',`View transactions for ${month.label} ${year}`);}
            const label = document.createElement('span'); label.textContent = month.label;
            const value = document.createElement('strong'); value.textContent = money(month.cashflow);
            item.append(label, value); host.appendChild(item);
        });
    }

    function renderQuarters(quarters, year) {
        const host = document.getElementById('yearly-quarters'); clear(host);
        const maxActivity = Math.max(...quarters.map(q => Math.max(q.income, q.spending)), 1);
        quarters.forEach(quarter => {
            const startMonth=(quarter.quarter-1)*3+1,start=TransactionDrilldown.monthRange(year,startMonth).start,end=TransactionDrilldown.monthRange(year,startMonth+2).end;
            const item = document.createElement('article'); item.className = 'yearly-quarter';
            const top = document.createElement('div'); top.className = 'yearly-quarter__top';
            const label = document.createElement('span'); label.textContent = quarter.label;
            const net = document.createElement('strong');const netLink=document.createElement('a');netLink.className='transaction-drilldown-link';netLink.href=TransactionDrilldown.url(TransactionDrilldown.financial({start,end,label:`${quarter.label} ${year} net movement`}));netLink.textContent=money(quarter.cashflow);net.appendChild(netLink); if (quarter.cashflow < 0) net.className = 'is-negative';
            top.append(label, net);
            const bar = document.createElement('div'); bar.className = 'yearly-quarter__bar';
            const fill = document.createElement('span'); fill.style.width = `${Math.max(4, Math.max(quarter.income, quarter.spending) / maxActivity * 100)}%`; bar.appendChild(fill);
            const details = document.createElement('div'); details.className = 'yearly-quarter__details';
            const income = document.createElement('a');income.className='transaction-drilldown-link';income.href=TransactionDrilldown.url(TransactionDrilldown.financial({start,end,direction:'income',label:`${quarter.label} ${year} income`})); income.textContent = `${money(quarter.income)} in`;
            const spending = document.createElement('a');spending.className='transaction-drilldown-link';spending.href=TransactionDrilldown.url(TransactionDrilldown.financial({start,end,direction:'spending',label:`${quarter.label} ${year} spending`})); spending.textContent = `${money(quarter.spending)} out`;
            details.append(income, spending); item.append(top, bar, details); host.appendChild(item);
        });
    }

    function renderCategories(categories, year) {
        const host = document.getElementById('yearly-categories'); clear(host);
        if (!categories.length) { const empty = document.createElement('div'); empty.className = 'yearly-empty'; empty.textContent = 'No spending categories recorded for this year.'; host.appendChild(empty); return; }
        categories.forEach((category, index) => {
            const item = document.createElement('a'); item.className = 'yearly-category'; item.href = TransactionDrilldown.url(TransactionDrilldown.financial({...TransactionDrilldown.yearRange(year),direction:'spending',dimension:'category',dimension_id:category.id,unclassified:category.id===null,label:`${category.name} spending · ${year}`}));
            const rank = document.createElement('span'); rank.className = 'yearly-category__rank'; rank.textContent = String(index + 1).padStart(2, '0');
            const body = document.createElement('div'); body.className = 'yearly-category__body';
            const line = document.createElement('div'); line.className = 'yearly-category__line';
            const name = document.createElement('strong'); name.textContent = category.name;
            const amount = document.createElement('span'); amount.textContent = money(category.amount); line.append(name, amount);
            const bar = document.createElement('span'); bar.className = 'yearly-category__bar'; const fill = document.createElement('span'); fill.style.width = `${category.relative}%`; bar.appendChild(fill); body.append(line, bar);
            const share = document.createElement('small'); share.textContent = `${category.share.toFixed(1)}%`; item.append(rank, body, share); host.appendChild(item);
        });
    }

    function renderInsights(insights) {
        const host = document.getElementById('yearly-insights'); clear(host);
        if (!insights.length) { const empty = document.createElement('div'); empty.className = 'yearly-empty'; empty.textContent = 'Add more transactions to unlock annual signals.'; host.appendChild(empty); return; }
        insights.forEach(insight => {
            const card = document.createElement('article'); card.className = `yearly-insight yearly-insight--${insight.tone}`;
            const icon = document.createElement('span'); icon.className = 'yearly-insight__icon'; const i = document.createElement('i'); i.className = `fas ${insight.icon}`; i.setAttribute('aria-hidden', 'true'); icon.appendChild(i);
            const title = document.createElement('strong'); title.textContent = insight.title;
            const detail = document.createElement('p'); detail.textContent = insight.detail;
            card.append(icon, title, detail); host.appendChild(card);
        });
    }

    function render(data) {
        const metrics = data.metrics;
        const positiveMonths = data.months.filter(month => month.cashflow >= 0 && (month.income || month.spending)).length;
        text('yearly-hero-title', money(metrics.cashflow));
        document.getElementById('yearly-hero-title').classList.toggle('is-negative', metrics.cashflow < 0);
        text('yearly-message', metrics.cashflow >= 0 ? `You kept ${money(metrics.cashflow)} across ${metrics.active_months} active months in ${data.year}.` : `Spending ran ${money(Math.abs(metrics.cashflow))} ahead of income in ${data.year}.`);
        text('yearly-coverage', `${metrics.active_months} active month${metrics.active_months === 1 ? '' : 's'} · ${data.year}`);
        text('yearly-rate', `${metrics.savings_rate.toFixed(1)}%`);
        const ring = document.getElementById('yearly-rate-ring'); ring.style.setProperty('--yearly-rate', `${Math.min(Math.abs(metrics.savings_rate), 100) * 3.6}deg`); ring.classList.toggle('is-negative', metrics.savings_rate < 0);
        text('yearly-income', money(metrics.income)); text('yearly-spending', money(metrics.spending)); text('yearly-positive-months', String(positiveMonths)); text('yearly-months-note', `of ${metrics.active_months || 12} active months`);
        document.getElementById('yearly-trends-link').href = `financial_trends.html?period=year&year=${encodeURIComponent(data.year)}&dimension=category&compare=previous_year`;
        setChange('yearly-income-change', data.comparison.income, true); setChange('yearly-spending-change', data.comparison.spending, false);
        const range=TransactionDrilldown.yearRange(data.year),base=TransactionDrilldown.financial(range);
        TransactionDrilldown.linkify('yearly-hero-title',{...base,label:`Net cash flow · ${data.year}`},money(metrics.cashflow));
        TransactionDrilldown.linkify('yearly-rate',{...base,label:`Savings-rate contributors · ${data.year}`},`${metrics.savings_rate.toFixed(1)}%`);
        TransactionDrilldown.linkify('yearly-income',{...base,direction:'income',label:`Income · ${data.year}`},money(metrics.income));
        TransactionDrilldown.linkify('yearly-spending',{...base,direction:'spending',label:`Spending · ${data.year}`},money(metrics.spending));
        const compareEnd=TransactionDrilldown.monthRange(data.year-1,data.comparison.through_month).end;
        TransactionDrilldown.linkify('yearly-income-change',{...base,direction:'income',compare_start:`${data.year-1}-01-01`,compare_end:compareEnd,label:'Income change versus prior year'},document.getElementById('yearly-income-change').textContent);
        TransactionDrilldown.linkify('yearly-spending-change',{...base,direction:'spending',compare_start:`${data.year-1}-01-01`,compare_end:compareEnd,label:'Spending change versus prior year'},document.getElementById('yearly-spending-change').textContent);
        renderChart(data.months,data.year); renderMonths(data.months,data.year); renderQuarters(data.quarters,data.year); renderCategories(data.top_categories,data.year); renderInsights(data.insights);
    }

    async function load() {
        errorEl.hidden = true; yearSelect.disabled = true;
        try {
            const response = await fetch(`../php_backend/public/yearly_dashboard.php?year=${encodeURIComponent(yearSelect.value)}`);
            if (!response.ok) throw new Error('Unable to load this year');
            render(await response.json());
            const url = new URL(window.location.href); url.searchParams.set('year', yearSelect.value); window.history.replaceState({}, '', url);
        } catch (error) {
            errorEl.textContent = 'The annual overview could not be loaded. Please try again.'; errorEl.hidden = false;
        } finally { yearSelect.disabled = false; }
    }

    yearSelect.addEventListener('change', load);
    populateYears().then(load).catch(() => { errorEl.textContent = 'The annual overview could not be loaded. Please try again.'; errorEl.hidden = false; });
})();

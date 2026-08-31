(function () {
    const byId = id => document.getElementById(id);
    const money = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 });
    const preciseMoney = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const dateLabel = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const palette = ['#f97316','#8b5cf6','#0f9f92','#e11d48','#0284c7','#d97706','#6366f1','#059669','#c026d3','#475569'];
    let availableMonths = [];
    let controller = null;

    function localDate(year, month, day) { return new Date(year, month, day, 12, 0, 0, 0); }
    function iso(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`; }
    function parseMonth(key) { const match = /^(\d{4})-(\d{2})$/.exec(key || ''); return match ? localDate(Number(match[1]), Number(match[2]) - 1, 1) : null; }
    function lastDay(date) { return localDate(date.getFullYear(), date.getMonth() + 1, 0); }
    function formatMoney(value) { return money.format(Number(value) || 0); }
    function formatDaily(value) { return `${preciseMoney.format(Number(value) || 0)}/day`; }

    function rangeForSelection() {
        const sorted = availableMonths.slice().sort((a, b) => a.key.localeCompare(b.key));
        const now = new Date();
        const endMonth = sorted.length ? parseMonth(sorted[sorted.length - 1].key) : localDate(now.getFullYear(), now.getMonth(), 1);
        const end = endMonth.getFullYear() === now.getFullYear() && endMonth.getMonth() === now.getMonth() ? localDate(now.getFullYear(), now.getMonth(), now.getDate()) : lastDay(endMonth);
        const selected = byId('burn-period').value;
        const firstAvailable = sorted.length ? parseMonth(sorted[0].key) : localDate(end.getFullYear(), end.getMonth(), 1);
        let start = firstAvailable;
        if (selected !== 'all') {
            const months = Number(selected) || 12;
            start = localDate(end.getFullYear(), end.getMonth() - months + 1, 1);
            if (start < firstAvailable) start = firstAvailable;
        }
        return { start, end };
    }

    function commonOptions() {
        return {
            chart: { backgroundColor: 'transparent', animation: false, spacing: [8, 8, 8, 8] },
            title: { text: null }, credits: { enabled: false }, accessibility: { enabled: true, description: 'Interactive spending chart. Select a point to view its contributing transactions.' },
            xAxis: { lineColor: '#e2e8f0', tickColor: '#e2e8f0', labels: { style: { color: '#64748b', fontSize: '10px' } } },
            yAxis: { min: 0, title: { text: null }, gridLineColor: 'rgba(148,163,184,.16)', labels: { formatter: function () { return '£' + Highcharts.numberFormat(this.value, 0); }, style: { color: '#64748b', fontSize: '10px' } } },
            legend: { align: 'right', verticalAlign: 'top', itemStyle: { color: '#475569', fontSize: '10px', fontWeight: '700' } },
            tooltip: { shared: true, valuePrefix: '£', valueSuffix: '/day', valueDecimals: 2, borderRadius: 10 }
        };
    }

    function emptyChart(id, copy) {
        const host = byId(id); host.replaceChildren();
        const empty = document.createElement('div'); empty.className = 'burn-empty'; empty.textContent = copy; host.appendChild(empty);
    }

    function renderSummary(data, range) {
        const metrics = data.metrics;
        byId('burn-latest').textContent = preciseMoney.format(metrics.latest_daily_burn);
        byId('burn-average').textContent = formatDaily(metrics.average_daily_burn);
        byId('burn-monthly').textContent = formatMoney(metrics.monthly_equivalent);
        byId('burn-total').textContent = formatMoney(metrics.total_spending);
        byId('burn-transactions').textContent = `${Number(metrics.transaction_count).toLocaleString('en-GB')} outgoing transactions`;
        byId('burn-story').textContent = metrics.latest_daily_burn > 0
            ? `Your latest observed month is running at ${formatDaily(metrics.latest_daily_burn)}, spread across its calendar days.`
            : 'There is no outgoing expenditure in the latest observed month.';
        byId('burn-method').textContent = data.method;
        byId('burn-range').textContent = `${dateLabel.format(range.start)} to ${dateLabel.format(range.end)} · ${data.period.months} month${data.period.months === 1 ? '' : 's'}`;
        byId('burn-peak').textContent = formatMoney(metrics.peak_day.amount);
        byId('burn-peak-date').textContent = metrics.peak_day.date ? dateLabel.format(new Date(`${metrics.peak_day.date}T12:00:00`)) : 'No expenditure in this period';
        const base=TransactionDrilldown.financial({start:data.period.start,end:data.period.end,direction:'spending'});
        const latest=data.months.length?TransactionDrilldown.monthRange(...data.months[data.months.length-1].key.split('-').map(Number)):base;
        TransactionDrilldown.linkify('burn-latest',{...base,...latest,label:'Latest monthly daily-burn contributors'},preciseMoney.format(metrics.latest_daily_burn));
        TransactionDrilldown.linkify('burn-average',{...base,label:'Historical daily-burn contributors'},formatDaily(metrics.average_daily_burn));
        TransactionDrilldown.linkify('burn-monthly',{...base,label:'Monthly-equivalent contributors'},formatMoney(metrics.monthly_equivalent));
        TransactionDrilldown.linkify('burn-total',{...base,label:'Observed expenditure'},formatMoney(metrics.total_spending));
        TransactionDrilldown.linkify('burn-transactions',{...base,label:'Observed outgoing transactions'},`${Number(metrics.transaction_count).toLocaleString('en-GB')} outgoing transactions`);
        if(metrics.peak_day.date){TransactionDrilldown.linkify('burn-peak',{...base,start:metrics.peak_day.date,end:metrics.peak_day.date,label:`Peak-day spending · ${metrics.peak_day.date}`},formatMoney(metrics.peak_day.amount));}
    }

    function renderSegmentChart(data) {
        if (!data.months.some(month => Number(month.spending) > 0)) { emptyChart('burn-segment-chart', 'No expenditure exists in this period.'); return; }
        const series = data.segments.map((segment, index) => ({
            type: 'area', name: segment.name, color: palette[index % palette.length],
            data: data.months.map(month => {
                const item = month.segments.find(row => row.id === segment.id && row.name === segment.name);
                const range=TransactionDrilldown.monthRange(...month.key.split('-').map(Number));
                return {y:item?Number(item.daily_burn):0,drilldown:segmentSearchLink(segment,{period:range})};
            })
        })).filter(item => item.data.some(value => value > 0));
        const options = commonOptions();
        Highcharts.chart('burn-segment-chart', {
            ...options,
            chart: { ...options.chart, type: 'area' },
            xAxis: { ...options.xAxis, categories: data.months.map(month => month.label), tickLength: 0 },
            plotOptions: { series:{cursor:'pointer',point:{events:{click:function(){window.location.href=this.options.drilldown;}}}},area: { stacking: 'normal', lineWidth: 1.5, fillOpacity: .68, marker: { enabled: false } } },
            series
        });
    }

    function renderActualChart(data) {
        if (!data.daily.some(day => Number(day.actual_spending) > 0)) { emptyChart('burn-actual-chart', 'No transaction-day expenditure exists in this period.'); return; }
        const options = commonOptions();
        Highcharts.chart('burn-actual-chart', {
            ...options,
            chart: { ...options.chart, zoomType: 'x' },
            xAxis: { type: 'datetime', lineColor: '#e2e8f0' },
            legend: { ...options.legend, enabled: true },
            tooltip: { shared: true, xDateFormat: '%e %b %Y', valuePrefix: '£', valueDecimals: 2 },
            plotOptions: { series:{cursor:'pointer',point:{events:{click:function(){window.location.href=this.options.drilldown;}}}},column: { borderWidth: 0, pointPadding: .04, groupPadding: .03 }, line: { marker: { enabled: false }, lineWidth: 2.5 } },
            series: [
                { type: 'column', name: 'Actual spending', color: 'rgba(249,115,22,.45)', data: data.daily.map(day => ({x:Date.parse(`${day.date}T12:00:00`),y:Number(day.actual_spending),drilldown:TransactionDrilldown.url(TransactionDrilldown.financial({start:day.date,end:day.date,direction:'spending',label:`Actual spending · ${day.date}`}))})) },
                { type: 'line', name: '14-day average', color: '#db2777', data: data.daily.map((day,index) => ({x:Date.parse(`${day.date}T12:00:00`),y:Number(day.rolling_average),drilldown:TransactionDrilldown.url(TransactionDrilldown.financial({start:data.daily[Math.max(0,index-13)].date,end:day.date,direction:'spending',label:`14-day spending window ending ${day.date}`}))})) }
            ]
        });
    }

    function segmentSearchLink(segment, data) {
        return TransactionDrilldown.url(TransactionDrilldown.financial({label:`${segment.name} spending`,start:data.period.start,end:data.period.end,direction:'spending',dimension:'segment',dimension_id:segment.unsegmented?undefined:segment.id,unclassified:!!segment.unsegmented}));
    }

    function renderSegments(data) {
        const host = byId('burn-segments'); host.replaceChildren();
        byId('burn-segment-count').textContent = `${data.segments.length} segment${data.segments.length === 1 ? '' : 's'}`;
        if (!data.segments.length) { const empty = document.createElement('div'); empty.className = 'burn-empty'; empty.textContent = 'No segment expenditure exists in this period.'; host.appendChild(empty); return; }
        const maximum = Math.max(...data.segments.map(segment => Number(segment.average_daily_burn)), 1);
        data.segments.forEach((segment, index) => {
            const row = document.createElement('article'); row.className = 'burn-segment-row'; row.style.setProperty('--segment-colour', palette[index % palette.length]);
            const name = document.createElement('div'); name.className = 'burn-segment-name'; name.innerHTML = '<span class="burn-segment-dot" aria-hidden="true"></span>';
            const text = document.createElement('span'); text.textContent = segment.name; name.appendChild(text);
            const bar = document.createElement('div'); bar.className = 'burn-segment-bar'; const fill = document.createElement('span'); fill.style.width = `${Number(segment.average_daily_burn) / maximum * 100}%`; bar.appendChild(fill);
            const values = [
                ['Latest', formatDaily(segment.latest_daily_burn),data.months.length?{period:TransactionDrilldown.monthRange(...data.months[data.months.length-1].key.split('-').map(Number))}:data],
                ['Average', formatDaily(segment.average_daily_burn),data],
                ['Share', `${Number(segment.share).toFixed(1)}%`,data]
            ].map(([label, value,scope]) => { const cell = document.createElement('div'); cell.className = 'burn-segment-value'; const caption = document.createElement('span'); caption.textContent = label; const strong = document.createElement('strong');const link=document.createElement('a');link.className='transaction-drilldown-link';link.href=segmentSearchLink(segment,scope);link.textContent=value;link.setAttribute('aria-label',`View ${segment.name} transactions behind ${label.toLowerCase()}`);strong.appendChild(link); cell.append(caption, strong); return cell; });
            const open = document.createElement('a'); open.className = 'burn-segment-open'; open.href = segmentSearchLink(segment, data); open.setAttribute('aria-label', `Open ${segment.name} expenditure`); open.innerHTML = '<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>';
            row.append(name, bar, ...values, open); host.appendChild(row);
        });
    }

    async function load() {
        if (controller) controller.abort(); controller = new AbortController();
        const range = rangeForSelection();
        const error = byId('burn-error'); error.hidden = true; byId('burn-loading').classList.add('is-visible');
        try {
            const response = await fetch(`../php_backend/public/daily_burn.php?start=${iso(range.start)}&end=${iso(range.end)}`, { signal: controller.signal, cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Daily Burn could not be loaded.');
            renderSummary(data, range); renderSegmentChart(data); renderActualChart(data); renderSegments(data);
            const url = new URL(window.location.href); url.searchParams.set('period', byId('burn-period').value); window.history.replaceState({}, '', url);
        } catch (failure) {
            if (failure.name === 'AbortError') return;
            error.textContent = failure.message || 'Daily Burn could not be loaded.'; error.hidden = false;
        } finally { byId('burn-loading').classList.remove('is-visible'); }
    }

    async function initialise() {
        const requested = new URLSearchParams(window.location.search).get('period');
        if ([...byId('burn-period').options].some(option => option.value === requested)) byId('burn-period').value = requested;
        try {
            const response = await fetch('../php_backend/public/transaction_months.php', { cache: 'no-store' });
            if (!response.ok) throw new Error();
            const rows = await response.json();
            availableMonths = (Array.isArray(rows) ? rows : []).map(item => ({ year: Number(item.year), month: Number(item.month), key: `${item.year}-${String(item.month).padStart(2, '0')}` })).filter(item => item.year && item.month >= 1 && item.month <= 12);
            await load();
        } catch (_) { const error = byId('burn-error'); error.textContent = 'Available transaction periods could not be loaded. Please try again.'; error.hidden = false; byId('burn-loading').classList.remove('is-visible'); }
    }

    byId('burn-period').addEventListener('change', load);
    byId('burn-refresh').addEventListener('click', load);
    initialise();
})();

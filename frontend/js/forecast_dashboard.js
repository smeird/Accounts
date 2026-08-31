(function () {
    'use strict';

    const currency = new Intl.NumberFormat('en-GB', {
        style: 'currency', currency: 'GBP', maximumFractionDigits: 0
    });
    const compactCurrency = new Intl.NumberFormat('en-GB', {
        style: 'currency', currency: 'GBP', notation: 'compact', maximumFractionDigits: 1
    });
    const main = document.getElementById('forecast-main');
    const errorEl = document.getElementById('forecast-error');

    function money(value) { return currency.format(Number(value) || 0); }
    function text(id, value) {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    }
    function clear(element) { while (element.firstChild) element.removeChild(element.firstChild); }
    function formatDate(value) {
        if (!value) return 'Not recorded';
        const date = new Date(`${value}T12:00:00`);
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }
    function signedMoney(value) {
        const number = Number(value) || 0;
        return `${number > 0 ? '+' : ''}${money(number)}`;
    }
    function axisMonthLabel(value) {
        const parts = String(value).split(' ');
        if (parts.length === 2 && window.matchMedia('(max-width: 640px)').matches) return parts[0];
        return parts.length === 2 ? `${parts[0]} '${parts[1].slice(-2)}` : String(value);
    }

    function renderHero(data) {
        const metrics = data.metrics;
        const position = document.getElementById('forecast-position-title');
        text('forecast-position-title', money(metrics.ending_balance));
        position.classList.toggle('is-negative', metrics.ending_balance < 0);
        text('forecast-message', metrics.cashflow >= 0
            ? `${signedMoney(metrics.cashflow)} expected net movement if the observed pattern continues.`
            : `${money(Math.abs(metrics.cashflow))} of expected drawdown if the observed pattern continues.`);
        text('forecast-conservative', money(metrics.conservative_ending_balance));
        text('forecast-optimistic', money(metrics.optimistic_ending_balance));
        text('forecast-period', data.period.label);
        const asOf = data.coverage.balance_as_of || data.coverage.latest_transaction_date || data.period.anchor_date;
        text('forecast-as-of', `Position anchored ${formatDate(asOf)}`);

        const confidence = document.getElementById('forecast-confidence');
        confidence.className = `forecast-confidence forecast-confidence--${data.coverage.confidence}`;
        confidence.textContent = `${data.coverage.confidence} confidence · ${data.coverage.active_months} active months`;
    }

    function renderMetrics(metrics) {
        text('forecast-cashflow', signedMoney(metrics.cashflow));
        document.getElementById('forecast-cashflow').classList.toggle('is-negative', metrics.cashflow < 0);
        text('forecast-income', money(metrics.income));
        text('forecast-spending', money(metrics.spending));
        text('forecast-negative-months', `${metrics.negative_months} / 12`);
        text('forecast-savings-rate', metrics.savings_rate === null
            ? 'No income baseline available'
            : `${Number(metrics.savings_rate).toFixed(1)}% of income retained`);
    }

    function chartBase() {
        return {
            chart: { backgroundColor: 'transparent', spacing: [8, 4, 4, 4], animation: false },
            title: { text: null },
            credits: { enabled: false },
            xAxis: {
                lineColor: '#dbe4ee', tickColor: '#dbe4ee', tickLength: 0,
                labels: {
                    formatter: function () { return axisMonthLabel(this.value); },
                    style: { color: '#64748b', fontSize: '10px' }
                }
            },
            yAxis: {
                title: { text: null }, gridLineColor: 'rgba(148,163,184,.16)',
                labels: { formatter: function () { return compactCurrency.format(this.value); }, style: { color: '#64748b', fontSize: '10px' } }
            },
            legend: {
                align: 'center', verticalAlign: 'bottom', symbolRadius: 4, itemDistance: 16,
                itemStyle: { color: '#475569', fontSize: '10px', fontWeight: '700' }
            },
            tooltip: {
                shared: true,
                formatter: function () {
                    const rows = this.points.map(point => `<span style="color:${point.color}">●</span> ${point.series.name}: <b>${money(point.y)}</b>`);
                    return `<b>${this.x}</b><br>${rows.join('<br>')}`;
                }
            },
            plotOptions: {
                series: { animation: false, marker: { enabled: false }, lineWidth: 2.5, connectNulls: false }
            },
            accessibility: { enabled: true }
        };
    }

    function renderMovementChart(history, forecast) {
        const categories = history.map(month => month.label).concat(forecast.map(month => month.label));
        const compact = window.matchMedia('(max-width: 640px)').matches;
        const padding = Array(Math.max(0, history.length - 1)).fill(null);
        const bridgeIncome = history.length ? history[history.length - 1].income : null;
        const bridgeSpending = history.length ? history[history.length - 1].spending : null;
        Highcharts.chart('forecast-movement-chart', Highcharts.merge(chartBase(), {
            xAxis: {
                categories: categories,
                labels: { step: compact ? 3 : (categories.length > 12 ? 2 : 1) },
                plotLines: [{
                    value: history.length - .5,
                    color: 'rgba(79,70,229,.28)',
                    dashStyle: 'ShortDash',
                    width: 1,
                    zIndex: 2,
                    label: { text: 'Forecast', rotation: 0, y: 12, style: { color: '#6366f1', fontSize: '9px', fontWeight: '700' } }
                }]
            },
            tooltip: { shared: true },
            series: [
                { name: 'Observed income', color: '#0f766e', cursor:'pointer',point:{events:{click:function(){if(this.options.drilldown)window.location.href=this.options.drilldown;}}}, data: history.map(month => ({y:month.income,drilldown:TransactionDrilldown.url(TransactionDrilldown.financial({...TransactionDrilldown.monthRange(...month.key.split('-').map(Number)),direction:'income',label:`${month.label} observed income`}))})).concat(Array(forecast.length).fill(null)) },
                { name: 'Forecast income', color: '#14b8a6', dashStyle: 'ShortDash', data: padding.concat([bridgeIncome]).concat(forecast.map(month => month.income)) },
                { name: 'Observed spending', color: '#6d28d9', cursor:'pointer',point:{events:{click:function(){if(this.options.drilldown)window.location.href=this.options.drilldown;}}}, data: history.map(month => ({y:month.spending,drilldown:TransactionDrilldown.url(TransactionDrilldown.financial({...TransactionDrilldown.monthRange(...month.key.split('-').map(Number)),direction:'spending',label:`${month.label} observed spending`}))})).concat(Array(forecast.length).fill(null)) },
                { name: 'Forecast spending', color: '#8b5cf6', dashStyle: 'ShortDash', data: padding.concat([bridgeSpending]).concat(forecast.map(month => month.spending)) }
            ]
        }));
    }

    function renderBalanceChart(forecast) {
        Highcharts.chart('forecast-balance-chart', Highcharts.merge(chartBase(), {
            xAxis: { categories: forecast.map(month => month.label), labels: { step: 2 } },
            series: [
                { name: 'Expected', color: '#312e81', data: forecast.map(month => month.expected_balance), lineWidth: 3 },
                { name: 'Conservative', color: '#e11d48', dashStyle: 'ShortDash', data: forecast.map(month => month.conservative_balance) },
                { name: 'Optimistic', color: '#0d9488', dashStyle: 'Dot', data: forecast.map(month => month.optimistic_balance) }
            ]
        }));
    }

    function renderCategories(categories) {
        const host = document.getElementById('forecast-categories');
        clear(host);
        if (!categories.length) {
            const empty = document.createElement('div');
            empty.className = 'forecast-inline-empty';
            empty.textContent = 'Categorise spending to see the likely drivers here.';
            host.appendChild(empty);
            return;
        }
        categories.forEach((category, index) => {
            const item = document.createElement('div');
            item.className = 'forecast-category';
            const rank = document.createElement('span');
            rank.className = 'forecast-category__rank';
            rank.textContent = String(index + 1).padStart(2, '0');
            const body = document.createElement('div');
            body.className = 'forecast-category__body';
            const line = document.createElement('div');
            line.className = 'forecast-category__line';
            const name = document.createElement('strong');
            name.textContent = category.name;
            const amount = document.createElement('span');
            amount.textContent = money(category.projected_amount);
            line.append(name, amount);
            const bar = document.createElement('span');
            bar.className = 'forecast-category__bar';
            const fill = document.createElement('span');
            fill.style.width = `${Math.max(3, category.relative)}%`;
            bar.appendChild(fill);
            body.append(line, bar);
            const share = document.createElement('small');
            share.textContent = `${Number(category.share).toFixed(1)}%`;
            item.append(rank, body, share);
            host.appendChild(item);
        });
    }

    function renderInsights(insights) {
        const host = document.getElementById('forecast-insights');
        clear(host);
        insights.forEach(insight => {
            const card = document.createElement('article');
            card.className = `forecast-insight forecast-insight--${insight.tone}`;
            const icon = document.createElement('span');
            icon.className = 'forecast-insight__icon';
            const glyph = document.createElement('i');
            glyph.className = `fas ${insight.icon}`;
            glyph.setAttribute('aria-hidden', 'true');
            icon.appendChild(glyph);
            const title = document.createElement('strong');
            title.textContent = insight.title;
            const detail = document.createElement('p');
            detail.textContent = insight.detail;
            card.append(icon, title, detail);
            host.appendChild(card);
        });
    }

    function appendDefinition(host, termText, definitionText, href) {
        const term = document.createElement('dt');
        term.textContent = termText;
        const definition = document.createElement('dd');
        if(href){const link=document.createElement('a');link.className='transaction-drilldown-link';link.href=href;link.textContent=definitionText;definition.appendChild(link);}else definition.textContent = definitionText;
        host.append(term, definition);
    }

    function renderMethodology(data) {
        text('forecast-method-approach', data.methodology.approach);
        text('forecast-method-exclusions', data.methodology.exclusions);
        text('forecast-method-notice', data.methodology.notice);
        const assumptions = document.getElementById('forecast-method-assumptions');
        clear(assumptions);
        data.methodology.assumptions.forEach(assumption => {
            const item = document.createElement('li');
            item.textContent = assumption;
            assumptions.appendChild(item);
        });
        const coverage = document.getElementById('forecast-coverage');
        clear(coverage);
        appendDefinition(coverage, 'History', data.coverage.history_start ? `${formatDate(data.coverage.history_start)} to ${formatDate(data.coverage.history_end)}` : 'No complete history');
        appendDefinition(coverage, 'Included activity', `${data.coverage.transaction_count} transactions across ${data.coverage.active_months} active months`,data.coverage.transaction_count?TransactionDrilldown.url(TransactionDrilldown.financial({start:data.coverage.history_start,end:data.coverage.latest_transaction_date||data.coverage.history_end,label:'Forecast model input transactions'})):null);
        appendDefinition(coverage, 'Recent baseline', `${data.coverage.modelled_months} complete active months`);
        appendDefinition(coverage, 'Position date', formatDate(data.coverage.balance_as_of || data.period.anchor_date));
    }

    function render(data) {
        document.getElementById('forecast-empty').hidden = data.has_data;
        renderHero(data);
        renderMetrics(data.metrics);
        renderMovementChart(data.history, data.forecast);
        renderBalanceChart(data.forecast);
        renderCategories(data.top_categories);
        renderInsights(data.insights);
        renderMethodology(data);
    }

    async function load() {
        errorEl.hidden = true;
        main.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch('../php_backend/public/forecast_dashboard.php', { cache: 'no-store' });
            if (!response.ok) throw new Error('Forecast API request failed');
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            render(data);
        } catch (error) {
            errorEl.textContent = 'The forecast could not be built. Please try again after refreshing the page.';
            errorEl.hidden = false;
        } finally {
            main.setAttribute('aria-busy', 'false');
        }
    }

    load();
})();

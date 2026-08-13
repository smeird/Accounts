(function () {
    'use strict';

    const currency = new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    });
    const compactCurrency = new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        notation: 'compact',
        maximumFractionDigits: 1
    });
    const percentage = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });
    const dateFormatter = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const yearSelect = document.getElementById('year-select');
    const status = document.getElementById('graphs-status');
    const content = document.getElementById('graphs-content');
    let activeRequest = null;

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function svgElement(tag, attributes) {
        const node = document.createElementNS(SVG_NS, tag);
        Object.entries(attributes || {}).forEach(([name, value]) => node.setAttribute(name, String(value)));
        return node;
    }

    function addSvgTitle(node, text) {
        const title = svgElement('title');
        title.textContent = text;
        node.appendChild(title);
    }

    function formatCurrency(value) {
        return currency.format(Number(value) || 0);
    }

    function formatCompactCurrency(value) {
        const numeric = Number(value) || 0;
        return Math.abs(numeric) < 1000 ? currency.format(numeric) : compactCurrency.format(numeric);
    }

    function niceStep(range, targetTicks) {
        const rough = Math.max(range, 1) / Math.max(targetTicks, 1);
        const magnitude = Math.pow(10, Math.floor(Math.log10(rough)));
        const normalized = rough / magnitude;
        const factor = normalized < 1.5 ? 1 : normalized < 3 ? 2 : normalized < 7 ? 5 : 10;
        return factor * magnitude;
    }

    function formatDate(value) {
        if (!value) return 'date unavailable';
        const parsed = new Date(String(value) + 'T12:00:00');
        return Number.isNaN(parsed.getTime()) ? String(value) : dateFormatter.format(parsed);
    }

    function comparisonCopy(value, comparisonYear, throughMonth) {
        if (value === null || value === undefined || !Number.isFinite(Number(value))) {
            return `No reliable comparison with ${comparisonYear}`;
        }
        const numeric = Number(value);
        const direction = numeric > 0 ? 'up' : numeric < 0 ? 'down' : 'unchanged';
        const amount = numeric === 0 ? '' : ` ${percentage.format(Math.abs(numeric))}%`;
        const through = throughMonth ? ` through month ${throughMonth}` : '';
        return `${direction}${amount} vs ${comparisonYear}${through}`;
    }

    async function requestJson(url, options) {
        const response = await fetch(url, Object.assign({ cache: 'no-store' }, options || {}));
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable response.');
        }
        if (!response.ok || (payload && payload.error)) {
            throw new Error(payload && payload.error ? payload.error : 'The data request failed.');
        }
        return payload;
    }

    function setStatus(message, isError) {
        status.replaceChildren();
        status.classList.toggle('is-error', Boolean(isError));
        const icon = element('i', isError ? 'fas fa-triangle-exclamation' : 'fas fa-circle-notch fa-spin');
        icon.setAttribute('aria-hidden', 'true');
        status.append(icon, element('span', '', message));
        status.hidden = false;
    }

    function emptyState(message) {
        return element('div', 'graphs-empty', message);
    }

    function renderMetrics(data) {
        const target = document.getElementById('graphs-metrics');
        const metrics = data.metrics;
        const comparison = data.comparison || {};
        const cards = [
            {
                label: 'Current net position',
                value: formatCurrency(metrics.balance),
                context: `Latest ledger balances • ${formatDate(metrics.balance_as_of)}`,
                tone: 'blue'
            },
            {
                label: 'Income',
                value: formatCurrency(metrics.income),
                context: comparisonCopy(comparison.income, comparison.year, comparison.through_month),
                tone: 'olive'
            },
            {
                label: 'Spending',
                value: formatCurrency(metrics.spending),
                context: `${formatCurrency(metrics.average_monthly_spending)} average per active month`,
                tone: 'gold'
            },
            {
                label: 'Net cash flow',
                value: formatCurrency(metrics.cashflow),
                context: comparisonCopy(comparison.cashflow, comparison.year, comparison.through_month),
                tone: metrics.cashflow < 0 ? 'pink' : 'blue'
            },
            {
                label: 'Savings rate',
                value: `${percentage.format(metrics.savings_rate)}%`,
                context: `${metrics.negative_months} negative cash-flow month${metrics.negative_months === 1 ? '' : 's'} across ${metrics.active_months} active`,
                tone: metrics.savings_rate < 0 ? 'pink' : 'olive'
            }
        ];
        target.replaceChildren();
        cards.forEach(card => {
            const item = element('article', 'graphs-metric');
            item.dataset.tone = card.tone;
            item.append(
                element('p', 'graphs-metric-label', card.label),
                element('p', 'graphs-metric-value', card.value),
                element('p', 'graphs-metric-context', card.context)
            );
            target.appendChild(item);
        });
    }

    function renderInsights(items) {
        const target = document.getElementById('graphs-insights');
        target.replaceChildren();
        (items || []).slice(0, 4).forEach(item => {
            const card = element('article', 'graphs-insight');
            card.dataset.tone = item.tone || 'neutral';
            const icon = element('span', 'graphs-insight-icon');
            const glyph = element('i', `fas ${item.icon || 'fa-chart-simple'}`);
            glyph.setAttribute('aria-hidden', 'true');
            icon.appendChild(glyph);
            const copy = element('div');
            copy.append(
                element('p', 'graphs-insight-title', item.title || 'Financial observation'),
                element('p', 'graphs-insight-detail', item.detail || '')
            );
            card.append(icon, copy);
            target.appendChild(card);
        });
        target.hidden = target.children.length === 0;
    }

    function renderMovementChart(months, latestMonth) {
        const target = document.getElementById('movement-chart');
        target.replaceChildren();
        const visibleMonths = months.slice(0, latestMonth || 12);
        const hasActivity = visibleMonths.some(month => Number(month.income) || Number(month.spending));
        if (!hasActivity) {
            target.appendChild(emptyState('No income or spending was recorded in this year.'));
            return;
        }

        const width = 960;
        const height = 350;
        const margin = { top: 18, right: 18, bottom: 46, left: 64 };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        const values = visibleMonths.reduce((all, month) => all.concat([
            Number(month.income) || 0,
            Number(month.spending) || 0,
            Number(month.cashflow) || 0
        ]), [0]);
        const dataMinimum = Math.min.apply(null, values);
        const dataMaximum = Math.max.apply(null, values);
        const tickStep = niceStep(dataMaximum - dataMinimum, 5);
        const minValue = Math.floor(Math.min(0, dataMinimum) / tickStep) * tickStep;
        let maxValue = Math.ceil(Math.max(0, dataMaximum) / tickStep) * tickStep;
        if (minValue === maxValue) maxValue += tickStep;
        const y = value => margin.top + ((maxValue - value) / (maxValue - minValue)) * plotHeight;
        const baseline = y(0);
        const groupWidth = plotWidth / visibleMonths.length;
        const barWidth = Math.max(8, Math.min(22, groupWidth * 0.24));

        const svg = svgElement('svg', {
            viewBox: `0 0 ${width} ${height}`,
            role: 'img',
            'aria-label': 'Monthly income and spending bars with a net cash-flow line'
        });

        for (let value = minValue; value <= maxValue + tickStep / 2; value += tickStep) {
            const gridY = y(value);
            svg.appendChild(svgElement('line', {
                x1: margin.left,
                x2: width - margin.right,
                y1: gridY,
                y2: gridY,
                class: Math.abs(value) < (maxValue - minValue) / 100 ? 'graphs-zero-line' : 'graphs-grid-line'
            }));
            const label = svgElement('text', { x: margin.left - 10, y: gridY + 4, 'text-anchor': 'end', class: 'graphs-axis-label' });
            label.textContent = formatCompactCurrency(value);
            svg.appendChild(label);
        }

        if (minValue < 0 && maxValue > 0) {
            svg.appendChild(svgElement('line', {
                x1: margin.left,
                x2: width - margin.right,
                y1: baseline,
                y2: baseline,
                class: 'graphs-zero-line'
            }));
        }

        const cashflowPoints = [];
        visibleMonths.forEach((month, index) => {
            const center = margin.left + groupWidth * index + groupWidth / 2;
            const income = Number(month.income) || 0;
            const spending = Number(month.spending) || 0;
            const cashflow = Number(month.cashflow) || 0;
            const incomeRect = svgElement('rect', {
                x: center - barWidth - 2,
                y: y(Math.max(0, income)),
                width: barWidth,
                height: Math.max(0.5, Math.abs(baseline - y(income))),
                rx: 3,
                class: 'graphs-income-bar'
            });
            addSvgTitle(incomeRect, `${month.label} income: ${formatCurrency(income)}`);
            const spendingRect = svgElement('rect', {
                x: center + 2,
                y: y(Math.max(0, spending)),
                width: barWidth,
                height: Math.max(0.5, Math.abs(baseline - y(spending))),
                rx: 3,
                class: 'graphs-spending-bar'
            });
            addSvgTitle(spendingRect, `${month.label} spending: ${formatCurrency(spending)}`);
            svg.append(incomeRect, spendingRect);
            cashflowPoints.push([center, y(cashflow), month, cashflow]);

            const monthLabel = svgElement('text', {
                x: center,
                y: height - 18,
                'text-anchor': 'middle',
                class: 'graphs-axis-label'
            });
            monthLabel.textContent = month.label;
            svg.appendChild(monthLabel);
        });

        if (cashflowPoints.length > 1) {
            const path = cashflowPoints.map((point, index) => `${index ? 'L' : 'M'} ${point[0]} ${point[1]}`).join(' ');
            svg.appendChild(svgElement('path', { d: path, class: 'graphs-cashflow-line' }));
        }
        cashflowPoints.forEach(point => {
            const circle = svgElement('circle', { cx: point[0], cy: point[1], r: 4, class: 'graphs-cashflow-point' });
            addSvgTitle(circle, `${point[2].label} cash flow: ${formatCurrency(point[3])}`);
            svg.appendChild(circle);
        });
        target.appendChild(svg);
    }

    function renderDivergingRows(targetId, rows, options) {
        const target = document.getElementById(targetId);
        target.replaceChildren();
        if (!rows.length) {
            target.appendChild(emptyState(options.empty));
            return;
        }
        const maximum = Math.max.apply(null, rows.map(row => Math.abs(Number(options.value(row)) || 0)).concat([1]));
        rows.forEach(row => {
            const value = Number(options.value(row)) || 0;
            const item = element('div', 'graphs-bar-row');
            const labelText = options.label(row);
            const label = element('span', 'graphs-bar-label', labelText);
            label.title = labelText;
            item.appendChild(label);
            const track = element('div', 'graphs-diverging-track');
            track.setAttribute('aria-label', `${labelText}: ${formatCurrency(value)}`);
            const negative = element('span', 'graphs-diverging-half');
            const positive = element('span', 'graphs-diverging-half');
            const fill = element('span', `graphs-diverging-fill${value >= 0 ? ' is-positive' : ''}`);
            fill.style.width = `${Math.max(1.5, Math.abs(value) / maximum * 100)}%`;
            (value < 0 ? negative : positive).appendChild(fill);
            track.append(negative, positive);
            item.append(track, element('span', 'graphs-bar-value', formatCurrency(value)));
            target.appendChild(item);
        });
    }

    function renderCashflow(months) {
        const active = months.filter(month => Number(month.income) || Number(month.spending));
        renderDivergingRows('cashflow-chart', active, {
            label: row => row.label,
            value: row => row.cashflow,
            empty: 'No active months are available for cash-flow comparison.'
        });
    }

    function renderRankedBars(targetId, rows, tone, emptyMessage) {
        const target = document.getElementById(targetId);
        target.replaceChildren();
        if (!rows.length) {
            target.appendChild(emptyState(emptyMessage));
            return;
        }
        const maximum = Math.max.apply(null, rows.map(row => Number(row.amount) || 0).concat([1]));
        rows.forEach(row => {
            const item = element('div', 'graphs-bar-row');
            const label = element(row.is_other ? 'span' : 'a', 'graphs-bar-label', row.name);
            if (!row.is_other) {
                label.href = `search.html?value=${encodeURIComponent(row.name)}`;
                label.title = `Find transactions matching ${row.name}`;
            }
            const track = element('span', 'graphs-bar-track');
            const fill = element('span', 'graphs-bar-fill');
            fill.dataset.tone = tone;
            fill.style.width = `${Math.max(1.5, Number(row.amount) / maximum * 100)}%`;
            track.appendChild(fill);
            const value = element('span', 'graphs-bar-value', `${formatCurrency(row.amount)} · ${percentage.format(row.share)}%`);
            item.append(label, track, value);
            target.appendChild(item);
        });
    }

    function renderHeatmap(categories, latestMonth) {
        const target = document.getElementById('spending-heatmap');
        target.replaceChildren();
        if (!categories.length) {
            target.appendChild(emptyState('No category spending is available for the selected year.'));
            return;
        }
        const grid = element('div', 'graphs-heatmap');
        grid.setAttribute('role', 'table');
        grid.setAttribute('aria-label', 'Monthly spending intensity by category');
        grid.appendChild(element('span', 'graphs-heatmap-heading', 'Category'));
        const monthLabels = (categories[0].months || []).slice(0, latestMonth || 12);
        grid.style.gridTemplateColumns = `minmax(8.5rem, 1.4fr) repeat(${monthLabels.length}, minmax(2.4rem, 1fr))`;
        grid.style.minWidth = `${Math.max(34, 9 + monthLabels.length * 3.1)}rem`;
        monthLabels.forEach(month => grid.appendChild(element('span', 'graphs-heatmap-heading', month.label)));

        categories.forEach(category => {
            grid.appendChild(element('span', 'graphs-heatmap-label', category.name));
            const visibleMonths = category.months.slice(0, monthLabels.length);
            const maximum = Math.max.apply(null, visibleMonths.map(month => Number(month.amount) || 0).concat([0]));
            visibleMonths.forEach(month => {
                const amount = Number(month.amount) || 0;
                const cell = element('span', `graphs-heatmap-cell${amount === 0 ? ' is-empty' : ''}`, amount ? formatCompactCurrency(amount) : '–');
                const intensity = maximum > 0 ? amount / maximum : 0;
                cell.style.setProperty('--cell-alpha', (0.08 + intensity * 0.72).toFixed(2));
                cell.title = `${category.name}, ${month.label}: ${formatCurrency(amount)}`;
                cell.setAttribute('aria-label', cell.title);
                grid.appendChild(cell);
            });
        });
        target.appendChild(grid);
    }

    function renderSegments(rows) {
        const target = document.getElementById('segment-chart');
        target.replaceChildren();
        if (!rows.length) {
            target.appendChild(emptyState('No segment spending is available for this year.'));
            return;
        }
        const composition = element('div', 'graphs-composition');
        composition.setAttribute('aria-label', 'Spending share by segment');
        rows.forEach(row => {
            const segment = element('span');
            segment.style.width = `${Math.max(0.5, Number(row.share))}%`;
            segment.title = `${row.name}: ${formatCurrency(row.amount)} (${percentage.format(row.share)}%)`;
            composition.appendChild(segment);
        });
        const list = element('ul', 'graphs-composition-list');
        rows.forEach(row => {
            const item = element('li', 'graphs-composition-item');
            item.append(
                element('span', 'graphs-composition-dot'),
                element('span', 'graphs-composition-name', row.name),
                element('span', 'graphs-composition-value', `${percentage.format(row.share)}%`)
            );
            list.appendChild(item);
        });
        target.append(composition, list);
    }

    function renderAccounts(rows, asOf) {
        document.getElementById('accounts-chart-note').textContent = `Latest ledger balances as of ${formatDate(asOf)}. These are current snapshots, not historical year-end values.`;
        renderDivergingRows('account-chart', rows, {
            label: row => row.name,
            value: row => row.balance,
            empty: 'No account balance snapshots are available.'
        });
    }

    function renderTags(rows) {
        const target = document.getElementById('tag-chart');
        target.replaceChildren();
        if (!rows.length) {
            target.appendChild(emptyState('No tagged spending patterns are available for this year.'));
            return;
        }
        rows.forEach(row => {
            const card = element(row.is_other ? 'div' : 'a', `graphs-tag${row.is_other ? ' is-summary' : ''}`);
            if (!row.is_other) card.href = `search.html?value=${encodeURIComponent(row.name)}`;
            card.appendChild(element('span', 'graphs-tag-name', row.name));
            const meta = element('span', 'graphs-tag-meta');
            meta.append(
                element('span', '', formatCurrency(row.amount)),
                element('span', '', `${percentage.format(row.share)}% of spend`)
            );
            card.appendChild(meta);
            target.appendChild(card);
        });
    }

    function renderDashboard(data) {
        document.getElementById('graphs-scope').textContent = data.scope.label;
        document.getElementById('graphs-scope-note').textContent = `${data.scope.note} ${data.scope.transaction_count.toLocaleString('en-GB')} included transaction${data.scope.transaction_count === 1 ? '' : 's'}.`;
        renderMetrics(data);
        renderInsights(data.insights);
        renderMovementChart(data.months || [], data.scope.latest_month);
        renderCashflow(data.months || []);
        renderRankedBars('category-chart', data.categories || [], 'blue', 'No spending categories are available for this year.');
        renderHeatmap(data.categories || [], data.scope.latest_month);
        renderSegments(data.segments || []);
        renderAccounts(data.accounts || [], data.metrics.balance_as_of);
        renderTags(data.tags || []);
    }

    async function loadYear(year) {
        if (activeRequest) activeRequest.abort();
        activeRequest = new AbortController();
        setStatus('Building the financial picture…', false);
        content.hidden = true;
        yearSelect.disabled = true;
        try {
            const data = await requestJson(`../php_backend/public/graphs_dashboard.php?year=${encodeURIComponent(year)}`, {
                signal: activeRequest.signal
            });
            renderDashboard(data);
            status.hidden = true;
            content.hidden = false;
        } catch (error) {
            if (error.name !== 'AbortError') {
                setStatus(error.message || 'The financial picture could not be loaded.', true);
            }
        } finally {
            yearSelect.disabled = false;
        }
    }

    async function initialise() {
        try {
            const months = await requestJson('../php_backend/public/transaction_months.php');
            const years = Array.from(new Set((Array.isArray(months) ? months : []).map(row => Number(row.year)).filter(Boolean)))
                .sort((a, b) => b - a);
            if (!years.length) years.push(new Date().getFullYear());
            yearSelect.replaceChildren();
            years.forEach(year => {
                const option = element('option', '', year);
                option.value = String(year);
                yearSelect.appendChild(option);
            });
            yearSelect.value = String(years[0]);
            await loadYear(years[0]);
        } catch (error) {
            setStatus(error.message || 'Available financial years could not be loaded.', true);
            yearSelect.disabled = true;
        }
    }

    yearSelect.addEventListener('change', event => loadYear(Number(event.target.value)));
    initialise();
})();

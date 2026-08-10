// Renders the glanceable financial snapshot on the Instant dashboard.
(function () {
    'use strict';

    const money = new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    });
    const preciseMoney = new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    const toneClasses = [
        'instant-tone--positive',
        'instant-tone--negative',
        'instant-tone--neutral'
    ];

    function byId(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        const element = byId(id);
        if (element) {
            element.textContent = value;
        }
    }

    function applyTone(element, tone) {
        if (!element) return;
        toneClasses.forEach(className => element.classList.remove(className));
        element.classList.add('instant-tone--' + (tone || 'neutral'));
    }

    function formatDate(value, options) {
        if (!value) return 'No activity yet';
        const date = new Date(value + (value.length === 10 ? 'T12:00:00' : ''));
        return date.toLocaleDateString('en-GB', options || {
            day: 'numeric',
            month: 'short'
        });
    }

    function changeLabel(value, lowerIsBetter) {
        if (value === null || typeof value === 'undefined') {
            return { text: 'No previous month comparison', tone: 'neutral' };
        }
        if (Math.abs(value) < 0.1) {
            return { text: 'In line with last month', tone: 'neutral' };
        }
        const direction = value > 0 ? 'up' : 'down';
        let tone = value > 0 ? 'positive' : 'negative';
        if (lowerIsBetter) tone = value > 0 ? 'negative' : 'positive';
        return {
            text: Math.abs(value).toFixed(0) + '% ' + direction + ' on last month',
            tone
        };
    }

    function emptyState(message, href, linkText) {
        const wrap = document.createElement('div');
        wrap.className = 'instant-empty';
        const icon = document.createElement('i');
        icon.className = 'fas fa-circle-info';
        icon.setAttribute('aria-hidden', 'true');
        const text = document.createElement('p');
        text.textContent = message;
        wrap.append(icon, text);
        if (href && linkText) {
            const link = document.createElement('a');
            link.href = href;
            link.textContent = linkText;
            wrap.appendChild(link);
        }
        return wrap;
    }

    function renderHeadline(data) {
        setText('instant-period', data.period.label + (data.period.is_current_month ? ' · current month' : ' · latest recorded month'));
        setText('instant-balance', money.format(data.headline.balance));
        setText('instant-message', data.headline.message);
        setText('instant-pace-text', data.headline.spending_pace.label);
        applyTone(byId('instant-pace'), data.headline.spending_pace.tone);

        const rate = data.metrics.savings_rate;
        setText('instant-savings-rate', rate === null ? '—' : Math.round(rate) + '%');
        const score = Math.max(0, Math.min(100, rate === null ? 0 : rate));
        byId('instant-score-ring').style.setProperty('--score-progress', (score * 3.6) + 'deg');
        applyTone(byId('instant-score-ring'), rate === null ? 'neutral' : (rate >= 10 ? 'positive' : (rate < 0 ? 'negative' : 'neutral')));

        setText('instant-income', money.format(data.metrics.income));
        setText('instant-spending', money.format(data.metrics.spending));
        setText('instant-cashflow', money.format(data.metrics.cashflow));
        applyTone(byId('instant-cashflow'), data.headline.tone);

        const incomeChange = changeLabel(data.metrics.income_change, false);
        const spendingChange = changeLabel(data.metrics.spending_change, true);
        setText('instant-income-change', incomeChange.text);
        setText('instant-spending-change', spendingChange.text);
        applyTone(byId('instant-income-change'), incomeChange.tone);
        applyTone(byId('instant-spending-change'), spendingChange.tone);

        const progress = Math.max(0, Math.min(100, data.period.progress || 0));
        setText('instant-month-progress', Math.round(progress) + '%');
        byId('instant-month-progress-bar').style.width = progress + '%';
        setText(
            'instant-latest-transaction',
            data.period.latest_transaction_date
                ? 'Latest activity ' + formatDate(data.period.latest_transaction_date, { day: 'numeric', month: 'long' })
                : 'No transactions recorded'
        );
    }

    function renderTrend(rows) {
        const rootStyles = getComputedStyle(document.documentElement);
        const brand = rootStyles.getPropertyValue('--brand-color-600').trim() || '#4f46e5';
        const textColor = getComputedStyle(document.body).color || '#172033';

        Highcharts.chart('instant-cashflow-chart', {
            chart: {
                type: 'areaspline',
                backgroundColor: 'transparent',
                spacing: [12, 4, 2, 0],
                animation: false
            },
            title: { text: null },
            credits: { enabled: false },
            accessibility: { enabled: false },
            xAxis: {
                categories: rows.map(row => row.label),
                lineColor: 'rgba(148, 163, 184, 0.24)',
                tickLength: 0,
                labels: { style: { color: '#64748b', fontSize: '11px' } }
            },
            yAxis: {
                title: { text: null },
                gridLineColor: 'rgba(148, 163, 184, 0.16)',
                labels: {
                    style: { color: '#64748b', fontSize: '10px' },
                    formatter: function () {
                        const abs = Math.abs(this.value);
                        return '£' + (abs >= 1000 ? Highcharts.numberFormat(this.value / 1000, 1) + 'k' : Highcharts.numberFormat(this.value, 0));
                    }
                }
            },
            legend: {
                align: 'left',
                verticalAlign: 'top',
                itemStyle: { color: textColor, fontSize: '11px', fontWeight: '600' },
                symbolRadius: 6
            },
            tooltip: {
                shared: true,
                valuePrefix: '£',
                valueDecimals: 0,
                borderRadius: 10
            },
            plotOptions: {
                areaspline: {
                    lineWidth: 2.5,
                    marker: { enabled: false, symbol: 'circle', radius: 3 },
                    fillOpacity: 0.09,
                    states: { hover: { lineWidth: 3 } }
                }
            },
            series: [
                {
                    name: 'Income',
                    color: '#10b981',
                    data: rows.map(row => Number(row.income) || 0)
                },
                {
                    name: 'Spending',
                    color: brand,
                    data: rows.map(row => Number(row.spending) || 0)
                }
            ]
        });
    }

    function attentionIcon(type) {
        return {
            budget: 'fa-gauge-high',
            cashflow: 'fa-arrow-trend-down',
            tags: 'fa-tags',
            accounts: 'fa-building-columns',
            clear: 'fa-circle-check'
        }[type] || 'fa-circle-info';
    }

    function renderAttention(items) {
        const container = byId('instant-attention');
        container.replaceChildren();
        setText('instant-attention-count', String(items.length));

        items.forEach(item => {
            const link = document.createElement('a');
            link.className = 'instant-attention instant-attention--' + item.severity;
            link.href = item.href;

            const icon = document.createElement('span');
            icon.className = 'instant-attention__icon';
            const iconGlyph = document.createElement('i');
            iconGlyph.className = 'fas ' + attentionIcon(item.type);
            iconGlyph.setAttribute('aria-hidden', 'true');
            icon.appendChild(iconGlyph);

            const copy = document.createElement('span');
            copy.className = 'instant-attention__copy';
            const title = document.createElement('strong');
            title.textContent = item.title;
            const detail = document.createElement('span');
            detail.textContent = item.detail;
            copy.append(title, detail);

            const arrow = document.createElement('i');
            arrow.className = 'fas fa-chevron-right instant-attention__arrow';
            arrow.setAttribute('aria-hidden', 'true');
            link.append(icon, copy, arrow);
            container.appendChild(link);
        });
    }

    function renderCategories(items) {
        const container = byId('instant-categories');
        container.replaceChildren();
        if (!items.length) {
            container.appendChild(emptyState('No spending has been recorded for this period.', 'upload.html', 'Import a statement'));
            return;
        }

        const maximum = Math.max.apply(null, items.map(item => Number(item.amount) || 0));
        items.forEach((item, index) => {
            const link = document.createElement('a');
            link.href = 'search.html?value=' + encodeURIComponent(item.name);
            link.className = 'instant-ranked-item';

            const rank = document.createElement('span');
            rank.className = 'instant-ranked-item__rank';
            rank.textContent = String(index + 1).padStart(2, '0');

            const body = document.createElement('span');
            body.className = 'instant-ranked-item__body';
            const line = document.createElement('span');
            line.className = 'instant-ranked-item__line';
            const name = document.createElement('strong');
            name.textContent = item.name;
            const amount = document.createElement('span');
            amount.textContent = money.format(item.amount);
            line.append(name, amount);
            const bar = document.createElement('span');
            bar.className = 'instant-ranked-item__bar';
            const fill = document.createElement('span');
            fill.style.width = (maximum > 0 ? (item.amount / maximum) * 100 : 0) + '%';
            bar.appendChild(fill);
            const share = document.createElement('small');
            share.textContent = item.share + '% of monthly spending';
            body.append(line, bar, share);
            link.append(rank, body);
            container.appendChild(link);
        });
    }

    function renderBudgets(budget) {
        setText('instant-budget-spent', money.format(budget.spent));
        setText('instant-budget-total', money.format(budget.total));
        setText('instant-budget-used', budget.used === null ? 'Not set' : Math.round(budget.used) + '%');
        const overallUsed = Math.max(0, Math.min(100, budget.used || 0));
        byId('instant-budget-progress').style.width = overallUsed + '%';
        byId('instant-budget-progress').className = budget.used > 100 ? 'is-over' : (budget.used >= 85 ? 'is-watch' : '');

        const container = byId('instant-budgets');
        container.replaceChildren();
        if (!budget.items.length) {
            container.appendChild(emptyState('Set category budgets to see pressure before limits are crossed.', 'budgets.html', 'Create budgets'));
            return;
        }

        budget.items.forEach(item => {
            const row = document.createElement('a');
            row.href = 'budgets.html';
            row.className = 'instant-budget-row';
            const label = document.createElement('span');
            const name = document.createElement('strong');
            name.textContent = item.category;
            const detail = document.createElement('small');
            detail.textContent = money.format(item.spent) + ' of ' + money.format(item.amount);
            label.append(name, detail);
            const badge = document.createElement('span');
            badge.className = 'instant-budget-badge instant-budget-badge--' + item.status;
            badge.textContent = Math.round(item.used) + '%';
            row.append(label, badge);
            container.appendChild(row);
        });
    }

    function renderAccounts(accounts) {
        const container = byId('instant-accounts');
        container.replaceChildren();
        if (!accounts.length) {
            container.appendChild(emptyState('No accounts are connected yet.', 'upload.html', 'Import your first statement'));
            return;
        }

        accounts.slice(0, 5).forEach(account => {
            const link = document.createElement('a');
            link.href = 'account.html?id=' + encodeURIComponent(account.id);
            link.className = 'instant-account';
            const icon = document.createElement('span');
            icon.className = 'instant-account__icon';
            const iconGlyph = document.createElement('i');
            iconGlyph.className = 'fas fa-building-columns';
            iconGlyph.setAttribute('aria-hidden', 'true');
            icon.appendChild(iconGlyph);
            const copy = document.createElement('span');
            const name = document.createElement('strong');
            name.textContent = account.name;
            const date = document.createElement('small');
            date.textContent = account.ledger_balance_date
                ? 'Balance dated ' + formatDate(account.ledger_balance_date)
                : 'Balance date unavailable';
            copy.append(name, date);
            const balance = document.createElement('span');
            balance.className = 'instant-account__balance ' + (account.balance < 0 ? 'instant-tone--negative' : '');
            balance.textContent = money.format(account.balance);
            link.append(icon, copy, balance);
            container.appendChild(link);
        });

        if (accounts.length > 5) {
            const more = document.createElement('a');
            more.href = 'account_dashboard.html';
            more.className = 'instant-list-more';
            more.textContent = 'View ' + (accounts.length - 5) + ' more accounts';
            container.appendChild(more);
        }
    }

    function renderRecent(items) {
        const container = byId('instant-recent');
        container.replaceChildren();
        if (!items.length) {
            container.appendChild(emptyState('No recent transactions are available.', 'upload.html', 'Import transactions'));
            return;
        }

        items.forEach(item => {
            const link = document.createElement('a');
            link.href = 'transaction.html?id=' + encodeURIComponent(item.id);
            link.className = 'instant-transaction';
            const date = document.createElement('time');
            date.dateTime = item.date;
            date.textContent = formatDate(item.date, { day: '2-digit', month: 'short' });
            const description = document.createElement('span');
            description.className = 'instant-transaction__description';
            const title = document.createElement('strong');
            title.textContent = item.description;
            const meta = document.createElement('small');
            const pieces = [item.account_name || 'Unknown account'];
            if (item.is_transfer) pieces.push('Transfer');
            else if (item.category_name) pieces.push(item.category_name);
            meta.textContent = pieces.join(' · ');
            description.append(title, meta);
            const amount = document.createElement('span');
            amount.className = 'instant-transaction__amount ' + (item.amount < 0 ? 'instant-tone--negative' : 'instant-tone--positive');
            amount.textContent = preciseMoney.format(item.amount);
            const arrow = document.createElement('i');
            arrow.className = 'fas fa-chevron-right';
            arrow.setAttribute('aria-hidden', 'true');
            link.append(date, description, amount, arrow);
            container.appendChild(link);
        });
    }

    function render(data) {
        renderHeadline(data);
        renderTrend(data.trend || []);
        renderAttention(data.attention || []);
        renderCategories(data.top_categories || []);
        renderBudgets(data.budget || { total: 0, spent: 0, used: null, items: [] });
        renderAccounts(data.accounts || []);
        renderRecent(data.recent || []);
        setText('instant-refreshed', 'Updated ' + new Date(data.period.generated_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }));
    }

    async function loadSnapshot() {
        const refresh = byId('instant-refresh');
        const error = byId('instant-error');
        refresh.disabled = true;
        refresh.setAttribute('aria-busy', 'true');
        refresh.classList.add('is-loading');
        error.hidden = true;

        try {
            const response = await fetch('../php_backend/public/instant_dashboard.php', { cache: 'no-store' });
            if (!response.ok) {
                throw new Error('The snapshot request failed with status ' + response.status);
            }
            const data = await response.json();
            if (data.error) {
                throw new Error(data.error);
            }
            render(data);
        } catch (caught) {
            console.error('Instant dashboard load failed', caught);
            error.textContent = 'Instant could not load your financial snapshot. Refresh the page or try again in a moment.';
            error.hidden = false;
        } finally {
            refresh.disabled = false;
            refresh.removeAttribute('aria-busy');
            refresh.classList.remove('is-loading');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        byId('instant-refresh').addEventListener('click', loadSnapshot);
        loadSnapshot();
    });
})();

(function (root) {
    'use strict';

    const currency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });

    function finiteNumber(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    function normaliseSection(section) {
        const value = section && typeof section === 'object' ? section : {};
        return {
            results: Array.isArray(value.results) ? value.results.map(row => ({
                description: String(row.description || 'Unknown transaction'),
                day: Math.min(31, Math.max(1, Math.round(finiteNumber(row.day) || 1))),
                occurrences: Math.max(0, Math.round(finiteNumber(row.occurrences))),
                average: Math.abs(finiteNumber(row.average)),
                last_amount: Math.abs(finiteNumber(row.last_amount || row.average)),
                total: Math.abs(finiteNumber(row.total))
            })) : [],
            total: Math.abs(finiteNumber(value.total)),
            next_month: Math.abs(finiteNumber(value.next_month))
        };
    }

    function normaliseRecurringPayload(payload) {
        const value = payload && typeof payload === 'object' ? payload : {};
        return { outgoings: normaliseSection(value.outgoings), income: normaliseSection(value.income) };
    }

    function buildRecurringSummary(payload) {
        const data = normaliseRecurringPayload(payload);
        return {
            outgoingNext: data.outgoings.next_month,
            incomeNext: data.income.next_month,
            netNext: data.income.next_month - data.outgoings.next_month,
            patterns: data.outgoings.results.length + data.income.results.length,
            outgoingPatterns: data.outgoings.results.length,
            incomePatterns: data.income.results.length
        };
    }

    function ordinal(day) {
        const number = Math.min(31, Math.max(1, Math.round(finiteNumber(day) || 1)));
        const remainder = number % 100;
        if (remainder >= 11 && remainder <= 13) return `${number}th`;
        if (number % 10 === 1) return `${number}st`;
        if (number % 10 === 2) return `${number}nd`;
        if (number % 10 === 3) return `${number}rd`;
        return `${number}th`;
    }

    function formatSchedule(day) {
        return `Around the ${ordinal(day)}`;
    }

    function formatCurrency(value) {
        return currency.format(finiteNumber(value));
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { normaliseRecurringPayload, buildRecurringSummary, ordinal, formatSchedule, formatCurrency };
    }

    if (!root || !root.document) return;

    const document = root.document;
    const tableInstances = { outgoings: null, income: null };
    const runButton = document.getElementById('run-analysis');
    const retryButton = document.getElementById('recurring-retry');
    const statePanel = document.getElementById('recurring-state');
    const resultsPanel = document.getElementById('recurring-results');
    const summaryPanel = document.getElementById('recurring-summary');

    function setText(id, value) {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    }

    function setBusy(isBusy) {
        runButton.disabled = isBusy;
        runButton.classList.toggle('is-loading', isBusy);
        summaryPanel.setAttribute('aria-busy', String(isBusy));
        statePanel.setAttribute('aria-busy', String(isBusy));
    }

    function showState(type, title, message) {
        statePanel.hidden = false;
        statePanel.className = `recurring-state is-${type}`;
        statePanel.querySelector('.recurring-state__icon i').className = type === 'loading'
            ? 'fas fa-arrows-rotate'
            : 'fas fa-triangle-exclamation';
        setText('recurring-state-title', title);
        setText('recurring-state-message', message);
        retryButton.hidden = type !== 'error';
    }

    function hideState() {
        statePanel.hidden = true;
        statePanel.setAttribute('aria-busy', 'false');
    }

    async function requestAnalysis() {
        const response = await fetch('../php_backend/public/recurring_spend.php', { cache: 'no-store' });
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable response.');
        }
        if (!response.ok || payload.error) throw new Error(payload.error || 'Recurring analysis could not be completed.');
        return normaliseRecurringPayload(payload);
    }

    function descriptionFormatter(cell) {
        const wrapper = document.createElement('div');
        const icon = document.createElement('span');
        const copy = document.createElement('span');
        const title = document.createElement('strong');
        const detail = document.createElement('small');
        const row = cell.getRow().getData();
        wrapper.className = 'recurring-merchant';
        icon.className = 'recurring-merchant__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = String(row.description || '?').trim().charAt(0).toUpperCase() || '?';
        title.textContent = row.description;
        detail.textContent = `${row.occurrences} occurrence${row.occurrences === 1 ? '' : 's'} in the last year`;
        copy.append(title, detail);
        wrapper.append(icon, copy);
        return wrapper;
    }

    function scheduleFormatter(cell) {
        const value = document.createElement('span');
        value.className = 'recurring-schedule';
        value.textContent = formatSchedule(cell.getValue());
        return value;
    }

    function moneyFormatter(cell) {
        const value = document.createElement('span');
        value.className = 'recurring-money';
        value.textContent = formatCurrency(cell.getValue());
        return value;
    }

    function historyFormatter(cell) {
        const row = cell.getRow().getData();
        const link = document.createElement('a');
        const icon = document.createElement('i');
        link.className = 'recurring-history-link';
        link.href = `search.html?value=${encodeURIComponent(row.description)}`;
        link.setAttribute('aria-label', `View transaction history for ${row.description}`);
        link.append(document.createTextNode('History'));
        icon.className = 'fas fa-arrow-right';
        icon.setAttribute('aria-hidden', 'true');
        link.appendChild(icon);
        return link;
    }

    function tableColumns() {
        return [
            { title: 'Commitment', field: 'description', minWidth: 210, formatter: descriptionFormatter },
            { title: 'Usual timing', field: 'day', minWidth: 130, formatter: scheduleFormatter },
            { title: 'Latest amount', field: 'last_amount', hozAlign: 'right', minWidth: 120, sorter: 'number', formatter: moneyFormatter },
            { title: '12-month total', field: 'total', hozAlign: 'right', minWidth: 120, sorter: 'number', formatter: moneyFormatter },
            { title: '', field: 'history', hozAlign: 'right', headerSort: false, minWidth: 90, formatter: historyFormatter }
        ];
    }

    function renderTable(kind, rows) {
        const grid = document.getElementById(kind === 'outgoings' ? 'outgoing-grid' : 'income-grid');
        const empty = document.getElementById(kind === 'outgoings' ? 'outgoing-empty' : 'income-empty');
        if (tableInstances[kind] && typeof tableInstances[kind].destroy === 'function') tableInstances[kind].destroy();
        tableInstances[kind] = null;
        grid.replaceChildren();

        if (!rows.length) {
            grid.hidden = true;
            empty.hidden = false;
            return;
        }

        grid.hidden = false;
        empty.hidden = true;
        tableInstances[kind] = root.tailwindTabulator(grid, {
            data: rows,
            columns: tableColumns(),
            layout: 'fitColumns',
            initialSort: [{ column: 'total', dir: 'desc' }],
            searchFields: ['description'],
            modernLabel: kind === 'outgoings' ? 'Recurring outgoings' : 'Recurring income',
            modernMaxHeight: '32rem',
            pagination: rows.length > 30,
            paginationSize: 30
        });
    }

    function renderSummary(data) {
        const summary = buildRecurringSummary(data);
        setText('summary-outgoings', formatCurrency(summary.outgoingNext));
        setText('summary-income', formatCurrency(summary.incomeNext));
        setText('summary-net', formatCurrency(summary.netNext));
        setText('summary-patterns', String(summary.patterns));
        setText('summary-patterns-detail', `${summary.outgoingPatterns} outgoing · ${summary.incomePatterns} income`);
        document.getElementById('summary-net-card').classList.toggle('is-negative', summary.netNext < 0);
    }

    function renderSection(kind, section) {
        const prefix = kind === 'outgoings' ? 'outgoing' : 'income';
        setText(`${prefix}-count`, String(section.results.length));
        setText(`${prefix}-total`, formatCurrency(section.total));
        setText(`${prefix}-next`, formatCurrency(section.next_month));
        renderTable(kind, section.results);
    }

    function renderAnalysis(data) {
        renderSummary(data);
        renderSection('outgoings', data.outgoings);
        renderSection('income', data.income);
        resultsPanel.hidden = false;
        summaryPanel.setAttribute('aria-busy', 'false');
        setText('analysis-updated', `Updated ${new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date())}`);
    }

    async function loadAnalysis(announce) {
        setBusy(true);
        showState('loading', 'Analysing repeat transactions…', 'Reviewing the latest twelve months and excluding account transfers.');
        try {
            const data = await requestAnalysis();
            renderAnalysis(data);
            hideState();
            if (announce && typeof root.showMessage === 'function') root.showMessage('Recurring analysis refreshed.');
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Recurring analysis could not be completed.';
            showState('error', 'We could not load recurring patterns', message);
            if (announce && typeof root.showMessage === 'function') root.showMessage(message, 'error');
        } finally {
            setBusy(false);
        }
    }

    runButton.addEventListener('click', () => loadAnalysis(true));
    retryButton.addEventListener('click', () => loadAnalysis(true));
    loadAnalysis(false);
})(typeof window !== 'undefined' ? window : null);

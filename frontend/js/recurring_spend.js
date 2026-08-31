(function (root) {
    'use strict';

    const currency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });
    const RECURRING_PAGE_SIZE = 10;

    function finiteNumber(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    function normaliseSection(section) {
        const value = section && typeof section === 'object' ? section : {};
        return {
            results: Array.isArray(value.results) ? value.results.map(row => ({
                description: String(row.description || 'Unknown transaction'),
                search_term: String(row.search_term || row.description || ''),
                descriptions: Array.isArray(row.descriptions) ? row.descriptions.map(String) : [],
                frequency: String(row.frequency || 'monthly'),
                schedule: String(row.schedule || ''),
                day: Math.min(31, Math.max(1, Math.round(finiteNumber(row.day) || 1))),
                occurrences: Math.max(0, Math.round(finiteNumber(row.occurrences))),
                average: Math.abs(finiteNumber(row.average)),
                last_amount: Math.abs(finiteNumber(row.last_amount || row.average)),
                total: Math.abs(finiteNumber(row.total)),
                transaction_ids: Array.isArray(row.transaction_ids) ? row.transaction_ids.map(Number).filter(id => id > 0) : [],
                latest_transaction_id: Math.max(0, Math.round(finiteNumber(row.latest_transaction_id)))
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

    function buildRecurringSelectionSummary(items) {
        const summary = { count: 0, outgoings: 0, income: 0, net: 0 };
        (Array.isArray(items) ? items : []).forEach(item => {
            if (!item || (item.kind !== 'outgoings' && item.kind !== 'income')) return;
            const amount = Math.abs(finiteNumber(item.amount));
            summary.count += 1;
            summary[item.kind] += amount;
        });
        summary.net = summary.income - summary.outgoings;
        return summary;
    }

    function recurringTablePaging(rowCount) {
        const count = Math.max(0, Math.round(finiteNumber(rowCount)));
        return {
            pagination: count > RECURRING_PAGE_SIZE,
            paginationSize: RECURRING_PAGE_SIZE
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

    function formatSchedule(day, schedule) {
        return schedule ? String(schedule) : `Monthly · around the ${ordinal(day)}`;
    }

    function formatCurrency(value) {
        return currency.format(finiteNumber(value));
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            normaliseRecurringPayload,
            buildRecurringSummary,
            buildRecurringSelectionSummary,
            recurringTablePaging,
            ordinal,
            formatSchedule,
            formatCurrency
        };
    }

    if (!root || !root.document) return;

    const document = root.document;
    const tableInstances = { outgoings: null, income: null };
    const selectedPatterns = new Map();
    const runButton = document.getElementById('run-analysis');
    const retryButton = document.getElementById('recurring-retry');
    const clearSelectionButton = document.getElementById('clear-recurring-selection');
    const statePanel = document.getElementById('recurring-state');
    const resultsPanel = document.getElementById('recurring-results');
    const summaryPanel = document.getElementById('recurring-summary');
    const selectionPanel = document.getElementById('recurring-selection');

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

    function recurringPatternKey(kind, row) {
        return JSON.stringify([kind, String(row.description || ''), finiteNumber(row.day)]);
    }

    function updateSelectionSummary(announce) {
        const summary = buildRecurringSelectionSummary(Array.from(selectedPatterns.values()));
        const itemLabel = `${summary.count} item${summary.count === 1 ? '' : 's'}`;
        setText('selected-count', itemLabel);
        setText('selected-outgoings', formatCurrency(summary.outgoings));
        setText('selected-income', formatCurrency(summary.income));
        setText('selected-net', formatCurrency(summary.net));
        const selected=Array.from(selectedPatterns.values()),allIds=selected.map(item=>item.latest_transaction_id).filter(Boolean),outIds=selected.filter(item=>item.kind==='outgoings').map(item=>item.latest_transaction_id).filter(Boolean),inIds=selected.filter(item=>item.kind==='income').map(item=>item.latest_transaction_id).filter(Boolean);
        if(outIds.length)TransactionDrilldown.linkify('selected-outgoings',{transaction_ids:outIds,transfer_scope:'exclude',ignored_scope:'exclude',label:'Selected recurring outgoing transactions'},formatCurrency(summary.outgoings));
        if(inIds.length)TransactionDrilldown.linkify('selected-income',{transaction_ids:inIds,transfer_scope:'exclude',ignored_scope:'exclude',label:'Selected recurring income transactions'},formatCurrency(summary.income));
        if(allIds.length)TransactionDrilldown.linkify('selected-net',{transaction_ids:allIds,transfer_scope:'exclude',ignored_scope:'exclude',label:'Selected recurring net contributors'},formatCurrency(summary.net));
        clearSelectionButton.disabled = summary.count === 0;
        selectionPanel.classList.toggle('has-selection', summary.count > 0);
        document.getElementById('selected-net-card').classList.toggle('is-negative', summary.net < 0);
        if (announce !== false) {
            setText(
                'recurring-selection-announcement',
                summary.count === 0
                    ? 'No recurring patterns selected.'
                    : `${itemLabel} selected. Outgoings ${formatCurrency(summary.outgoings)}, income ${formatCurrency(summary.income)}, net ${formatCurrency(summary.net)}.`
            );
        }
    }

    function clearRecurringSelection(announce) {
        selectedPatterns.clear();
        document.querySelectorAll('.recurring-select-control input[type="checkbox"]').forEach(checkbox => {
            checkbox.checked = false;
            const row = checkbox.closest('.tabulator-row');
            if (row) row.classList.remove('is-running-total-selected');
        });
        updateSelectionSummary(announce);
    }

    function updatePatternSelection(kind, row, checked, rowElement) {
        const key = row.selection_key || recurringPatternKey(kind, row);
        if (checked) {
            selectedPatterns.set(key, { kind, amount: row.last_amount, latest_transaction_id:row.latest_transaction_id });
        } else {
            selectedPatterns.delete(key);
        }
        if (rowElement) rowElement.classList.toggle('is-running-total-selected', checked);
        updateSelectionSummary(true);
    }

    function descriptionFormatter(kind) {
        return function (cell) {
            const wrapper = document.createElement('div');
            const selection = document.createElement('label');
            const checkbox = document.createElement('input');
            const icon = document.createElement('span');
            const copy = document.createElement('span');
            const title = document.createElement('strong');
            const detail = document.createElement('small');
            const rowComponent = cell.getRow();
            const row = rowComponent.getData();
            const key = row.selection_key || recurringPatternKey(kind, row);
            wrapper.className = 'recurring-merchant';
            selection.className = 'recurring-select-control';
            checkbox.type = 'checkbox';
            checkbox.checked = selectedPatterns.has(key);
            checkbox.setAttribute('aria-label', `Include ${row.description} (${formatCurrency(row.last_amount)}) in the quick total`);
            checkbox.setAttribute('data-tooltip', 'Include in quick total');
            checkbox.addEventListener('change', () => {
                updatePatternSelection(kind, row, checkbox.checked, rowComponent.getElement());
            });
            selection.appendChild(checkbox);
            icon.className = 'recurring-merchant__icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = String(row.description || '?').trim().charAt(0).toUpperCase() || '?';
            title.textContent = row.description;
            const history=document.createElement('a');history.className='transaction-drilldown-link';history.href=TransactionDrilldown.url({transaction_ids:row.transaction_ids,direction:kind==='income'?'income':'spending',transfer_scope:'exclude',ignored_scope:'exclude',label:`${row.description} recurring history`});history.textContent=`${row.occurrences} occurrence${row.occurrences===1?'':'s'} in the last year`;history.setAttribute('aria-label',`View ${row.description} transaction history`);detail.appendChild(history);
            copy.append(title, detail);
            wrapper.append(selection, icon, copy);
            return wrapper;
        };
    }

    function scheduleFormatter(cell) {
        const value = document.createElement('span');
        const row = cell.getRow().getData();
        value.className = 'recurring-schedule';
        value.textContent = formatSchedule(cell.getValue(), row.schedule);
        return value;
    }

    function moneyFormatter(cell) {
        const value = document.createElement('span');
        value.className = 'recurring-money';
        if(cell.getField()==='total'){
            const row=cell.getRow().getData(),link=document.createElement('a');link.className='transaction-drilldown-link';link.href=TransactionDrilldown.url({transaction_ids:row.transaction_ids,transfer_scope:'exclude',ignored_scope:'exclude',label:`${row.description} 12-month total`});link.textContent=formatCurrency(cell.getValue());value.appendChild(link);
        }else value.textContent = formatCurrency(cell.getValue());
        return value;
    }

    function historyFormatter(cell) {
        const row = cell.getRow().getData();
        const link = document.createElement('a');
        const icon = document.createElement('i');
        link.className = 'recurring-history-link';
        link.href = TransactionDrilldown.url({transaction_ids:row.transaction_ids,transfer_scope:'exclude',ignored_scope:'exclude',label:`${row.description} recurring history`});
        link.setAttribute('aria-label', `View transaction history for ${row.description}`);
        link.append(document.createTextNode('History'));
        icon.className = 'fas fa-arrow-right';
        icon.setAttribute('aria-hidden', 'true');
        link.appendChild(icon);
        return link;
    }

    function tableColumns(kind) {
        return [
            { title: 'Pattern', field: 'description', minWidth: 250, formatter: descriptionFormatter(kind) },
            { title: 'Usual timing', field: 'day', minWidth: 130, formatter: scheduleFormatter },
            { title: 'Latest amount', field: 'last_amount', hozAlign: 'right', minWidth: 120, sorter: 'number', formatter: moneyFormatter },
            { title: '12-month total', field: 'total', hozAlign: 'right', minWidth: 120, sorter: 'number', formatter: moneyFormatter },
            { title: 'History', field: 'history', hozAlign: 'right', headerSort: false, minWidth: 90, formatter: historyFormatter }
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
        const preparedRows = rows.map(row => Object.assign({}, row, {
            selection_key: recurringPatternKey(kind, row)
        }));
        const paging = recurringTablePaging(rows.length);
        tableInstances[kind] = root.tailwindTabulator(grid, {
            data: preparedRows,
            columns: tableColumns(kind),
            layout: 'fitColumns',
            initialSort: [{ column: 'total', dir: 'desc' }],
            searchFields: ['description'],
            modernLabel: kind === 'outgoings' ? 'Recurring outgoings' : 'Recurring income',
            pagination: paging.pagination,
            paginationMode: 'local',
            paginationSize: paging.paginationSize,
            paginationCounter: 'rows',
            rowFormatter: row => {
                row.getElement().classList.toggle(
                    'is-running-total-selected',
                    selectedPatterns.has(row.getData().selection_key)
                );
            }
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
        const ids=section.results.flatMap(row=>row.transaction_ids||[]);if(ids.length&&ids.length<=250)TransactionDrilldown.linkify(`${prefix}-total`,{transaction_ids:ids,transfer_scope:'exclude',ignored_scope:'exclude',label:`Recurring ${kind} · trailing 12 months`},formatCurrency(section.total));
    }

    function renderAnalysis(data) {
        clearRecurringSelection(false);
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
    clearSelectionButton.addEventListener('click', () => clearRecurringSelection(true));
    updateSelectionSummary(false);
    loadAnalysis(false);
})(typeof window !== 'undefined' ? window : null);

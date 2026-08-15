// Monthly transaction ledger with explicit classification pills and responsive card rows.
(function () {
    'use strict';

    const pageMain = document.querySelector('main.ops-main');
    const monthSelect = document.getElementById('month');
    const yearSelect = document.getElementById('year');
    const form = document.getElementById('statement-form');
    const untaggedOnly = document.getElementById('untagged-only');
    const region = document.getElementById('transactions-grid');
    const status = document.getElementById('statement-table-status');
    const resultCount = document.getElementById('statement-result-count');
    const search = document.getElementById('statement-search');
    const sort = document.getElementById('statement-sort');
    const filters = Array.from(document.querySelectorAll('[data-statement-filter]'));
    const footer = document.getElementById('statement-table-footer');
    const pageStatus = document.getElementById('statement-page-status');
    const loadMore = document.getElementById('statement-load-more');
    const chartContainer = document.getElementById('category-donut');
    const PAGE_SIZE = 50;
    const currency = new Intl.NumberFormat('en-GB', { style:'currency', currency:'GBP' });
    const integer = new Intl.NumberFormat('en-GB');
    const longMonth = new Intl.DateTimeFormat('en-GB', { month:'long' });
    const shortMonth = new Intl.DateTimeFormat('en-GB', { month:'short' });
    const state = { transactions:[], groups:[], query:'', filter:'all', category:null, sort:'date-desc', limit:PAGE_SIZE, chart:null };
    let loadSequence = 0;
    let highchartsPromise = null;
    const urlParams = new URLSearchParams(window.location.search);
    const paramYear = urlParams.get('year');
    const paramMonth = urlParams.get('month');

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function numberValue(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function isTransfer(transaction) {
        return transaction.transfer_id !== null && transaction.transfer_id !== '' && transaction.transfer_id !== undefined;
    }

    function needsClassification(transaction) {
        return !isTransfer(transaction) && (!transaction.category_name || !transaction.tag_name || !transaction.segment_name);
    }

    function escapeMarkup(value) {
        return String(value).replace(/[&<>"']/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[character]));
    }

    async function requestJson(url, options) {
        const response = await fetch(url, Object.assign({ cache:'no-store' }, options || {}));
        if (!response.ok) throw new Error('A data request could not be completed.');
        return response.json();
    }

    function afterNextPaint() {
        return new Promise(resolve => window.requestAnimationFrame(() => resolve()));
    }

    function loadHighcharts() {
        if (window.Highcharts) return Promise.resolve(window.Highcharts);
        if (highchartsPromise) return highchartsPromise;
        highchartsPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://code.highcharts.com/highcharts.js';
            script.async = true;
            script.onload = () => {
                if (window.applyChartTheme) window.applyChartTheme();
                resolve(window.Highcharts);
            };
            script.onerror = () => reject(new Error('The spending chart library could not be loaded.'));
            document.head.appendChild(script);
        });
        return highchartsPromise;
    }

    function setLines(id, lines) {
        const target = document.getElementById(id);
        target.replaceChildren();
        lines.forEach((line, index) => {
            if (index) target.appendChild(document.createElement('br'));
            target.appendChild(document.createTextNode(line));
        });
    }

    function normalise(transaction) {
        return {
            id:Number(transaction.id),
            account_id:Number(transaction.account_id),
            date:String(transaction.date || ''),
            amount:numberValue(transaction.amount),
            description:String(transaction.description || 'Unnamed transaction'),
            memo:String(transaction.memo || ''),
            category_name:transaction.category_name ? String(transaction.category_name) : '',
            tag_name:transaction.tag_name ? String(transaction.tag_name) : '',
            segment_name:transaction.segment_name ? String(transaction.segment_name) : '',
            group_id:transaction.group_id ? String(transaction.group_id) : '',
            group_name:transaction.group_name ? String(transaction.group_name) : '',
            transfer_id:transaction.transfer_id === null ? null : transaction.transfer_id
        };
    }

    const groupsPromise = requestJson('../php_backend/public/groups.php').then(rows => Array.isArray(rows) ? rows : []).catch(() => []);

    async function loadRecurringDescriptions() {
        try {
            const data = await requestJson('../php_backend/public/recurring_spend.php');
            const recurring = new Set();
            const rows = []
                .concat(data && data.outgoings && Array.isArray(data.outgoings.results) ? data.outgoings.results : [])
                .concat(data && data.income && Array.isArray(data.income.results) ? data.income.results : [])
                .concat(data && Array.isArray(data.results) ? data.results : []);
            rows.forEach(item => {
                if (item.description) recurring.add(String(item.description).toLowerCase());
            });
            return recurring;
        } catch (error) {
            return new Set();
        }
    }

    async function loadTotalBalance() {
        try {
            const rows = await requestJson('../php_backend/public/account_dashboard.php');
            return Array.isArray(rows) ? rows.reduce((sum, row) => sum + numberValue(row.balance), 0) : 0;
        } catch (error) {
            return 0;
        }
    }

    function renderMetrics(data, previous, recurringSet, totalBalance, year, month) {
        let income = 0;
        let outgoings = 0;
        let incomeCount = 0;
        let expenseCount = 0;
        let recurringTotal = 0;
        let oneOffTotal = 0;
        const spending = {};
        const previousSpending = {};

        data.forEach(transaction => {
            if (isTransfer(transaction)) return;
            const category = transaction.category_name || 'Uncategorised';
            if (transaction.amount > 0) {
                income += transaction.amount;
                incomeCount += 1;
            } else if (transaction.amount < 0) {
                const expense = -transaction.amount;
                outgoings += expense;
                expenseCount += 1;
                spending[category] = (spending[category] || 0) + expense;
                if (recurringSet.has(transaction.description.toLowerCase())) recurringTotal += expense;
                else oneOffTotal += expense;
            }
        });
        previous.forEach(transaction => {
            if (!isTransfer(transaction) && transaction.amount < 0) {
                const category = transaction.category_name || 'Uncategorised';
                previousSpending[category] = (previousSpending[category] || 0) + (-transaction.amount);
            }
        });

        const delta = income - outgoings;
        document.getElementById('income-total').textContent = currency.format(income);
        document.getElementById('outgoings-total').textContent = currency.format(outgoings);
        const deltaTarget = document.getElementById('delta-total');
        deltaTarget.textContent = currency.format(delta);
        deltaTarget.classList.toggle('text-green-600', delta >= 0);
        deltaTarget.classList.toggle('text-red-600', delta < 0);
        document.getElementById('savings-rate').textContent = `${(income > 0 ? delta / income * 100 : 0).toFixed(1)}%`;
        const categories = Object.entries(spending).sort((left, right) => right[1] - left[1]);
        document.getElementById('largest-category').textContent = categories.length ? categories[0][0] : 'N/A';
        const recurringPercent = outgoings > 0 ? recurringTotal / outgoings * 100 : 0;
        const oneOffPercent = outgoings > 0 ? oneOffTotal / outgoings * 100 : 0;
        setLines('recurring-ratio', [
            `Recurring: ${currency.format(recurringTotal)} (${recurringPercent.toFixed(1)}%)`,
            `One-off: ${currency.format(oneOffTotal)} (${oneOffPercent.toFixed(1)}%)`
        ]);
        setLines('avg-transaction', [
            `Income: ${currency.format(incomeCount ? income / incomeCount : 0)}`,
            `Expenses: ${currency.format(expenseCount ? outgoings / expenseCount : 0)}`
        ]);
        const daysInMonth = new Date(year, month, 0).getDate();
        const burnRate = (outgoings - income) / daysInMonth;
        document.getElementById('days-negative').textContent = burnRate > 0 && totalBalance > 0 ? integer.format(Math.floor(totalBalance / burnRate)) : 'N/A';
        if (window.Highcharts) renderChart(spending, previousSpending);
        return { spending, previousSpending };
    }

    function setCellLabel(cell, label) {
        cell.dataset.label = label;
        return cell;
    }

    function classificationPill(kind, value, missingLabel, iconName) {
        const present = Boolean(value);
        const pill = element(present ? 'a' : 'span', `statement-pill statement-pill--${kind}${present ? '' : ' is-missing'}`);
        if (present) pill.href = `search.html?value=${encodeURIComponent(value)}`;
        pill.setAttribute('aria-label', `${kind}: ${present ? value : missingLabel}`);
        const icon = element('i', `fas ${iconName}`);
        icon.setAttribute('aria-hidden', 'true');
        pill.title = `${kind.charAt(0).toUpperCase() + kind.slice(1)}: ${present ? value : missingLabel}`;
        pill.append(icon, element('span', 'statement-pill-value', present ? value : 'Unassigned'));
        return pill;
    }

    async function updateGroup(transaction, select, previousId) {
        select.disabled = true;
        const nextId = select.value;
        try {
            await requestJson('../php_backend/public/update_transaction.php', {
                method:'POST',
                headers:{ 'Content-Type':'application/json' },
                body:JSON.stringify({
                    transaction_id:transaction.id,
                    account_id:transaction.account_id,
                    description:transaction.description,
                    group_id:nextId === '' ? '' : Number(nextId)
                })
            });
            const selected = select.options[select.selectedIndex];
            transaction.group_id = nextId;
            transaction.group_name = nextId ? selected.textContent : '';
            select.closest('.statement-group-editor').classList.toggle('is-missing', !nextId);
            showToast(nextId ? `Group changed to ${transaction.group_name}.` : 'Group removed.', 'success');
        } catch (error) {
            select.value = previousId;
            showToast('The group could not be updated.', 'error');
        } finally {
            select.disabled = false;
        }
    }

    function groupEditor(transaction) {
        const wrapper = element('label', `statement-pill statement-pill--group statement-group-editor${transaction.group_id ? '' : ' is-missing'}`);
        const icon = element('i', 'fas fa-layer-group');
        icon.setAttribute('aria-hidden', 'true');
        wrapper.appendChild(icon);
        const select = element('select', 'unstyled statement-group-select');
        select.setAttribute('aria-label', `Group for ${transaction.description}`);
        select.appendChild(new Option('Unassigned', ''));
        state.groups.filter(group => Boolean(Number(group.active))).forEach(group => select.appendChild(new Option(group.name, String(group.id))));
        if (transaction.group_id && !Array.from(select.options).some(option => option.value === transaction.group_id)) {
            select.appendChild(new Option(transaction.group_name || 'Current group', transaction.group_id));
        }
        select.value = transaction.group_id;
        select.addEventListener('change', () => updateGroup(transaction, select, transaction.group_id));
        wrapper.appendChild(select);
        return wrapper;
    }

    function classificationCell(transaction) {
        const cell = setCellLabel(element('td', 'statement-classification-cell'), 'Classification');
        const pills = element('div', 'statement-pills');
        if (isTransfer(transaction)) pills.appendChild(classificationPill('transfer', 'Transfer', 'Transfer', 'fa-right-left'));
        pills.append(
            classificationPill('segment', transaction.segment_name, 'Unsegmented', 'fa-chart-pie'),
            classificationPill('category', transaction.category_name, 'Uncategorised', 'fa-folder'),
            classificationPill('tag', transaction.tag_name, 'Untagged', 'fa-tag'),
            groupEditor(transaction)
        );
        cell.appendChild(pills);
        return cell;
    }

    function dateCell(transaction) {
        const cell = setCellLabel(element('td', 'statement-date-cell'), 'Date');
        const parsed = new Date(`${transaction.date}T00:00:00`);
        const time = element('time', 'statement-date');
        time.dateTime = transaction.date;
        if (Number.isNaN(parsed.getTime())) time.textContent = transaction.date;
        else {
            time.append(element('strong', '', String(parsed.getDate())), element('span', '', shortMonth.format(parsed)), element('small', '', String(parsed.getFullYear())));
        }
        cell.appendChild(time);
        return cell;
    }

    function transactionCell(transaction) {
        const cell = setCellLabel(element('td', 'statement-transaction-cell'), 'Transaction');
        const identity = element('div', 'statement-transaction');
        const marker = element('span', `statement-transaction-icon ${transaction.amount >= 0 ? 'is-income' : 'is-spending'}`);
        const markerIcon = element('i', `fas ${isTransfer(transaction) ? 'fa-right-left' : transaction.amount >= 0 ? 'fa-arrow-down' : 'fa-arrow-up'}`);
        markerIcon.setAttribute('aria-hidden', 'true');
        marker.appendChild(markerIcon);
        const copy = element('span', 'statement-transaction-copy');
        const link = element('a', 'statement-description', transaction.description);
        link.href = `transaction.html?id=${encodeURIComponent(transaction.id)}`;
        copy.appendChild(link);
        if (transaction.memo) copy.appendChild(element('span', 'statement-memo', transaction.memo));
        identity.append(marker, copy);
        cell.appendChild(identity);
        return cell;
    }

    function amountCell(transaction) {
        const cell = setCellLabel(element('td', `statement-amount-cell ${transaction.amount < 0 ? 'is-negative' : 'is-positive'}`), 'Amount');
        const value = element('strong', 'statement-amount', currency.format(transaction.amount));
        let label = transaction.amount >= 0 ? 'Income' : 'Spending';
        if (isTransfer(transaction)) label = 'Transfer';
        cell.append(value, element('span', 'statement-amount-kind', label));
        return cell;
    }

    function headerCell(label, field, align) {
        const th = element('th', align ? `is-${align}` : '');
        th.scope = 'col';
        const active = state.sort.indexOf(`${field}-`) === 0;
        th.setAttribute('aria-sort', active ? (state.sort.endsWith('-asc') ? 'ascending' : 'descending') : 'none');
        const button = element('button', 'statement-sort-button', label);
        button.type = 'button';
        const icon = element('i', `fas ${active ? (state.sort.endsWith('-asc') ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort'}`);
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);
        button.addEventListener('click', () => {
            const defaultDirection = field === 'date' || field === 'amount' ? 'desc' : 'asc';
            state.sort = active ? `${field}-${state.sort.endsWith('-asc') ? 'desc' : 'asc'}` : `${field}-${defaultDirection}`;
            sort.value = state.sort;
            state.limit = PAGE_SIZE;
            renderTable();
        });
        th.appendChild(button);
        return th;
    }

    function matchesFilter(transaction) {
        if (state.category !== null) return !isTransfer(transaction) && (transaction.category_name || 'Uncategorised') === state.category;
        if (state.filter === 'income') return transaction.amount > 0 && !isTransfer(transaction);
        if (state.filter === 'spending') return transaction.amount < 0 && !isTransfer(transaction);
        if (state.filter === 'needs-classification') return needsClassification(transaction);
        if (state.filter === 'transfers') return isTransfer(transaction);
        return true;
    }

    function visibleTransactions() {
        const query = state.query.toLowerCase();
        const rows = state.transactions.filter(transaction => {
            if (!matchesFilter(transaction)) return false;
            return !query || [transaction.date, transaction.description, transaction.memo, transaction.category_name, transaction.tag_name, transaction.segment_name, transaction.group_name, transaction.amount]
                .some(value => String(value || '').toLowerCase().includes(query));
        });
        const splitAt = state.sort.lastIndexOf('-');
        const field = state.sort.slice(0, splitAt);
        const direction = state.sort.slice(splitAt + 1);
        rows.sort((left, right) => {
            const a = left[field];
            const b = right[field];
            let result = typeof a === 'string' ? a.localeCompare(String(b), undefined, { sensitivity:'base' }) : numberValue(a) - numberValue(b);
            if (result === 0) result = left.id - right.id;
            return direction === 'desc' ? -result : result;
        });
        return rows;
    }

    function emptyState() {
        const empty = element('div', 'statement-empty');
        const icon = element('span', 'statement-empty-icon');
        const iconEl = element('i', 'fas fa-receipt');
        iconEl.setAttribute('aria-hidden', 'true');
        icon.appendChild(iconEl);
        empty.append(icon, element('h3', '', 'No matching transactions'), element('p', '', 'Try another search or classification filter.'));
        const reset = element('button', 'statement-empty-reset', 'Clear table filters');
        reset.type = 'button';
        reset.addEventListener('click', () => {
            search.value = '';
            state.query = '';
            setFilter('all', null, false);
        });
        empty.appendChild(reset);
        return empty;
    }

    function renderTable() {
        const filtered = visibleTransactions();
        const visible = filtered.slice(0, state.limit);
        resultCount.textContent = state.category ? `${integer.format(filtered.length)} in ${state.category}` : `${integer.format(filtered.length)} shown`;
        status.hidden = true;
        region.hidden = false;
        if (!filtered.length) {
            region.replaceChildren(emptyState());
            footer.hidden = true;
            return;
        }

        const table = element('table', 'statement-table');
        table.appendChild(element('caption', 'sr-only', 'Monthly transactions with segment, category, tag and group classifications'));
        const head = element('thead');
        const headerRow = element('tr');
        headerRow.append(
            headerCell('Date', 'date'),
            headerCell('Transaction', 'description'),
            element('th', '', 'Classification'),
            headerCell('Amount', 'amount', 'right'),
            element('th', 'statement-open-heading', 'Open')
        );
        Array.from(headerRow.children).forEach(cell => { if (!cell.scope) cell.scope = 'col'; });
        head.appendChild(headerRow);
        const body = element('tbody');
        visible.forEach(transaction => {
            const row = element('tr', needsClassification(transaction) ? 'needs-classification' : '');
            row.append(dateCell(transaction), transactionCell(transaction), classificationCell(transaction), amountCell(transaction));
            const openCell = setCellLabel(element('td', 'statement-open-cell'), 'Open transaction');
            const open = element('a', 'statement-open-link');
            open.href = `transaction.html?id=${encodeURIComponent(transaction.id)}`;
            open.setAttribute('aria-label', `Open ${transaction.description}`);
            const arrow = element('i', 'fas fa-arrow-right');
            arrow.setAttribute('aria-hidden', 'true');
            open.appendChild(arrow);
            openCell.appendChild(open);
            row.appendChild(openCell);
            body.appendChild(row);
        });
        table.append(head, body);
        region.replaceChildren(table);
        footer.hidden = false;
        pageStatus.textContent = `Showing ${integer.format(visible.length)} of ${integer.format(filtered.length)}`;
        const remaining = filtered.length - visible.length;
        loadMore.hidden = remaining <= 0;
        if (remaining > 0) loadMore.textContent = `Show ${integer.format(Math.min(PAGE_SIZE, remaining))} more`;
    }

    function setFilter(filter, category, scroll) {
        state.filter = filter;
        state.category = category;
        state.limit = PAGE_SIZE;
        filters.forEach(button => {
            const active = category === null && button.dataset.statementFilter === filter;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        renderTable();
        if (scroll !== false) document.querySelector('.monthly-statement').scrollIntoView({ behavior:'smooth', block:'start' });
    }

    function showToast(message, tone) {
        const existing = document.querySelector('.statement-toast');
        if (existing) existing.remove();
        const toast = element('div', `statement-toast statement-toast--${tone}`, message);
        toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        document.body.appendChild(toast);
        window.setTimeout(() => toast.remove(), 2800);
    }

    function renderChart(spending, previousSpending) {
        if (!window.Highcharts || !chartContainer) return;
        if (state.chart) state.chart.destroy();
        const data = Object.entries(spending).map(([category, total]) => ({
            name:category,
            y:Number(total.toFixed(2)),
            color:window.getCategoryColor ? getCategoryColor(category) : undefined,
            change:total - (previousSpending[category] || 0)
        })).sort((left, right) => right.y - left.y);
        if (!data.length) {
            chartContainer.replaceChildren(element('div', 'statement-chart-empty', 'No spending to chart for this selection.'));
            return;
        }
        state.chart = Highcharts.chart(chartContainer, {
            chart:{ type:'pie', height:420, backgroundColor:'transparent' },
            title:{ text:null },
            credits:{ enabled:false },
            legend:{ enabled:true, align:'right', verticalAlign:'middle', layout:'vertical', itemMarginBottom:6, itemStyle:{ color:'#475569', fontSize:'10px', fontWeight:'700' } },
            plotOptions:{ pie:{ innerSize:'64%', borderWidth:3, borderColor:'rgba(255,255,255,.9)', cursor:'pointer', dataLabels:{ enabled:false }, showInLegend:true, point:{ events:{ click:function () { setFilter('category', this.name, true); } } } } },
            tooltip:{ formatter:function () {
                const sign = this.point.change >= 0 ? '+' : '−';
                return `<b>${escapeMarkup(this.point.name)}</b><br>Spend: ${currency.format(this.y)}<br>Month change: ${sign}${currency.format(Math.abs(this.point.change))}<br>Share: ${Highcharts.numberFormat(this.percentage, 1)}%`;
            } },
            series:[{ name:'Spending', data }]
        });
    }

    function showLoading() {
        status.hidden = false;
        status.className = 'statement-table-status';
        const spinner = element('span', 'statement-spinner');
        spinner.setAttribute('aria-hidden', 'true');
        status.replaceChildren(spinner, document.createTextNode('Loading monthly activity'));
        region.hidden = true;
        footer.hidden = true;
        resultCount.textContent = 'Loading';
    }

    function showError(error) {
        status.hidden = false;
        status.className = 'statement-table-status statement-table-status--error';
        status.replaceChildren(element('strong', '', 'The statement could not be loaded'), element('span', '', error.message || 'Please try again.'));
        region.hidden = true;
        footer.hidden = true;
        resultCount.textContent = 'Unavailable';
    }

    async function loadTransactions() {
        const sequence = ++loadSequence;
        const month = Number(monthSelect.value);
        const year = Number(yearSelect.value);
        if (!month || !year) return;
        const selectedDate = new Date(year, month - 1, 1);
        const title = `Monthly Statement — ${longMonth.format(selectedDate)} ${year}`;
        window.updatePageHeader(pageMain, { title });
        document.title = title;
        showLoading();
        const previousDate = new Date(year, month - 2, 1);
        const untagged = untaggedOnly.checked ? '&untagged=1' : '';
        try {
            const [groups, currentRaw] = await Promise.all([
                groupsPromise,
                requestJson(`../php_backend/public/transactions.php?month=${month}&year=${year}${untagged}`)
            ]);
            if (sequence !== loadSequence) return;
            if (!Array.isArray(currentRaw)) throw new Error('The statement data was not in the expected format.');
            state.groups = groups;
            state.transactions = currentRaw.map(normalise);
            state.limit = PAGE_SIZE;
            state.filter = 'all';
            state.category = null;
            filters.forEach(button => {
                const active = button.dataset.statementFilter === 'all';
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            renderTable();
            let chartData = renderMetrics(state.transactions, [], new Set(), 0, year, month);

            // Give the browser a chance to paint the ledger before secondary
            // analysis or the optional chart library does additional work.
            await afterNextPaint();
            if (sequence !== loadSequence) return;
            const highchartsReady = loadHighcharts().catch(() => null);
            const previousPromise = requestJson(`../php_backend/public/transactions.php?month=${previousDate.getMonth() + 1}&year=${previousDate.getFullYear()}${untagged}`)
                .then(rows => Array.isArray(rows) ? rows : [])
                .catch(() => []);
            const [recurring, balance, previousRaw] = await Promise.all([
                loadRecurringDescriptions(),
                loadTotalBalance(),
                previousPromise
            ]);
            if (sequence !== loadSequence) return;
            chartData = renderMetrics(state.transactions, previousRaw.map(normalise), recurring, balance, year, month);
            highchartsReady.then(highcharts => {
                if (highcharts && sequence === loadSequence) renderChart(chartData.spending, chartData.previousSpending);
            });
        } catch (error) {
            if (sequence !== loadSequence) return;
            showError(error);
        }
    }

    function populateMonthOptions(monthsByYear, year) {
        monthSelect.replaceChildren();
        (monthsByYear[year] || []).forEach(month => {
            const option = new Option(longMonth.format(new Date(Number(year), Number(month) - 1, 1)), String(month));
            monthSelect.appendChild(option);
        });
    }

    function fallbackMonths() {
        const now = new Date();
        const monthsByYear = {};
        for (let year = now.getFullYear(); year >= now.getFullYear() - 5; year -= 1) monthsByYear[year] = Array.from({ length:12 }, (_, index) => index + 1);
        return monthsByYear;
    }

    async function initialiseMonths() {
        let monthsByYear = {};
        try {
            const rows = await requestJson('../php_backend/public/transaction_months.php');
            if (!Array.isArray(rows) || !rows.length) throw new Error('No statement months');
            rows.forEach(row => {
                const year = String(row.year);
                const month = Number(row.month);
                if (!monthsByYear[year]) monthsByYear[year] = [];
                if (!monthsByYear[year].includes(month)) monthsByYear[year].push(month);
            });
            Object.values(monthsByYear).forEach(months => months.sort((left, right) => right - left));
        } catch (error) {
            monthsByYear = fallbackMonths();
        }
        const years = Object.keys(monthsByYear).sort((left, right) => Number(right) - Number(left));
        yearSelect.replaceChildren(...years.map(year => new Option(year, year)));
        const initialYear = paramYear && monthsByYear[paramYear] ? paramYear : years[0];
        yearSelect.value = initialYear;
        populateMonthOptions(monthsByYear, initialYear);
        const requestedMonth = Number(paramMonth);
        if (requestedMonth && monthsByYear[initialYear].includes(requestedMonth)) monthSelect.value = String(requestedMonth);
        yearSelect.addEventListener('change', () => populateMonthOptions(monthsByYear, yearSelect.value));
        loadTransactions();
    }

    search.addEventListener('input', () => {
        state.query = search.value.trim();
        state.limit = PAGE_SIZE;
        renderTable();
    });
    sort.addEventListener('change', () => {
        state.sort = sort.value;
        state.limit = PAGE_SIZE;
        renderTable();
    });
    filters.forEach(button => button.addEventListener('click', () => setFilter(button.dataset.statementFilter, null, false)));
    loadMore.addEventListener('click', () => {
        state.limit += PAGE_SIZE;
        renderTable();
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        loadTransactions();
    });
    untaggedOnly.addEventListener('change', () => loadTransactions());
    [['income-card','income'], ['outgoings-card','spending']].forEach(([id, filter]) => {
        const card = document.getElementById(id);
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-label', `Show ${filter} transactions`);
        card.addEventListener('click', () => setFilter(filter, null, true));
        card.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                setFilter(filter, null, true);
            }
        });
    });

    initialiseMonths();
})();

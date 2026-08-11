// Modern account portfolio table: semantic, responsive, searchable, and safely editable.
(function () {
    'use strict';

    const summary = document.getElementById('account-summary');
    const region = document.getElementById('accounts-table');
    const status = document.getElementById('account-table-status');
    const count = document.getElementById('account-result-count');
    const search = document.getElementById('account-search');
    const sort = document.getElementById('account-sort');
    const filters = Array.from(document.querySelectorAll('[data-account-filter]'));
    const chart = document.getElementById('accounts-chart');
    const currency = new Intl.NumberFormat('en-GB', { style:'currency', currency:'GBP' });
    const integer = new Intl.NumberFormat('en-GB');
    const date = new Intl.DateTimeFormat('en-GB', { day:'numeric', month:'short', year:'numeric' });
    const state = { accounts:[], query:'', type:'all', sort:'name-asc' };

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

    function escapeMarkup(value) {
        return String(value).replace(/[&<>"']/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[character]));
    }

    function isCredit(account) {
        return Number(account.is_credit_card) === 1;
    }

    function initials(name) {
        return String(name || 'Account').trim().split(/\s+/).slice(0, 2).map(part => part.charAt(0)).join('').toUpperCase();
    }

    function normalise(raw) {
        return {
            id:Number(raw.id),
            name:String(raw.name || 'Unnamed account'),
            sort_code:String(raw.sort_code || ''),
            account_number:String(raw.account_number || ''),
            is_credit_card:Number(raw.is_credit_card) === 1 ? 1 : 0,
            transactions:numberValue(raw.transactions),
            balance:numberValue(raw.balance),
            last_transaction:raw.last_transaction ? String(raw.last_transaction) : ''
        };
    }

    function activity(account) {
        if (!account.last_transaction) return { label:'No activity yet', detail:'No transactions', tone:'quiet', timestamp:0 };
        const parsed = new Date(account.last_transaction + 'T00:00:00');
        if (Number.isNaN(parsed.getTime())) return { label:account.last_transaction, detail:'Activity recorded', tone:'quiet', timestamp:0 };
        const days = Math.max(0, Math.floor((Date.now() - parsed.getTime()) / 86400000));
        if (days <= 7) return { label:date.format(parsed), detail:days === 0 ? 'Updated today' : `Updated ${days}d ago`, tone:'fresh', timestamp:parsed.getTime() };
        if (days <= 30) return { label:date.format(parsed), detail:`Updated ${days}d ago`, tone:'steady', timestamp:parsed.getTime() };
        return { label:date.format(parsed), detail:`No activity for ${days}d`, tone:'stale', timestamp:parsed.getTime() };
    }

    function metric(icon, label, value, helper, tone) {
        const card = element('article', `account-summary-card account-summary-card--${tone}`);
        const top = element('div', 'account-summary-top');
        const iconWrap = element('span', 'account-summary-icon');
        const iconEl = element('i', `fas ${icon}`);
        iconEl.setAttribute('aria-hidden', 'true');
        iconWrap.appendChild(iconEl);
        top.append(iconWrap, element('span', 'account-summary-label', label));
        card.append(top, element('strong', 'account-summary-value', value), element('p', 'account-summary-helper', helper));
        return card;
    }

    function renderSummary() {
        const net = state.accounts.reduce((total, account) => total + account.balance, 0);
        const bankCount = state.accounts.filter(account => !isCredit(account)).length;
        const creditExposure = state.accounts.filter(isCredit).reduce((total, account) => total + Math.max(0, -account.balance), 0);
        const transactions = state.accounts.reduce((total, account) => total + account.transactions, 0);
        summary.replaceChildren(
            metric('fa-wallet', 'Net position', currency.format(net), 'Across every connected account', net < 0 ? 'rose' : 'indigo'),
            metric('fa-building-columns', 'Bank accounts', integer.format(bankCount), `${integer.format(state.accounts.length)} account${state.accounts.length === 1 ? '' : 's'} in total`, 'cyan'),
            metric('fa-credit-card', 'Credit exposure', currency.format(creditExposure), creditExposure ? 'Outstanding card balances' : 'No card balance outstanding', 'rose'),
            metric('fa-arrow-trend-up', 'Transactions tracked', integer.format(transactions), 'Ignored activity excluded', 'emerald')
        );
    }

    function filteredAccounts() {
        const query = state.query.toLowerCase();
        const rows = state.accounts.filter(account => {
            const typeMatch = state.type === 'all' || (state.type === 'credit' ? isCredit(account) : !isCredit(account));
            const searchMatch = !query || [account.name, account.sort_code, account.account_number].some(value => value.toLowerCase().includes(query));
            return typeMatch && searchMatch;
        });
        const [field, direction] = state.sort.split('-');
        rows.sort((left, right) => {
            let a = left[field];
            let b = right[field];
            if (field === 'last_transaction') {
                a = activity(left).timestamp;
                b = activity(right).timestamp;
            }
            const result = typeof a === 'string' ? a.localeCompare(String(b), undefined, { sensitivity:'base' }) : numberValue(a) - numberValue(b);
            return direction === 'desc' ? -result : result;
        });
        return rows;
    }

    function setCellLabel(cell, label) {
        cell.dataset.label = label;
        return cell;
    }

    function identifierCell(account) {
        const cell = setCellLabel(element('td', 'account-identifier'), 'Identifier');
        const primary = account.account_number || 'Not supplied';
        cell.appendChild(element('span', 'account-identifier-number', primary));
        cell.appendChild(element('span', 'account-identifier-sort', account.sort_code ? `Sort ${account.sort_code}` : 'Card account'));
        return cell;
    }

    function beginRename(account, cell) {
        const form = element('form', 'account-rename-form');
        const input = element('input', 'account-rename-input');
        input.type = 'text';
        input.value = account.name;
        input.maxLength = 120;
        input.setAttribute('aria-label', `New name for ${account.name}`);
        const actions = element('span', 'account-rename-actions');
        const save = element('button', 'account-rename-save', 'Save');
        save.type = 'submit';
        const cancel = element('button', 'account-rename-cancel', 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', () => renderTable());
        actions.append(save, cancel);
        form.append(input, actions);
        form.addEventListener('click', event => event.stopPropagation());
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const nextName = input.value.trim();
            if (!nextName || nextName === account.name) {
                renderTable();
                return;
            }
            input.disabled = true;
            save.disabled = true;
            save.textContent = 'Saving…';
            try {
                const response = await fetch('../php_backend/public/update_account.php', {
                    method:'POST',
                    headers:{ 'Content-Type':'application/json' },
                    body:JSON.stringify({ account_id:account.id, name:nextName })
                });
                if (!response.ok) throw new Error('The account name could not be saved.');
                account.name = nextName;
                renderTable();
                renderChart();
                status.hidden = false;
                status.className = 'account-table-status account-table-status--success';
                status.textContent = `Renamed account to ${nextName}.`;
                window.setTimeout(() => { status.hidden = true; }, 2600);
            } catch (error) {
                input.disabled = false;
                save.disabled = false;
                save.textContent = 'Save';
                const message = element('span', 'account-rename-error', error.message);
                form.appendChild(message);
            }
        });
        cell.replaceChildren(form);
        input.focus();
        input.select();
    }

    function identityCell(account) {
        const cell = setCellLabel(element('td', 'account-identity-cell'), 'Account');
        const wrap = element('div', 'account-identity');
        const avatar = element('span', `account-avatar ${isCredit(account) ? 'account-avatar--credit' : ''}`, initials(account.name));
        const copy = element('span', 'account-identity-copy');
        const link = element('a', 'account-name', account.name);
        link.href = `account.html?id=${encodeURIComponent(account.id)}`;
        const meta = element('span', 'account-type');
        const icon = element('i', `fas ${isCredit(account) ? 'fa-credit-card' : 'fa-building-columns'}`);
        icon.setAttribute('aria-hidden', 'true');
        meta.append(icon, document.createTextNode(isCredit(account) ? ' Credit card' : ' Bank account'));
        copy.append(link, meta);
        const rename = element('button', 'account-rename-button');
        rename.type = 'button';
        rename.setAttribute('aria-label', `Rename ${account.name}`);
        rename.setAttribute('data-tooltip', `Rename ${account.name}`);
        const pencil = element('i', 'fas fa-pen');
        pencil.setAttribute('aria-hidden', 'true');
        rename.appendChild(pencil);
        rename.addEventListener('click', () => beginRename(account, cell));
        wrap.append(avatar, copy, rename);
        cell.appendChild(wrap);
        return cell;
    }

    function activityCell(account) {
        const details = activity(account);
        const cell = setCellLabel(element('td', 'account-activity'), 'Last activity');
        const primary = element('span', 'account-activity-date', details.label);
        const secondary = element('span', `account-activity-state account-activity-state--${details.tone}`, details.detail);
        cell.append(primary, secondary);
        return cell;
    }

    function balanceCell(account, maximum) {
        const cell = setCellLabel(element('td', `account-balance ${account.balance < 0 ? 'is-negative' : 'is-positive'}`), 'Balance');
        const amount = element('strong', 'account-balance-value', currency.format(account.balance));
        const track = element('span', 'account-balance-track');
        const fill = element('span', 'account-balance-fill');
        fill.style.width = `${Math.max(7, Math.round(Math.abs(account.balance) / maximum * 100))}%`;
        track.appendChild(fill);
        cell.append(amount, track);
        return cell;
    }

    function headerCell(label, field, align) {
        const th = element('th', align ? `is-${align}` : '');
        th.scope = 'col';
        const active = state.sort.indexOf(field + '-') === 0;
        th.setAttribute('aria-sort', active ? (state.sort.endsWith('-asc') ? 'ascending' : 'descending') : 'none');
        const button = element('button', 'account-sort-button', label);
        button.type = 'button';
        button.dataset.sortField = field;
        const icon = element('i', `fas ${active ? (state.sort.endsWith('-asc') ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort'}`);
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);
        button.addEventListener('click', () => {
            state.sort = active && state.sort.endsWith('-asc') ? `${field}-desc` : `${field}-asc`;
            sort.value = state.sort;
            renderTable();
        });
        th.appendChild(button);
        return th;
    }

    function emptyState() {
        const empty = element('div', 'account-empty');
        const icon = element('span', 'account-empty-icon');
        const iconEl = element('i', 'fas fa-magnifying-glass');
        iconEl.setAttribute('aria-hidden', 'true');
        icon.appendChild(iconEl);
        empty.append(icon, element('h3', '', 'No matching accounts'), element('p', '', 'Try a different search or account type.'), element('button', 'account-empty-reset', 'Clear filters'));
        empty.querySelector('button').addEventListener('click', () => {
            state.query = '';
            state.type = 'all';
            search.value = '';
            filters.forEach(button => {
                const active = button.dataset.accountFilter === 'all';
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            renderTable();
        });
        return empty;
    }

    function renderTable() {
        const rows = filteredAccounts();
        count.textContent = `${integer.format(rows.length)} shown`;
        region.hidden = false;
        status.hidden = true;
        if (!rows.length) {
            region.replaceChildren(emptyState());
            return;
        }

        const table = element('table', 'account-table');
        const caption = element('caption', 'sr-only', 'Accounts with identifiers, recent activity, transaction counts and balances');
        const head = element('thead');
        const headerRow = element('tr');
        headerRow.append(
            headerCell('Account', 'name'),
            element('th', '', 'Identifier'),
            headerCell('Last activity', 'last_transaction'),
            headerCell('Transactions', 'transactions', 'right'),
            headerCell('Balance', 'balance', 'right'),
            element('th', 'account-open-heading', 'Open')
        );
        Array.from(headerRow.children).forEach(cell => { if (!cell.scope) cell.scope = 'col'; });
        head.appendChild(headerRow);
        const body = element('tbody');
        const maximum = Math.max(1, ...state.accounts.map(account => Math.abs(account.balance)));
        rows.forEach(account => {
            const row = element('tr');
            row.append(identityCell(account), identifierCell(account), activityCell(account));
            const transactions = setCellLabel(element('td', 'account-transactions', integer.format(account.transactions)), 'Transactions');
            row.append(transactions, balanceCell(account, maximum));
            const openCell = setCellLabel(element('td', 'account-open-cell'), 'Open account');
            const open = element('a', 'account-open-link');
            open.href = `account.html?id=${encodeURIComponent(account.id)}`;
            open.setAttribute('aria-label', `Open ${account.name}`);
            const arrow = element('i', 'fas fa-arrow-right');
            arrow.setAttribute('aria-hidden', 'true');
            open.appendChild(arrow);
            openCell.appendChild(open);
            row.appendChild(openCell);
            body.appendChild(row);
        });
        table.append(caption, head, body);
        region.replaceChildren(table);
    }

    function renderChart() {
        if (!window.Highcharts || !chart) return;
        const rows = state.accounts.slice().sort((left, right) => Math.abs(right.balance) - Math.abs(left.balance));
        chart.style.height = `${Math.max(300, rows.length * 44 + 110)}px`;
        Highcharts.chart(chart, {
            chart:{ type:'bar', backgroundColor:'transparent', spacing:[12, 16, 12, 4] },
            title:{ text:null },
            xAxis:{ categories:rows.map(account => account.name), lineWidth:0, tickWidth:0, labels:{ style:{ color:'#334155', fontSize:'11px', fontWeight:'700' } } },
            yAxis:{ title:{ text:null }, gridLineColor:'rgba(148,163,184,.16)', plotLines:[{ value:0, color:'#94a3b8', width:1, zIndex:4 }], labels:{ formatter:function () { return currency.format(this.value); }, style:{ color:'#64748b', fontSize:'10px' } } },
            legend:{ enabled:false },
            credits:{ enabled:false },
            tooltip:{ borderWidth:0, shadow:true, formatter:function () { return `<b>${escapeMarkup(this.point.name)}</b><br>${currency.format(this.y)}`; } },
            plotOptions:{ series:{ borderRadius:6, pointWidth:18, cursor:'pointer', animation:{ duration:500 }, dataLabels:{ enabled:false }, point:{ events:{ click:function () { window.location.href = `account.html?id=${encodeURIComponent(this.options.accountId)}`; } } } } },
            series:[{ name:'Balance', data:rows.map(account => ({ name:account.name, y:account.balance, accountId:account.id, color:account.balance < 0 ? '#fb7185' : '#4f46e5' })) }]
        });
    }

    function showError(message) {
        count.textContent = 'Unavailable';
        status.hidden = false;
        status.className = 'account-table-status account-table-status--error';
        status.replaceChildren(element('strong', '', 'Accounts could not be loaded'), element('span', '', message));
        summary.replaceChildren(metric('fa-triangle-exclamation', 'Account data unavailable', '—', 'Refresh the page to try again', 'rose'));
        chart.closest('.account-chart-panel').hidden = true;
    }

    search.addEventListener('input', () => {
        state.query = search.value.trim();
        renderTable();
    });
    sort.addEventListener('change', () => {
        state.sort = sort.value;
        renderTable();
    });
    filters.forEach(button => button.addEventListener('click', () => {
        state.type = button.dataset.accountFilter;
        filters.forEach(candidate => {
            const active = candidate === button;
            candidate.classList.toggle('is-active', active);
            candidate.setAttribute('aria-pressed', String(active));
        });
        renderTable();
    }));

    fetch('../php_backend/public/account_dashboard.php', { cache:'no-store' })
        .then(response => {
            if (!response.ok) throw new Error('The server did not return account data.');
            return response.json();
        })
        .then(data => {
            if (!Array.isArray(data)) throw new Error('The account data was not in the expected format.');
            state.accounts = data.map(normalise);
            renderSummary();
            renderTable();
            renderChart();
        })
        .catch(error => showError(error.message || 'Please try again.'));
})();

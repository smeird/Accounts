(function () {
    const currency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 });
    const preciseCurrency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', minimumFractionDigits: 2 });
    const monthInput = document.getElementById('month');
    const categorySelect = document.getElementById('category');
    const amountInput = document.getElementById('amount');
    const errorEl = document.getElementById('budget-error');
    let currentBudgets = [];

    function money(value) { return currency.format(Number(value) || 0); }
    function preciseMoney(value) { return preciseCurrency.format(Number(value) || 0); }
    function text(id, value) { document.getElementById(id).textContent = value; }
    function clear(element) { while (element.firstChild) element.removeChild(element.firstChild); }
    function announce(message, type) { if (typeof window.showMessage === 'function') window.showMessage(message, type); }
    function periodParts() { const [year, month] = monthInput.value.split('-').map(Number); return { year, month }; }
    function statusFor(used) { return used > 100 ? 'over' : used >= 75 ? 'watch' : 'safe'; }
    function usedFor(budget) {
        const amount = Number(budget.amount) || 0;
        const spent = Number(budget.spent) || 0;
        return amount > 0 ? spent / amount * 100 : spent > 0 ? 101 : 0;
    }

    function setPeriodLabel() {
        const date = new Date(`${monthInput.value}-01T12:00:00`);
        text('budget-period-label', date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }));
    }

    async function request(url, options) {
        const response = await fetch(url, options);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || 'Request failed');
        return payload;
    }

    async function loadCategories() {
        const categories = await request('../php_backend/public/categories.php');
        clear(categorySelect);
        categories.forEach(category => {
            const option = document.createElement('option'); option.value = category.id; option.textContent = category.name; categorySelect.appendChild(option);
        });
    }

    function renderSummary(data) {
        const planned = data.reduce((sum, budget) => sum + Number(budget.amount || 0), 0);
        const spent = data.reduce((sum, budget) => sum + Number(budget.spent || 0), 0);
        const remaining = planned - spent;
        const used = planned > 0 ? spent / planned * 100 : spent > 0 ? 101 : 0;
        const safe = data.filter(budget => statusFor(usedFor(budget)) === 'safe').length;
        const pressure = data.length - safe;
        text('budget-hero-title', money(Math.abs(remaining)));
        document.getElementById('budget-hero-title').classList.toggle('is-over', remaining < 0);
        text('budget-kicker', remaining < 0 ? 'Beyond your combined budgets' : 'Available across your budgets');
        text('budget-hero-message', data.length === 0 ? 'Start with one category limit to see your monthly runway.' : remaining >= 0 ? `${money(remaining)} remains before your category plans are fully used.` : `You are ${money(Math.abs(remaining))} beyond the combined plan.`);
        text('budget-used', `${used.toFixed(0)}%`); text('budget-planned', money(planned)); text('budget-spent', money(spent)); text('budget-safe-count', String(safe)); text('budget-pressure-count', String(pressure)); text('budget-count', String(data.length));
        if(data.length){const {year,month}=periodParts();TransactionDrilldown.linkify('budget-spent',TransactionDrilldown.financial({...TransactionDrilldown.monthRange(year,month),direction:'spending',dimension:'category',dimension_ids:data.map(item=>item.category_id),label:'Spending across budgeted categories'}),money(spent));}
        const fill = document.getElementById('budget-overall-fill'); fill.style.width = `${Math.min(used, 100)}%`; fill.className = statusFor(used) === 'safe' ? '' : `is-${statusFor(used)}`;
    }

    function renderBudgets(data) {
        const host = document.getElementById('budget-list'); clear(host);
        if (!data.length) {
            const empty = document.createElement('div'); empty.className = 'budget-empty';
            const icon = document.createElement('i'); icon.className = 'fas fa-chart-simple'; icon.setAttribute('aria-hidden', 'true');
            const title = document.createElement('strong'); title.textContent = 'No budgets for this month yet';
            const copy = document.createElement('p'); copy.textContent = 'Choose a category and set a limit to start tracking your runway.';
            empty.append(icon, title, copy); host.appendChild(empty); return;
        }
        const ordered = [...data].sort((a, b) => usedFor(b) - usedFor(a));
        ordered.forEach(budget => {
            const used = usedFor(budget);
            const status = statusFor(used);
            const row = document.createElement('article'); row.className = `budget-row budget-row--${status}`;
            const content = document.createElement('div');
            const top = document.createElement('div'); top.className = 'budget-row__top';
            const nameBlock = document.createElement('div'); nameBlock.className = 'budget-row__name';
            const name = document.createElement('strong'); name.textContent = budget.category;
            const meta = document.createElement('div'); meta.className = 'budget-row__meta';
            const statusBadge = document.createElement('span'); statusBadge.className = 'budget-status'; statusBadge.textContent = status === 'safe' ? 'Comfortable' : status === 'watch' ? 'Watch' : 'Over';
            const percent = document.createElement('span'); percent.textContent = `${used.toFixed(0)}% used`; meta.append(statusBadge, percent); nameBlock.append(name, meta);
            const figures = document.createElement('div'); figures.className = 'budget-row__figures'; const spent = document.createElement('strong');const spentLink=document.createElement('a');spentLink.className='transaction-drilldown-link';const {year,month}=periodParts();spentLink.href=TransactionDrilldown.url(TransactionDrilldown.financial({...TransactionDrilldown.monthRange(year,month),direction:'spending',dimension:'category',dimension_id:budget.category_id,label:`${budget.category} budget spending`}));spentLink.textContent=preciseMoney(budget.spent);spent.appendChild(spentLink); const plan = document.createElement('span'); plan.textContent = `of ${preciseMoney(budget.amount)}`; figures.append(spent, plan); top.append(nameBlock, figures);
            const track = document.createElement('div'); track.className = 'budget-row__track'; track.setAttribute('aria-label', `${budget.category}: ${used.toFixed(0)}% of budget used`); const fill = document.createElement('span'); fill.className = 'budget-row__fill'; fill.style.width = `${Math.min(used, 100)}%`; track.appendChild(fill);
            const footer = document.createElement('div'); footer.className = 'budget-row__footer'; const label = document.createElement('span'); label.textContent = Number(budget.left) >= 0 ? 'Remaining' : 'Over by'; const left = document.createElement('strong'); left.textContent = preciseMoney(Math.abs(Number(budget.left))); footer.append(label, left); content.append(top, track, footer);
            const actions = document.createElement('div'); actions.className = 'budget-row__actions';
            const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'budget-icon-button'; edit.setAttribute('aria-label', `Edit ${budget.category} budget`); edit.innerHTML = '<i class="fas fa-pen" aria-hidden="true"></i>'; edit.addEventListener('click', () => { categorySelect.value = budget.category_id; amountInput.value = Number(budget.amount).toFixed(2); amountInput.focus(); document.getElementById('budget-form').scrollIntoView({ behavior: 'smooth', block: 'center' }); });
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'budget-icon-button budget-icon-button--delete'; remove.setAttribute('aria-label', `Delete ${budget.category} budget`); remove.innerHTML = '<i class="fas fa-trash" aria-hidden="true"></i>'; remove.addEventListener('click', () => deleteBudget(budget));
            actions.append(edit, remove); row.append(content, actions); host.appendChild(row);
        });
    }

    async function loadBudgets() {
        setPeriodLabel(); errorEl.hidden = true;
        try {
            const { year, month } = periodParts(); currentBudgets = await request(`../php_backend/public/budgets.php?month=${month}&year=${year}`); renderSummary(currentBudgets); renderBudgets(currentBudgets);
        } catch (error) { errorEl.textContent = 'Budgets could not be loaded. Please try again.'; errorEl.hidden = false; }
    }

    async function deleteBudget(budget) {
        if (!window.confirm(`Delete the ${budget.category} budget for this month?`)) return;
        try { await request('../php_backend/public/budgets.php', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: budget.id }) }); await loadBudgets(); announce('Budget deleted'); }
        catch (error) { announce('Budget could not be deleted', 'error'); }
    }

    document.getElementById('budget-form').addEventListener('submit', async event => {
        event.preventDefault(); const button = event.submitter; button.disabled = true;
        try { const { year, month } = periodParts(); await request('../php_backend/public/budgets.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ category_id: Number(categorySelect.value), amount: Number(amountInput.value), month, year }) }); amountInput.value = ''; await loadBudgets(); announce('Budget saved'); }
        catch (error) { announce('Budget could not be saved', 'error'); }
        finally { button.disabled = false; }
    });

    document.getElementById('ai-form').addEventListener('submit', async event => {
        event.preventDefault(); const button = document.getElementById('ai-run'); const result = document.getElementById('ai-result'); const debug = document.getElementById('ai-debug'); button.disabled = true; result.textContent = 'Building your suggested plan…'; announce('AI budgeting started');
        try {
            const { year, month } = periodParts(); const data = await request('../php_backend/public/ai_budget.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ goal: Number(document.getElementById('goal').value), month, year }) });
            if (data.status !== 'ok') throw new Error(data.error || 'AI budgeting failed');
            document.getElementById('goal').value = ''; result.textContent = data.summary || 'Your suggested budgets have been applied.'; await loadBudgets(); announce('AI budgets applied');
            if (data.debug) { document.getElementById('ai-debug-request').textContent = data.debug.prompt || ''; document.getElementById('ai-debug-response').textContent = typeof data.debug.response === 'string' ? data.debug.response : JSON.stringify(data.debug.response, null, 2); debug.hidden = false; }
        } catch (error) { result.textContent = error.message || 'AI budgeting failed.'; announce('AI budgeting failed', 'error'); }
        finally { button.disabled = false; }
    });

    async function initDebug() { try { const data = await request('../php_backend/public/ai_debug.php'); if (data.debug) document.getElementById('ai-debug').hidden = false; } catch (error) {} }

    monthInput.value = new Date().toISOString().slice(0, 7); monthInput.addEventListener('change', loadBudgets);
    Promise.all([loadCategories(), loadBudgets()]).catch(() => { errorEl.textContent = 'Budget tools could not be loaded. Please refresh the page.'; errorEl.hidden = false; }); initDebug();
})();

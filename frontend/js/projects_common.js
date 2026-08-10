(function () {
    const currency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 });
    const preciseCurrency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', minimumFractionDigits: 2 });

    function number(value) { return Number(value) || 0; }
    function money(value) { return currency.format(number(value)); }
    function preciseMoney(value) { return preciseCurrency.format(number(value)); }
    function clear(element) { while (element.firstChild) element.removeChild(element.firstChild); }
    function text(id, value) { const element = document.getElementById(id); if (element) element.textContent = value; }
    function announce(message, type) { if (typeof window.showMessage === 'function') window.showMessage(message, type); }
    function used(project) {
        const planned = number(project.cost_medium);
        const spent = number(project.spent);
        return planned > 0 ? spent / planned * 100 : spent > 0 ? 101 : 0;
    }
    function status(project) { const value = used(project); return value > 100 ? 'over' : value >= 75 ? 'watch' : 'safe'; }
    function summary(projects) {
        return projects.reduce((result, project) => {
            result.planned += number(project.cost_medium);
            result.spent += number(project.spent);
            if (status(project) === 'over') result.over++;
            if (number(project.benefit_risk) >= 4) result.riskBenefit++;
            return result;
        }, { count: projects.length, planned: 0, spent: 0, over: 0, riskBenefit: 0 });
    }
    async function request(options, archived) {
        const suffix = archived ? '?archived=1' : '';
        const response = await fetch('../php_backend/public/projects.php' + suffix, options);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || (options && payload.status !== 'ok')) throw new Error(payload.error || 'Project request failed');
        return payload;
    }
    function actionButton(iconClass, label, modifier) {
        const button = document.createElement('button'); button.type = 'button'; button.className = `project-icon-button${modifier ? ` project-icon-button--${modifier}` : ''}`; button.setAttribute('aria-label', label);
        const icon = document.createElement('i'); icon.className = `fas ${iconClass}`; icon.setAttribute('aria-hidden', 'true'); button.appendChild(icon); return button;
    }
    function emptyState(title, copy, iconClass) {
        const empty = document.createElement('div'); empty.className = 'project-empty';
        const icon = document.createElement('i'); icon.className = `fas ${iconClass || 'fa-lightbulb'}`; icon.setAttribute('aria-hidden', 'true');
        const strong = document.createElement('strong'); strong.textContent = title;
        const paragraph = document.createElement('p'); paragraph.textContent = copy;
        empty.append(icon, strong, paragraph); return empty;
    }
    function headerActions(primaryLabel) {
        const wrap = document.createElement('div'); wrap.className = 'project-header-actions';
        const board = document.createElement('a'); board.href = 'projects_board.html'; board.className = 'project-header-link';
        const boardIcon = document.createElement('i'); boardIcon.className = 'fas fa-table-columns'; boardIcon.setAttribute('aria-hidden', 'true');
        const boardLabel = document.createElement('span'); boardLabel.textContent = 'Board'; board.append(boardIcon, boardLabel);
        const add = document.createElement('a'); add.href = 'project_add.html'; add.className = 'project-header-link project-header-link--primary';
        const addIcon = document.createElement('i'); addIcon.className = 'fas fa-plus'; addIcon.setAttribute('aria-hidden', 'true');
        const addLabel = document.createElement('span'); addLabel.textContent = primaryLabel || 'New project'; add.append(addIcon, addLabel);
        wrap.append(board, add); return wrap;
    }
    function renderHero(projects, options) {
        const totals = summary(projects);
        const remaining = totals.planned - totals.spent;
        text(options.valueId, options.archived ? money(totals.planned) : money(remaining));
        text(options.messageId, options.archived ? `${totals.count} archived project${totals.count === 1 ? '' : 's'} represent ${money(totals.planned)} in paused plans.` : totals.count === 0 ? 'Capture your first project to start building a prioritised pipeline.' : remaining >= 0 ? `${money(remaining)} remains between current spend and the combined mid-cost plans.` : `Current spend is ${money(Math.abs(remaining))} beyond the combined mid-cost plans.`);
        text(options.signalId, options.archived ? String(totals.count) : String(totals.over));
        text(options.signalLabelId, options.archived ? 'parked projects' : `over plan`);
        text(options.countId, String(totals.count)); text(options.plannedId, money(totals.planned)); text(options.spentId, money(totals.spent)); text(options.riskId, String(options.archived ? totals.riskBenefit : totals.over + totals.riskBenefit));
        return totals;
    }

    window.ProjectUI = { number, money, preciseMoney, clear, text, announce, used, status, summary, request, actionButton, emptyState, headerActions, renderHero };
})();

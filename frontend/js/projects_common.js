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
    function rating(value) { return Math.max(0, Math.min(5, Math.round(number(value)))); }
    function prioritySignals(project) {
        return {
            consequence: rating(project.benefit_risk),
            urgency: rating(project.weight_risk),
            preservation: rating(project.benefit_sustainability),
            financial: rating(project.benefit_financial),
            daily: rating(project.benefit_quality)
        };
    }
    function priorityScore(project) {
        const signal = prioritySignals(project);
        return Math.round((signal.consequence * 35 + signal.urgency * 25 + signal.preservation * 20 + signal.financial * 10 + signal.daily * 10) / 5);
    }
    function priorityTier(project) {
        const signal = prioritySignals(project); const score = priorityScore(project);
        if (signal.consequence >= 5 && signal.urgency >= 4) return {key:'critical',label:'Critical — act now',rank:1};
        if (score >= 70 || (signal.consequence >= 4 && signal.urgency >= 4)) return {key:'important',label:'Important — plan next',rank:2};
        if (score >= 50 || signal.consequence >= 4 || signal.preservation >= 4) return {key:'preventive',label:'Preventive — schedule soon',rank:3};
        if (score >= 30) return {key:'improvement',label:'Improvement — worthwhile',rank:4};
        return {key:'nice',label:'Nice to have',rank:5};
    }
    function comparePriority(a, b) {
        const tier = priorityTier(a).rank - priorityTier(b).rank;
        return tier || priorityScore(b) - priorityScore(a) || number(a.id) - number(b.id);
    }
    function summary(projects) {
        return projects.reduce((result, project) => {
            result.planned += number(project.cost_medium);
            result.spent += number(project.spent);
            if (status(project) === 'over') result.over++;
            const tier = priorityTier(project);
            if (tier.key === 'critical') result.critical++;
            if (tier.key === 'critical' || tier.key === 'important') result.doNext++;
            return result;
        }, { count: projects.length, planned: 0, spent: 0, over: 0, critical: 0, doNext: 0 });
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
        const priorityOrder = [...projects].sort(comparePriority);
        const top = priorityOrder[0];
        text(options.valueId, options.archived ? money(totals.planned) : money(remaining));
        text(options.messageId, options.archived ? `${totals.count} archived project${totals.count === 1 ? '' : 's'} represent ${money(totals.planned)} in paused plans.` : totals.count === 0 ? 'Capture your first project to start building a prioritised pipeline.' : `${remaining >= 0 ? `${money(remaining)} remains across the combined plans.` : `Spend is ${money(Math.abs(remaining))} beyond the combined plans.`} Highest priority: ${top.name || 'Untitled project'} — ${priorityTier(top).label}.`);
        text(options.signalId, options.archived ? String(totals.count) : String(totals.critical));
        text(options.signalLabelId, options.archived ? 'parked projects' : 'critical now');
        text(options.countId, String(totals.count)); text(options.plannedId, money(totals.planned)); text(options.spentId, money(totals.spent)); text(options.priorityId, String(totals.doNext));
        const groupIds=projects.map(project=>Number(project.group_id)).filter(id=>id>0);
        if(groupIds.length&&totals.spent>0)TransactionDrilldown.linkify(options.spentId,{direction:'spending',transfer_scope:'exclude',ignored_scope:'include',dimension:'group',dimension_ids:groupIds,label:options.archived?'Historic project spending':'Project spending to date'},money(totals.spent));
        return totals;
    }

    window.ProjectUI = { number, money, preciseMoney, clear, text, announce, used, status, rating, prioritySignals, priorityScore, priorityTier, comparePriority, summary, request, actionButton, emptyState, headerActions, renderHero };
})();

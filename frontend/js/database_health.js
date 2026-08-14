function normaliseHealthPayload(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return null;
    }
    const issues = Array.isArray(payload.issues) ? payload.issues : [];
    const summary = payload.summary && typeof payload.summary === 'object' ? payload.summary : {};
    return {
        status: payload.healthy === true ? 'healthy' : (payload.status === 'issues' ? 'issues' : 'error'),
        healthy: payload.healthy === true,
        checked_at: String(payload.checked_at || ''),
        database: payload.database && typeof payload.database === 'object' ? payload.database : {},
        summary,
        issues,
        scope: String(payload.scope || '')
    };
}

function repairableIssueIds(audit) {
    return audit && Array.isArray(audit.issues)
        ? audit.issues.filter(issue => issue && issue.repairable === true).map(issue => String(issue.id))
        : [];
}

function initDatabaseHealth() {
    const statusCard = document.getElementById('database-health-status');
    if (!statusCard) return;

    const title = document.getElementById('database-health-title');
    const kicker = document.getElementById('database-health-kicker');
    const message = document.getElementById('database-health-message');
    const meta = document.getElementById('database-health-meta');
    const refreshButton = document.getElementById('database-health-refresh');
    const resultsSection = document.getElementById('database-health-results');
    const cleanSection = document.getElementById('database-health-clean');
    const issueContainer = document.getElementById('database-health-issues');
    const repairButton = document.getElementById('database-health-repair');
    const selectAllButton = document.getElementById('database-health-select-all');
    const selectedCount = document.getElementById('database-health-selected-count');
    const repairResult = document.getElementById('database-health-repair-result');
    const dialog = document.getElementById('database-health-dialog');
    const dialogMessage = document.getElementById('database-health-dialog-message');
    const cancelButton = document.getElementById('database-health-cancel');
    const confirmButton = document.getElementById('database-health-confirm');
    let currentAudit = null;
    let isBusy = false;

    function setBusy(busy, label) {
        isBusy = busy;
        refreshButton.disabled = busy;
        repairButton.disabled = busy || selectedRepairIds().length === 0;
        confirmButton.disabled = busy;
        statusCard.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (label) refreshButton.querySelector('span').textContent = label;
        refreshButton.querySelector('i').classList.toggle('fa-spin', busy);
    }

    function selectedRepairIds() {
        return Array.from(issueContainer.querySelectorAll('input[data-issue-id]:checked')).map(input => input.dataset.issueId);
    }

    function updateSelection() {
        const count = selectedRepairIds().length;
        selectedCount.textContent = `${count} repair${count === 1 ? '' : 's'} selected`;
        repairButton.disabled = isBusy || count === 0;
    }

    function setMetric(id, value) {
        document.getElementById(id).textContent = Number(value) || 0;
    }

    function createBadge(text, manual) {
        const badge = document.createElement('span');
        badge.className = `database-health-badge${manual ? ' is-manual' : ''}`;
        badge.textContent = text;
        return badge;
    }

    function renderIssue(issue) {
        const card = document.createElement('article');
        const selector = issue.repairable ? document.createElement('input') : document.createElement('span');
        const body = document.createElement('div');
        const top = document.createElement('div');
        const objectName = document.createElement('strong');
        const description = document.createElement('p');
        const icon = document.createElement('span');
        const manual = !issue.repairable;
        card.className = `database-health-issue${manual ? ' is-manual' : ''}`;

        if (issue.repairable) {
            selector.type = 'checkbox';
            selector.checked = true;
            selector.dataset.issueId = String(issue.id);
            selector.setAttribute('aria-label', `Select ${issue.operation || issue.id}`);
            selector.addEventListener('change', updateSelection);
        } else {
            selector.className = 'database-health-issue__icon';
            const selectorIcon = document.createElement('i');
            selectorIcon.className = 'fas fa-eye';
            selectorIcon.setAttribute('aria-hidden', 'true');
            selector.appendChild(selectorIcon);
        }

        top.className = 'database-health-issue__top';
        objectName.textContent = `${issue.table || 'Database'} · ${issue.object || issue.kind || 'schema'}`;
        top.append(objectName, createBadge(issue.kind ? String(issue.kind).replaceAll('_', ' ') : 'issue', false), createBadge(manual ? 'Manual review' : 'Safe repair', manual));
        description.textContent = String(issue.message || 'Schema issue detected.');
        body.append(top, description);

        if (manual && issue.manual_reason) {
            const reason = document.createElement('small');
            reason.textContent = String(issue.manual_reason);
            body.appendChild(reason);
        }
        if (issue.repairable && issue.sql) {
            const details = document.createElement('details');
            const summary = document.createElement('summary');
            const sql = document.createElement('code');
            summary.textContent = 'Show planned SQL';
            sql.textContent = String(issue.sql);
            details.append(summary, sql);
            body.appendChild(details);
        }

        if (issue.repairable) {
            icon.className = 'database-health-issue__icon';
            const iconElement = document.createElement('i');
            iconElement.className = 'fas fa-wrench';
            iconElement.setAttribute('aria-hidden', 'true');
            icon.appendChild(iconElement);
            card.append(selector, body, icon);
        } else {
            card.append(selector, body);
        }
        return card;
    }

    function renderAudit(audit) {
        currentAudit = audit;
        const summary = audit.summary || {};
        statusCard.classList.remove('is-checking', 'is-healthy', 'has-issues', 'has-error');
        statusCard.classList.add(audit.healthy ? 'is-healthy' : 'has-issues');
        kicker.textContent = audit.healthy ? 'Schema current' : 'Attention recommended';
        title.textContent = audit.healthy ? 'Database structure is healthy' : `${Number(summary.issues) || audit.issues.length} schema issue${(Number(summary.issues) || audit.issues.length) === 1 ? '' : 's'} found`;
        message.textContent = audit.healthy
            ? 'This installation matches the application’s managed schema catalogue.'
            : 'Review the findings below. Safe catalogue repairs are selected; anything that could affect stored values remains manual.';

        meta.replaceChildren();
        [audit.database.name && `Database ${audit.database.name}`, audit.database.server_version && `MySQL ${audit.database.server_version}`, audit.checked_at && `Checked ${new Date(audit.checked_at).toLocaleString()}`]
            .filter(Boolean)
            .forEach(value => {
                const pill = document.createElement('span');
                pill.textContent = value;
                meta.appendChild(pill);
            });

        setMetric('health-total-tables', summary.managed_tables);
        setMetric('health-passed-checks', summary.passed);
        setMetric('health-safe-repairs', summary.repairable);
        setMetric('health-manual-issues', summary.manual);
        issueContainer.replaceChildren(...audit.issues.map(renderIssue));
        document.getElementById('database-health-summary').textContent = audit.healthy
            ? 'No structural drift was found.'
            : `${Number(summary.repairable) || 0} safe repair${Number(summary.repairable) === 1 ? '' : 's'} and ${Number(summary.manual) || 0} manual-review item${Number(summary.manual) === 1 ? '' : 's'}.`;
        resultsSection.hidden = audit.healthy;
        cleanSection.hidden = !audit.healthy;
        selectAllButton.hidden = repairableIssueIds(audit).length === 0;
        updateSelection();
    }

    function renderError(error) {
        statusCard.classList.remove('is-checking', 'is-healthy', 'has-issues');
        statusCard.classList.add('has-error');
        kicker.textContent = 'Check failed';
        title.textContent = 'Database Health could not run';
        message.textContent = error.message || 'The schema audit could not be completed.';
        resultsSection.hidden = true;
        cleanSection.hidden = true;
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, options);
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable Database Health response.');
        }
        if (!response.ok) {
            throw new Error(String(payload.message || payload.error || 'Database Health request failed.'));
        }
        return payload;
    }

    async function runAudit() {
        setBusy(true, 'Checking…');
        repairResult.hidden = true;
        try {
            const payload = await fetchJson('../php_backend/public/database_health.php', { headers: { Accept: 'application/json' } });
            const audit = normaliseHealthPayload(payload);
            if (!audit || audit.status === 'error') throw new Error('The server returned an incomplete schema audit.');
            renderAudit(audit);
        } catch (error) {
            renderError(error);
        } finally {
            setBusy(false, 'Run check again');
        }
    }

    function openConfirmation() {
        const count = selectedRepairIds().length;
        if (!count) return;
        dialogMessage.textContent = `${count} selected schema repair${count === 1 ? '' : 's'} will be applied. MySQL may briefly lock an affected table while its structure changes.`;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    }

    function closeConfirmation() {
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }

    async function runRepair() {
        const ids = selectedRepairIds();
        if (!ids.length) return;
        closeConfirmation();
        setBusy(true, 'Repairing…');
        repairResult.hidden = true;
        try {
            const payload = await fetchJson('../php_backend/public/database_health.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'repair', confirm: 'REPAIR_SCHEMA', issue_ids: ids })
            });
            const audit = normaliseHealthPayload(payload.audit);
            if (!audit) throw new Error('The repair completed without a readable follow-up audit.');
            renderAudit(audit);
            repairResult.classList.toggle('is-error', payload.status === 'error' || payload.status === 'partial');
            const resultTitle = document.createElement('strong');
            const resultMessage = document.createElement('p');
            resultTitle.textContent = payload.status === 'success' ? 'Repair complete' : 'Repair needs attention';
            resultMessage.textContent = String(payload.message || 'The selected repairs finished.');
            repairResult.replaceChildren(resultTitle, resultMessage);
            repairResult.hidden = false;
            repairResult.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
            if (typeof showMessage === 'function') showMessage(resultMessage.textContent, payload.status === 'success' ? 'success' : 'error');
        } catch (error) {
            renderError(error);
            if (typeof showMessage === 'function') showMessage(error.message, 'error');
        } finally {
            setBusy(false, 'Run check again');
        }
    }

    refreshButton.addEventListener('click', runAudit);
    repairButton.addEventListener('click', openConfirmation);
    cancelButton.addEventListener('click', closeConfirmation);
    confirmButton.addEventListener('click', runRepair);
    selectAllButton.addEventListener('click', () => {
        issueContainer.querySelectorAll('input[data-issue-id]').forEach(input => { input.checked = true; });
        updateSelection();
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) closeConfirmation();
    });

    runAudit();
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { normaliseHealthPayload, repairableIssueIds };
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', initDatabaseHealth);
}

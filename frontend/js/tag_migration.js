function normaliseMigrationPayload(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return null;
    return {
        schemaReady: payload.schema_ready === true,
        schemaMessage: String(payload.schema_message || ''),
        contract: payload.contract && typeof payload.contract === 'object' ? payload.contract : {},
        current: payload.current && typeof payload.current === 'object' ? payload.current : {},
        runs: Array.isArray(payload.runs) ? payload.runs : []
    };
}

function restoreConfirmationValid(value) {
    return String(value || '').trim().toUpperCase() === 'RESTORE';
}

function initTagMigration() {
    const status = document.getElementById('migration-status');
    if (!status) return;
    const statusTitle = document.getElementById('migration-status-title');
    const statusCopy = document.getElementById('migration-status-copy');
    const healthLink = document.getElementById('migration-health-link');
    const discoveryLink = document.getElementById('migration-discovery-link');
    const createButton = document.getElementById('migration-create-snapshot');
    const createNote = document.getElementById('migration-create-note');
    const nameInput = document.getElementById('migration-snapshot-name');
    const refreshButton = document.getElementById('migration-refresh');
    const runsContainer = document.getElementById('migration-runs');
    const dialog = document.getElementById('migration-restore-dialog');
    const dialogSummary = document.getElementById('migration-dialog-summary');
    const restoreInput = document.getElementById('migration-restore-confirmation');
    const restoreConfirm = document.getElementById('migration-restore-confirm');
    const restoreCancel = document.getElementById('migration-restore-cancel');
    let restoreRunId = null;
    let busy = false;

    function number(value) {
        return new Intl.NumberFormat().format(Number(value) || 0);
    }

    function setBusy(isBusy) {
        busy = isBusy;
        status.setAttribute('aria-busy', isBusy ? 'true' : 'false');
        createButton.disabled = isBusy || createButton.dataset.ready !== 'true';
        refreshButton.disabled = isBusy;
        createButton.querySelector('i').classList.toggle('fa-spin', isBusy);
        refreshButton.querySelector('i').classList.toggle('fa-spin', isBusy);
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, options);
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable migration response.');
        }
        if (!response.ok) throw new Error(String(payload.message || payload.detail || 'Migration request failed.'));
        return payload;
    }

    function addDefinition(name, copy) {
        const card = document.createElement('div');
        const title = document.createElement('strong');
        const text = document.createElement('p');
        card.className = 'migration-definition';
        title.textContent = name;
        text.textContent = String(copy || '');
        card.append(title, text);
        return card;
    }

    function renderContract(contract) {
        const definitions = contract.definitions && typeof contract.definitions === 'object' ? contract.definitions : {};
        document.getElementById('migration-definitions').replaceChildren(...Object.entries(definitions).map(([name, copy]) => addDefinition(name, copy)));
        const thresholds = contract.success_thresholds && typeof contract.success_thresholds === 'object' ? contract.success_thresholds : {};
        const rows = Object.entries(thresholds).map(([name, value]) => {
            const row = document.createElement('span');
            const label = document.createElement('span');
            const metric = document.createElement('strong');
            label.textContent = name.replaceAll('_', ' ');
            metric.textContent = String(value);
            row.append(label, metric);
            return row;
        });
        document.getElementById('migration-thresholds').replaceChildren(...rows);
    }

    function metric(label, value) {
        const node = document.createElement('div');
        const title = document.createElement('span');
        const strong = document.createElement('strong');
        node.className = 'migration-run__metric';
        title.textContent = label;
        strong.textContent = number(value);
        node.append(title, strong);
        return node;
    }

    function renderRun(run) {
        const row = document.createElement('article');
        const identity = document.createElement('div');
        const title = document.createElement('strong');
        const date = document.createElement('span');
        const statusPill = document.createElement('span');
        const restore = document.createElement('button');
        row.className = 'migration-run';
        identity.className = 'migration-run__identity';
        title.textContent = String(run.name || `Snapshot #${run.id}`);
        const parsedDate = run.created_at ? new Date(String(run.created_at).replace(' ', 'T')) : null;
        date.textContent = parsedDate && !Number.isNaN(parsedDate.getTime()) ? parsedDate.toLocaleString() : 'Date unavailable';
        statusPill.className = 'migration-run__status';
        statusPill.textContent = String(run.status || 'snapshot').replaceAll('_', ' ');
        identity.append(title, date, statusPill);
        restore.type = 'button';
        restore.className = 'migration-run__restore';
        restore.textContent = 'Preview restore';
        restore.setAttribute('aria-label', `Preview restore for ${title.textContent}`);
        restore.addEventListener('click', () => previewRestore(Number(run.id)));
        row.append(identity, metric('Transactions', run.transaction_count), metric('Eligible', run.eligible_count), metric('Transfers', run.protected_transfer_count), metric('Excluded', run.protected_ignore_count), restore);
        return row;
    }

    function renderOverview(view) {
        const current = view.current;
        document.getElementById('migration-total').textContent = number(current.transaction_count);
        document.getElementById('migration-eligible').textContent = number(current.eligible_count);
        document.getElementById('migration-transfers').textContent = number(current.protected_transfer_count);
        document.getElementById('migration-ignored').textContent = number(current.protected_ignore_count);
        document.getElementById('migration-coverage').textContent = `Current coverage ${Number(current.eligible_tagged_percent || 0).toFixed(1)}%`;
        renderContract(view.contract);
        runsContainer.replaceChildren(...(view.runs.length ? view.runs.map(renderRun) : [Object.assign(document.createElement('div'), { className: 'migration-empty', textContent: 'No protected classification snapshots have been created yet.' })]));

        status.classList.remove('is-loading', 'is-ready', 'has-error');
        status.classList.add(view.schemaReady ? 'is-ready' : 'has-error');
        statusTitle.textContent = view.schemaReady ? 'Classification safeguards are ready' : 'Database preparation is required';
        statusCopy.textContent = view.schemaMessage;
        healthLink.hidden = view.schemaReady;
        discoveryLink.hidden = !view.schemaReady;
        createButton.dataset.ready = view.schemaReady ? 'true' : 'false';
        createButton.disabled = busy || !view.schemaReady;
        createNote.textContent = view.schemaReady
            ? 'Snapshot creation is non-destructive and does not change live classifications.'
            : 'Database Health will offer only the catalogue-controlled Phase 1 schema changes.';
    }

    async function loadOverview() {
        setBusy(true);
        try {
            const payload = await fetchJson('../php_backend/public/tag_migration.php', { headers: { Accept: 'application/json' } });
            const view = normaliseMigrationPayload(payload);
            if (!view) throw new Error('The server returned incomplete migration information.');
            renderOverview(view);
        } catch (error) {
            status.classList.remove('is-loading', 'is-ready');
            status.classList.add('has-error');
            statusTitle.textContent = 'Tag rebuild safety check failed';
            statusCopy.textContent = error.message;
            if (typeof showMessage === 'function') showMessage(error.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function createSnapshot() {
        setBusy(true);
        try {
            const payload = await fetchJson('../php_backend/public/tag_migration.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'create_snapshot', confirm: 'CREATE_CLASSIFICATION_SNAPSHOT', name: nameInput.value })
            });
            nameInput.value = '';
            if (typeof showMessage === 'function') showMessage(payload.message || 'Classification snapshot created.', 'success');
            await loadOverview();
        } catch (error) {
            if (typeof showMessage === 'function') showMessage(error.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    function summaryItem(label, value, blocker) {
        const node = document.createElement('div');
        const title = document.createElement('span');
        const strong = document.createElement('strong');
        if (blocker) node.className = 'is-blocker';
        title.textContent = label;
        strong.textContent = String(value);
        node.append(title, strong);
        return node;
    }

    async function previewRestore(runId) {
        if (busy) return;
        setBusy(true);
        try {
            const preview = await fetchJson(`../php_backend/public/tag_migration.php?action=rollback_preview&run_id=${encodeURIComponent(runId)}`, { headers: { Accept: 'application/json' } });
            restoreRunId = runId;
            restoreInput.value = '';
            restoreConfirm.disabled = true;
            const items = [
                summaryItem('Assignments that would change', number(preview.changed_transactions)),
                summaryItem('Later transactions left untouched', number(preview.new_transactions_untouched)),
                summaryItem('Protected rows that differ', number(preview.protected_changes)),
                summaryItem('Integrity hash', preview.hash_valid ? 'Verified' : 'Failed', !preview.hash_valid)
            ];
            (Array.isArray(preview.blockers) ? preview.blockers : []).forEach(message => items.push(summaryItem('Restore blocked', message, true)));
            dialogSummary.replaceChildren(...items);
            restoreInput.disabled = preview.restorable !== true || Number(preview.changed_transactions) === 0;
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        } catch (error) {
            if (typeof showMessage === 'function') showMessage(error.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    function closeDialog() {
        restoreRunId = null;
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }

    async function restoreSnapshot() {
        if (!restoreRunId || !restoreConfirmationValid(restoreInput.value)) return;
        const runId = restoreRunId;
        closeDialog();
        setBusy(true);
        try {
            const payload = await fetchJson('../php_backend/public/tag_migration.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'restore_snapshot', confirm: 'RESTORE_CLASSIFICATIONS', run_id: runId })
            });
            if (typeof showMessage === 'function') showMessage(payload.message || 'Classifications restored.', 'success');
            await loadOverview();
        } catch (error) {
            if (typeof showMessage === 'function') showMessage(error.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    createButton.addEventListener('click', createSnapshot);
    refreshButton.addEventListener('click', loadOverview);
    restoreInput.addEventListener('input', () => { restoreConfirm.disabled = restoreInput.disabled || !restoreConfirmationValid(restoreInput.value); });
    restoreCancel.addEventListener('click', closeDialog);
    restoreConfirm.addEventListener('click', restoreSnapshot);
    dialog.addEventListener('click', event => { if (event.target === dialog) closeDialog(); });
    loadOverview();
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { normaliseMigrationPayload, restoreConfirmationValid };
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', initTagMigration);
}

function normalizeCutoverPayload(payload) {
    const source = payload && typeof payload === 'object' && payload.cutover && typeof payload.cutover === 'object'
        ? payload.cutover : null;
    if (!source) return null;
    return {
        schemaReady: source.schema_ready === true,
        schemaMessage: String(source.schema_message || ''),
        runs: Array.isArray(source.runs) ? source.runs : [],
        selectedRun: source.selected_run && typeof source.selected_run === 'object' ? source.selected_run : null
    };
}

function taxonomyCutoverCanApply(selectedRun) {
    return Boolean(selectedRun && selectedRun.can_apply === true && selectedRun.run && selectedRun.run.status === 'ready');
}

function initTagTaxonomyCutover() {
    const status = document.getElementById('cutover-status');
    if (!status) return;
    const runSelect = document.getElementById('cutover-run');
    const refresh = document.getElementById('cutover-refresh');
    const apply = document.getElementById('cutover-apply');
    const rollback = document.getElementById('cutover-rollback');
    const dialog = document.getElementById('cutover-confirm');
    const confirmInput = document.getElementById('cutover-confirm-input');
    const confirmSubmit = document.getElementById('cutover-confirm-submit');
    let view = null;
    let busy = false;
    let pendingAction = null;

    const number = value => new Intl.NumberFormat().format(Number(value) || 0);
    const money = value => new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(Number(value) || 0);
    const selectedRunId = () => Number(runSelect.value || (view && view.selectedRun && view.selectedRun.run ? view.selectedRun.run.id : 0));

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, { cache: 'no-store', ...options });
        let payload;
        try { payload = await response.json(); } catch (error) { throw new Error('The server returned an unreadable cutover response.'); }
        if (!response.ok) throw new Error(String(payload.message || 'The cutover request failed.'));
        return payload;
    }

    function post(body) {
        return fetchJson('../php_backend/public/tag_taxonomy_cutover.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        });
    }

    function setBusy(value) {
        busy = value;
        status.setAttribute('aria-busy', value ? 'true' : 'false');
        refresh.disabled = value;
        refresh.querySelector('i').classList.toggle('fa-spin', value);
        const selected = view && view.selectedRun;
        apply.disabled = value || !taxonomyCutoverCanApply(selected);
        rollback.disabled = value || !(selected && selected.can_rollback === true);
    }

    function option(value, text) {
        const node = document.createElement('option');
        node.value = String(value);
        node.textContent = text;
        return node;
    }

    function renderRuns() {
        const selected = view.selectedRun && view.selectedRun.run;
        const options = view.runs.map(run => option(run.id, `${run.name} · ${String(run.status).replaceAll('_', ' ')}`));
        if (!options.length) options.push(option('', 'No reviewed taxonomy runs'));
        runSelect.replaceChildren(...options);
        if (selected) runSelect.value = String(selected.id);
        runSelect.disabled = busy || !view.schemaReady || !view.runs.length;
        document.getElementById('cutover-run-detail').textContent = selected
            ? `${number(selected.eligible_count)} eligible · ${number(selected.protected_transfer_count)} transfers protected · status ${selected.status}`
            : 'Complete Taxonomy Studio review and mark a run ready first.';
    }

    function renderStatus() {
        const selected = view.selectedRun;
        const run = selected && selected.run;
        const title = document.getElementById('cutover-status-title');
        const copy = document.getElementById('cutover-status-copy');
        const state = document.getElementById('cutover-state');
        state.className = 'cutover-state';
        if (!view.schemaReady) {
            title.textContent = 'Database preparation is required';
            copy.textContent = view.schemaMessage || 'Apply the Phase 3 database catalogue repairs first.';
            state.textContent = 'Schema required';
            state.classList.add('is-error');
        } else if (!run) {
            title.textContent = 'No reviewed taxonomy is ready';
            copy.textContent = 'Finish review in Taxonomy Studio, then return here for the separate cutover.';
            state.textContent = 'Waiting for review';
        } else if (run.status === 'applied') {
            title.textContent = 'The reviewed taxonomy is live';
            copy.textContent = 'The cutover passed financial and classification reconciliation. Its audit record can support a controlled rollback.';
            state.textContent = 'Applied and reconciled';
            state.classList.add('is-ready');
        } else if (run.status === 'rolled_back') {
            title.textContent = 'This cutover was rolled back';
            copy.textContent = 'Snapshot classifications and the previous taxonomy relationships were restored.';
            state.textContent = 'Rolled back';
        } else if (selected.can_apply) {
            title.textContent = 'The reviewed taxonomy is ready to apply';
            copy.textContent = 'Every preview safeguard passes. Apply remains atomic and will cancel itself if reconciliation differs.';
            state.textContent = 'Ready for confirmation';
            state.classList.add('is-ready');
        } else {
            title.textContent = 'Cutover is blocked safely';
            copy.textContent = 'Resolve the listed safety checks before applying any live classifications.';
            state.textContent = 'Action required';
            state.classList.add('is-error');
        }
    }

    function renderMetrics() {
        const metrics = (view.selectedRun && view.selectedRun.metrics) || {};
        document.getElementById('cutover-transactions').textContent = number(metrics.transactions_to_retag);
        document.getElementById('cutover-coverage').textContent = `${Number(metrics.coverage_percent || 0).toFixed(1)}% of staged eligible transactions`;
        document.getElementById('cutover-tags').textContent = number(metrics.approved_proposals);
        document.getElementById('cutover-tags-detail').textContent = `${number(metrics.new_tags)} new · ${number(metrics.reused_tags)} reused`;
        document.getElementById('cutover-aliases').textContent = number(metrics.direction_aware_aliases);
        document.getElementById('cutover-deferred').textContent = number(metrics.deferred_transactions);
        document.getElementById('cutover-untouched-detail').textContent = `${number(metrics.newly_protected_transactions)} newly protected · ${number(metrics.post_snapshot_transactions_untouched)} newer transactions`;
    }

    function check(text, okay = true) {
        const item = document.createElement('li');
        const icon = document.createElement('i');
        icon.className = okay ? 'fas fa-circle-check' : 'fas fa-circle-exclamation';
        if (!okay) icon.style.color = '#dc2626';
        const copy = document.createElement('span');
        copy.textContent = text;
        item.append(icon, copy);
        return item;
    }

    function renderChecks() {
        const selected = view.selectedRun;
        const snapshot = (selected && selected.snapshot) || {};
        const metrics = (selected && selected.metrics) || {};
        const blockers = selected && Array.isArray(selected.blockers) ? selected.blockers : [];
        const checks = document.getElementById('cutover-checks');
        checks.replaceChildren(
            check('Immutable classification snapshot hash is valid.', snapshot.hash_valid === true),
            check('Transfers and explicit IGNORE transactions remain outside the retag set.', Number(snapshot.protected_changes || 0) === 0),
            check(`${Number(metrics.coverage_percent || 0).toFixed(1)}% reviewed coverage meets the 95% cutover threshold.`, Number(metrics.coverage_percent || 0) >= 95),
            check('Every analysed pattern resolves to one approved canonical tag.', Number(metrics.unresolved_proposed_patterns || 0) === 0),
            check(`${number(metrics.post_snapshot_transactions_untouched)} post-snapshot transaction(s) will remain untouched.`, true)
        );
        const gate = document.getElementById('cutover-gate');
        const blockerNode = document.getElementById('cutover-blockers');
        gate.className = 'cutover-gate';
        gate.textContent = blockers.length ? `${number(blockers.length)} blocker${blockers.length === 1 ? '' : 's'}` : 'All checks pass';
        gate.classList.add(blockers.length ? 'is-error' : 'is-ready');
        blockerNode.hidden = blockers.length === 0;
        blockerNode.replaceChildren(...blockers.map(message => {
            const row = document.createElement('div');
            row.textContent = message;
            return row;
        }));
    }

    function renderFingerprint() {
        const fingerprint = (view.selectedRun && view.selectedRun.financial_fingerprint) || {};
        document.getElementById('cutover-fingerprint-count').textContent = number(fingerprint.transaction_count);
        document.getElementById('cutover-fingerprint-signed').textContent = money(fingerprint.signed_total);
        document.getElementById('cutover-fingerprint-absolute').textContent = money(fingerprint.absolute_total);
    }

    function renderProposals() {
        const plans = view.selectedRun && Array.isArray(view.selectedRun.proposals) ? view.selectedRun.proposals : [];
        const root = document.getElementById('cutover-proposals');
        if (!plans.length) {
            const empty = document.createElement('div');
            empty.className = 'cutover-empty';
            empty.textContent = 'No approved canonical tag plan is available for this run.';
            root.replaceChildren(empty);
            return;
        }
        root.replaceChildren(...plans.map(plan => {
            const card = document.createElement('article');
            const header = document.createElement('header');
            const name = document.createElement('h3');
            const state = document.createElement('span');
            const description = document.createElement('p');
            const meta = document.createElement('div');
            const aliases = document.createElement('div');
            card.className = 'cutover-proposal';
            name.textContent = plan.canonical_name;
            state.textContent = plan.existing_tag_id ? 'Reuse tag' : 'New tag';
            header.append(name, state);
            description.textContent = plan.description || 'Reviewed canonical transaction type.';
            meta.className = 'cutover-proposal__meta';
            [`${number(plan.transaction_count)} transactions`, plan.category_name || 'No category', plan.segment_name || 'No segment'].forEach(text => {
                const node = document.createElement('span'); node.textContent = text; meta.append(node);
            });
            aliases.className = 'cutover-alias-list';
            (plan.aliases || []).slice(0, 5).forEach(alias => {
                const node = document.createElement('span');
                const direction = document.createElement('b');
                direction.textContent = alias.direction === 'incoming' ? 'IN' : 'OUT';
                node.append(direction, document.createTextNode(alias.alias));
                aliases.append(node);
            });
            if ((plan.aliases || []).length > 5) {
                const more = document.createElement('span'); more.textContent = `+${number(plan.aliases.length - 5)} rules`; aliases.append(more);
            }
            card.append(header, description, meta, aliases);
            return card;
        }));
    }

    function renderActions() {
        const selected = view.selectedRun;
        const run = selected && selected.run;
        apply.hidden = Boolean(run && run.status !== 'ready');
        rollback.hidden = !(run && run.status === 'applied');
        apply.disabled = busy || !taxonomyCutoverCanApply(selected);
        rollback.disabled = busy || !(selected && selected.can_rollback === true);
        const title = document.getElementById('cutover-action-title');
        const copy = document.getElementById('cutover-action-copy');
        if (run && run.status === 'applied') {
            title.textContent = 'Cutover audit retained';
            copy.textContent = selected.can_rollback ? 'Rollback checks pass and the protected snapshot remains restorable.' : 'Rollback is blocked because live state has changed since cutover.';
        } else if (selected && selected.can_apply) {
            title.textContent = 'Ready for one atomic write';
            copy.textContent = `${number(selected.metrics.transactions_to_retag)} classifications will change; transaction dates, descriptions and amounts cannot change.`;
        } else {
            title.textContent = 'No live changes available';
            copy.textContent = 'Every safety blocker must be resolved before the apply control is enabled.';
        }
    }

    function render() {
        renderRuns(); renderStatus(); renderMetrics(); renderChecks(); renderFingerprint(); renderProposals(); renderActions(); setBusy(busy);
    }

    async function load(runId = 0) {
        setBusy(true);
        try {
            const suffix = runId ? `?run_id=${encodeURIComponent(runId)}&_=${Date.now()}` : `?_=${Date.now()}`;
            const payload = await fetchJson(`../php_backend/public/tag_taxonomy_cutover.php${suffix}`, { headers: { Accept: 'application/json' } });
            view = normalizeCutoverPayload(payload);
            if (!view) throw new Error('The server returned an invalid cutover preview.');
            render();
        } catch (error) {
            document.getElementById('cutover-status-title').textContent = 'Cutover preview could not be loaded';
            document.getElementById('cutover-status-copy').textContent = error.message;
            document.getElementById('cutover-state').textContent = 'Unavailable';
            document.getElementById('cutover-state').className = 'cutover-state is-error';
            if (window.showOverlay) window.showOverlay(error.message, 'error');
        } finally { setBusy(false); }
    }

    function openConfirm(action) {
        pendingAction = action;
        const isApply = action === 'apply';
        const phrase = isApply ? 'APPLY_REVIEWED_TAXONOMY' : 'ROLLBACK_TAXONOMY_CUTOVER';
        document.getElementById('cutover-confirm-title').textContent = isApply ? 'Apply reviewed taxonomy' : 'Rollback taxonomy cutover';
        document.getElementById('cutover-confirm-copy').textContent = isApply
            ? 'This writes only reviewed classifications and taxonomy relationships. All work is cancelled if reconciliation fails.'
            : 'This restores snapshot classifications and the audited tag, category, and alias state. Newer transactions remain untouched.';
        document.getElementById('cutover-confirm-phrase').textContent = phrase;
        confirmInput.value = '';
        confirmInput.dataset.phrase = phrase;
        confirmSubmit.disabled = true;
        dialog.showModal();
        window.setTimeout(() => confirmInput.focus(), 50);
    }

    async function executePending() {
        const action = pendingAction;
        const phrase = confirmInput.dataset.phrase;
        pendingAction = null;
        if (!action || confirmInput.value !== phrase) return;
        setBusy(true);
        try {
            const payload = await post({ action, run_id: selectedRunId(), confirm: phrase });
            if (window.showOverlay) window.showOverlay(payload.message || 'Taxonomy operation completed.', 'success');
            await load(selectedRunId());
        } catch (error) {
            if (window.showOverlay) window.showOverlay(error.message, 'error');
            else window.alert(error.message);
        } finally { setBusy(false); }
    }

    runSelect.addEventListener('change', () => load(selectedRunId()));
    refresh.addEventListener('click', () => load(selectedRunId()));
    apply.addEventListener('click', () => openConfirm('apply'));
    rollback.addEventListener('click', () => openConfirm('rollback'));
    confirmInput.addEventListener('input', () => { confirmSubmit.disabled = confirmInput.value !== confirmInput.dataset.phrase; });
    dialog.addEventListener('close', () => { if (dialog.returnValue === 'default') executePending(); });
    load();
}

if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', initTagTaxonomyCutover);
if (typeof module !== 'undefined' && module.exports) module.exports = { normalizeCutoverPayload, taxonomyCutoverCanApply };

function normalizeDiscoveryPayload(payload) {
    const source = payload && typeof payload === 'object' && payload.discovery && typeof payload.discovery === 'object'
        ? payload.discovery : null;
    if (!source) return null;
    return {
        schemaReady: source.schema_ready === true,
        schemaMessage: String(source.schema_message || ''),
        runs: Array.isArray(source.runs) ? source.runs : [],
        selectedRun: source.selected_run && typeof source.selected_run === 'object' ? source.selected_run : null,
        categories: Array.isArray(source.categories) ? source.categories : [],
        metrics: source.metrics && typeof source.metrics === 'object' ? source.metrics : {},
        proposals: Array.isArray(source.proposals) ? source.proposals : []
    };
}

const TAXONOMY_EARLY_FINISH_COVERAGE = 95;

function taxonomyCanMarkReady(view) {
    if (!view || !view.selectedRun || view.selectedRun.status !== 'staging') return false;
    const metrics = view.metrics || {};
    return Number(metrics.pending_patterns || 0) === 0
        && Number(metrics.pending_proposals || 0) === 0
        && Number(metrics.approved_proposals || 0) > 0;
}

function taxonomyCanFinishEarly(view) {
    if (!view || !view.selectedRun || view.selectedRun.status !== 'staging') return false;
    const metrics = view.metrics || {};
    return Number(metrics.coverage_percent || 0) >= TAXONOMY_EARLY_FINISH_COVERAGE
        && Number(metrics.pending_patterns || 0) > 0
        && Number(metrics.pending_proposals || 0) === 0
        && Number(metrics.approved_proposals || 0) > 0;
}

function taxonomyProposalValid(name) {
    const value = String(name || '').trim();
    return value.length > 0 && value.length <= 100 && value.toLowerCase() !== 'ignore';
}

function initTagTaxonomyDiscovery() {
    const status = document.getElementById('taxonomy-status');
    if (!status) return;
    const statusTitle = document.getElementById('taxonomy-status-title');
    const statusCopy = document.getElementById('taxonomy-status-copy');
    const runSelect = document.getElementById('taxonomy-run');
    const runDetail = document.getElementById('taxonomy-run-detail');
    const prepareButton = document.getElementById('taxonomy-prepare');
    const analyseButton = document.getElementById('taxonomy-analyse');
    const batchSize = document.getElementById('taxonomy-batch-size');
    const refreshButton = document.getElementById('taxonomy-refresh');
    const readyButton = document.getElementById('taxonomy-ready');
    const cutoverLink = document.getElementById('taxonomy-cutover-link');
    const readyButtonLabel = readyButton.querySelector('span');
    const proposalsNode = document.getElementById('taxonomy-proposals');
    let view = null;
    let busy = false;

    function number(value) {
        return new Intl.NumberFormat().format(Number(value) || 0);
    }

    function percent(value) {
        return `${Number(value || 0).toFixed(1)}%`;
    }

    function money(value) {
        return new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 }).format(Number(value) || 0);
    }

    function selectedRunId() {
        return Number(runSelect.value || (view && view.selectedRun ? view.selectedRun.id : 0));
    }

    function updateControls() {
        const run = view && view.selectedRun;
        const isStaging = run && run.status === 'staging';
        const prepared = run && Boolean(run.discovery_started_at);
        const canFinishEarly = taxonomyCanFinishEarly(view);
        cutoverLink.hidden = !(run && ['ready', 'applied', 'rolled_back'].includes(run.status));
        prepareButton.disabled = busy || !view || !view.schemaReady || !run || prepared || run.status !== 'snapshot';
        analyseButton.disabled = busy || !isStaging || Number((view.metrics || {}).pending_patterns || 0) === 0;
        batchSize.disabled = busy || !isStaging;
        refreshButton.disabled = busy;
        readyButton.disabled = busy || (!taxonomyCanMarkReady(view) && !canFinishEarly);
        readyButtonLabel.textContent = canFinishEarly ? 'Finish and defer remainder' : 'Mark taxonomy ready';
        prepareButton.querySelector('i').classList.toggle('fa-spin', busy && !prepared);
        analyseButton.querySelector('i').classList.toggle('fa-spin', busy && isStaging);
        refreshButton.querySelector('i').classList.toggle('fa-spin', busy);
    }

    function setBusy(value) {
        busy = value;
        status.setAttribute('aria-busy', value ? 'true' : 'false');
        updateControls();
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, options);
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable taxonomy response.');
        }
        if (!response.ok) throw new Error(String(payload.message || 'The taxonomy request failed.'));
        return payload;
    }

    function post(body) {
        return fetchJson('../php_backend/public/tag_taxonomy_discovery.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        });
    }

    function empty(message) {
        const node = document.createElement('div');
        node.className = 'taxonomy-empty';
        node.textContent = message;
        return node;
    }

    function option(value, label) {
        const node = document.createElement('option');
        node.value = String(value);
        node.textContent = label;
        return node;
    }

    function renderRuns() {
        const previous = view.selectedRun ? String(view.selectedRun.id) : '';
        const options = view.runs.map(run => option(run.id, `${run.name} · ${String(run.status || 'snapshot').replaceAll('_', ' ')}`));
        if (!options.length) options.push(option('', 'No Phase 1 snapshots available'));
        runSelect.replaceChildren(...options);
        runSelect.value = previous;
        runSelect.disabled = busy || !view.schemaReady || options.length === 0;
        const run = view.selectedRun;
        if (!run) {
            runDetail.textContent = 'Create a Phase 1 baseline snapshot before beginning discovery.';
            return;
        }
        runDetail.textContent = `${number(run.eligible_count)} eligible transactions · ${number(run.protected_transfer_count)} transfers and ${number(run.protected_ignore_count)} exclusions protected`;
    }

    function renderMetrics() {
        const metrics = view.metrics || {};
        const readyWithDeferred = view.selectedRun && view.selectedRun.status === 'ready' && Number(metrics.deferred_patterns || 0) > 0;
        document.getElementById('taxonomy-patterns').textContent = number(metrics.patterns);
        document.getElementById('taxonomy-pending-label').textContent = readyWithDeferred ? 'Deferred' : 'Awaiting AI';
        document.getElementById('taxonomy-pending-patterns').textContent = number(readyWithDeferred ? metrics.deferred_patterns : metrics.pending_patterns);
        document.getElementById('taxonomy-pending-copy').textContent = readyWithDeferred
            ? `${number(metrics.deferred_transactions)} uncommon transactions left unchanged`
            : 'Processed in bounded batches';
        document.getElementById('taxonomy-proposals-count').textContent = number(metrics.proposals);
        document.getElementById('taxonomy-approved-copy').textContent = `${number(metrics.approved_proposals)} approved · ${number(metrics.pending_proposals)} awaiting review`;
        document.getElementById('taxonomy-coverage').textContent = percent(metrics.coverage_percent);
        document.getElementById('taxonomy-coverage-copy').textContent = `${number(metrics.proposed_transactions)} of ${number(metrics.transactions)} staged`;
    }

    function fieldLabel(text, target) {
        const label = document.createElement('label');
        label.textContent = text;
        label.htmlFor = target;
        return label;
    }

    function evidencePattern(pattern) {
        const node = document.createElement('div');
        const alias = document.createElement('strong');
        const count = document.createElement('span');
        const detail = document.createElement('small');
        node.className = 'taxonomy-pattern';
        alias.textContent = `${pattern.alias} · ${pattern.direction}`;
        count.textContent = `${number(pattern.transaction_count)} · ${money(pattern.absolute_amount)}`;
        detail.textContent = `Previously: ${pattern.current_tags || 'Unassigned'} · AI confidence ${percent(Number(pattern.confidence || 0) * 100)}`;
        node.append(alias, count, detail);
        return node;
    }

    function proposalCard(proposal) {
        const card = document.createElement('article');
        const editor = document.createElement('div');
        const top = document.createElement('div');
        const statusPill = document.createElement('span');
        const confidence = document.createElement('span');
        const nameId = `taxonomy-name-${proposal.id}`;
        const categoryId = `taxonomy-category-${proposal.id}`;
        const descriptionId = `taxonomy-description-${proposal.id}`;
        const name = document.createElement('input');
        const category = document.createElement('select');
        const description = document.createElement('textarea');
        const stats = document.createElement('div');
        const buttons = document.createElement('div');
        const save = document.createElement('button');
        const approve = document.createElement('button');
        const reject = document.createElement('button');
        const evidence = document.createElement('div');
        const evidenceTitle = document.createElement('h3');
        const patternList = document.createElement('div');
        card.className = `taxonomy-proposal-card${proposal.status === 'approved' ? ' is-approved' : ''}`;
        editor.className = 'taxonomy-proposal-card__editor';
        top.className = 'taxonomy-proposal-card__top';
        statusPill.className = 'taxonomy-proposal-status';
        statusPill.textContent = String(proposal.status || 'pending');
        confidence.className = 'taxonomy-confidence';
        confidence.textContent = `AI confidence ${percent(Number(proposal.confidence || 0) * 100)}`;
        top.append(statusPill, confidence);
        name.id = nameId;
        name.type = 'text';
        name.maxLength = 100;
        name.value = String(proposal.canonical_name || '');
        category.id = categoryId;
        category.append(option('', 'No category yet'), ...view.categories.map(item => option(item.id, item.name)));
        category.value = proposal.category_id === null ? '' : String(proposal.category_id);
        description.id = descriptionId;
        description.maxLength = 1000;
        description.value = String(proposal.description || '');
        stats.className = 'taxonomy-proposal-stats';
        [`${number(proposal.pattern_count)} aliases`, `${number(proposal.transaction_count)} transactions`, money(proposal.absolute_amount)].forEach(text => {
            const node = document.createElement('span');
            node.textContent = text;
            stats.append(node);
        });
        buttons.className = 'taxonomy-proposal-buttons';
        save.type = approve.type = reject.type = 'button';
        save.className = 'taxonomy-save';
        approve.className = 'taxonomy-approve';
        reject.className = 'taxonomy-reject';
        save.textContent = 'Save for review';
        approve.textContent = proposal.status === 'approved' ? 'Re-approve changes' : 'Approve canonical tag';
        reject.textContent = 'Reject and reanalyse';
        const submit = statusValue => reviewProposal(proposal.id, statusValue, name, category, description);
        save.addEventListener('click', () => submit('pending'));
        approve.addEventListener('click', () => submit('approved'));
        reject.addEventListener('click', () => {
            if (window.confirm(`Reject “${name.value.trim()}” and return its aliases to the AI queue?`)) submit('rejected');
        });
        buttons.append(save, approve, reject);
        editor.append(top, fieldLabel('Canonical tag', nameId), name, fieldLabel('Existing category (optional)', categoryId), category, fieldLabel('Definition', descriptionId), description, stats, buttons);
        evidence.className = 'taxonomy-evidence';
        evidenceTitle.textContent = 'Alias evidence';
        patternList.className = 'taxonomy-pattern-list';
        const patterns = Array.isArray(proposal.patterns) ? proposal.patterns : [];
        patternList.append(...patterns.slice(0, 8).map(evidencePattern));
        if (patterns.length > 8) {
            const more = document.createElement('div');
            more.className = 'taxonomy-more';
            more.textContent = `${number(patterns.length - 8)} additional aliases are grouped under this proposal.`;
            patternList.append(more);
        }
        if (!patterns.length) patternList.append(empty('Rejected proposal retained as a do-not-suggest rule.'));
        evidence.append(evidenceTitle, patternList);
        card.append(editor, evidence);
        return card;
    }

    function renderProposals() {
        if (!view.selectedRun || !view.selectedRun.discovery_started_at) {
            proposalsNode.replaceChildren(empty('Prepare the selected protected baseline to extract reusable patterns.'));
            return;
        }
        if (!view.proposals.length) {
            proposalsNode.replaceChildren(empty('Patterns are ready. Run the first AI batch to create reviewable canonical proposals.'));
            return;
        }
        proposalsNode.replaceChildren(...view.proposals.map(proposalCard));
    }

    function renderStatus() {
        status.classList.remove('has-error');
        if (!view.schemaReady) {
            status.classList.add('has-error');
            statusTitle.textContent = 'Database preparation is required';
            statusCopy.replaceChildren(document.createTextNode(view.schemaMessage || 'Apply the Phase 2 schema changes first. '));
            const link = document.createElement('a');
            link.href = 'database_health.html';
            link.className = 'taxonomy-schema-link';
            link.textContent = 'Open Database Health';
            statusCopy.append(' ', link);
            return;
        }
        const run = view.selectedRun;
        if (!run) {
            statusTitle.textContent = 'Create a protected baseline first';
            statusCopy.textContent = 'Phase 2 always starts from an immutable Phase 1 classification snapshot.';
        } else if (run.status === 'ready') {
            const deferredPatterns = Number((view.metrics || {}).deferred_patterns || 0);
            statusTitle.textContent = deferredPatterns > 0
                ? `The reviewed taxonomy is ready at ${percent((view.metrics || {}).coverage_percent)}`
                : 'The reviewed taxonomy is ready';
            statusCopy.textContent = deferredPatterns > 0
                ? `${number(deferredPatterns)} uncommon patterns were deferred. They retain their existing assignments and the live ledger is still unchanged.`
                : 'Staging is frozen. No live assignments have changed; cutover belongs to the next phase.';
        } else if (run.discovery_started_at) {
            const canFinishEarly = taxonomyCanFinishEarly(view);
            statusTitle.textContent = canFinishEarly ? 'You can finish at the current coverage' : 'Discovery is isolated from the live ledger';
            statusCopy.textContent = canFinishEarly
                ? 'The reviewed majority can be frozen now and the unresolved remainder safely deferred.'
                : 'Continue AI batches and approve each canonical proposal. Rejecting a tag returns its aliases to the queue.';
        } else {
            statusTitle.textContent = 'Build from the protected snapshot';
            statusCopy.textContent = 'Preparing patterns removes changing references and groups repeat wording without making an AI call.';
        }
    }

    function render(nextView) {
        view = nextView;
        renderRuns();
        renderMetrics();
        renderStatus();
        renderProposals();
        updateControls();
    }

    async function load(runId) {
        setBusy(true);
        try {
            const suffix = runId ? `?run_id=${encodeURIComponent(runId)}` : '';
            const payload = await fetchJson(`../php_backend/public/tag_taxonomy_discovery.php${suffix}`, { headers: { Accept: 'application/json' } });
            const nextView = normalizeDiscoveryPayload(payload);
            if (!nextView) throw new Error('The server returned incomplete discovery information.');
            render(nextView);
        } catch (error) {
            status.classList.add('has-error');
            statusTitle.textContent = 'Taxonomy studio could not load';
            statusCopy.textContent = error.message;
            if (typeof showMessage === 'function') showMessage(error.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function perform(body, loadingMessage) {
        setBusy(true);
        let loadingToast = null;
        const dismissLoading = () => {
            if (loadingToast && typeof dismissNotification === 'function') dismissNotification(loadingToast);
            loadingToast = null;
        };
        if (typeof showMessage === 'function') loadingToast = showMessage(loadingMessage, 'loading');
        try {
            const payload = await post(body);
            const nextView = normalizeDiscoveryPayload(payload);
            if (!nextView) throw new Error('The server did not return the updated taxonomy.');
            render(nextView);
            dismissLoading();
            if (typeof showMessage === 'function') showMessage(payload.message || 'Taxonomy staging updated.', 'success');
        } catch (error) {
            dismissLoading();
            if (typeof showMessage === 'function') showMessage(error.message, 'error');
        } finally {
            dismissLoading();
            setBusy(false);
        }
    }

    async function reviewProposal(proposalId, statusValue, name, category, description) {
        if (!taxonomyProposalValid(name.value)) {
            if (typeof showMessage === 'function') showMessage('Enter a reusable canonical name other than IGNORE.', 'warning');
            name.focus();
            return;
        }
        await perform({
            action: 'review_proposal',
            run_id: selectedRunId(),
            proposal_id: proposalId,
            canonical_name: name.value.trim(),
            category_id: category.value || null,
            description: description.value.trim(),
            status: statusValue
        }, statusValue === 'rejected' ? 'Returning aliases to the review queue…' : 'Saving the staged canonical tag…');
    }

    runSelect.addEventListener('change', () => load(selectedRunId()));
    refreshButton.addEventListener('click', () => load(selectedRunId()));
    prepareButton.addEventListener('click', () => perform({ action: 'prepare', run_id: selectedRunId(), confirm: 'PREPARE_TAXONOMY_DISCOVERY' }, 'Extracting reusable transaction patterns…'));
    analyseButton.addEventListener('click', () => perform({ action: 'analyse_batch', run_id: selectedRunId(), limit: Number(batchSize.value) }, 'AI is designing the next staged taxonomy batch…'));
    readyButton.addEventListener('click', () => {
        const deferRemaining = taxonomyCanFinishEarly(view);
        const metrics = view.metrics || {};
        const confirmation = deferRemaining
            ? `Finish at ${percent(metrics.coverage_percent)} coverage and defer ${number(metrics.pending_patterns)} unresolved patterns? Deferred transactions will remain unchanged.`
            : 'Freeze this reviewed taxonomy as ready for the later cutover phase?';
        if (window.confirm(confirmation)) {
            perform({
                action: 'mark_ready',
                run_id: selectedRunId(),
                defer_remaining: deferRemaining,
                confirm: deferRemaining ? 'MARK_TAXONOMY_READY_WITH_DEFERRED' : 'MARK_TAXONOMY_READY'
            }, deferRemaining ? 'Deferring the unresolved remainder and freezing staging…' : 'Checking taxonomy readiness…');
        }
    });
    load();
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { normalizeDiscoveryPayload, taxonomyCanMarkReady, taxonomyCanFinishEarly, taxonomyProposalValid };
}
if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', initTagTaxonomyDiscovery);

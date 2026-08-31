(function () {
    'use strict';

    const state = { snapshot: null, activeTab: 'inbox', currentInbox: null, rulesTable: null };
    const panels = Array.from(document.querySelectorAll('[data-tagging-panel]'));
    const tabButtons = Array.from(document.querySelectorAll('[data-tagging-tab]'));

    async function requestJson(url, options) {
        const response = await fetch(url, Object.assign({ cache: 'no-store' }, options || {}));
        let payload;
        try { payload = await response.json(); }
        catch (error) { throw new Error('The server returned an unreadable response.'); }
        if (!response.ok || payload.error) {
            const failure = new Error(payload.error || 'The request could not be completed.');
            failure.payload = payload;
            throw failure;
        }
        return payload;
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function number(value) { return Number(value || 0).toLocaleString('en-GB'); }
    function money(value) { return Number(value || 0).toLocaleString('en-GB', { style: 'currency', currency: 'GBP' }); }
    function announce(message, tone) { if (window.showMessage) window.showMessage(message, tone || 'success'); }

    function activateTab(name, updateHash) {
        if (!panels.some(panel => panel.dataset.taggingPanel === name)) name = 'inbox';
        state.activeTab = name;
        tabButtons.forEach(button => {
            const selected = button.dataset.taggingTab === name;
            button.setAttribute('aria-selected', selected ? 'true' : 'false');
            button.tabIndex = selected ? 0 : -1;
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.taggingPanel !== name; });
        if (updateHash !== false && history.replaceState) history.replaceState(null, '', `#${name}`);
        if (name === 'rules') loadRules().catch(error => announce(error.message, 'error'));
    }

    tabButtons.forEach(button => button.addEventListener('click', () => activateTab(button.dataset.taggingTab)));

    function createTagPicker(prefix) {
        const input = document.getElementById(`${prefix}-tag-search`);
        const idInput = document.getElementById(`${prefix}-tag-id`);
        const results = document.getElementById(`${prefix}-tag-results`);
        const status = document.getElementById(`${prefix}-tag-status`);
        let selected = null;
        let timer = null;
        let controller = null;
        let activeIndex = -1;

        function close() {
            results.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }
        function clear(clearText) {
            selected = null;
            idInput.value = '';
            if (clearText) input.value = '';
            results.replaceChildren();
            close();
            status.textContent = 'Search and choose an existing canonical tag.';
        }
        function choose(tag) {
            selected = { id: Number(tag.id), name: String(tag.name) };
            idInput.value = String(selected.id);
            input.value = selected.name;
            results.replaceChildren();
            close();
            status.textContent = `Selected: ${selected.name}`;
        }
        function render(data) {
            const tags = Array.isArray(data.tags) ? data.tags : [];
            results.replaceChildren();
            tags.forEach(tag => {
                const option = element('button', 'tagging-picker-option');
                option.type = 'button';
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.append(element('span', '', tag.name), element('small', '', `#${tag.id}`));
                option.addEventListener('click', () => choose(tag));
                results.appendChild(option);
            });
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            status.textContent = tags.length ? `${tags.length} canonical tags shown.${data.has_more ? ' Keep typing to narrow the list.' : ''}` : 'No canonical tags match.';
        }
        async function search() {
            if (controller) controller.abort();
            controller = new AbortController();
            const params = new URLSearchParams({ options: '1', q: input.value.trim(), limit: '20' });
            status.textContent = 'Searching canonical tags…';
            try { render(await requestJson(`../php_backend/public/tags.php?${params.toString()}`, { signal: controller.signal })); }
            catch (error) { if (error.name !== 'AbortError') status.textContent = error.message; }
        }
        input.addEventListener('input', () => {
            selected = null; idInput.value = ''; window.clearTimeout(timer);
            timer = window.setTimeout(search, 150);
        });
        input.addEventListener('focus', () => { if (results.children.length) results.hidden = false; else search(); });
        input.addEventListener('keydown', event => {
            const options = Array.from(results.querySelectorAll('[role="option"]'));
            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && options.length) {
                event.preventDefault();
                activeIndex = event.key === 'ArrowDown' ? (activeIndex + 1) % options.length : (activeIndex - 1 + options.length) % options.length;
                options.forEach((option, index) => option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false'));
                options[activeIndex].scrollIntoView({ block: 'nearest' });
            } else if (event.key === 'Enter' && activeIndex >= 0 && options[activeIndex]) {
                event.preventDefault(); options[activeIndex].click();
            } else if (event.key === 'Escape') close();
        });
        document.addEventListener('pointerdown', event => { if (!input.parentElement.contains(event.target)) close(); });
        return { clear, choose, selected: () => selected };
    }

    const inboxPicker = createTagPicker('inbox');
    const mergePicker = createTagPicker('merge');
    const rulePicker = createTagPicker('rule');

    function renderMetrics(metrics) {
        document.getElementById('tagging-coverage').textContent = `${Number(metrics.coverage || 0).toFixed(1)}%`;
        document.getElementById('tagging-coverage-bar').style.width = `${Math.min(100, Number(metrics.coverage || 0))}%`;
        TransactionDrilldown.linkify('metric-untagged',{dimension:'tag',unclassified:true,direction:'all',transfer_scope:'exclude',ignored_scope:'exclude',label:'Untagged transactions'},number(metrics.untagged),'View untagged transactions');
        document.getElementById('metric-tags').textContent = number(metrics.active_tags);
        document.getElementById('metric-tags-detail').textContent = `${number(metrics.unused_tags)} unused · ${number(metrics.active_tags)} active`;
        document.getElementById('metric-rules').textContent = number(metrics.active_rules);
        document.getElementById('metric-rules-detail').textContent = `${number(metrics.observed_rules)} observed since tracking began`;
        document.getElementById('metric-gaps').textContent = number(metrics.category_gaps);
        document.getElementById('tab-inbox-count').textContent = number(metrics.untagged);
        document.getElementById('rules-observed').textContent = number(metrics.observed_rules);
        document.getElementById('rules-broad').textContent = number(metrics.broad_rules);
    }

    function inboxMatches(row, query) {
        if (!query) return true;
        return `${row.description || ''} ${row.memo || ''}`.toLocaleLowerCase('en-GB').includes(query);
    }

    function renderInbox() {
        const host = document.getElementById('tagging-inbox');
        const query = document.getElementById('inbox-search').value.trim().toLocaleLowerCase('en-GB');
        const rows = (state.snapshot.inbox || []).filter(row => inboxMatches(row, query));
        host.replaceChildren();
        if (!rows.length) {
            host.appendChild(element('div', 'tagging-empty', query ? 'No unmatched wording matches this search.' : 'Nothing needs tagging. New imports will appear here only when saved rules cannot classify them.'));
            return;
        }
        rows.forEach(row => {
            const article = element('article', 'tagging-inbox-row');
            const identity = element('div');
            identity.append(element('h3', '', row.description || 'No description'), element('p', '', row.memo || 'No additional memo'));
            const scope = element('div');
            scope.append(element('span', 'tagging-direction', row.direction === 'outgoing' ? 'Money leaving' : row.direction === 'incoming' ? 'Money arriving' : 'Either direction'), element('small', '', `Latest ${row.latest_date || '—'}`));
            const totals = element('div');
            const evidence={description_exact:row.description,memo_exact:row.memo||'',direction:row.direction==='incoming'?'income':'spending',transfer_scope:'exclude',ignored_scope:'exclude',label:`Unmatched ${row.description}`};
            const count=element('strong');const countLink=element('a','transaction-drilldown-link',number(row.transaction_count));countLink.href=TransactionDrilldown.url(evidence);countLink.setAttribute('aria-label',`View ${row.transaction_count} unmatched transactions`);count.appendChild(countLink);
            const total=element('small');const totalLink=element('a','transaction-drilldown-link',money(row.total_amount));totalLink.href=TransactionDrilldown.url(evidence);total.appendChild(totalLink);totals.append(count,total);
            const button = element('button', 'tagging-primary', 'Resolve');
            button.type = 'button';
            button.addEventListener('click', () => openInbox(row));
            article.append(identity, scope, totals, button);
            host.appendChild(article);
        });
    }

    function openInbox(row) {
        state.currentInbox = row;
        document.getElementById('inbox-dialog-copy').textContent = `${row.transaction_count} transaction${Number(row.transaction_count) === 1 ? '' : 's'} use “${row.description}”. Choose the existing tag they should reuse.`;
        inboxPicker.clear(true);
        document.getElementById('inbox-dialog').showModal();
    }

    document.getElementById('inbox-search').addEventListener('input', renderInbox);
    async function resolveInboxPattern(selected, confirmOverlap) {
        const response = await requestJson('../php_backend/public/tagging_workspace.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'resolve_inbox', alias: state.currentInbox.description, direction: state.currentInbox.direction, tag_id: selected.id, confirm_overlap: Boolean(confirmOverlap) })
        });
        if (response.result.requires_confirmation) {
            const names = (response.result.overlaps || []).map(item => `${item.alias} → ${item.tag_name}`).join('\n');
            if (!window.confirm(`This wording overlaps rules for another tag:\n\n${names}\n\nSave the more specific rule anyway?`)) return null;
            return resolveInboxPattern(selected, true);
        }
        return response;
    }

    document.getElementById('inbox-form').addEventListener('submit', async event => {
        if (event.submitter && event.submitter.value === 'cancel') return;
        event.preventDefault();
        const selected = inboxPicker.selected();
        if (!selected || !state.currentInbox) { announce('Choose an existing canonical tag.', 'error'); return; }
        const button = document.getElementById('inbox-submit'); button.disabled = true;
        try {
            const response = await resolveInboxPattern(selected, false);
            if (!response) return;
            document.getElementById('inbox-dialog').close();
            announce(`${number(response.result.tagged)} transactions tagged using ${selected.name}.`);
            await loadSnapshot();
        } catch (error) { announce(error.message, 'error'); }
        finally { button.disabled = false; }
    });

    function categoryOptions(selectedId) {
        const select = document.getElementById('tag-edit-category');
        select.replaceChildren(new Option('Unassigned', ''));
        (state.snapshot.categories || []).forEach(category => select.appendChild(new Option(category.name, String(category.id))));
        select.value = selectedId === null || selectedId === undefined ? '' : String(selectedId);
    }

    function renderCatalogue() {
        const body = document.getElementById('catalogue-body');
        const query = document.getElementById('catalogue-search').value.trim().toLocaleLowerCase('en-GB');
        const tags = (state.snapshot.tags || []).filter(tag => `${tag.name} ${tag.description || ''} ${tag.category_name || ''} ${tag.segment_name || ''}`.toLocaleLowerCase('en-GB').includes(query));
        body.replaceChildren();
        tags.forEach(tag => {
            const row = document.createElement('tr');
            const identity = document.createElement('td');
            identity.append(element('span', 'tagging-tag-name', tag.name), element('span', 'tagging-meta', tag.description || 'No description'));
            const home = document.createElement('td');
            home.append(element('span', '', tag.category_name || 'Unassigned'), element('span', 'tagging-meta', tag.segment_name || 'No segment'));
            const transactions = element('td');const transactionLink=element('a','transaction-drilldown-link',number(tag.transaction_count));transactionLink.href=TransactionDrilldown.url({dimension:'tag',dimension_id:tag.id,direction:'all',transfer_scope:'include',ignored_scope:'include',label:`Transactions tagged ${tag.name}`});transactionLink.setAttribute('aria-label',`View transactions tagged ${tag.name}`);transactions.appendChild(transactionLink);
            const rules = document.createElement('td');
            rules.append(element('span', '', number(tag.rule_count)), element('span', 'tagging-meta', tag.last_rule_match ? `Last used ${String(tag.last_rule_match).slice(0, 10)}` : 'No recorded use yet'));
            const actions = document.createElement('td');
            const group = element('div', 'tagging-row-actions');
            if (!tag.protected) {
                const edit = element('button', '', 'Edit'); edit.type = 'button'; edit.addEventListener('click', () => openTag(tag));
                const merge = element('button', '', 'Merge'); merge.type = 'button'; merge.addEventListener('click', () => openMerge(tag));
                const retire = element('button', 'is-danger', 'Retire'); retire.type = 'button'; retire.addEventListener('click', () => retireTag(tag));
                group.append(edit, merge, retire);
            } else group.appendChild(element('span', 'tagging-protected', 'Protected'));
            actions.appendChild(group); row.append(identity, home, transactions, rules, actions); body.appendChild(row);
        });
    }

    document.getElementById('catalogue-search').addEventListener('input', renderCatalogue);
    document.getElementById('new-tag').addEventListener('click', () => openTag(null));

    function openTag(tag, proposedName) {
        document.getElementById('tag-dialog-title').textContent = tag ? 'Edit canonical tag' : 'New canonical tag';
        document.getElementById('tag-edit-id').value = tag ? String(tag.id) : '';
        document.getElementById('tag-edit-name').value = tag ? tag.name : (proposedName || '');
        document.getElementById('tag-edit-description').value = tag ? (tag.description || '') : '';
        categoryOptions(tag ? tag.category_id : null);
        document.getElementById('tag-dialog').showModal();
        document.getElementById('tag-edit-name').focus();
    }

    document.getElementById('tag-form').addEventListener('submit', async event => {
        if (event.submitter && event.submitter.value === 'cancel') return;
        event.preventDefault();
        const id = Number(document.getElementById('tag-edit-id').value || 0);
        const payload = { action: id ? 'update_tag' : 'create_tag', id, name: document.getElementById('tag-edit-name').value.trim(), description: document.getElementById('tag-edit-description').value.trim(), category_id: document.getElementById('tag-edit-category').value };
        const button = document.getElementById('tag-submit'); button.disabled = true;
        try {
            const response = await requestJson('../php_backend/public/tagging_workspace.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            document.getElementById('tag-dialog').close();
            announce(response.result.reactivated ? 'Canonical tag restored.' : id ? 'Canonical tag updated.' : 'Canonical tag created.');
            await loadSnapshot();
        } catch (error) { announce(error.message, 'error'); }
        finally { button.disabled = false; }
    });

    async function retireTag(tag) {
        if (!window.confirm(`Retire ${tag.name}? Its ${number(tag.transaction_count)} historical transaction assignments will remain, while its rules are disabled.`)) return;
        try {
            const response = await requestJson('../php_backend/public/tagging_workspace.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'retire_tag', id: tag.id }) });
            announce(`${tag.name} retired; ${number(response.result.transactions_retained)} historical assignments retained.`);
            await loadSnapshot();
        } catch (error) { announce(error.message, 'error'); }
    }

    function openMerge(tag) {
        document.getElementById('merge-source-id').value = String(tag.id);
        document.getElementById('merge-source-name').textContent = tag.name;
        mergePicker.clear(true);
        document.getElementById('merge-dialog').showModal();
    }

    document.getElementById('merge-form').addEventListener('submit', async event => {
        if (event.submitter && event.submitter.value === 'cancel') return;
        event.preventDefault();
        const sourceId = Number(document.getElementById('merge-source-id').value);
        const target = mergePicker.selected();
        if (!target || target.id === sourceId) { announce('Choose a different destination tag.', 'error'); return; }
        if (!window.confirm(`Merge this tag into ${target.name}? This moves its transactions and rules and cannot be undone from this screen.`)) return;
        const button = document.getElementById('merge-submit'); button.disabled = true;
        try {
            const response = await requestJson('../php_backend/public/tagging_workspace.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'merge_tag', source_id: sourceId, target_id: target.id }) });
            document.getElementById('merge-dialog').close();
            announce(`${number(response.result.transactions_moved)} transactions and ${number(response.result.rules_moved)} rules merged into ${target.name}.`);
            await loadSnapshot();
        } catch (error) { announce(error.message, 'error'); }
        finally { button.disabled = false; }
    });

    async function loadRules() {
        if (state.rulesTable) { await state.rulesTable.setData(); return; }
        state.rulesTable = tailwindTabulator('#rules-table', {
            ajaxURL: '../php_backend/public/tag_aliases.php', layout: 'fitDataStretch', pagination: true, paginationMode: 'remote', paginationSize: 25,
            paginationSizeSelector: [25, 50, 100], sortMode: 'remote', initialSort: [{ column: 'alias', dir: 'asc' }], modernRemoteSearchParam: 'q', modernMaxHeight: '68vh',
            columns: [
                { title: 'Wording', field: 'alias' },
                { title: 'Canonical Tag', field: 'tag_name', formatter: badgeFormatter('bg-indigo-200 text-indigo-800') },
                { title: 'Scope', field: 'direction', formatter: cell => `${cell.getRow().getData().match_type === 'exact' ? 'Exact' : 'Contains'} · ${{ outgoing: 'Leaving', incoming: 'Arriving', any: 'Either' }[cell.getValue()] || 'Either'}` },
                { title: 'Evidence', field: 'last_matched_at', formatter: cell => { const row = cell.getRow().getData(); return row.last_matched_at ? `${number(row.support_count)} matches · ${String(row.last_matched_at).slice(0, 10)}` : 'Not observed yet'; } },
                { title: 'State', field: 'active', formatter: cell => Number(cell.getValue()) === 1 ? 'Active' : 'Paused' },
                { title: 'Actions', formatter: cell => ruleActions(cell.getRow().getData()) }
            ]
        });
    }

    function ruleActions(row) {
        const group = element('div', 'tagging-row-actions');
        const edit = element('button', '', 'Edit'); edit.type = 'button'; edit.addEventListener('click', () => openRule(row));
        const toggle = element('button', '', Number(row.active) === 1 ? 'Pause' : 'Enable'); toggle.type = 'button'; toggle.addEventListener('click', () => saveRule(Object.assign({}, row, { active: Number(row.active) !== 1 }), true));
        const remove = element('button', 'is-danger', 'Remove'); remove.type = 'button'; remove.addEventListener('click', () => removeRule(row));
        group.append(edit, toggle, remove); return group;
    }

    document.getElementById('new-rule').addEventListener('click', () => openRule(null));
    function openRule(row) {
        document.getElementById('rule-dialog-title').textContent = row ? 'Edit matching rule' : 'New matching rule';
        document.getElementById('rule-edit-id').value = row ? String(row.id) : '';
        document.getElementById('rule-alias').value = row ? row.alias : '';
        document.getElementById('rule-match-type').value = row ? row.match_type : 'contains';
        document.getElementById('rule-direction').value = row ? (row.direction || 'any') : 'any';
        document.getElementById('rule-active').checked = row ? Number(row.active) === 1 : true;
        if (row) rulePicker.choose({ id: row.tag_id, name: row.tag_name }); else rulePicker.clear(true);
        document.getElementById('rule-dialog').showModal();
    }

    async function saveRule(row, forceOverlap) {
        const payload = { id: Number(row.id || 0), alias: row.alias, tag_id: Number(row.tag_id), match_type: row.match_type || 'contains', direction: row.direction || 'any', active: Boolean(row.active), confirm_overlap: Boolean(forceOverlap) };
        try {
            await requestJson('../php_backend/public/tag_aliases.php', { method: payload.id ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        } catch (error) {
            if (error.payload && error.payload.requires_confirmation) {
                const names = (error.payload.overlaps || []).map(item => `${item.alias} → ${item.tag_name}`).join('\n');
                if (window.confirm(`${error.message}\n\n${names}\n\nSave it anyway? Longer, direction-specific and exact rules take precedence.`)) return saveRule(row, true);
            }
            throw error;
        }
        if (state.rulesTable) await state.rulesTable.setData();
        await loadSnapshot(false);
        announce(payload.id ? 'Rule updated.' : 'Rule created.');
    }

    document.getElementById('rule-form').addEventListener('submit', async event => {
        if (event.submitter && event.submitter.value === 'cancel') return;
        event.preventDefault();
        const selected = rulePicker.selected();
        if (!selected) { announce('Choose an existing canonical tag.', 'error'); return; }
        const button = document.getElementById('rule-submit'); button.disabled = true;
        try {
            await saveRule({ id: Number(document.getElementById('rule-edit-id').value || 0), alias: document.getElementById('rule-alias').value.trim(), tag_id: selected.id, match_type: document.getElementById('rule-match-type').value, direction: document.getElementById('rule-direction').value, active: document.getElementById('rule-active').checked }, false);
            document.getElementById('rule-dialog').close();
        } catch (error) { announce(error.message, 'error'); }
        finally { button.disabled = false; }
    });

    async function removeRule(row) {
        if (!window.confirm(`Remove the rule “${row.alias}”? Existing transaction tags are not changed.`)) return;
        try {
            await requestJson('../php_backend/public/tag_aliases.php', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: row.id }) });
            announce('Rule removed.'); await state.rulesTable.setData(); await loadSnapshot(false);
        } catch (error) { announce(error.message, 'error'); }
    }

    function showAutomationResult(title, copy, reviews) {
        const host = document.getElementById('automation-result'); host.replaceChildren(); host.hidden = false;
        host.append(element('strong', '', title), element('p', '', copy));
        if (Array.isArray(reviews) && reviews.length) {
            const list = element('div', 'tagging-review-list');
            reviews.forEach(review => {
                const item = element('div', 'tagging-review-item');
                const copyNode = element('div'); copyNode.append(element('strong', '', review.suggested_tag), element('small', '', `${number(review.transactions)} transactions held for review`));
                const button = element('button', 'tagging-secondary', 'Review as new tag'); button.type = 'button';
                button.addEventListener('click', () => { activateTab('catalogue'); openTag(null, review.suggested_tag === 'Unresolved pattern' ? '' : review.suggested_tag); });
                item.append(copyNode, button); list.appendChild(item);
            });
            host.appendChild(list);
        }
    }

    document.getElementById('run-ai-tagging').addEventListener('click', async event => {
        const button = event.currentTarget; button.disabled = true; showAutomationResult('Smart tagging is running…', 'Saved rules are applied first; AI can only choose from the canonical catalogue.');
        try {
            const data = await requestJson('../php_backend/public/ai_tags.php', { method: 'POST' });
            showAutomationResult('Smart tagging complete', `${number(data.processed)} transactions tagged. ${number(data.review_required_count)} unfamiliar suggestions were held back safely.`, data.review_required);
            announce('Smart tagging complete.'); await loadSnapshot(false);
        } catch (error) { showAutomationResult('Smart tagging could not finish', error.message); announce(error.message, 'error'); }
        finally { button.disabled = false; }
    });

    document.getElementById('run-ai-categories').addEventListener('click', async event => {
        const button = event.currentTarget; button.disabled = true; showAutomationResult('Category review is running…', 'Existing links will not be changed.');
        try {
            const data = await requestJson('../php_backend/public/ai_category_tags.php', { method: 'POST' });
            showAutomationResult('Category review complete', `${number(data.applied || data.updated || 0)} tag-to-category links applied. ${number(data.skipped || data.rejected || 0)} uncertain suggestions skipped.`);
            announce('Tag categories reviewed.'); await loadSnapshot(false);
        } catch (error) { showAutomationResult('Category review could not finish', error.message); announce(error.message, 'error'); }
        finally { button.disabled = false; }
    });

    function renderFreshStartPreview(preview) {
        const host = document.getElementById('fresh-start-summary');
        host.replaceChildren();
        [
            ['Transactions to clear', preview.classified_transactions],
            ['Rules to remove', preview.rules_to_remove],
            ['Category links to clear', preview.category_links_to_clear],
            ['Canonical tags retained', preview.canonical_tags_retained]
        ].forEach(item => {
            const card = element('div');
            card.append(element('span', '', item[0]), element('strong', '', number(item[1])));
            host.appendChild(card);
        });
    }

    const freshStartDialog = document.getElementById('fresh-start-dialog');
    const freshStartConfirmation = document.getElementById('fresh-start-confirmation');
    const freshStartSubmit = document.getElementById('fresh-start-submit');
    document.getElementById('open-fresh-start').addEventListener('click', () => {
        renderFreshStartPreview(state.snapshot.fresh_start || {});
        freshStartConfirmation.value = '';
        freshStartSubmit.disabled = true;
        freshStartDialog.showModal();
        freshStartConfirmation.focus();
    });
    freshStartConfirmation.addEventListener('input', () => {
        freshStartSubmit.disabled = freshStartConfirmation.value.trim() !== 'START FRESH';
    });
    document.getElementById('fresh-start-form').addEventListener('submit', async event => {
        if (event.submitter && event.submitter.value === 'cancel') return;
        event.preventDefault();
        if (freshStartConfirmation.value.trim() !== 'START FRESH') return;
        freshStartSubmit.disabled = true;
        announce('Taking a safety snapshot and resetting tagging…', 'loading');
        try {
            const response = await requestJson('../php_backend/public/tagging_workspace.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start_fresh', confirmation: freshStartConfirmation.value.trim() })
            });
            const result = response.result;
            freshStartDialog.close();
            if (state.rulesTable) await state.rulesTable.setData();
            await loadSnapshot(false);
            const configured = state.snapshot.automation && state.snapshot.automation.configured;
            showAutomationResult(configured ? 'Ready for a clean AI pass' : 'Reset complete — configure AI next', `${number(result.transactions_reset)} transactions cleared, ${number(result.rules_removed)} old rules removed and ${number(result.category_links_cleared)} category links removed. Safety snapshot #${number(result.snapshot_run_id)} was saved.`);
            announce(configured ? 'Tagging reset complete. Run smart tagging when ready.' : 'Tagging reset complete. Configure AI before rebuilding.', configured ? 'success' : 'warning');
            (configured ? document.getElementById('run-ai-tagging') : document.querySelector('a[href="../settings.php"]'))?.focus();
        } catch (error) {
            announce(error.message, 'error');
        } finally {
            freshStartSubmit.disabled = freshStartConfirmation.value.trim() !== 'START FRESH';
        }
    });

    function renderHistory(historyData) {
        const host = document.getElementById('rebuild-history'); host.replaceChildren();
        if (!historyData) { host.appendChild(element('div', 'tagging-empty', 'No taxonomy rebuild has been recorded.')); return; }
        if (historyData.fresh_start) {
            const reset = historyData.fresh_start;
            const result = reset.result || {};
            const status = element('div');
            status.append(element('span', 'tagging-eyebrow', 'Fresh-start safety snapshot'), element('h3', 'tagging-tag-name', historyData.name), element('p', 'tagging-meta', `Reset ${reset.reset_at || historyData.created_at || '—'} · Run #${historyData.id}`));
            host.appendChild(status);
            const grid = element('div', 'tagging-history-grid');
            [['Transactions cleared', result.transactions_reset], ['Rules removed', result.rules_removed], ['Category links cleared', result.category_links_cleared], ['Canonical tags retained', reset.retained && reset.retained.canonical_tags]].forEach(item => {
                const card = element('div'); card.append(element('span', '', item[0]), element('strong', '', item[1] === undefined || item[1] === null ? '—' : number(item[1])), element('small', '', 'Audited reset')); grid.appendChild(card);
            });
            host.appendChild(grid);
            return;
        }
        const status = element('div'); status.append(element('span', 'tagging-eyebrow', historyData.cleanup_completed ? 'Completed and cleaned' : historyData.status), element('h3', 'tagging-tag-name', historyData.name), element('p', 'tagging-meta', `Applied ${historyData.applied_at || '—'} · Run #${historyData.id}`));
        host.appendChild(status);
        const metrics = historyData.cleanup_metrics || {};
        const grid = element('div', 'tagging-history-grid');
        [['Legacy tags retired', metrics.tags_to_deprecate], ['Rules disabled', metrics.aliases_to_disable], ['History retained', metrics.transactions_retaining_history], ['Active legacy remaining', metrics.remaining_active_legacy_tags]].forEach(item => {
            const card = element('div'); card.append(element('span', '', item[0]), element('strong', '', item[1] === undefined || item[1] === null ? '—' : number(item[1])), element('small', '', historyData.cleanup_completed ? 'Audited result' : 'Awaiting completion')); grid.appendChild(card);
        });
        host.appendChild(grid);
    }

    async function loadSnapshot(renderAll) {
        state.snapshot = await requestJson('../php_backend/public/tagging_workspace.php?limit=100');
        renderMetrics(state.snapshot.metrics || {});
        renderInbox(); renderCatalogue(); renderHistory(state.snapshot.rebuild_history);
        renderFreshStartPreview(state.snapshot.fresh_start || {});
        const configured = state.snapshot.automation && state.snapshot.automation.configured;
        document.getElementById('run-ai-tagging').disabled = !configured;
        document.getElementById('run-ai-categories').disabled = !configured;
        if (!configured) showAutomationResult('AI is not configured', 'Add an OpenAI API token in Settings before running smart tagging.');
        if (renderAll !== false && state.activeTab === 'rules') await loadRules();
    }

    const initialTab = String(location.hash || '').replace('#', '') || 'inbox';
    activateTab(initialTab, false);
    loadSnapshot().catch(error => { announce(error.message, 'error'); document.getElementById('tagging-inbox').appendChild(element('div', 'tagging-empty', 'The tagging workspace could not be loaded.')); });
})();

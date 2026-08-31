(function () {
    'use strict';

    const groupList = document.getElementById('group-list');
    const groupSearch = document.getElementById('group-search');
    const groupForm = document.getElementById('group-form');
    const groupNameInput = document.getElementById('group-name');
    const loadingState = document.getElementById('group-loading');
    const emptyState = document.getElementById('group-empty');
    const manager = document.getElementById('group-manager');
    const editForm = document.getElementById('group-edit-form');
    const editName = document.getElementById('group-edit-name');
    const editDescription = document.getElementById('group-edit-description');
    const editActive = document.getElementById('group-edit-active');

    const state = {
        groups: [],
        selectedGroupId: null,
        query: '',
        isSaving: false
    };

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    async function requestJson(url, options) {
        const response = await fetch(url, Object.assign({ cache: 'no-store' }, options || {}));
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable response.');
        }
        if (!response.ok || (payload && payload.error)) {
            throw new Error(payload && payload.error ? payload.error : 'The request could not be completed.');
        }
        return payload;
    }

    function announce(message, isError) {
        window.showMessage(message, isError ? 'error' : 'success');
    }

    function normalise(value) {
        return String(value || '').trim().toLocaleLowerCase('en-GB');
    }

    function selectedGroup() {
        return state.groups.find(group => Number(group.id) === Number(state.selectedGroupId)) || null;
    }

    function transactionCount(group) {
        return Number(group && group.transaction_count) || 0;
    }

    function statusText(group) {
        return Number(group && group.active) === 1 ? 'Active' : 'Inactive';
    }

    function renderGroupList() {
        const query = normalise(state.query);
        const groups = state.groups.filter(group => {
            const haystack = normalise(`${group.name} ${group.description || ''} ${statusText(group)}`);
            return !query || haystack.includes(query);
        });

        document.getElementById('group-count').textContent = String(state.groups.length);
        groupList.replaceChildren();
        if (!groups.length) {
            const message = state.groups.length
                ? 'No groups match this search.'
                : 'Add your first group below.';
            groupList.appendChild(element('div', 'group-list-empty', message));
            return;
        }

        groups.forEach(group => {
            const count = transactionCount(group);
            const active = Number(group.active) === 1;
            const button = element('button', 'group-list-button');
            button.type = 'button';
            button.dataset.groupId = String(group.id);
            button.setAttribute('aria-current', Number(group.id) === Number(state.selectedGroupId) ? 'true' : 'false');
            button.setAttribute('aria-label', `${group.name}, ${count} linked transaction${count === 1 ? '' : 's'}, ${statusText(group).toLowerCase()}`);

            const copy = element('span', 'group-list-copy');
            copy.append(
                element('span', 'group-list-name', group.name),
                element('span', 'group-list-description', group.description || 'No description added')
            );
            const meta = element('span', 'group-list-meta');
            meta.appendChild(element('span', `group-list-status${active ? '' : ' is-inactive'}`, statusText(group)));
            meta.appendChild(element('span', 'group-list-total', `${count.toLocaleString('en-GB')} linked`));
            button.append(copy, meta);

            button.addEventListener('click', () => {
                state.selectedGroupId = Number(group.id);
                editForm.hidden = true;
                render();
            });
            groupList.appendChild(button);
        });
    }

    function renderSelection(group) {
        const active = Number(group.active) === 1;
        const status = document.getElementById('selected-group-status');
        const toggle = document.getElementById('toggle-group-button');
        const count = transactionCount(group);
        const statusLabel = statusText(group);

        document.getElementById('selected-group-name').textContent = group.name;
        document.getElementById('selected-group-description').textContent = group.description || 'No description added.';
        status.textContent = statusLabel;
        status.className = `group-status-badge${active ? '' : ' is-inactive'}`;
        TransactionDrilldown.linkify('summary-transactions',{dimension:'group',dimension_id:group.id,direction:'all',transfer_scope:'include',ignored_scope:'include',label:`Transactions linked to ${group.name}`},count.toLocaleString('en-GB'),`View ${count} transactions linked to ${group.name}`);
        document.getElementById('summary-status').textContent = statusLabel;
        toggle.textContent = active ? 'Deactivate' : 'Activate';
        toggle.className = `group-status-button${active ? '' : ' is-inactive'}`;
        toggle.setAttribute('aria-label', `${active ? 'Deactivate' : 'Activate'} ${group.name}`);
        toggle.disabled = state.isSaving;
        document.getElementById('edit-group-button').disabled = state.isSaving;
        document.getElementById('delete-group-button').disabled = state.isSaving;
    }

    function render() {
        if (state.groups.length && !selectedGroup()) state.selectedGroupId = Number(state.groups[0].id);
        renderGroupList();

        const hasGroups = state.groups.length > 0;
        loadingState.hidden = true;
        emptyState.hidden = hasGroups;
        manager.hidden = !hasGroups;
        if (hasGroups) renderSelection(selectedGroup());
    }

    async function loadData(showLoading) {
        if (showLoading) {
            loadingState.hidden = false;
            emptyState.hidden = true;
            manager.hidden = true;
        }
        const groups = await requestJson('../php_backend/public/groups.php');
        state.groups = Array.isArray(groups) ? groups : [];
        if (state.selectedGroupId && !selectedGroup()) state.selectedGroupId = null;
        render();
    }

    async function updateGroup(group, payload, successMessage) {
        if (!group || state.isSaving) return;
        state.isSaving = true;
        renderSelection(group);
        try {
            await requestJson('../php_backend/public/groups.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ id: Number(group.id) }, payload))
            });
            editForm.hidden = true;
            await loadData(false);
            announce(successMessage, false);
        } catch (error) {
            announce(error.message || 'The group could not be updated.', true);
        } finally {
            state.isSaving = false;
            if (selectedGroup()) render();
        }
    }

    groupSearch.addEventListener('input', event => {
        state.query = event.target.value;
        renderGroupList();
    });

    groupForm.addEventListener('submit', async event => {
        event.preventDefault();
        const name = groupNameInput.value.trim();
        if (!name || state.isSaving) return;

        const submit = groupForm.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
            const result = await requestJson('../php_backend/public/groups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name, description: '', active: true })
            });
            state.selectedGroupId = Number(result.id);
            state.query = '';
            groupSearch.value = '';
            groupNameInput.value = '';
            await loadData(false);
            announce(`${name} created and selected.`, false);
        } catch (error) {
            announce(error.message || 'The group could not be created.', true);
        } finally {
            submit.disabled = false;
        }
    });

    document.getElementById('edit-group-button').addEventListener('click', () => {
        const group = selectedGroup();
        if (!group) return;
        editName.value = group.name;
        editDescription.value = group.description || '';
        editActive.checked = Number(group.active) === 1;
        editForm.hidden = false;
        editName.focus();
    });

    document.getElementById('cancel-group-edit').addEventListener('click', () => {
        editForm.hidden = true;
    });

    editForm.addEventListener('submit', event => {
        event.preventDefault();
        const group = selectedGroup();
        const name = editName.value.trim();
        if (!group || !name) return;
        updateGroup(group, {
            name: name,
            description: editDescription.value.trim(),
            active: editActive.checked
        }, 'Group details updated.');
    });

    document.getElementById('toggle-group-button').addEventListener('click', () => {
        const group = selectedGroup();
        if (!group) return;
        const active = Number(group.active) === 1;
        updateGroup(group, {
            name: group.name,
            description: group.description || '',
            active: !active
        }, `${group.name} ${active ? 'deactivated' : 'activated'}.`);
    });

    document.getElementById('delete-group-button').addEventListener('click', async () => {
        const group = selectedGroup();
        if (!group || state.isSaving) return;
        const count = transactionCount(group);
        const warning = count
            ? `Delete ${group.name}? This will clear its link from ${count.toLocaleString('en-GB')} transaction${count === 1 ? '' : 's'} before deleting the group.`
            : `Delete ${group.name}?`;
        if (!window.confirm(warning)) return;

        state.isSaving = true;
        renderSelection(group);
        try {
            await requestJson('../php_backend/public/groups.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(group.id) })
            });
            state.selectedGroupId = null;
            await loadData(false);
            announce(`${group.name} deleted.`, false);
        } catch (error) {
            announce(error.message || 'The group could not be deleted.', true);
        } finally {
            state.isSaving = false;
            if (selectedGroup()) render();
        }
    });

    loadData(true).catch(error => {
        loadingState.replaceChildren();
        const icon = element('i', 'fas fa-triangle-exclamation');
        icon.setAttribute('aria-hidden', 'true');
        loadingState.append(icon, element('p', '', error.message || 'Groups could not be loaded.'));
        announce(error.message || 'Groups could not be loaded.', true);
    });
})();

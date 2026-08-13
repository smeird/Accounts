(function () {
    'use strict';

    const categoryList = document.getElementById('category-list');
    const categorySearch = document.getElementById('category-search');
    const categoryForm = document.getElementById('category-form');
    const categoryNameInput = document.getElementById('category-name');
    const tagSearch = document.getElementById('tag-search');
    const tagList = document.getElementById('tag-list');
    const filterButtons = Array.from(document.querySelectorAll('[data-tag-filter]'));
    const loadingState = document.getElementById('category-loading');
    const emptyState = document.getElementById('category-empty');
    const manager = document.getElementById('category-manager');
    const editForm = document.getElementById('category-edit-form');
    const editName = document.getElementById('category-edit-name');
    const editDescription = document.getElementById('category-edit-description');
    const toast = document.getElementById('category-toast');

    const state = {
        categories: [],
        tags: [],
        selectedCategoryId: null,
        categoryQuery: '',
        tagQuery: '',
        filter: 'unassigned',
        pendingTagId: null,
        isSaving: false,
        selectedTagIds: new Set(),
        visibleTagIds: [],
        toastTimer: null
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
        window.clearTimeout(state.toastTimer);
        toast.textContent = message;
        toast.classList.toggle('is-error', Boolean(isError));
        toast.hidden = false;
        state.toastTimer = window.setTimeout(() => { toast.hidden = true; }, isError ? 6000 : 3000);
    }

    function selectedCategory() {
        return state.categories.find(category => Number(category.id) === Number(state.selectedCategoryId)) || null;
    }

    function categoryMap() {
        const map = new Map();
        state.categories.forEach(category => {
            (category.tags || []).forEach(tag => map.set(Number(tag.id), category));
        });
        return map;
    }

    function normalise(value) {
        return String(value || '').trim().toLocaleLowerCase('en-GB');
    }

    function renderCategoryList() {
        const query = normalise(state.categoryQuery);
        const categories = state.categories.filter(category => {
            const haystack = normalise(`${category.name} ${category.description || ''} ${category.segment_name || ''}`);
            return !query || haystack.includes(query);
        });

        document.getElementById('category-count').textContent = String(state.categories.length);
        categoryList.replaceChildren();
        if (!categories.length) {
            const message = state.categories.length
                ? 'No categories match this search.'
                : 'Add your first category below.';
            categoryList.appendChild(element('div', 'category-list-empty', message));
            return;
        }

        categories.forEach(category => {
            const button = element('button', 'category-list-button');
            button.type = 'button';
            button.dataset.categoryId = String(category.id);
            button.setAttribute('aria-current', Number(category.id) === Number(state.selectedCategoryId) ? 'true' : 'false');
            button.setAttribute('aria-label', `${category.name}, ${(category.tags || []).length} linked tag${(category.tags || []).length === 1 ? '' : 's'}`);
            button.append(
                element('span', 'category-list-name', category.name),
                element('span', 'category-list-total', String((category.tags || []).length))
            );
            button.addEventListener('click', () => {
                state.selectedCategoryId = Number(category.id);
                state.filter = 'unassigned';
                state.tagQuery = '';
                state.selectedTagIds.clear();
                tagSearch.value = '';
                editForm.hidden = true;
                render();
            });
            categoryList.appendChild(button);
        });
    }

    function renderSelectionHeader(category, assignments) {
        document.getElementById('selected-category-name').textContent = category.name;
        document.getElementById('selected-category-description').textContent = category.description || 'No description added.';
        const linked = assignments.filter(item => item.category && Number(item.category.id) === Number(category.id)).length;
        const unassigned = assignments.filter(item => !item.category).length;
        document.getElementById('selected-category-total').textContent = `${linked} linked`;
        document.getElementById('summary-linked').textContent = String(linked);
        document.getElementById('summary-unassigned').textContent = String(unassigned);
        document.getElementById('filter-linked-count').textContent = String(linked);
        document.getElementById('filter-unassigned-count').textContent = String(unassigned);
        document.getElementById('filter-all-count').textContent = String(assignments.length);
    }

    function tagAssignments() {
        const assignments = categoryMap();
        return state.tags.map(tag => ({ tag: tag, category: assignments.get(Number(tag.id)) || null }));
    }

    function actionForAssignment(item, category) {
        if (item.category && Number(item.category.id) === Number(category.id)) {
            return { label: 'Remove', tone: 'secondary', categoryId: null };
        }
        if (item.category) {
            return { label: 'Move here', tone: 'primary', categoryId: Number(category.id) };
        }
        return { label: 'Add', tone: 'primary', categoryId: Number(category.id) };
    }

    async function assignTags(tags, categoryId, pendingTagId) {
        if (state.isSaving || !tags.length) return;
        state.isSaving = true;
        state.pendingTagId = pendingTagId || null;
        renderTagList();
        try {
            const result = await requestJson('../php_backend/public/categories.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'assign_tags', tag_ids: tags.map(tag => Number(tag.id)), category_id: categoryId })
            });
            state.selectedTagIds.clear();
            await loadData(false);
            const category = categoryId === null ? null : selectedCategory();
            const transactionCopy = Number(result.updated_transactions) > 0
                ? ` ${Number(result.updated_transactions).toLocaleString('en-GB')} existing transaction${Number(result.updated_transactions) === 1 ? '' : 's'} updated.`
                : '';
            const subject = tags.length === 1 ? tags[0].name : `${tags.length.toLocaleString('en-GB')} tags`;
            announce(category ? `${subject} assigned to ${category.name}.${transactionCopy}` : `${subject} now unassigned.${transactionCopy}`, false);
        } catch (error) {
            announce(error.message || 'The tag assignment could not be saved.', true);
        } finally {
            state.isSaving = false;
            state.pendingTagId = null;
            renderTagList();
        }
    }

    function assignTag(tag, categoryId) {
        return assignTags([tag], categoryId, Number(tag.id));
    }

    function renderTagRow(item, category) {
        const row = element('article', 'category-tag-row');
        const selection = element('label', 'category-tag-select');
        const checkbox = element('input');
        checkbox.type = 'checkbox';
        checkbox.checked = state.selectedTagIds.has(Number(item.tag.id));
        checkbox.disabled = state.isSaving;
        checkbox.setAttribute('aria-label', `Select ${item.tag.name}`);
        checkbox.addEventListener('change', () => {
            if (checkbox.checked) state.selectedTagIds.add(Number(item.tag.id));
            else state.selectedTagIds.delete(Number(item.tag.id));
            renderBulkControls();
        });
        selection.appendChild(checkbox);
        const main = element('div', 'category-tag-main');
        const icon = element('span', 'category-tag-icon');
        const glyph = element('i', 'fas fa-tag');
        glyph.setAttribute('aria-hidden', 'true');
        icon.appendChild(glyph);
        const copy = element('div', 'category-tag-copy');
        copy.appendChild(element('p', 'category-tag-name', item.tag.name));
        const meta = element('div', 'category-tag-meta');
        if (item.tag.keyword) meta.appendChild(element('span', 'category-tag-keyword', item.tag.keyword));
        let locationClass = 'category-tag-location';
        let locationText = 'Unassigned';
        if (item.category && Number(item.category.id) === Number(category.id)) {
            locationClass += ' is-linked';
            locationText = 'Linked here';
        } else if (item.category) {
            locationClass += ' is-other';
            locationText = `Currently in ${item.category.name}`;
        }
        meta.appendChild(element('span', locationClass, locationText));
        copy.appendChild(meta);
        main.append(icon, copy);

        const action = actionForAssignment(item, category);
        const button = element('button', `category-tag-action is-${action.tone}`, action.label);
        button.type = 'button';
        button.setAttribute('aria-label', `${action.label} ${item.tag.name}${action.categoryId === null ? '' : ` to ${category.name}`}`);
        button.disabled = state.isSaving;
        if (state.pendingTagId === Number(item.tag.id)) button.textContent = 'Saving…';
        button.addEventListener('click', () => assignTag(item.tag, action.categoryId));
        row.append(selection, main, button);
        return row;
    }

    function renderBulkControls() {
        const selectedCount = state.selectedTagIds.size;
        const visibleSelected = state.visibleTagIds.filter(tagId => state.selectedTagIds.has(tagId)).length;
        const assignments = categoryMap();
        const category = selectedCategory();
        let selectedAssignedCount = 0;
        let selectedNeedsAssignmentCount = 0;
        state.selectedTagIds.forEach(tagId => {
            const assignedCategory = assignments.get(tagId);
            if (assignedCategory) selectedAssignedCount += 1;
            if (!assignedCategory || !category || Number(assignedCategory.id) !== Number(category.id)) {
                selectedNeedsAssignmentCount += 1;
            }
        });
        const selectVisible = document.getElementById('select-visible-tags');
        selectVisible.checked = state.visibleTagIds.length > 0 && visibleSelected === state.visibleTagIds.length;
        selectVisible.indeterminate = visibleSelected > 0 && visibleSelected < state.visibleTagIds.length;
        selectVisible.disabled = state.isSaving || state.visibleTagIds.length === 0;
        document.getElementById('selected-tag-count').textContent = `${selectedCount.toLocaleString('en-GB')} selected`;
        document.getElementById('assign-selected-tags').disabled = state.isSaving || selectedNeedsAssignmentCount === 0;
        document.getElementById('unassign-selected-tags').disabled = state.isSaving || selectedAssignedCount === 0;
    }

    function renderTagList() {
        const category = selectedCategory();
        if (!category) return;
        const assignments = tagAssignments();
        renderSelectionHeader(category, assignments);
        filterButtons.forEach(button => button.setAttribute('aria-pressed', button.dataset.tagFilter === state.filter ? 'true' : 'false'));

        const query = normalise(state.tagQuery);
        const filtered = assignments.filter(item => {
            const linkedHere = item.category && Number(item.category.id) === Number(category.id);
            if (state.filter === 'unassigned' && item.category) return false;
            if (state.filter === 'linked' && !linkedHere) return false;
            const haystack = normalise(`${item.tag.name} ${item.tag.keyword || ''} ${item.tag.description || ''} ${item.category ? item.category.name : 'unassigned'}`);
            return !query || haystack.includes(query);
        });
        state.visibleTagIds = filtered.map(item => Number(item.tag.id));

        document.getElementById('tag-results-summary').textContent = `${filtered.length.toLocaleString('en-GB')} of ${assignments.length.toLocaleString('en-GB')} tags shown`;
        tagList.replaceChildren();
        if (!filtered.length) {
            const empty = element('div', 'category-empty-tags');
            const icon = element('i', 'fas fa-magnifying-glass');
            icon.setAttribute('aria-hidden', 'true');
            empty.append(icon, element('p', '', query ? 'No tags match this search and filter.' : 'No tags are available in this view.'));
            tagList.appendChild(empty);
            renderBulkControls();
            return;
        }
        filtered.forEach(item => tagList.appendChild(renderTagRow(item, category)));
        renderBulkControls();
    }

    function render() {
        if (state.categories.length && !selectedCategory()) state.selectedCategoryId = Number(state.categories[0].id);
        renderCategoryList();
        const hasCategories = state.categories.length > 0;
        loadingState.hidden = true;
        emptyState.hidden = hasCategories;
        manager.hidden = !hasCategories;
        if (hasCategories) renderTagList();
    }

    async function loadData(showLoading) {
        if (showLoading) {
            loadingState.hidden = false;
            emptyState.hidden = true;
            manager.hidden = true;
        }
        const responses = await Promise.all([
            requestJson('../php_backend/public/categories.php'),
            requestJson('../php_backend/public/tags.php')
        ]);
        state.categories = Array.isArray(responses[0]) ? responses[0] : [];
        state.tags = Array.isArray(responses[1])
            ? responses[1].filter(tag => normalise(tag.name) !== 'ignore')
            : [];
        const currentTagIds = new Set(state.tags.map(tag => Number(tag.id)));
        state.selectedTagIds.forEach(tagId => {
            if (!currentTagIds.has(tagId)) state.selectedTagIds.delete(tagId);
        });
        if (state.categories.length && !selectedCategory()) state.selectedCategoryId = Number(state.categories[0].id);
        render();
    }

    categorySearch.addEventListener('input', event => {
        state.categoryQuery = event.target.value;
        renderCategoryList();
    });

    tagSearch.addEventListener('input', event => {
        state.tagQuery = event.target.value;
        renderTagList();
    });

    filterButtons.forEach(button => button.addEventListener('click', () => {
        state.filter = button.dataset.tagFilter;
        renderTagList();
    }));

    document.getElementById('select-visible-tags').addEventListener('change', event => {
        if (event.target.checked) state.visibleTagIds.forEach(tagId => state.selectedTagIds.add(tagId));
        else state.visibleTagIds.forEach(tagId => state.selectedTagIds.delete(tagId));
        renderTagList();
    });

    document.getElementById('assign-selected-tags').addEventListener('click', () => {
        const selected = state.tags.filter(tag => state.selectedTagIds.has(Number(tag.id)));
        const category = selectedCategory();
        if (category) assignTags(selected, Number(category.id), null);
    });

    document.getElementById('unassign-selected-tags').addEventListener('click', () => {
        const selected = state.tags.filter(tag => state.selectedTagIds.has(Number(tag.id)));
        assignTags(selected, null, null);
    });

    categoryForm.addEventListener('submit', async event => {
        event.preventDefault();
        const name = categoryNameInput.value.trim();
        if (!name) return;
        const submit = categoryForm.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
            const result = await requestJson('../php_backend/public/categories.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'create', name: name })
            });
            state.selectedCategoryId = Number(result.id);
            state.categoryQuery = '';
            categorySearch.value = '';
            categoryNameInput.value = '';
            await loadData(false);
            announce(`${name} created and selected.`, false);
        } catch (error) {
            announce(error.message || 'The category could not be created.', true);
        } finally {
            submit.disabled = false;
        }
    });

    document.getElementById('edit-category-button').addEventListener('click', () => {
        const category = selectedCategory();
        if (!category) return;
        editName.value = category.name;
        editDescription.value = category.description || '';
        editForm.hidden = false;
        editName.focus();
    });

    document.getElementById('cancel-category-edit').addEventListener('click', () => { editForm.hidden = true; });

    editForm.addEventListener('submit', async event => {
        event.preventDefault();
        const category = selectedCategory();
        const name = editName.value.trim();
        if (!category || !name) return;
        try {
            await requestJson('../php_backend/public/categories.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: Number(category.id),
                    name: name,
                    description: editDescription.value.trim(),
                    segment_id: category.segment_id
                })
            });
            editForm.hidden = true;
            await loadData(false);
            announce('Category details updated.', false);
        } catch (error) {
            announce(error.message || 'The category could not be updated.', true);
        }
    });

    document.getElementById('delete-category-button').addEventListener('click', async () => {
        const category = selectedCategory();
        if (!category || !window.confirm(`Delete ${category.name}? Its tags will become unassigned and transaction category values will be cleared.`)) return;
        try {
            await requestJson('../php_backend/public/categories.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(category.id) })
            });
            state.selectedCategoryId = null;
            await loadData(false);
            announce(`${category.name} deleted.`, false);
        } catch (error) {
            announce(error.message || 'The category could not be deleted.', true);
        }
    });

    loadData(true).catch(error => {
        loadingState.replaceChildren();
        const icon = element('i', 'fas fa-triangle-exclamation');
        icon.setAttribute('aria-hidden', 'true');
        loadingState.append(icon, element('p', '', error.message || 'Categories and tags could not be loaded.'));
        announce(error.message || 'Categories and tags could not be loaded.', true);
    });
})();

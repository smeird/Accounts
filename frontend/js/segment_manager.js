(function () {
    'use strict';

    const segmentList = document.getElementById('segment-list');
    const segmentSearch = document.getElementById('segment-search');
    const segmentForm = document.getElementById('segment-form');
    const segmentNameInput = document.getElementById('segment-name');
    const categorySearch = document.getElementById('category-search');
    const categoryList = document.getElementById('category-list');
    const filterButtons = Array.from(document.querySelectorAll('[data-category-filter]'));
    const loadingState = document.getElementById('segment-loading');
    const emptyState = document.getElementById('segment-empty');
    const manager = document.getElementById('segment-manager');
    const editForm = document.getElementById('segment-edit-form');
    const editName = document.getElementById('segment-edit-name');
    const editDescription = document.getElementById('segment-edit-description');
    const toast = document.getElementById('segment-toast');

    const state = {
        segments: [],
        categories: [],
        selectedSegmentId: null,
        segmentQuery: '',
        categoryQuery: '',
        filter: 'unassigned',
        pendingCategoryId: null,
        isSaving: false,
        selectedCategoryIds: new Set(),
        visibleCategoryIds: [],
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

    function selectedSegment() {
        return state.segments.find(segment => Number(segment.id) === Number(state.selectedSegmentId)) || null;
    }

    function segmentMap() {
        const map = new Map();
        state.segments.forEach(segment => {
            (segment.categories || []).forEach(category => map.set(Number(category.id), segment));
        });
        return map;
    }

    function normalise(value) {
        return String(value || '').trim().toLocaleLowerCase('en-GB');
    }

    function renderSegmentList() {
        const query = normalise(state.segmentQuery);
        const segments = state.segments.filter(segment => {
            const haystack = normalise(`${segment.name} ${segment.description || ''}`);
            return !query || haystack.includes(query);
        });

        document.getElementById('segment-count').textContent = String(state.segments.length);
        segmentList.replaceChildren();
        if (!segments.length) {
            const message = state.segments.length
                ? 'No segments match this search.'
                : 'Add your first segment below.';
            segmentList.appendChild(element('div', 'segment-list-empty', message));
            return;
        }

        segments.forEach(segment => {
            const button = element('button', 'segment-list-button');
            button.type = 'button';
            button.dataset.segmentId = String(segment.id);
            button.setAttribute('aria-current', Number(segment.id) === Number(state.selectedSegmentId) ? 'true' : 'false');
            button.setAttribute('aria-label', `${segment.name}, ${(segment.categories || []).length} linked categor${(segment.categories || []).length === 1 ? 'y' : 'ies'}`);
            button.append(
                element('span', 'segment-list-name', segment.name),
                element('span', 'segment-list-total', String((segment.categories || []).length))
            );
            button.addEventListener('click', () => {
                state.selectedSegmentId = Number(segment.id);
                state.filter = 'unassigned';
                state.categoryQuery = '';
                state.selectedCategoryIds.clear();
                categorySearch.value = '';
                editForm.hidden = true;
                render();
            });
            segmentList.appendChild(button);
        });
    }

    function categoryAssignments() {
        const assignments = segmentMap();
        return state.categories.map(category => ({ category: category, segment: assignments.get(Number(category.id)) || null }));
    }

    function renderSelectionHeader(segment, assignments) {
        document.getElementById('selected-segment-name').textContent = segment.name;
        document.getElementById('selected-segment-description').textContent = segment.description || 'No description added.';
        const linked = assignments.filter(item => item.segment && Number(item.segment.id) === Number(segment.id)).length;
        const unassigned = assignments.filter(item => !item.segment).length;
        document.getElementById('selected-segment-total').textContent = `${linked} linked`;
        document.getElementById('summary-linked').textContent = String(linked);
        document.getElementById('summary-unassigned').textContent = String(unassigned);
        document.getElementById('filter-linked-count').textContent = String(linked);
        document.getElementById('filter-unassigned-count').textContent = String(unassigned);
        document.getElementById('filter-all-count').textContent = String(assignments.length);
    }

    function actionForAssignment(item, segment) {
        if (item.segment && Number(item.segment.id) === Number(segment.id)) {
            return { label: 'Remove', tone: 'secondary', segmentId: null };
        }
        if (item.segment) {
            return { label: 'Move here', tone: 'primary', segmentId: Number(segment.id) };
        }
        return { label: 'Add', tone: 'primary', segmentId: Number(segment.id) };
    }

    async function assignCategories(categories, segmentId, pendingCategoryId) {
        if (state.isSaving || !categories.length) return;
        state.isSaving = true;
        state.pendingCategoryId = pendingCategoryId || null;
        renderCategoryList();
        try {
            const result = await requestJson('../php_backend/public/segments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'assign_categories',
                    category_ids: categories.map(category => Number(category.id)),
                    segment_id: segmentId
                })
            });
            state.selectedCategoryIds.clear();
            await loadData(false);
            const segment = segmentId === null ? null : selectedSegment();
            const transactionCopy = Number(result.updated_transactions) > 0
                ? ` ${Number(result.updated_transactions).toLocaleString('en-GB')} existing transaction${Number(result.updated_transactions) === 1 ? '' : 's'} updated.`
                : '';
            const subject = categories.length === 1 ? categories[0].name : `${categories.length.toLocaleString('en-GB')} categories`;
            announce(segment ? `${subject} assigned to ${segment.name}.${transactionCopy}` : `${subject} now unassigned.${transactionCopy}`, false);
        } catch (error) {
            announce(error.message || 'The category assignment could not be saved.', true);
        } finally {
            state.isSaving = false;
            state.pendingCategoryId = null;
            renderCategoryList();
        }
    }

    function assignCategory(category, segmentId) {
        return assignCategories([category], segmentId, Number(category.id));
    }

    function renderCategoryRow(item, segment) {
        const row = element('article', 'segment-category-row');
        const selection = element('label', 'segment-category-select');
        const checkbox = element('input');
        checkbox.type = 'checkbox';
        checkbox.checked = state.selectedCategoryIds.has(Number(item.category.id));
        checkbox.disabled = state.isSaving;
        checkbox.setAttribute('aria-label', `Select ${item.category.name}`);
        checkbox.addEventListener('change', () => {
            if (checkbox.checked) state.selectedCategoryIds.add(Number(item.category.id));
            else state.selectedCategoryIds.delete(Number(item.category.id));
            renderBulkControls();
        });
        selection.appendChild(checkbox);

        const main = element('div', 'segment-category-main');
        const icon = element('span', 'segment-category-icon');
        const glyph = element('i', 'fas fa-folder-open');
        glyph.setAttribute('aria-hidden', 'true');
        icon.appendChild(glyph);
        const copy = element('div', 'segment-category-copy');
        copy.appendChild(element('p', 'segment-category-name', item.category.name));
        const meta = element('div', 'segment-category-meta');
        if (item.category.description) meta.appendChild(element('span', 'segment-category-description', item.category.description));
        let locationClass = 'segment-category-location';
        let locationText = 'Unassigned';
        if (item.segment && Number(item.segment.id) === Number(segment.id)) {
            locationClass += ' is-linked';
            locationText = 'Linked here';
        } else if (item.segment) {
            locationClass += ' is-other';
            locationText = `Currently in ${item.segment.name}`;
        }
        meta.appendChild(element('span', locationClass, locationText));
        copy.appendChild(meta);
        main.append(icon, copy);

        const action = actionForAssignment(item, segment);
        const button = element('button', `segment-category-action is-${action.tone}`, action.label);
        button.type = 'button';
        button.setAttribute('aria-label', `${action.label} ${item.category.name}${action.segmentId === null ? '' : ` to ${segment.name}`}`);
        button.disabled = state.isSaving;
        if (state.pendingCategoryId === Number(item.category.id)) button.textContent = 'Saving…';
        button.addEventListener('click', () => assignCategory(item.category, action.segmentId));
        row.append(selection, main, button);
        return row;
    }

    function renderBulkControls() {
        const selectedCount = state.selectedCategoryIds.size;
        const visibleSelected = state.visibleCategoryIds.filter(categoryId => state.selectedCategoryIds.has(categoryId)).length;
        const assignments = segmentMap();
        const segment = selectedSegment();
        let selectedAssignedCount = 0;
        let selectedNeedsAssignmentCount = 0;
        state.selectedCategoryIds.forEach(categoryId => {
            const assignedSegment = assignments.get(categoryId);
            if (assignedSegment) selectedAssignedCount += 1;
            if (!assignedSegment || !segment || Number(assignedSegment.id) !== Number(segment.id)) {
                selectedNeedsAssignmentCount += 1;
            }
        });
        const selectVisible = document.getElementById('select-visible-categories');
        selectVisible.checked = state.visibleCategoryIds.length > 0 && visibleSelected === state.visibleCategoryIds.length;
        selectVisible.indeterminate = visibleSelected > 0 && visibleSelected < state.visibleCategoryIds.length;
        selectVisible.disabled = state.isSaving || state.visibleCategoryIds.length === 0;
        document.getElementById('selected-category-count').textContent = `${selectedCount.toLocaleString('en-GB')} selected`;
        document.getElementById('assign-selected-categories').disabled = state.isSaving || selectedNeedsAssignmentCount === 0;
        document.getElementById('unassign-selected-categories').disabled = state.isSaving || selectedAssignedCount === 0;
    }

    function renderCategoryList() {
        const segment = selectedSegment();
        if (!segment) return;
        const assignments = categoryAssignments();
        renderSelectionHeader(segment, assignments);
        filterButtons.forEach(button => button.setAttribute('aria-pressed', button.dataset.categoryFilter === state.filter ? 'true' : 'false'));

        const query = normalise(state.categoryQuery);
        const filtered = assignments.filter(item => {
            const linkedHere = item.segment && Number(item.segment.id) === Number(segment.id);
            if (state.filter === 'unassigned' && item.segment) return false;
            if (state.filter === 'linked' && !linkedHere) return false;
            const haystack = normalise(`${item.category.name} ${item.category.description || ''} ${item.segment ? item.segment.name : 'unassigned'}`);
            return !query || haystack.includes(query);
        });
        state.visibleCategoryIds = filtered.map(item => Number(item.category.id));

        document.getElementById('category-results-summary').textContent = `${filtered.length.toLocaleString('en-GB')} of ${assignments.length.toLocaleString('en-GB')} categories shown`;
        categoryList.replaceChildren();
        if (!filtered.length) {
            const empty = element('div', 'segment-empty-categories');
            const icon = element('i', 'fas fa-magnifying-glass');
            icon.setAttribute('aria-hidden', 'true');
            empty.append(icon, element('p', '', query ? 'No categories match this search and filter.' : 'No categories are available in this view.'));
            categoryList.appendChild(empty);
            renderBulkControls();
            return;
        }
        filtered.forEach(item => categoryList.appendChild(renderCategoryRow(item, segment)));
        renderBulkControls();
    }

    function render() {
        if (state.segments.length && !selectedSegment()) state.selectedSegmentId = Number(state.segments[0].id);
        renderSegmentList();
        const hasSegments = state.segments.length > 0;
        loadingState.hidden = true;
        emptyState.hidden = hasSegments;
        manager.hidden = !hasSegments;
        if (hasSegments) renderCategoryList();
    }

    async function loadData(showLoading) {
        if (showLoading) {
            loadingState.hidden = false;
            emptyState.hidden = true;
            manager.hidden = true;
        }
        const responses = await Promise.all([
            requestJson('../php_backend/public/segments.php'),
            requestJson('../php_backend/public/categories.php')
        ]);
        state.segments = Array.isArray(responses[0]) ? responses[0] : [];
        state.categories = Array.isArray(responses[1]) ? responses[1] : [];
        const currentCategoryIds = new Set(state.categories.map(category => Number(category.id)));
        state.selectedCategoryIds.forEach(categoryId => {
            if (!currentCategoryIds.has(categoryId)) state.selectedCategoryIds.delete(categoryId);
        });
        if (state.segments.length && !selectedSegment()) state.selectedSegmentId = Number(state.segments[0].id);
        render();
    }

    segmentSearch.addEventListener('input', event => {
        state.segmentQuery = event.target.value;
        renderSegmentList();
    });

    categorySearch.addEventListener('input', event => {
        state.categoryQuery = event.target.value;
        renderCategoryList();
    });

    filterButtons.forEach(button => button.addEventListener('click', () => {
        state.filter = button.dataset.categoryFilter;
        renderCategoryList();
    }));

    document.getElementById('select-visible-categories').addEventListener('change', event => {
        if (event.target.checked) state.visibleCategoryIds.forEach(categoryId => state.selectedCategoryIds.add(categoryId));
        else state.visibleCategoryIds.forEach(categoryId => state.selectedCategoryIds.delete(categoryId));
        renderCategoryList();
    });

    document.getElementById('assign-selected-categories').addEventListener('click', () => {
        const selected = state.categories.filter(category => state.selectedCategoryIds.has(Number(category.id)));
        const segment = selectedSegment();
        if (segment) assignCategories(selected, Number(segment.id), null);
    });

    document.getElementById('unassign-selected-categories').addEventListener('click', () => {
        const selected = state.categories.filter(category => state.selectedCategoryIds.has(Number(category.id)));
        assignCategories(selected, null, null);
    });

    segmentForm.addEventListener('submit', async event => {
        event.preventDefault();
        const name = segmentNameInput.value.trim();
        if (!name) return;
        const submit = segmentForm.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
            const result = await requestJson('../php_backend/public/segments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name })
            });
            state.selectedSegmentId = Number(result.id);
            state.segmentQuery = '';
            segmentSearch.value = '';
            segmentNameInput.value = '';
            await loadData(false);
            announce(`${name} created and selected.`, false);
        } catch (error) {
            announce(error.message || 'The segment could not be created.', true);
        } finally {
            submit.disabled = false;
        }
    });

    document.getElementById('edit-segment-button').addEventListener('click', () => {
        const segment = selectedSegment();
        if (!segment) return;
        editName.value = segment.name;
        editDescription.value = segment.description || '';
        editForm.hidden = false;
        editName.focus();
    });

    document.getElementById('cancel-segment-edit').addEventListener('click', () => { editForm.hidden = true; });

    editForm.addEventListener('submit', async event => {
        event.preventDefault();
        const segment = selectedSegment();
        const name = editName.value.trim();
        if (!segment || !name) return;
        try {
            await requestJson('../php_backend/public/segments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: Number(segment.id),
                    name: name,
                    description: editDescription.value.trim()
                })
            });
            editForm.hidden = true;
            await loadData(false);
            announce('Segment details updated.', false);
        } catch (error) {
            announce(error.message || 'The segment could not be updated.', true);
        }
    });

    document.getElementById('delete-segment-button').addEventListener('click', async () => {
        const segment = selectedSegment();
        if (!segment || !window.confirm(`Delete ${segment.name}? Its categories and related transactions will become unsegmented.`)) return;
        try {
            await requestJson('../php_backend/public/segments.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(segment.id) })
            });
            state.selectedSegmentId = null;
            await loadData(false);
            announce(`${segment.name} deleted.`, false);
        } catch (error) {
            announce(error.message || 'The segment could not be deleted.', true);
        }
    });

    loadData(true).catch(error => {
        loadingState.replaceChildren();
        const icon = element('i', 'fas fa-triangle-exclamation');
        icon.setAttribute('aria-hidden', 'true');
        loadingState.append(icon, element('p', '', error.message || 'Segments and categories could not be loaded.'));
        announce(error.message || 'Segments and categories could not be loaded.', true);
    });
})();

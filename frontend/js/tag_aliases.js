let tagAliasTable;
let selectedTag = null;
let tagSearchTimer = null;
let tagSearchController = null;
let tagSearchSequence = 0;
let activeTagOption = -1;

const tagPicker = document.getElementById('tag-picker');
const tagSearch = document.getElementById('tag-search');
const tagId = document.getElementById('tag_id');
const tagResults = document.getElementById('tag-results');
const tagSearchStatus = document.getElementById('tag-search-status');

async function fetchJson(url, options) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.error || 'Request failed');
    }
    return data;
}

function setTagPickerOpen(open) {
    tagResults.hidden = !open;
    tagSearch.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) {
        tagSearch.removeAttribute('aria-activedescendant');
        activeTagOption = -1;
    }
}

function setTagStatus(message, tone = '') {
    tagSearchStatus.textContent = message;
    tagSearchStatus.classList.toggle('is-selected', tone === 'selected');
    tagSearchStatus.classList.toggle('is-error', tone === 'error');
}

function clearTagSelection() {
    window.clearTimeout(tagSearchTimer);
    if (tagSearchController) tagSearchController.abort();
    tagSearchSequence++;
    selectedTag = null;
    tagId.value = '';
    tagSearch.value = '';
    tagSearch.removeAttribute('aria-invalid');
    tagResults.replaceChildren();
    setTagPickerOpen(false);
    setTagStatus('Type at least two characters to find a canonical tag.');
}

function selectTag(tag) {
    window.clearTimeout(tagSearchTimer);
    if (tagSearchController) tagSearchController.abort();
    tagSearchSequence++;
    selectedTag = { id: Number(tag.id), name: String(tag.name) };
    tagId.value = String(selectedTag.id);
    tagSearch.value = selectedTag.name;
    tagSearch.removeAttribute('aria-invalid');
    setTagPickerOpen(false);
    tagResults.replaceChildren();
    setTagStatus(`Selected canonical tag: ${selectedTag.name}`, 'selected');
}

function updateActiveTagOption(index) {
    const options = Array.from(tagResults.querySelectorAll('[role="option"]'));
    if (!options.length) return;
    activeTagOption = (index + options.length) % options.length;
    options.forEach((option, optionIndex) => {
        option.setAttribute('aria-selected', optionIndex === activeTagOption ? 'true' : 'false');
    });
    const active = options[activeTagOption];
    tagSearch.setAttribute('aria-activedescendant', active.id);
    active.scrollIntoView({ block: 'nearest' });
}

function renderTagResults(data, query) {
    tagResults.replaceChildren();
    activeTagOption = -1;
    const matches = Array.isArray(data.tags) ? data.tags : [];

    if (!matches.length) {
        const empty = document.createElement('div');
        empty.className = 'tag-picker-empty';
        empty.textContent = `No canonical tags match “${query}”.`;
        tagResults.appendChild(empty);
        setTagPickerOpen(true);
        setTagStatus('No matching canonical tags found.');
        return;
    }

    matches.forEach(tag => {
        const option = document.createElement('button');
        const name = document.createElement('span');
        const identifier = document.createElement('span');
        option.type = 'button';
        option.id = `tag-option-${tag.id}`;
        option.className = 'tag-picker-option';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        name.className = 'tag-picker-option-name';
        name.textContent = tag.name;
        identifier.className = 'tag-picker-option-id';
        identifier.textContent = `#${tag.id}`;
        option.append(name, identifier);
        option.addEventListener('click', () => selectTag(tag));
        tagResults.appendChild(option);
    });

    setTagPickerOpen(true);
    const suffix = data.has_more ? ' Keep typing to narrow the list.' : '';
    setTagStatus(`${matches.length} matching tag${matches.length === 1 ? '' : 's'}.${suffix}`);
}

async function searchTags(query) {
    if (tagSearchController) tagSearchController.abort();
    tagSearchController = new AbortController();
    const sequence = ++tagSearchSequence;
    const params = new URLSearchParams({ options: '1', q: query, limit: '20' });
    setTagStatus('Searching canonical tags…');

    try {
        const data = await fetchJson(`../php_backend/public/tags.php?${params.toString()}`, {
            signal: tagSearchController.signal
        });
        if (sequence !== tagSearchSequence) return;
        renderTagResults(data, query);
    } catch (error) {
        if (error.name === 'AbortError') return;
        tagResults.replaceChildren();
        setTagPickerOpen(false);
        setTagStatus(error.message || 'Canonical tags could not be loaded.', 'error');
    }
}

tagSearch.addEventListener('input', () => {
    if (tagSearchController) tagSearchController.abort();
    tagSearchSequence++;
    selectedTag = null;
    tagId.value = '';
    tagSearch.removeAttribute('aria-invalid');
    window.clearTimeout(tagSearchTimer);
    const query = tagSearch.value.trim();
    if (query.length < 2) {
        tagResults.replaceChildren();
        setTagPickerOpen(false);
        setTagStatus('Type at least two characters to find a canonical tag.');
        return;
    }
    tagSearchTimer = window.setTimeout(() => searchTags(query), 180);
});

tagSearch.addEventListener('keydown', event => {
    const options = Array.from(tagResults.querySelectorAll('[role="option"]'));
    if (event.key === 'ArrowDown' && options.length) {
        event.preventDefault();
        updateActiveTagOption(activeTagOption + 1);
    } else if (event.key === 'ArrowUp' && options.length) {
        event.preventDefault();
        updateActiveTagOption(activeTagOption - 1);
    } else if (event.key === 'Enter' && activeTagOption >= 0 && options[activeTagOption]) {
        event.preventDefault();
        options[activeTagOption].click();
    } else if (event.key === 'Escape') {
        setTagPickerOpen(false);
    }
});

tagSearch.addEventListener('focus', () => {
    if (tagResults.children.length && tagSearch.value.trim().length >= 2 && !selectedTag) {
        setTagPickerOpen(true);
    }
});

document.addEventListener('pointerdown', event => {
    if (!tagPicker.contains(event.target)) setTagPickerOpen(false);
});

async function loadAliases() {
    if (tagAliasTable) {
        return tagAliasTable.setData();
    }

    tagAliasTable = tailwindTabulator('#tag-alias-table', {
        ajaxURL: '../php_backend/public/tag_aliases.php',
        layout: 'fitDataStretch',
        pagination: true,
        paginationMode: 'remote',
        paginationSize: 25,
        paginationSizeSelector: [25, 50, 100],
        sortMode: 'remote',
        initialSort: [{ column: 'alias', dir: 'asc' }],
        modernRemoteSearchParam: 'q',
        modernMaxHeight: '70vh',
        columns: [
            { title: 'Alias', field: 'alias' },
            { title: 'Canonical Tag', field: 'tag_name', formatter: badgeFormatter('bg-indigo-200 text-indigo-800') },
            { title: 'Match Type', field: 'match_type' },
            {
                title: 'Direction',
                field: 'direction',
                formatter: cell => ({ outgoing: 'Money leaving', incoming: 'Money arriving', any: 'Either direction' }[String(cell.getValue() || 'any')] || 'Either direction')
            },
            {
                title: 'Active',
                field: 'active',
                formatter: cell => Number(cell.getValue()) === 1 ? 'Yes' : 'No'
            },
            {
                title: 'Actions',
                formatter: function(cell) {
                    const container = document.createElement('div');
                    const row = cell.getRow().getData();

                    const edit = document.createElement('button');
                    edit.innerHTML = '<i class="fas fa-edit w-4 h-4"></i>';
                    edit.className = 'bg-indigo-600 text-white px-2 py-1 rounded mr-2';
                    edit.setAttribute('aria-label', `Edit alias ${row.alias}`);
                    edit.addEventListener('click', async () => {
                        const alias = prompt('Alias', row.alias);
                        if (alias === null) return;
                        const tagInput = prompt('Canonical tag ID', String(row.tag_id));
                        if (tagInput === null) return;
                        const matchType = prompt('Match type (contains/exact)', row.match_type || 'contains');
                        if (matchType === null) return;
                        const activeInput = prompt('Active? (yes/no)', Number(row.active) === 1 ? 'yes' : 'no');
                        if (activeInput === null) return;
                        const direction = prompt('Direction (any/outgoing/incoming)', row.direction || 'any');
                        if (direction === null) return;

                        try {
                            await fetchJson('../php_backend/public/tag_aliases.php', {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    id: row.id,
                                    alias,
                                    tag_id: Number(tagInput),
                                    match_type: matchType,
                                    direction,
                                    active: activeInput.toLowerCase() === 'yes' || activeInput === '1' || activeInput.toLowerCase() === 'true'
                                })
                            });
                            await loadAliases();
                            showMessage('Tag alias updated');
                        } catch (error) {
                            showMessage(error.message, 'error');
                        }
                    });

                    const del = document.createElement('button');
                    del.innerHTML = '<i class="fas fa-trash w-4 h-4"></i>';
                    del.className = 'bg-red-600 text-white px-2 py-1 rounded';
                    del.setAttribute('aria-label', `Delete alias ${row.alias}`);
                    del.addEventListener('click', async () => {
                        if (!confirm('Delete this alias mapping?')) return;
                        try {
                            await fetchJson('../php_backend/public/tag_aliases.php', {
                                method: 'DELETE',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ id: row.id })
                            });
                            await loadAliases();
                            showMessage('Tag alias deleted');
                        } catch (error) {
                            showMessage(error.message, 'error');
                        }
                    });

                    container.appendChild(edit);
                    container.appendChild(del);
                    return container;
                }
            }
        ]
    });
}

document.getElementById('tag-alias-form').addEventListener('submit', async event => {
    event.preventDefault();
    const canonicalTagId = Number(tagId.value);
    if (!selectedTag || canonicalTagId <= 0) {
        tagSearch.setAttribute('aria-invalid', 'true');
        setTagStatus('Choose a canonical tag from the search results before creating the alias.', 'error');
        showMessage('Choose a canonical tag from the search results', 'error');
        tagSearch.focus();
        return;
    }

    const payload = {
        alias: document.getElementById('alias').value,
        tag_id: canonicalTagId,
        match_type: document.getElementById('match_type').value,
        direction: document.getElementById('direction').value,
        active: document.getElementById('active').checked
    };
    const canonicalTagName = selectedTag.name;

    try {
        await fetchJson('../php_backend/public/tag_aliases.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });

        event.target.reset();
        document.getElementById('active').checked = true;
        clearTagSelection();
        await loadAliases();
        showMessage(`Alias created for ${canonicalTagName}`);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

loadAliases().catch(error => showMessage(error.message, 'error'));

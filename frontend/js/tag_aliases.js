let tagAliasTable;
let tagOptions = [];

async function fetchJson(url, options) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.error || 'Request failed');
    }
    return data;
}

async function loadTags() {
    const tags = await fetchJson('../php_backend/public/tags.php');
    tagOptions = tags.map(tag => ({ value: Number(tag.id), label: tag.name }));

    const select = document.getElementById('tag_id');
    select.innerHTML = '';
    tagOptions.forEach(option => {
        const element = document.createElement('option');
        element.value = option.value;
        element.textContent = option.label;
        select.appendChild(element);
    });
}

function getTagNameById(tagId) {
    const match = tagOptions.find(option => option.value === Number(tagId));
    return match ? match.label : `Tag #${tagId}`;
}

async function loadAliases() {
    const aliases = await fetchJson('../php_backend/public/tag_aliases.php');

    if (tagAliasTable) {
        tagAliasTable.setData(aliases);
        return;
    }

    tagAliasTable = tailwindTabulator('#tag-alias-table', {
        data: aliases,
        layout: 'fitDataStretch',
        columns: [
            { title: 'Alias', field: 'alias' },
            { title: 'Canonical Tag', field: 'tag_name', formatter: badgeFormatter('bg-indigo-200 text-indigo-800') },
            { title: 'Match Type', field: 'match_type' },
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

                        try {
                            await fetchJson('../php_backend/public/tag_aliases.php', {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    id: row.id,
                                    alias,
                                    tag_id: Number(tagInput),
                                    match_type: matchType,
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
    const payload = {
        alias: document.getElementById('alias').value,
        tag_id: Number(document.getElementById('tag_id').value),
        match_type: document.getElementById('match_type').value,
        active: document.getElementById('active').checked
    };

    try {
        await fetchJson('../php_backend/public/tag_aliases.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });

        event.target.reset();
        document.getElementById('active').checked = true;
        if (tagOptions.length > 0) {
            document.getElementById('tag_id').value = String(tagOptions[0].value);
        }
        await loadAliases();
        showMessage(`Alias created for ${getTagNameById(payload.tag_id)}`);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

(async function init() {
    try {
        await loadTags();
        await loadAliases();
    } catch (error) {
        showMessage(error.message, 'error');
    }
})();

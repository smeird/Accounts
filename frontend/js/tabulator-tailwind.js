// Tabulator modules are loaded via the main bundle. Avoid dynamically
// injecting module scripts from external CDNs so the app works in offline
// or restricted environments without console errors.

// Create a coloured badge element used in table cells
function createBadge(text, colorClasses) {
    const span = document.createElement('span');
    span.textContent = text;
    span.className = `inline-block px-2 py-1 text-xs font-semibold rounded ${colorClasses}`;
    return span;
}

// Return a Tabulator formatter that displays values as badges
function badgeFormatter(colorClasses) {
    return function (cell) {
        const value = cell.getValue();
        if (!value) return '';
        if (Array.isArray(value)) {
            const container = document.createElement('div');
            value.forEach(v => {
                const badge = createBadge(v, colorClasses);
                const link = document.createElement('a');
                link.href = `search.html?value=${encodeURIComponent(v)}`;
                link.appendChild(badge);
                container.appendChild(link);
            });
            return container;
        }
        const badge = createBadge(value, colorClasses);
        const link = document.createElement('a');
        link.href = `search.html?value=${encodeURIComponent(value)}`;
        link.appendChild(badge);
        return link;
    };
}

// Apply consistent styling to Tabulator calculation rows
function styleCalcRows(table) {
    const rows = table.element.querySelectorAll('.tabulator-calcs-row');
    rows.forEach(row => {
        row.classList.remove('bg-white');
        row.classList.add('ops-table-row');
        row.style.backgroundColor = '';
        row.querySelectorAll('.tabulator-cell').forEach(cell => {
            cell.classList.remove('bg-white');
            cell.style.backgroundColor = '';
        });
    });
}

// Initialise a Tabulator table with Tailwind styling defaults
function tailwindTabulator(element, options) {
    options = options || {};

    const enableSearch = options.simpleSearch !== false;
    const searchFields = options.searchFields;

    // Allow rowClick handler to be bound after table creation
    const rowClickHandler = options.rowClick;
    delete options.rowClick;


    // Apply the Simple theme to all Tabulator tables
    options.theme = 'simple';

    if (!options.layout) {
        options.layout = 'fitDataStretch';
    }

    const userRowFormatter = options.rowFormatter;
    // Styling is handled with CSS classes to avoid expensive per-cell DOM
    // updates when large data sets are rendered.
    options.rowFormatter = function(row) {
        if (userRowFormatter) userRowFormatter(row);
        const rowEl = row.getElement();
        rowEl.classList.remove('bg-white', 'hover:bg-white');
        rowEl.classList.add('ops-table-row');
        rowEl.classList.remove('tabulator-row-even', 'tabulator-row-odd');
    };
    if (options.pagination === undefined) {
        options.pagination = 'local';
    }
    options.paginationSize = 20;

    // Freeze the first column by default using the column definition to
    // maintain compatibility across Tabulator versions. Earlier builds used
    // `cols[0].freeze(true)` after the table was built, but newer versions
    // (v6+) removed the freeze function from column components, triggering
    // errors. Setting the `frozen` property avoids calling missing APIs while
    // still freezing the column when the module is available.
    if (Array.isArray(options.columns) && options.columns.length) {
        options.columns[0].frozen = true;
    }

    const table = new Tabulator(element, options);
    const el = table.element;
    el.style.colorScheme = 'light';


    if (rowClickHandler) {
        table.on('rowClick', rowClickHandler);
    }

    if (enableSearch) {
        const tableEl = typeof element === 'string' ? document.querySelector(element) : element;

        // Remove any existing search input inserted by a previous
        // table initialisation to avoid duplicate fields.
        const existing = tableEl.previousElementSibling;
        if (existing && existing.classList.contains('tabulator-search')) {
            existing.remove();
        }

        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search';
        searchInput.className = 'tabulator-search ops-input mb-2 w-full';
        searchInput.style.colorScheme = 'light';
        tableEl.parentNode.insertBefore(searchInput, tableEl);
        let searchInProgress = false;
        searchInput.addEventListener('input', function() {
            if (typeof table.search === 'function') {
                if (searchInProgress) return;
                searchInProgress = true;
                try {
                    table.search(this.value, searchFields);
                } finally {
                    searchInProgress = false;
                }
            } else {
                const query = this.value.toLowerCase();
                table.setFilter(function(data) {
                    return Object.entries(data).some(([field, v]) => {
                        if (searchFields && !searchFields.includes(field)) return false;
                        return v && v.toString().toLowerCase().includes(query);
                    });
                });
            }
        });
    }
    table.on('tableBuilt', function() {
        styleCalcRows(table);
    });
    table.on('dataProcessed', function() {
        styleCalcRows(table);
    });
    el.classList.add('border-0', 'rounded-xl', 'overflow-hidden', 'ops-standard-table');
    const header = el.querySelector('.tabulator-header');
    if (header) {
        header.classList.remove('bg-white');
        header.classList.add('rounded-t-lg');
        header.style.backgroundColor = '';
        header.querySelectorAll('.tabulator-col').forEach(col => {
            col.style.borderRight = '0';
            col.style.borderLeft = '0';
        });
    }
    const tableHolder = el.querySelector('.tabulator-tableholder');
    if (tableHolder) tableHolder.classList.add('rounded-b-lg');
    const paginator = el.querySelector('.tabulator-paginator');
    if (paginator) {
        paginator.classList.remove('bg-white');
        paginator.classList.add('p-2', 'rounded-b-lg', 'ops-table-paginator');
        paginator.style.backgroundColor = '';
    }
    return table;
}

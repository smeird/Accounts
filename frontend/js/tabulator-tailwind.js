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
        row.classList.add('bg-white');
        row.style.backgroundColor = 'white';
        row.querySelectorAll('.tabulator-cell').forEach(cell => {
            cell.classList.add('bg-white');
            cell.style.backgroundColor = 'white';
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
    options.rowFormatter = function(row) {
        if (userRowFormatter) userRowFormatter(row);
        const rowEl = row.getElement();
        rowEl.classList.add('bg-white', 'hover:bg-white', 'border-b', 'border-gray-200', 'border-b-[0.5px]');
        rowEl.classList.remove('tabulator-row-even', 'tabulator-row-odd');
        rowEl.style.borderTop = '0';
        rowEl.querySelectorAll('.tabulator-cell').forEach(cell => {
            cell.style.borderRight = '0';
            cell.style.borderLeft = '0';
            cell.style.borderTop = '0';
            cell.style.borderBottom = '0';
        });
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
    const bodyFont = getComputedStyle(document.body).fontFamily;
    const headingEl = document.querySelector('h1, h2, h3, h4, h5, h6');
    const headingFont = headingEl ? getComputedStyle(headingEl).fontFamily : bodyFont;
    const accentEl = document.querySelector('button, .accent');
    const accentFont = accentEl ? getComputedStyle(accentEl).fontFamily : bodyFont;
    const el = table.element;
    el.style.setProperty('--tabulator-font-family', bodyFont);
    el.style.setProperty('--tabulator-row-font-family', bodyFont);
    el.style.setProperty('--tabulator-header-font-family', headingFont);
    el.style.setProperty('--tabulator-header-font-weight', '700');
    el.style.fontFamily = bodyFont;


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
        searchInput.className = 'tabulator-search mb-2 p-2 border rounded w-full';
        searchInput.style.fontFamily = accentFont;
        searchInput.style.fontWeight = '300';
        tableEl.parentNode.insertBefore(searchInput, tableEl);
        searchInput.addEventListener('input', function() {
            if (typeof table.search === 'function') {
                table.search(this.value, searchFields);
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
        const titles = el.querySelectorAll('.tabulator-col-title');
        titles.forEach(title => {
            title.style.fontFamily = headingFont;
            title.style.fontWeight = '700';
        });
        styleCalcRows(table);
    });
    table.on('dataProcessed', function() {
        styleCalcRows(table);
    });
    el.classList.add('border-0', 'rounded-lg', 'overflow-hidden', 'bg-white', 'shadow-sm', 'dark:bg-gray-800');
    const header = el.querySelector('.tabulator-header');
    if (header) {
        header.classList.add('bg-white', 'border-b', 'border-gray-200', 'border-b-[0.5px]', 'rounded-t-lg');
        header.style.backgroundColor = 'white';
        header.querySelectorAll('.tabulator-col').forEach(col => {
            col.style.borderRight = '0';
            col.style.borderLeft = '0';
        });
    }
    const tableHolder = el.querySelector('.tabulator-tableholder');
    if (tableHolder) tableHolder.classList.add('rounded-b-lg');
    const paginator = el.querySelector('.tabulator-paginator');
    if (paginator) {
        paginator.classList.add('bg-white', 'border-t', 'border-gray-200', 'border-t-[0.5px]', 'p-2', 'rounded-b-lg');
        paginator.style.backgroundColor = 'white';
    }
    return table;
}

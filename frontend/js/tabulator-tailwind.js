// Ensure the ResizeColumns module is available for Tabulator. Loading it via
// a synchronous XHR causes cross-origin errors when the page is served from a
// different domain, so instead inject the script tag which allows the browser
// to fetch it without CORS issues.
if (typeof Tabulator !== 'undefined' && !(Tabulator.prototype.modules && Tabulator.prototype.modules.resizeColumns)) {
    var script = document.createElement('script');
    script.src = 'https://unpkg.com/tabulator-tables@6.3.0/dist/js/modules/resizeColumns.js';
    script.async = false;
    document.head.appendChild(script);
}

// Ensure the global Search module is available so tables can use simple search
if (typeof Tabulator !== 'undefined' && !(Tabulator.prototype.modules && Tabulator.prototype.modules.search)) {
    var searchScript = document.createElement('script');
    searchScript.src = 'https://unpkg.com/tabulator-tables@6.3.0/dist/js/modules/search.js';
    searchScript.async = false;
    document.head.appendChild(searchScript);
}

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

// Initialise a Tabulator table with Tailwind styling defaults
function tailwindTabulator(element, options) {
    options = options || {};

    const enableSearch = options.simpleSearch !== false;

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
    options.pagination = options.pagination || 'local';
    options.paginationSize = 20;
    const table = new Tabulator(element, options);

    if (rowClickHandler) {
        table.on('rowClick', rowClickHandler);
    }

    if (enableSearch) {
        const tableEl = typeof element === 'string' ? document.querySelector(element) : element;
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search';
        searchInput.className = 'mb-2 p-2 border rounded w-full';
        tableEl.parentNode.insertBefore(searchInput, tableEl);
        searchInput.addEventListener('input', function() {
            if (typeof table.search === 'function') {
                table.search(this.value);
            } else {
                const query = this.value.toLowerCase();
                table.setFilter(function(data) {
                    return Object.values(data).some(v =>
                        v && v.toString().toLowerCase().includes(query)
                    );
                });
            }
        });
    }
    table.on('tableBuilt', function() {
        const cols = table.getColumns();
        if (cols.length) {
            cols[0].freeze(true);
        }
    });
    const el = table.element;
    el.classList.add('border-0', 'rounded-lg', 'overflow-hidden', 'bg-white', 'shadow-sm');
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

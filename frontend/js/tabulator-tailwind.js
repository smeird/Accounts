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
            value.forEach(v => container.appendChild(createBadge(v, colorClasses)));
            return container;
        }
        return createBadge(value, colorClasses);
    };
}

// Initialise a Tabulator table with Tailwind styling defaults
function tailwindTabulator(element, options) {
    options = options || {};
    if (!options.layout) {
        options.layout = 'fitColumns';
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

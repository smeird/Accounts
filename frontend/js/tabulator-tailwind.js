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
    if (!options.layout || options.layout === 'fitColumns') {
        options.layout = 'fitDataStretch';
    }
    const userRowFormatter = options.rowFormatter;
    options.rowFormatter = function(row) {
        if (userRowFormatter) userRowFormatter(row);
        const rowEl = row.getElement();
        rowEl.classList.add('bg-white', 'hover:bg-gray-50', 'border-b', 'border-gray-200', 'border-b-[0.5px]');
        rowEl.querySelectorAll('.tabulator-cell').forEach(cell => {
            cell.classList.add('border-r', 'border-gray-200', 'border-r-[0.5px]');
        });
    };
    options.pagination = options.pagination || 'local';
    options.paginationSize = 20;
    const table = new Tabulator(element, options);
    const el = table.element;
    el.classList.add('border', 'border-gray-200', 'border-[0.5px]', 'rounded-lg', 'overflow-hidden', 'bg-white', 'shadow-sm');
    const header = el.querySelector('.tabulator-header');
    if (header) header.classList.add('bg-white', 'border-b', 'border-gray-200', 'border-b-[0.5px]', 'rounded-t-lg');
    const tableHolder = el.querySelector('.tabulator-tableholder');
    if (tableHolder) tableHolder.classList.add('rounded-b-lg');
    const paginator = el.querySelector('.tabulator-paginator');
    if (paginator) paginator.classList.add('bg-white', 'border-t', 'border-gray-200', 'border-t-[0.5px]', 'p-2', 'rounded-b-lg');
    table.on('tableBuilt', () => {
        const cols = table.getColumns();
        if (cols.length) {
            cols[0].fitData();
        }
    });
    return table;
}

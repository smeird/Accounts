function createBadge(text, colorClasses) {
    const span = document.createElement('span');
    span.textContent = text;
    span.className = `inline-block px-2 py-1 text-xs font-semibold rounded ${colorClasses}`;
    return span;
}

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

function tailwindTabulator(element, options) {
    options = options || {};
    const userRowFormatter = options.rowFormatter;
    options.rowFormatter = function(row) {
        if (userRowFormatter) userRowFormatter(row);
        row.getElement().classList.add('odd:bg-white', 'even:bg-gray-50', 'hover:bg-gray-100');
    };
    options.pagination = options.pagination || 'local';
    options.paginationSize = 20;
    const table = new Tabulator(element, options);
    const el = table.element;
    el.classList.add('border', 'border-gray-200', 'rounded', 'bg-white', 'shadow-sm');
    const header = el.querySelector('.tabulator-header');
    if (header) header.classList.add('bg-gray-100');
    const paginator = el.querySelector('.tabulator-paginator');
    if (paginator) paginator.classList.add('bg-gray-50', 'border-t', 'border-gray-200', 'p-2');
    return table;
}

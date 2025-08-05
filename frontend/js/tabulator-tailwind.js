function tailwindTabulator(element, options) {
    options = options || {};
    const userRowFormatter = options.rowFormatter;
    options.rowFormatter = function(row) {
        if (userRowFormatter) userRowFormatter(row);
        row.getElement().classList.add('odd:bg-white', 'even:bg-gray-50', 'hover:bg-gray-100');
    };
    const table = new Tabulator(element, options);
    const el = table.element;
    el.classList.add('border', 'border-gray-200', 'rounded', 'bg-white', 'shadow-sm');
    const header = el.querySelector('.tabulator-header');
    if (header) header.classList.add('bg-gray-100');
    return table;
}

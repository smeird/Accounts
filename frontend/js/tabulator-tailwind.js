// Shared adapter for the site's modern table system. Tabulator remains the
// data engine for dense and dynamic views, while this adapter supplies the
// same toolbar, classification pills, responsive card rows and accessible
// labelling used by the native Account and Monthly Statement tables.

function ensureModernTableStyles() {
    if (document.getElementById('modern-tables-css')) return;
    const link = document.createElement('link');
    const source = document.currentScript && document.currentScript.src;
    link.id = 'modern-tables-css';
    link.rel = 'stylesheet';
    link.href = source ? new URL('../modern_tables.css?v=20260815-pill-key', source).href : 'modern_tables.css?v=20260815-pill-key';
    document.head.appendChild(link);
}

ensureModernTableStyles();

function classificationKind(colorClasses) {
    const classes = String(colorClasses || '');
    if (/purple|violet/.test(classes)) return 'group';
    if (/green|emerald/.test(classes)) return 'category';
    if (/yellow|amber|orange/.test(classes)) return 'segment';
    if (/indigo|blue|cyan/.test(classes)) return 'tag';
    return 'label';
}

const classificationKeyOrder = ['segment', 'category', 'tag', 'group'];

function createClassificationKey(kinds) {
    const key = document.createElement('div');
    const title = document.createElement('span');
    key.className = 'classification-key modern-table-key';
    key.setAttribute('aria-label', 'Classification colour key');
    title.className = 'classification-key-title';
    title.textContent = 'Key';
    key.appendChild(title);
    kinds.forEach(kind => {
        const item = document.createElement('span');
        const swatch = document.createElement('i');
        item.className = 'classification-key-item';
        swatch.className = `classification-key-swatch classification-key-swatch--${kind}`;
        swatch.setAttribute('aria-hidden', 'true');
        item.append(swatch, document.createTextNode(kind));
        key.appendChild(item);
    });
    return key;
}

// Create a coloured badge element used in table cells
function createBadge(text, colorClasses, kind) {
    const span = document.createElement('span');
    const resolvedKind = kind || classificationKind(colorClasses);
    const value = document.createElement('span');
    value.className = 'modern-table-pill-value';
    value.textContent = text;
    span.className = `modern-table-pill modern-table-pill--${resolvedKind} ${colorClasses || ''}`.trim();
    span.setAttribute('aria-label', `${resolvedKind}: ${text}`);
    span.title = `${resolvedKind.charAt(0).toUpperCase() + resolvedKind.slice(1)}: ${text}`;
    span.appendChild(value);
    return span;
}

// Return a Tabulator formatter that displays values as badges
function badgeFormatter(colorClasses, kind) {
    return function (cell) {
        const value = cell.getValue();
        const resolvedKind = kind || classificationKind(colorClasses);
        if (!value) {
            const missing = createBadge('Unassigned', colorClasses, resolvedKind);
            missing.setAttribute('aria-label', `No ${resolvedKind} assigned`);
            missing.classList.add('is-missing');
            return missing;
        }
        if (Array.isArray(value)) {
            const container = document.createElement('div');
            container.className = 'modern-table-pill-list';
            value.forEach(v => {
                const badge = createBadge(v, colorClasses, resolvedKind);
                const link = document.createElement('a');
                link.href = `search.html?value=${encodeURIComponent(v)}`;
                link.setAttribute('aria-label', `Search for ${resolvedKind} ${v}`);
                link.appendChild(badge);
                container.appendChild(link);
            });
            return container;
        }
        const badge = createBadge(value, colorClasses, resolvedKind);
        const link = document.createElement('a');
        link.href = `search.html?value=${encodeURIComponent(value)}`;
        link.setAttribute('aria-label', `Search for ${resolvedKind} ${value}`);
        link.appendChild(badge);
        return link;
    };
}

function plainColumnTitle(definition) {
    const holder = document.createElement('span');
    holder.innerHTML = String(definition.title || definition.field || 'Value');
    return holder.textContent.trim() || 'Value';
}

function modernCellKind(definition) {
    const field = String(definition.field || '').toLowerCase();
    const title = String(definition.title || '').toLowerCase();
    const name = `${field} ${title}`.replace(/[_-]/g, ' ');
    if (/\b(actions?|remove|delete|open|edit)\b/.test(name)) return 'actions';
    if (/\b(amount|balance|total|cost|spent|income|expense|outgoing)\b/.test(name)) return 'money';
    if (/\b(date|month|year|period|time)\b/.test(name)) return 'date';
    if (/category/.test(name)) return 'category';
    if (/(^|_)tag|\btag\b/.test(name)) return 'tag';
    if (/segment/.test(name)) return 'segment';
    if (/group/.test(name)) return 'group';
    return 'text';
}

function isTransactionColumnSet(columns) {
    const fields = [];
    const visit = function (items) {
        (items || []).forEach(column => {
            if (Array.isArray(column.columns)) visit(column.columns);
            fields.push(String(column.field || column.title || '').toLowerCase().replace(/[_-]/g, ' '));
        });
    };
    visit(columns);
    const has = pattern => fields.some(field => pattern.test(field));
    return has(/\b(date|transaction date)\b/) && has(/\b(amount|value)\b/) && has(/\b(description|memo|payee|transaction)\b/);
}

function createTagCorrectionLink() {
    const link = document.createElement('a');
    const icon = document.createElement('i');
    const label = document.createElement('span');
    link.className = 'modern-table-fix-link';
    link.href = 'ai_data_fix.html';
    link.setAttribute('aria-label', 'Correct a tagging mistake with AI');
    icon.className = 'fas fa-wand-magic-sparkles';
    icon.setAttribute('aria-hidden', 'true');
    label.textContent = 'Fix a tagging mistake';
    link.append(icon, label);
    return link;
}

function decorateModernRow(row) {
    const rowElement = row.getElement();
    rowElement.classList.remove('bg-white', 'hover:bg-white', 'tabulator-row-even', 'tabulator-row-odd');
    rowElement.classList.add('ops-table-row', 'modern-table-row');
    row.getCells().forEach((cell, index) => {
        const definition = cell.getColumn().getDefinition();
        const cellElement = cell.getElement();
        const kind = modernCellKind(definition);
        cellElement.dataset.label = plainColumnTitle(definition);
        cellElement.dataset.modernKind = kind;
        cellElement.dataset.modernPriority = index === 0 ? 'primary' : (kind === 'actions' ? 'actions' : 'secondary');
    });
}

function tableRegionLabel(tableElement, requestedLabel) {
    if (requestedLabel) return requestedLabel;
    if (tableElement.getAttribute('aria-label')) return tableElement.getAttribute('aria-label');
    const section = tableElement.closest('section,.cards,.ops-table-block,.transaction-card');
    const heading = section && section.querySelector('h2,h3');
    if (heading && heading.textContent.trim()) return heading.textContent.trim();
    return `${document.title || 'Data'} table`;
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

function activeRowCount(table) {
    if (typeof table.getDataCount === 'function') return table.getDataCount('active');
    if (typeof table.getData === 'function') return table.getData('active').length;
    return 0;
}

// Initialise a Tabulator table with Tailwind styling defaults
function tailwindTabulator(element, options) {
    options = options || {};

    const enableSearch = options.simpleSearch !== false;
    const searchFields = options.searchFields;
    const requestedPageSize = options.paginationSize;
    const modernResponsiveOption = options.modernResponsive;
    const modernLabel = options.modernLabel;
    const modernFreezeFirst = options.modernFreezeFirst === true;
    const modernMaxHeight = options.modernMaxHeight;
    const modernRemoteSearchParam = options.modernRemoteSearchParam;
    delete options.simpleSearch;
    delete options.searchFields;
    delete options.modernResponsive;
    delete options.modernLabel;
    delete options.modernFreezeFirst;
    delete options.modernMaxHeight;
    delete options.modernRemoteSearchParam;

    // Allow rowClick handler to be bound after table creation
    const rowClickHandler = options.rowClick;
    delete options.rowClick;

    const tableElement = typeof element === 'string' ? document.querySelector(element) : element;
    if (!tableElement) throw new Error('The table container could not be found.');
    const resolvedLabel = tableRegionLabel(tableElement, modernLabel);
    const classificationKinds = classificationKeyOrder.filter(kind =>
        Array.isArray(options.columns) && options.columns.some(column => modernCellKind(column) === kind)
    );
    const containsTransactions = isTransactionColumnSet(options.columns);
    const isMatrix = modernResponsiveOption === false || /(^|-)pivot-table$/.test(tableElement.id || '') || (Array.isArray(options.columns) && options.columns.length > 10);
    const modernResponsive = !isMatrix;
    let remoteSearchQuery = '';
    let remoteTotal = null;
    if (modernResponsive && options.responsiveLayout) options.responsiveLayout = false;

    if (modernMaxHeight && !options.height && !options.maxHeight) {
        options.maxHeight = modernMaxHeight;
    }

    if (modernRemoteSearchParam) {
        const baseAjaxParams = options.ajaxParams;
        const userAjaxResponse = options.ajaxResponse;
        options.ajaxParams = function () {
            const base = typeof baseAjaxParams === 'function' ? baseAjaxParams() : baseAjaxParams;
            const params = Object.assign({}, base || {});
            params[modernRemoteSearchParam] = remoteSearchQuery;
            return params;
        };
        options.ajaxResponse = function (url, params, response) {
            remoteTotal = response && Number.isFinite(Number(response.total)) ? Number(response.total) : null;
            return userAjaxResponse ? userAjaxResponse(url, params, response) : response;
        };
    }

    if (!options.layout) {
        options.layout = 'fitDataStretch';
    }

    const userRowFormatter = options.rowFormatter;
    // Styling is handled with CSS classes to avoid expensive per-cell DOM
    // updates when large data sets are rendered.
    options.rowFormatter = function(row) {
        if (userRowFormatter) userRowFormatter(row);
        decorateModernRow(row);
    };
    // Tabulator 6 uses a boolean switch plus an explicit mode. Normalise the
    // older shorthand so existing callers keep working without deprecation
    // warnings after the library upgrade.
    if (typeof options.pagination === 'string') {
        options.paginationMode = options.paginationMode || options.pagination;
        options.pagination = true;
    } else if (options.pagination === undefined) {
        options.pagination = true;
        options.paginationMode = options.paginationMode || 'local';
    }
    if (options.pagination !== false) {
        options.paginationSize = requestedPageSize || 20;
    }

    // Frozen columns add measurable layout work, especially when widths are
    // content-driven. Keep them for matrix views only when a caller opts in.
    if (modernFreezeFirst && Array.isArray(options.columns) && options.columns.length) {
        options.columns[0].frozen = true;
    }

    const table = new Tabulator(tableElement, options);
    const el = table.element;
    el.style.colorScheme = 'light';
    el.classList.add('border-0', 'rounded-xl', 'overflow-hidden', 'ops-standard-table', 'modern-table');
    el.classList.add(modernResponsive ? 'modern-table--cards' : 'modern-table--matrix');
    el.setAttribute('role', 'region');
    el.setAttribute('aria-label', resolvedLabel);
    el.tabIndex = 0;


    if (rowClickHandler) {
        table.on('rowClick', rowClickHandler);
    }

    if (enableSearch) {
        // Remove any existing search input inserted by a previous
        // table initialisation to avoid duplicate fields.
        const existing = tableElement.previousElementSibling;
        if (existing && existing.classList.contains('modern-table-toolbar')) {
            existing.remove();
        }

        const toolbar = document.createElement('div');
        const searchLabel = document.createElement('label');
        const searchIcon = document.createElement('i');
        const searchInput = document.createElement('input');
        const count = document.createElement('span');
        toolbar.className = 'modern-table-toolbar';
        searchLabel.className = 'modern-table-search';
        searchIcon.className = 'fas fa-magnifying-glass';
        searchIcon.setAttribute('aria-hidden', 'true');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search this table';
        searchInput.setAttribute('aria-label', `Search ${resolvedLabel}`);
        // This control owns its complete appearance. Mark it unstyled so the
        // shared form decorator cannot reapply a native Safari text field
        // inside the modern search surface.
        searchInput.className = 'tabulator-search modern-table-search-input unstyled';
        searchInput.style.colorScheme = 'light';
        count.className = 'modern-table-count';
        count.setAttribute('role', 'status');
        count.setAttribute('aria-live', 'polite');
        searchLabel.append(searchIcon, searchInput);
        toolbar.appendChild(searchLabel);
        if (classificationKinds.length) toolbar.appendChild(createClassificationKey(classificationKinds));
        if (containsTransactions) toolbar.appendChild(createTagCorrectionLink());
        toolbar.appendChild(count);
        tableElement.parentNode.insertBefore(toolbar, tableElement);
        let searchInProgress = false;
        let searchTimer = null;
        searchInput.addEventListener('input', function() {
            window.clearTimeout(searchTimer);
            const query = this.value;
            searchTimer = window.setTimeout(function () {
                if (searchInProgress) return;
                searchInProgress = true;
                const normalisedQuery = query.toLowerCase();
                try {
                    if (modernRemoteSearchParam) {
                        remoteSearchQuery = query.trim();
                        remoteTotal = null;
                        table.setData();
                    } else if (!normalisedQuery) {
                        table.clearFilter();
                    } else {
                        table.setFilter(function(data) {
                            return Object.entries(data).some(([field, v]) => {
                                if (searchFields && !searchFields.includes(field)) return false;
                                return v !== null && v !== undefined && v.toString().toLowerCase().includes(normalisedQuery);
                            });
                        });
                    }
                } finally {
                    searchInProgress = false;
                }
            }, 120);
        });

        const updateCount = function () {
            const rows = remoteTotal === null ? activeRowCount(table) : remoteTotal;
            count.textContent = `${rows.toLocaleString('en-GB')} ${rows === 1 ? 'row' : 'rows'}`;
        };
        const queueCountUpdate = function () { window.setTimeout(updateCount, 0); };
        table.on('dataProcessed', queueCountUpdate);
        table.on('dataFiltered', queueCountUpdate);
        table.on('pageLoaded', queueCountUpdate);
        table.on('tableDestroyed', function () { toolbar.remove(); });
        window.setTimeout(updateCount, 0);
    }
    table.on('tableBuilt', function() { styleCalcRows(table); });
    table.on('dataProcessed', function() {
        styleCalcRows(table);
    });
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

    const media = window.matchMedia('(max-width: 720px)');
    const redrawForViewport = function () { table.redraw(); };
    if (typeof media.addEventListener === 'function') media.addEventListener('change', redrawForViewport);
    else if (typeof media.addListener === 'function') media.addListener(redrawForViewport);
    return table;
}

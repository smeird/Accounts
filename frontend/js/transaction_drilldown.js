(function (root) {
    'use strict';

    const allowedKeys = [
        'value', 'min_amount', 'max_amount', 'start', 'end', 'direction',
        'transfer_scope', 'ignored_scope', 'dimension', 'dimension_id',
        'dimension_ids', 'unclassified', 'include_unclassified', 'account_id', 'transaction_ids',
        'description_exact', 'memo_exact', 'compare_start', 'compare_end',
        'all', 'label'
    ];

    function iso(year, month, day) {
        return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    function monthRange(year, month) {
        const last = new Date(Number(year), Number(month), 0).getDate();
        return { start: iso(year, month, 1), end: iso(year, month, last) };
    }

    function yearRange(year) {
        return { start: iso(year, 1, 1), end: iso(year, 12, 31) };
    }

    function normaliseValue(key, value) {
        if (Array.isArray(value)) return value.filter(Boolean).join(',');
        if (typeof value === 'boolean') return value ? '1' : '';
        return value === null || typeof value === 'undefined' ? '' : String(value);
    }

    function url(options) {
        const params = new URLSearchParams();
        allowedKeys.forEach(key => {
            const value = normaliseValue(key, options && options[key]);
            if (value !== '') params.set(key, value);
        });
        return `search.html?${params.toString()}`;
    }

    function financial(options) {
        return Object.assign({ direction: 'all', transfer_scope: 'exclude', ignored_scope: 'exclude' }, options || {});
    }

    function linkify(target, options, text, ariaLabel) {
        const element = typeof target === 'string' ? root && root.document.getElementById(target) : target;
        if (!element) return null;
        const documentRef = element.ownerDocument || (root && root.document);
        if (!documentRef) return null;
        const link = documentRef.createElement('a');
        link.className = 'transaction-drilldown-link';
        link.href = url(options || {});
        link.textContent = text === undefined ? element.textContent : text;
        link.setAttribute('aria-label', ariaLabel || `View contributing transactions for ${options?.label || link.textContent}`);
        link.setAttribute('data-tooltip', ariaLabel || 'View contributing transactions');
        element.replaceChildren(link);
        element.classList.add('has-transaction-drilldown');
        return link;
    }

    function highchartsPoint(optionsForPoint) {
        return {
            cursor: 'pointer',
            point: {
                events: {
                    click: function () {
                        root.location.href = url(optionsForPoint(this));
                    }
                }
            }
        };
    }

    const api = { url, financial, linkify, monthRange, yearRange, highchartsPoint };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.TransactionDrilldown = api;
})(typeof window !== 'undefined' ? window : null);

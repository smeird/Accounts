(function () {
    'use strict';

    function isoDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function defaultPeriod(now) {
        const current = now instanceof Date ? now : new Date();
        return { start: isoDate(new Date(current.getFullYear(), current.getMonth(), 1)), end: isoDate(current) };
    }

    function validatePeriod(start, end) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(start || '') || !/^\d{4}-\d{2}-\d{2}$/.test(end || '')) {
            throw new Error('Choose both dates for the export period.');
        }
        if (start > end) throw new Error('The start date must be on or before the end date.');
        return { start, end };
    }

    function queryString(period) {
        return new URLSearchParams({ start: period.start, end: period.end }).toString();
    }

    function filenameFromResponse(response, fallback) {
        const disposition = response.headers && response.headers.get ? response.headers.get('content-disposition') : '';
        const match = disposition && disposition.match(/filename="?([^";]+)"?/i);
        return match && match[1] ? match[1] : fallback;
    }

    async function responseError(response, fallback) {
        try {
            const payload = await response.json();
            return payload.error || fallback;
        } catch (error) {
            return fallback;
        }
    }

    function setBusy(button, busy, busyLabel) {
        if (!button) return;
        if (!button.dataset.idleLabel) button.dataset.idleLabel = button.querySelector('span').textContent;
        button.disabled = busy;
        button.classList.toggle('is-loading', busy);
        button.querySelector('span').textContent = busy ? busyLabel : button.dataset.idleLabel;
    }

    function announce(message, tone) {
        if (typeof window.showMessage === 'function') window.showMessage(message, tone || 'success');
    }

    async function downloadStandard(format, period) {
        const base = `${window.location.hostname || 'accounts'}-${period.start}-to-${period.end}`;
        if (format === 'ofx') {
            const response = await fetch(`../php_backend/public/export_ofx.php?${queryString(period)}`, { cache: 'no-store' });
            if (!response.ok) throw new Error(await responseError(response, 'The OFX export could not be created.'));
            saveAs(await response.blob(), filenameFromResponse(response, `${base}.ofx`));
            return;
        }

        const response = await fetch(`../php_backend/public/export_data.php?${queryString(period)}`, { cache: 'no-store' });
        if (!response.ok) throw new Error(await responseError(response, 'The data export could not be created.'));
        const data = await response.json();
        if (!Array.isArray(data)) throw new Error(data.error || 'The server returned an unexpected export.');
        if (format === 'csv') {
            saveAs(new Blob([Papa.unparse(data)], { type: 'text/csv;charset=utf-8;' }), `${base}.csv`);
        } else {
            saveAs(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json;charset=utf-8;' }), `${base}.json`);
        }
    }

    async function downloadExcel(period) {
        const response = await fetch(`../php_backend/public/export_excel.php?${queryString(period)}`, { cache: 'no-store' });
        if (!response.ok) throw new Error(await responseError(response, 'The Excel workbook could not be created.'));
        const fallback = `${window.location.hostname || 'accounts'}-financial-workbook-${period.start}-to-${period.end}.xlsx`;
        saveAs(await response.blob(), filenameFromResponse(response, fallback));
    }

    function initExport() {
        const standardForm = document.getElementById('standard-export-form');
        const excelForm = document.getElementById('excel-export-form');
        if (!standardForm || !excelForm) return;

        const defaults = defaultPeriod(new Date());
        ['start-date', 'excel-start-date'].forEach(id => { const input = document.getElementById(id); if (!input.value) input.value = defaults.start; });
        ['end-date', 'excel-end-date'].forEach(id => { const input = document.getElementById(id); if (!input.value) input.value = defaults.end; });

        standardForm.addEventListener('submit', async event => {
            event.preventDefault();
            const button = document.getElementById('export-data');
            const status = document.getElementById('standard-export-status');
            try {
                const period = validatePeriod(document.getElementById('start-date').value, document.getElementById('end-date').value);
                const format = document.getElementById('format').value;
                setBusy(button, true, 'Preparing extract…');
                status.textContent = 'Preparing your download…';
                await downloadStandard(format, period);
                status.textContent = 'Download ready.';
                announce('Export generated');
            } catch (error) {
                status.textContent = error.message;
                announce(error.message, 'error');
            } finally {
                setBusy(button, false, '');
            }
        });

        excelForm.addEventListener('submit', async event => {
            event.preventDefault();
            const button = document.getElementById('export-excel');
            const status = document.getElementById('excel-export-status');
            try {
                const period = validatePeriod(document.getElementById('excel-start-date').value, document.getElementById('excel-end-date').value);
                setBusy(button, true, 'Building workbook…');
                status.textContent = 'Creating the summary, analysis and transaction sheets…';
                await downloadExcel(period);
                status.textContent = 'Workbook ready.';
                announce('Excel financial workbook generated');
            } catch (error) {
                status.textContent = error.message;
                announce(error.message, 'error');
            } finally {
                setBusy(button, false, '');
            }
        });
    }

    if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', initExport);
    if (typeof module !== 'undefined' && module.exports) module.exports = { defaultPeriod, validatePeriod, queryString, filenameFromResponse };
}());

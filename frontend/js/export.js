// Handles exporting transactions in various formats using external libraries
function initExport() {
    const btn = document.getElementById('export-data');
    if (!btn) return;

    // default dates to current month
    const startInput = document.getElementById('start-date');
    const endInput = document.getElementById('end-date');
    if (startInput && !startInput.value) {
        const now = new Date();
        startInput.value = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0,10);
    }
    if (endInput && !endInput.value) {
        endInput.value = new Date().toISOString().slice(0,10);
    }

    btn.addEventListener('click', async () => {
        const start = startInput.value;
        const end = endInput.value;
        const format = document.getElementById('format').value;
        const params = [];
        if (start) params.push(`start=${encodeURIComponent(start)}`);
        if (end) params.push(`end=${encodeURIComponent(end)}`);
        const qs = params.length ? `?${params.join('&')}` : '';

        if (format === 'ofx') {
            window.location = `../php_backend/public/export_ofx.php${qs}`;
            return;
        }

        const resp = await fetch(`../php_backend/public/export_data.php${qs}`);
        const data = await resp.json();
        const base = `${window.location.hostname}-${new Date().toISOString().slice(0,10)}`;

        if (format === 'csv') {
            const csv = Papa.unparse(data);
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            saveAs(blob, `${base}.csv`);
        } else if (format === 'xlsx') {
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Transactions');
            const wbout = XLSX.write(wb, { bookType: 'xlsx', type: 'array' });
            const blob = new Blob([wbout], { type: 'application/octet-stream' });
            saveAs(blob, `${base}.xlsx`);
        } else if (format === 'json') {
            const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
            saveAs(blob, `${base}.json`);
        }

        if (typeof showMessage !== 'undefined') {
            showMessage('Export generated');
        }
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', initExport);
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { initExport };
}

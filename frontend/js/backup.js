// Handles downloading and restoring backups
// Set up handlers for downloading and restoring backups
function initBackup() {
    const dlBtn = document.getElementById('download-backup');
    const ofxBtn = document.getElementById('download-ofx');
    const form = document.getElementById('restore-form');
    if (dlBtn) {
        dlBtn.addEventListener('click', () => {
            const parts = Array.from(document.querySelectorAll('input[name="parts"]:checked')).map(cb => cb.value);
            const qs = parts.length ? `?parts=${parts.join(',')}` : '';
            fetch(`../php_backend/public/backup.php${qs}`)
                .then(resp => {
                    const disposition = resp.headers.get('Content-Disposition') || '';
                    let filename = `${window.location.hostname}-${new Date().toISOString().slice(0, 10)}.json.gz`;
                    const match = disposition.match(/filename="?([^";]+)"?/i);
                    if (match) {
                        filename = match[1];
                    }
                    return resp.blob().then(blob => ({ blob, filename }));
                })
                .then(({ blob, filename }) => {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    if (typeof showMessage !== 'undefined') {
                        showMessage('Backup downloaded');
                    }
                })
                .catch(() => showMessage && showMessage('Download failed', 'error'));
        });
    }

    if (ofxBtn) {
        ofxBtn.addEventListener('click', () => {
            fetch('../php_backend/public/export_ofx.php')
                .then(resp => {
                    const disposition = resp.headers.get('Content-Disposition') || '';
                    let filename = `${window.location.hostname}-${new Date().toISOString().slice(0, 10)}.ofx`;
                    const match = disposition.match(/filename="?([^";]+)"?/i);
                    if (match) {
                        filename = match[1];
                    }
                    return resp.blob().then(blob => ({ blob, filename }));
                })
                .then(({ blob, filename }) => {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    if (typeof showMessage !== 'undefined') {
                        showMessage('OFX exported');
                    }
                })
                .catch(() => showMessage && showMessage('Download failed', 'error'));
        });
    }

    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const fileInput = document.getElementById('backup-file');
            if (!fileInput.files.length) {
                return;
            }
            const fd = new FormData();
            fd.append('backup_file', fileInput.files[0]);
            fetch(form.action, { method: 'POST', body: fd })
                .then(resp => resp.text())
                .then(showMessage)
                .catch(() => showMessage('Restore failed', 'error'));
        });
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', initBackup);
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { initBackup };
}

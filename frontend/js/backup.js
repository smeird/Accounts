// Handles downloading and restoring backups
// Set up handlers for downloading and restoring backups
function initBackup() {
    const dlBtn = document.getElementById('download-backup');
    const form = document.getElementById('restore-form');
    if (dlBtn) {
        dlBtn.addEventListener('click', () => {
            fetch('../php_backend/public/backup.php')
                .then(resp => resp.blob())
                .then(blob => {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'backup.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    if (typeof showMessage !== 'undefined') {
                        showMessage('Backup downloaded');
                    }
                })
                .catch(() => showMessage && showMessage('Download failed'));
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
                .catch(() => showMessage('Restore failed'));
        });
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', initBackup);
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { initBackup };
}

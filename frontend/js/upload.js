const MAX_UPLOAD_FILES = 20;

function isSupportedStatementFile(file) {
    return Boolean(file && typeof file.name === 'string' && /\.(ofx|qfx)$/i.test(file.name));
}

function formatFileSize(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(value < 10240 ? 1 : 0)} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function normaliseUploadPayload(text, httpStatus = 200) {
    let payload;
    try {
        payload = JSON.parse(text);
    } catch (error) {
        return {
            status: 'error',
            message: 'The server returned an unreadable upload result. No success has been assumed.',
            totals: {},
            files: []
        };
    }

    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return { status: 'error', message: 'The server returned an invalid upload result.', totals: {}, files: [] };
    }
    const status = ['success', 'partial', 'error'].includes(payload.status)
        ? payload.status
        : 'error';
    return {
        status: httpStatus >= 400 && status === 'success' ? 'error' : status,
        message: String(payload.message || payload.error || (httpStatus >= 400
            ? 'The upload failed.'
            : 'The server returned an incomplete upload result. No success has been assumed.')),
        totals: payload.totals && typeof payload.totals === 'object' ? payload.totals : {},
        files: Array.isArray(payload.files) ? payload.files : []
    };
}

function balanceStatusText(account) {
    const status = String(account?.balance_status || '');
    return {
        updated: 'balance refreshed',
        recovered: 'balance repaired',
        protected: 'empty zero balance ignored',
        stale: 'older balance ignored'
    }[status] || '';
}

function initUpload() {
    const form = document.getElementById('upload-form');
    if (!form) return;

    const input = document.getElementById('ofx-files');
    const dropzone = document.getElementById('upload-dropzone');
    const selection = document.getElementById('upload-selection');
    const selectionSummary = document.getElementById('upload-selection-summary');
    const fileList = document.getElementById('upload-file-list');
    const clearButton = document.getElementById('clear-selection');
    const submitButton = document.getElementById('upload-submit');
    const validation = document.getElementById('upload-validation');
    const progress = document.getElementById('upload-progress');
    const progressTrack = progress.querySelector('[role="progressbar"]');
    const progressBar = document.getElementById('upload-progress-bar');
    const progressLabel = document.getElementById('upload-progress-label');
    const progressValue = document.getElementById('upload-progress-value');
    const progressDetail = document.getElementById('upload-progress-detail');
    const result = document.getElementById('upload-result');
    const anotherButton = document.getElementById('upload-another');
    let selectedFiles = [];
    let isBusy = false;

    function setValidation(message = '') {
        validation.textContent = message;
        validation.hidden = message === '';
    }

    function renderSelection(files) {
        selectedFiles = Array.from(files || []).slice(0, MAX_UPLOAD_FILES);
        fileList.replaceChildren();
        selection.hidden = selectedFiles.length === 0;
        const invalidFiles = selectedFiles.filter(file => !isSupportedStatementFile(file));
        selectionSummary.textContent = selectedFiles.length === 1 ? '1 statement selected' : `${selectedFiles.length} statements selected`;

        selectedFiles.forEach(file => {
            const item = document.createElement('li');
            const icon = document.createElement('i');
            const copy = document.createElement('div');
            const name = document.createElement('strong');
            const detail = document.createElement('small');
            const valid = isSupportedStatementFile(file);
            item.classList.toggle('is-invalid', !valid);
            icon.className = valid ? 'fas fa-file-invoice' : 'fas fa-circle-exclamation';
            icon.setAttribute('aria-hidden', 'true');
            name.textContent = file.name;
            detail.textContent = valid ? formatFileSize(file.size) : 'Unsupported file type';
            copy.append(name, detail);
            item.append(icon, copy);
            fileList.appendChild(item);
        });

        if (Array.from(files || []).length > MAX_UPLOAD_FILES) {
            setValidation(`Only the first ${MAX_UPLOAD_FILES} files can be imported at once.`);
        } else if (invalidFiles.length) {
            setValidation('Remove unsupported files. Only OFX and QFX statements can be imported.');
        } else {
            setValidation('');
        }
        submitButton.disabled = isBusy || selectedFiles.length === 0 || invalidFiles.length > 0;
    }

    function clearSelection({ keepResult = false } = {}) {
        selectedFiles = [];
        input.value = '';
        renderSelection([]);
        progress.hidden = true;
        progress.classList.remove('is-processing');
        if (!keepResult) result.hidden = true;
    }

    function setBusy(busy) {
        isBusy = busy;
        input.disabled = busy;
        clearButton.disabled = busy;
        dropzone.classList.toggle('is-disabled', busy);
        dropzone.setAttribute('aria-disabled', busy ? 'true' : 'false');
        submitButton.disabled = busy || selectedFiles.length === 0 || selectedFiles.some(file => !isSupportedStatementFile(file));
        submitButton.querySelector('span').textContent = busy ? 'Importing…' : 'Import transactions';
    }

    function updateProgress(percent, label, detail) {
        const bounded = Math.max(0, Math.min(100, Math.round(percent)));
        progress.hidden = false;
        progressBar.style.width = `${bounded}%`;
        progressValue.textContent = `${bounded}%`;
        progressTrack.setAttribute('aria-valuenow', String(bounded));
        progressLabel.textContent = label;
        if (detail) progressDetail.textContent = detail;
    }

    function appendAccountDetails(container, accounts) {
        if (!Array.isArray(accounts) || !accounts.length) return;
        const details = document.createElement('div');
        details.className = 'upload-account-results';
        accounts.forEach(account => {
            const item = document.createElement('span');
            const name = String(account.account_name || 'Account');
            const balanceDetail = balanceStatusText(account);
            item.textContent = `${name}: ${Number(account.inserted) || 0} new, ${Number(account.duplicates) || 0} skipped${balanceDetail ? ` · ${balanceDetail}` : ''}`;
            details.appendChild(item);
        });
        container.appendChild(details);
    }

    function renderFileResults(files) {
        const container = document.getElementById('upload-result-files');
        container.replaceChildren();
        if (!files.length) {
            const empty = document.createElement('p');
            empty.className = 'upload-file-result';
            empty.textContent = 'No per-file details were returned.';
            container.appendChild(empty);
            return;
        }
        files.forEach(file => {
            const card = document.createElement('article');
            const top = document.createElement('div');
            const name = document.createElement('strong');
            const badge = document.createElement('span');
            const message = document.createElement('p');
            const failed = file.status !== 'success';
            card.className = `upload-file-result${failed ? ' is-error' : ''}`;
            top.className = 'upload-file-result__top';
            name.textContent = String(file.file || 'Statement file');
            badge.className = 'upload-file-result__badge';
            badge.textContent = failed ? 'Failed' : 'Complete';
            message.textContent = String(file.message || (failed ? 'This file could not be imported.' : 'Import complete.'));
            top.append(name, badge);
            card.append(top, message);
            appendAccountDetails(card, file.accounts);
            container.appendChild(card);
        });
    }

    function renderResult(payload) {
        const totals = payload.totals || {};
        const status = payload.status;
        result.classList.toggle('is-partial', status === 'partial');
        result.classList.toggle('is-error', status === 'error');
        document.getElementById('upload-result-kicker').textContent = status === 'success' ? 'Import complete' : (status === 'partial' ? 'Import partly complete' : 'Import stopped');
        document.getElementById('upload-result-title').textContent = status === 'success' ? 'Your transactions are ready' : (status === 'partial' ? 'Some files need attention' : 'Nothing was imported');
        document.getElementById('upload-result-message').textContent = payload.message;
        const icon = document.querySelector('#upload-result-icon i');
        icon.className = status === 'success' ? 'fas fa-check' : (status === 'partial' ? 'fas fa-triangle-exclamation' : 'fas fa-xmark');
        document.getElementById('upload-total-inserted').textContent = Number(totals.inserted) || 0;
        document.getElementById('upload-total-duplicates').textContent = Number(totals.duplicates) || 0;
        document.getElementById('upload-total-tagged').textContent = Number(totals.tagged) || 0;
        document.getElementById('upload-total-categorised').textContent = Number(totals.categorised) || 0;
        document.getElementById('upload-total-warnings').textContent = Number(totals.warnings) || 0;
        document.getElementById('upload-total-failed').textContent = Number(totals.failed_files) || (status === 'error' ? Math.max(1, payload.files.length) : 0);
        renderFileResults(payload.files);
        result.hidden = false;
        document.getElementById('upload-result-title').focus({ preventScroll: true });
        result.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    }

    function renderTransportError(message) {
        renderResult({
            status: 'error',
            message,
            totals: { failed_files: selectedFiles.length || 1 },
            files: selectedFiles.map(file => ({ file: file.name, status: 'error', message }))
        });
    }

    input.addEventListener('change', () => renderSelection(input.files));
    clearButton.addEventListener('click', () => clearSelection());
    anotherButton.addEventListener('click', () => {
        clearSelection();
        dropzone.focus();
        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    });
    dropzone.addEventListener('keydown', event => {
        if ((event.key === 'Enter' || event.key === ' ') && !isBusy) {
            event.preventDefault();
            input.click();
        }
    });
    ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => {
        event.preventDefault();
        if (!isBusy) dropzone.classList.add('is-dragging');
    }));
    ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, event => {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
    }));
    dropzone.addEventListener('drop', event => {
        if (!isBusy) renderSelection(event.dataTransfer.files);
    });

    form.addEventListener('submit', event => {
        event.preventDefault();
        if (isBusy || !selectedFiles.length) {
            setValidation('Choose at least one OFX or QFX statement first.');
            return;
        }
        if (selectedFiles.some(file => !isSupportedStatementFile(file))) {
            setValidation('Remove unsupported files before importing.');
            return;
        }

        setValidation('');
        setBusy(true);
        result.hidden = true;
        progress.classList.remove('is-processing');
        updateProgress(0, 'Uploading statements…', 'Keep this page open while the statements are processed.');
        const data = new FormData();
        selectedFiles.forEach(file => data.append('ofx_files[]', file, file.name));
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.timeout = 300000;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', uploadEvent => {
            if (!uploadEvent.lengthComputable) return;
            updateProgress((uploadEvent.loaded / uploadEvent.total) * 72, 'Uploading statements…', `${selectedFiles.length} file${selectedFiles.length === 1 ? '' : 's'} selected`);
        });
        xhr.upload.addEventListener('load', () => {
            progress.classList.add('is-processing');
            updateProgress(86, 'Processing transactions…', 'Checking bank IDs, applying tags, and assigning categories.');
        });
        xhr.addEventListener('load', () => {
            progress.classList.remove('is-processing');
            const payload = normaliseUploadPayload(xhr.responseText, xhr.status);
            updateProgress(100, payload.status === 'error' ? 'Import stopped' : 'Import complete', payload.message);
            renderResult(payload);
            if (typeof showMessage === 'function') {
                showMessage(payload.message, payload.status === 'success' ? 'success' : 'error');
            }
        });
        xhr.addEventListener('error', () => renderTransportError('The server could not be reached. No success has been assumed.'));
        xhr.addEventListener('timeout', () => renderTransportError('The import took too long to respond. Check the transaction list before trying again.'));
        xhr.addEventListener('abort', () => renderTransportError('The import was cancelled.'));
        xhr.addEventListener('loadend', () => setBusy(false));
        xhr.send(data);
    });

    renderSelection([]);
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { MAX_UPLOAD_FILES, balanceStatusText, formatFileSize, isSupportedStatementFile, normaliseUploadPayload };
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', initUpload);
}

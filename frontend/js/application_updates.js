(function () {
    function initApplicationUpdates() {
        const card = document.getElementById('update-status');
        if (!card) return;
        const check = document.getElementById('update-check');
        const install = document.getElementById('update-install');
        const confirm = document.getElementById('update-confirm');
        const cancel = document.getElementById('update-cancel');
        const dialog = document.getElementById('update-dialog');
        const guidance = document.getElementById('update-guidance');
        const result = document.getElementById('update-result');
        let latest = null;
        let busy = false;

        const text = (id, value) => { document.getElementById(id).textContent = value; };
        const number = value => Number.isFinite(Number(value)) ? Number(value) : 0;
        const checkedTime = value => {
            const date = value ? new Date(value) : null;
            return date && !Number.isNaN(date.getTime()) ? date.toLocaleString() : '—';
        };

        function setBusy(value, label) {
            busy = value;
            check.disabled = value;
            confirm.disabled = value;
            install.disabled = value || !latest || latest.can_update !== true;
            card.setAttribute('aria-busy', value ? 'true' : 'false');
            check.querySelector('span').textContent = label;
            check.querySelector('i').classList.toggle('fa-spin', value);
        }

        function render(status) {
            latest = status;
            const state = String(status.state || 'unavailable');
            card.className = `update-status is-${state.replaceAll('_', '-')}`;
            text('update-kicker', state === 'update_available' ? 'Update available' : state === 'current' ? 'Version current' : 'Update attention');
            text('update-title', state === 'current' ? 'You have the latest code' : state === 'update_available' ? status.message : 'Automatic update unavailable');
            text('update-message', status.message || 'The update state could not be determined.');
            text('update-state', state.replaceAll('_', ' '));
            text('update-branch', status.branch || '—');
            text('update-current', status.commit || '—');
            text('update-available', status.available_commit || '—');
            text('update-difference', `${number(status.behind)} behind · ${number(status.ahead)} ahead`);
            text('update-tree', status.dirty === true ? 'Local changes present' : status.dirty === false ? 'Clean' : 'Unknown');
            text('update-checked', checkedTime(status.checked_at));
            install.disabled = busy || status.can_update !== true;
            guidance.hidden = !status.detail;
            guidance.textContent = status.detail ? `Server detail: ${status.detail}` : '';
        }

        async function request(options) {
            const response = await fetch('../php_backend/public/git_pull.php', options);
            let payload;
            try { payload = await response.json(); }
            catch (error) { throw new Error('The server returned an unreadable update response.'); }
            if (!response.ok) {
                const failure = new Error(String(payload.message || 'The update request failed.'));
                failure.payload = payload;
                throw failure;
            }
            return payload;
        }

        async function runCheck() {
            result.hidden = true;
            setBusy(true, 'Checking…');
            try { render(await request({ headers: { Accept: 'application/json' }, cache: 'no-store' })); }
            catch (error) { render(error.payload && error.payload.audit ? error.payload.audit : { state: 'unavailable', message: error.message, can_update: false }); }
            finally { setBusy(false, 'Check again'); }
        }

        function closeDialog() {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }

        function openDialog() {
            if (!latest || latest.can_update !== true) return;
            text('update-dialog-message', `${number(latest.behind)} update${number(latest.behind) === 1 ? '' : 's'} will be installed on ${latest.branch}.`);
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }

        async function runUpdate() {
            closeDialog();
            result.hidden = true;
            setBusy(true, 'Updating…');
            try {
                const payload = await request({
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'update', confirm: 'INSTALL_UPDATE' })
                });
                if (payload.audit) render(payload.audit);
                const title = document.createElement('strong');
                const message = document.createElement('p');
                title.textContent = 'Update installed';
                message.textContent = `${payload.from || 'Previous version'} → ${payload.to || 'latest'} · ${number(payload.changed_files)} files changed. Refresh this page to load the new interface.`;
                result.replaceChildren(title, message);
                result.hidden = false;
                if (typeof window.showMessage === 'function') window.showMessage('Application updated successfully. Refresh to load the new version.', 'success');
            } catch (error) {
                if (error.payload && error.payload.audit) render(error.payload.audit);
                const title = document.createElement('strong');
                const message = document.createElement('p');
                title.textContent = 'Update not installed';
                message.textContent = error.message;
                result.replaceChildren(title, message);
                result.hidden = false;
                result.classList.add('is-error');
                if (typeof window.showMessage === 'function') window.showMessage(error.message, 'error');
            } finally { setBusy(false, 'Check again'); }
        }

        check.addEventListener('click', runCheck);
        install.addEventListener('click', openDialog);
        cancel.addEventListener('click', closeDialog);
        confirm.addEventListener('click', runUpdate);
        runCheck();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initApplicationUpdates);
    else initApplicationUpdates();
}());

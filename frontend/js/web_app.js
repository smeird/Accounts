// Shared iPhone/standalone experience. No financial values or drafts are persisted.
(function () {
    'use strict';
    const script = document.currentScript;
    const frontend = new URL('../', script.src);
    const root = new URL('../', frontend);
    const api = new URL('php_backend/public/', root);
    const nativeFetch = window.fetch.bind(window);
    const editForms = new Map();
    let locked = false;
    let identity = null;
    let sessionDialog;
    let connection;
    let resumePromise;
    let hiddenAt = 0;
    let waitingWorker;
    let loginFrame;

    function returnDestination() {
        return location.pathname.startsWith(root.pathname)
            ? location.pathname.slice(root.pathname.length) + location.search + location.hash
            : 'frontend/instant.html';
    }
    function loginUrl(embedded = false) {
        const url = new URL('index.php', root);
        url.searchParams.set('return', embedded ? 'frontend/reauth_complete.html' : returnDestination());
        if (embedded) url.searchParams.set('embedded', '1');
        return url.href;
    }
    function isApi(url) { return url.origin === api.origin && url.pathname.startsWith(api.pathname); }
    function serialise(form) {
        return JSON.stringify(Array.from(form.elements).filter(el => el.id && el.type !== 'password' && el.type !== 'file').map(el => [el.id, el.value, el.checked]));
    }
    function hasEdits() {
        return Array.from(editForms).some(([form, original]) => form.isConnected && serialise(form) !== original);
    }
    function markSaved(form, savedState) {
        if (!form) return;
        if (savedState && serialise(form) !== savedState) editForms.set(form, savedState);
        else editForms.delete(form);
    }
    function notify(text, tone = 'info') { window.showMessage?.(text, tone); }
    function button(text, action) {
        const element = document.createElement('button');
        element.type = 'button'; element.textContent = text; element.setAttribute('aria-label', text);
        element.addEventListener('click', action);
        return element;
    }
    function showConnection(message) {
        if (!connection) return;
        connection.querySelector('span').textContent = message;
        connection.dataset.tone = /^(Connected|An app update)/.test(message) ? 'info' : 'warning';
        connection.hidden = false;
    }
    function showSession() {
        const alreadyLocked = locked;
        locked = true;
        if (!sessionDialog) return;
        sessionDialog.querySelector('a').href = loginUrl();
        if (!alreadyLocked) sessionDialog.querySelector('[data-session-status]').textContent = '';
        if (!sessionDialog.open) sessionDialog.showModal();
    }
    async function lockSession() {
        // Block new writes immediately; let the server destroy the session too.
        showSession();
        try {
            await nativeFetch(new URL('lock_session.php', api), { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        } catch (_) { showConnection('Offline — reconnect to sign in. No changes will be sent while locked.'); }
    }
    window.AppWeb = { hasEdits, markSaved, capture: serialise, lockSession, sessionExpired: showSession, isLocked: () => locked, loginUrl };

    // Observe failures without replaying writes. A failed response remains available
    // to the caller so existing page error handling continues to work.
    window.fetch = async function (input, options) {
        const url = new URL(typeof input === 'string' || input instanceof URL ? input : input.url, location.href);
        const method = String(options?.method || (typeof input === 'object' && input.method) || 'GET').toUpperCase();
        const apiRequest = isApi(url);
        if (locked && apiRequest && !['GET', 'HEAD'].includes(method) && !/\/passkey_login_(?:options|verify)\.php$/.test(url.pathname)) {
            throw new Error('Sign in again before saving. Your open edits have been kept.');
        }
        try {
            const response = await nativeFetch(input, options);
            if (apiRequest && response.status === 401 && !/\/passkey_login_/.test(url.pathname)) showSession();
            if (apiRequest && response.status === 403) {
                response.clone().json().then(data => { if (data.error === 'Session expired') showSession(); }).catch(() => {});
            }
            if (apiRequest && /\/current_user\.php$/.test(url.pathname) && response.ok) {
                response.clone().json().then(data => { if (!identity) identity = data.username; }).catch(() => {});
            }
            return response;
        } catch (error) {
            if (error.name !== 'AbortError' && apiRequest) {
                showConnection(['GET', 'HEAD'].includes(method)
                    ? 'Connection interrupted — displayed figures may be out of date. Reconnect and retry.'
                    : 'Connection interrupted — this change may not have reached the server. Check its current state before trying again.');
            }
            throw error;
        }
    };

    async function checkSession() {
        const response = await nativeFetch(new URL('current_user.php', api), { credentials: 'same-origin', cache: 'no-store' });
        if (response.status === 401 || response.status === 403) { showSession(); return false; }
        if (!response.ok) throw new Error('Unable to check your session. Try again.');
        const user = await response.json();
        if (identity && user.username !== identity) {
            showSession();
            sessionDialog.querySelector('[data-session-status]').textContent = 'A different user is signed in. Sign in with the original account to continue these edits, or close this window.';
            return false;
        }
        identity = user.username;
        locked = false;
        sessionDialog?.close();
        loginFrame?.remove();
        loginFrame = null;
        sessionDialog?.classList.remove('web-app-reauth');
        return true;
    }
    async function resume() {
        if (resumePromise || !document.getElementById('menu') || navigator.onLine === false) return;
        resumePromise = (async () => {
            try {
                if (!await checkSession()) return;
                if (hasEdits()) {
                    showConnection('Connected — your unfinished edits are still open. Save or discard them before refreshing.');
                    return;
                }
                // Refresh only when the page opts in; editing/operations pages
                // never reload automatically or repeat their actions.
                if (document.body.hasAttribute('data-resume-refresh')) location.reload();
                else showConnection('Connected — refresh to check for updated information.');
            } catch (_) { showConnection('Unable to reach the server. Your open edits have been kept. Try again when connected.'); }
        })();
        try { await resumePromise; } finally { resumePromise = null; }
    }
    function installDialog() {
        const dialog = document.createElement('dialog');
        dialog.className = 'web-app-dialog';
        dialog.setAttribute('aria-labelledby', 'web-app-install-title');
        dialog.innerHTML = '<h2 id="web-app-install-title">Keep Accounts on your Home Screen</h2><ol><li>Open this app in Safari over HTTPS.</li><li>Open the Share menu and choose <strong>Add to Home Screen</strong> (it may be under More).</li><li>Keep <strong>Open as Web App</strong> enabled if offered, then tap Add.</li></ol><p>Open the new icon and sign in with your passkey or password. Your server must stay reachable; financial data needs a connection.</p><p>On other browsers, use the browser’s Install app or Add to Home Screen option.</p>';
        dialog.append(button('Close installation guide', () => dialog.close()));
        document.body.append(dialog);
        return dialog;
    }
    function navigation() {
        const menu = document.getElementById('menu');
        if (!menu) return;
        document.body.classList.add('has-mobile-navigation');
        const nav = document.createElement('nav');
        nav.className = 'web-app-tabs'; nav.setAttribute('aria-label', 'Quick navigation');
        const tabs = [['instant.html', 'Overview', 'fa-house'], ['search.html', 'Transactions', 'fa-magnifying-glass'], ['budgets.html', 'Planning', 'fa-compass']];
        const current = location.pathname.split('/').pop();
        let activeTab = false;
        const sections = {
            'instant.html': /^(?:index|instant|account_dashboard|monthly_statement|account)\.html$/,
            'search.html': /^(?:search|transaction|upload|report|transfers|ignored)\.html$/,
            'budgets.html': /^(?:budgets|pensions|mortgages|projects|projects_board|project_add|projects_archived)\.html$/
        };
        tabs.forEach(([page, label, icon]) => {
            const a = document.createElement('a'); a.href = new URL(page, frontend).href;
            a.setAttribute('aria-label', label);
            a.innerHTML = `<i class="fas ${icon}" aria-hidden="true"></i><span>${label}</span>`;
            if (sections[page].test(current)) { a.setAttribute('aria-current', 'page'); activeTab = true; }
            nav.append(a);
        });
        const more = button('More', () => document.getElementById('menu-toggle')?.click());
        more.innerHTML = '<i class="fas fa-bars" aria-hidden="true"></i><span>More</span>';
        more.setAttribute('aria-label', 'More navigation options'); more.setAttribute('aria-controls', 'menu');
        new MutationObserver(() => more.setAttribute('aria-expanded', String(menu.classList.contains('is-open')))).observe(menu, { attributes: true, attributeFilter: ['class'] });
        more.setAttribute('aria-expanded', 'false');
        if (!activeTab) more.setAttribute('aria-current', 'page');
        nav.append(more); document.body.append(nav);

        const back = button('Back', () => {
            const previous = document.referrer && new URL(document.referrer);
            if (history.length > 1 && previous && previous.origin === location.origin) history.back();
            else location.assign(new URL('instant.html', frontend));
        });
        back.className = 'web-app-back';
        back.setAttribute('aria-label', 'Back to previous page or financial overview');
        document.body.append(back);
    }
    function initialise() {
        connection = document.createElement('aside');
        connection.className = 'web-app-connection'; connection.hidden = true;
        connection.setAttribute('role', 'status'); connection.setAttribute('aria-live', 'polite');
        connection.innerHTML = '<span></span>';
        connection.append(button('Retry', async () => {
            if (document.getElementById('menu')) await resume();
            else location.reload();
        }), button('Refresh', () => { if (!hasEdits() || confirm('Discard your unfinished edits and refresh?')) location.reload(); }));
        document.body.append(connection);
        if ('ResizeObserver' in window) new ResizeObserver(() => {
            document.documentElement.style.setProperty('--web-app-status-height', connection.hidden ? '0px' : `${connection.offsetHeight + 16}px`);
        }).observe(connection);
        if (navigator.onLine === false) showConnection('Offline — financial information needs a connection. Your open edits stay in this window.');

        sessionDialog = document.createElement('dialog');
        sessionDialog.className = 'web-app-dialog web-app-session'; sessionDialog.setAttribute('aria-labelledby', 'web-app-session-title');
        sessionDialog.innerHTML = '<h2 id="web-app-session-title">Sign in again</h2><p>Your session has ended. Your unfinished edits remain in this window; they have not been saved.</p><p>Sign in here to keep your edits in this app. Keep this window open until you have saved.</p><p data-session-status role="status"></p>';
        sessionDialog.addEventListener('cancel', event => event.preventDefault());
        const login = document.createElement('a'); login.href = loginUrl(); login.textContent = 'Start again at sign-in'; login.setAttribute('aria-label', 'Leave this page and start again at sign-in');
        const continueSession = async () => {
            try { if (await checkSession()) { document.dispatchEvent(new CustomEvent('app:session-restored')); notify('Signed in. Your edits are ready to save.'); } }
            catch (error) { sessionDialog.querySelector('[data-session-status]').textContent = error.message; }
        };
        sessionDialog.append(button('Sign in here', () => {
            loginFrame?.remove();
            sessionDialog.querySelector('[data-session-status]').textContent = '';
            loginFrame = document.createElement('iframe');
            loginFrame.title = 'Secure sign-in to Accounts';
            loginFrame.setAttribute('allow', "publickey-credentials-get 'self'");
            loginFrame.src = loginUrl(true);
            sessionDialog.classList.add('web-app-reauth');
            sessionDialog.append(loginFrame);
        }), button('Continue after signing in', continueSession), login);
        window.addEventListener('message', event => {
            if (event.origin === location.origin && loginFrame && event.source === loginFrame.contentWindow && event.data?.type === 'accounts:authenticated') continueSession();
        });
        document.body.append(sessionDialog);
        if (locked) showSession();
        const guide = installDialog();
        const guideButton = button('Install on your phone', () => guide.showModal());
        guideButton.className = 'web-app-install';
        guideButton.hidden = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        const menu = document.getElementById('menu');
        // The menu fragment is loaded asynchronously. Keep the install control
        // outside it so replacing the fragment cannot destroy its handler.
        if (menu) {
            const observer = new MutationObserver(() => {
                const footer = menu.querySelector('#user-info');
                if (footer && !menu.contains(guideButton)) footer.before(guideButton);
            });
            observer.observe(menu, { childList: true });
        } else document.querySelector('main')?.append(guideButton);
        navigation();
        const trackEdit = event => {
            const form = event.target.closest?.('form[data-preserve-edits]');
            if (form && !editForms.has(form)) editForms.set(form, serialise(form));
        };
        document.addEventListener('focusin', trackEdit, true);
        document.addEventListener('pointerdown', trackEdit, true);
        document.addEventListener('app:form-saved', event => markSaved(event.target.closest?.('form')));
        window.addEventListener('beforeunload', event => { if (hasEdits()) { event.preventDefault(); event.returnValue = ''; } });
        // Do not depend on beforeunload (which iOS may omit).
        document.addEventListener('click', event => {
            const link = event.target.closest?.('a[href]');
            if (!link || link.target === '_blank' || link.hasAttribute('download') || link.getAttribute('href').startsWith('#') || !hasEdits()) return;
            if (!confirm('Leave this page and discard your unfinished edits?')) event.preventDefault();
            else editForms.clear();
        });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) hiddenAt = Date.now();
            else if (hiddenAt && Date.now() - hiddenAt > 30000) { hiddenAt = 0; resume(); }
        });
        window.addEventListener('pageshow', event => { if (event.persisted) resume(); });
        window.addEventListener('offline', () => showConnection('Offline — financial information needs a connection. Your open edits stay in this window.'));
        window.addEventListener('online', resume);
        window.visualViewport?.addEventListener('resize', () => {
            const keyboard = window.innerHeight - window.visualViewport.height > 150;
            document.body.classList.toggle('web-app-keyboard-open', keyboard);
        });
        if ('serviceWorker' in navigator && window.isSecureContext) {
            navigator.serviceWorker.register(new URL('sw.js', root), { scope: root.pathname, updateViaCache: 'none' }).then(registration => {
                const offer = () => {
                    waitingWorker = registration.waiting;
                    if (!waitingWorker || !navigator.serviceWorker.controller) return;
                    showConnection('An app update is ready. Save your edits, then choose Update.');
                    if (!connection.querySelector('[data-update]')) {
                        const update = button('Update', () => {
                            if (hasEdits()) { notify('Save or discard your unfinished edits before updating.', 'warning'); return; }
                            waitingWorker?.postMessage({ type: 'ACTIVATE_UPDATE' });
                        });
                        update.dataset.update = 'true'; connection.append(update);
                    }
                };
                offer();
                registration.addEventListener('updatefound', () => registration.installing?.addEventListener('statechange', offer));
            }).catch(() => { /* Online use remains available when installation is unavailable. */ });
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                // Another window may activate an update: never reload an edit.
                if (waitingWorker && !hasEdits()) location.reload();
            });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise);
    else initialise();
})();

// Isolated iPhone-sized checks. Run against a local PHP server; APIs are mocked.
// NODE_PATH=/path/to/playwright/node_modules ACCOUNTS_TEST_URL=http://127.0.0.1:8018 node tests/web_app_browser.cjs
const { chromium, webkit } = require('playwright');
const engine = process.env.ACCOUNTS_TEST_BROWSER === 'webkit' ? webkit : chromium;
const assert = require('node:assert/strict');
const base = process.env.ACCOUNTS_TEST_URL || 'http://127.0.0.1:8018';
(async () => {
    const browser = await engine.launch({ headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, serviceWorkers: 'block' });
        const page = await context.newPage(), errors = [], calls = [];
        let signedIn = true, username = 'Preview', writes = 0;
        const tx = { id: 17, account_id: 1, account_name: 'Current account', date: '2026-10-17', amount: -25, description: 'Cafe test', tag_name: '', category_name: '', segment_name: '', group_name: '', transfer_id: null };
        page.on('pageerror', error => errors.push(error.message));
        await context.route('https://**', route => route.abort());
        await context.route('**/php_backend/public/**', route => {
            const req = route.request(), url = new URL(req.url()), file = url.pathname.split('/').pop();
            calls.push(file);
            if (file === 'current_user.php') return route.fulfill({ status: signedIn ? 200 : 401, json: signedIn ? { username } : { error: 'Authentication required' } });
            if (file === 'transaction.php') return route.fulfill({ json: tx });
            if (file === 'groups.php' || file === 'categories.php') return route.fulfill({ json: [] });
            if (file === 'tags.php') return route.fulfill({ json: [] });
            if (file === 'session_timeout.php') return route.fulfill({ json: { minutes: 0 } });
            if (file === 'lock_session.php') { signedIn = false; return route.fulfill({ json: { locked: true } }); }
            if (file === 'update_transaction.php') { writes++; return route.fulfill({ json: { status: 'ok' } }); }
            if (file === 'brand_settings.php') return route.fulfill({ json: { site_name: 'Preview Accounts', color_scheme: 'indigo', brand_color: '#4f46e5', brand_color_dark: '#4338ca' } });
            return route.fulfill({ json: {} });
        });
        await page.goto(`${base}/frontend/transaction.html?id=17`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => !document.getElementById('transaction-workspace').hidden);
        assert.equal(await page.locator('.web-app-tabs').isVisible(), true);
        assert.equal(await page.locator('.web-app-tabs a').count(), 3);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: '/tmp/accounts-iphone-detail.png', fullPage: true });
        await page.getByRole('button', { name: 'More navigation options', exact: true }).click();
        await page.waitForFunction(() => document.getElementById('menu').classList.contains('is-open'));
        assert.notEqual(await page.evaluate(() => document.activeElement.id), 'sidebar-search', 'opening navigation must not open the keyboard');
        await page.getByRole('button', { name: 'Install on your phone', exact: true }).click();
        assert.equal(await page.getByRole('heading', { name: 'Keep Accounts on your Home Screen' }).isVisible(), true);
        await page.getByRole('button', { name: 'Close installation guide' }).click();
        await page.locator('.site-menu-close').click();
        await page.getByRole('button', { name: 'Edit this transaction classification' }).click();
        assert.equal(await page.locator('.transaction-phone-editor').evaluate(element => element.open), true);
        await page.screenshot({ path: '/tmp/accounts-iphone-editor.png', fullPage: true });
        await page.fill('#tag', 'Unfinished cafe tag');
        assert.equal(await page.evaluate(() => AppWeb.hasEdits()), true);
        assert.equal(await page.locator('#tag').evaluate(element => getComputedStyle(element).fontSize), '16px');
        signedIn = false;
        await page.evaluate(() => fetch('../php_backend/public/current_user.php'));
        await page.waitForFunction(() => document.querySelector('.web-app-session').open);
        assert.equal(await page.inputValue('#tag'), 'Unfinished cafe tag');
        const login = new URL(await page.locator('.web-app-session a').getAttribute('href'));
        assert.equal(login.searchParams.get('return'), 'frontend/transaction.html?id=17');
        assert.match(await page.evaluate(() => fetch('../php_backend/public/update_transaction.php', { method: 'POST' }).catch(e => e.message)), /Sign in again/);
        assert.equal(writes, 0, 'never replay or send a write while locked');
        signedIn = true; username = 'Different account';
        await page.getByRole('button', { name: 'Continue after signing in' }).click();
        await page.waitForFunction(() => /different user/.test(document.querySelector('[data-session-status]').textContent));
        assert.equal(await page.locator('.web-app-session').evaluate(element => element.open), true);
        username = 'Preview';
        await page.getByRole('button', { name: 'Continue after signing in' }).click();
        await page.waitForFunction(() => !document.querySelector('.web-app-session').open);
        assert.equal(await page.inputValue('#tag'), 'Unfinished cafe tag');
        await page.click('#save-transaction');
        await page.waitForFunction(() => document.getElementById('transaction-status').textContent === 'Changes saved');
        assert.equal(writes, 1);
        assert.equal(await page.evaluate(() => AppWeb.hasEdits()), false);
        assert.equal(await page.evaluate(() => Object.keys(sessionStorage).length), 0);
        // Reauthenticate in a same-origin frame: installed iPhone apps need
        // their own cookie store, rather than a separate Safari window.
        await context.route('**/index.php?**', route => route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><body><button id="complete" onclick="location.href='frontend/reauth_complete.html'">Complete sign-in</button></body></html>` }));
        signedIn = false;
        await page.evaluate(() => fetch('../php_backend/public/current_user.php'));
        await page.waitForFunction(() => document.querySelector('.web-app-session').open);
        await page.getByRole('button', { name: 'Sign in here', exact: true }).click();
        const frame = page.frameLocator('iframe[title="Secure sign-in to Accounts"]');
        await frame.locator('#complete').waitFor();
        const embedded = new URL(await page.locator('.web-app-session iframe').getAttribute('src'));
        assert.equal(embedded.searchParams.get('return'), 'frontend/reauth_complete.html');
        assert.equal(embedded.searchParams.get('embedded'), '1');
        await page.evaluate(() => window.dispatchEvent(new MessageEvent('message', { origin: location.origin, source: window, data: { type: 'accounts:authenticated' } })));
        assert.equal(await page.evaluate(() => AppWeb.isLocked()), true, 'Only the expected child frame can confirm sign-in');
        signedIn = true;
        await frame.locator('#complete').click();
        await page.waitForFunction(() => !document.querySelector('.web-app-session').open);
        assert.equal(await page.locator('.web-app-session iframe').count(), 0);
        assert.equal(writes, 1, 'Embedded sign-in does not replay the saved transaction');
        await page.getByRole('button', { name: 'Back to transaction, keeping unfinished edits' }).click();
        await context.setOffline(true);
        await page.waitForFunction(() => !document.querySelector('.web-app-connection').hidden);
        assert.match(await page.locator('.web-app-connection').innerText(), /Offline/);
        await context.setOffline(false);
        await page.setViewportSize({ width: 320, height: 568 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: '/tmp/accounts-iphone-transaction.png', fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1000 });
        assert.equal(await page.locator('.web-app-tabs').isVisible(), false);
        assert.deepEqual(errors, []);
        console.log('PASS: Phone navigation, install guide, small-screen layout, edit preservation, same-account session recovery, write gating, save, offline indicator and desktop layout');
        await context.close();

        // A real service worker serves only a public fallback on a failed navigation.
        const offlineContext = await browser.newContext();
        const offlinePage = await offlineContext.newPage();
        await offlinePage.goto(`${base}/frontend/offline.html`);
        await offlinePage.evaluate(async () => { await navigator.serviceWorker.register('../sw.js', { scope: '../', updateViaCache: 'none' }); await navigator.serviceWorker.ready; });
        await offlinePage.reload();
        await offlinePage.waitForFunction(() => !!navigator.serviceWorker.controller);
        if (process.env.ACCOUNTS_TEST_BROWSER === 'webkit') {
            // Playwright WebKit offline navigation fails before dispatching the
            // worker; cache policy is tested here and fallback is exercised in Chromium.
            const cached = await offlinePage.evaluate(async () => (await Promise.all((await caches.keys()).map(async name => (await (await caches.open(name)).keys()).map(request => new URL(request.url).pathname)))).flat());
            assert.deepEqual(cached, ['/frontend/offline.html']);
            console.log('PASS: WebKit worker activation and public-only offline cache');
            await offlineContext.close();
            return;
        }
        await offlineContext.setOffline(true);
        await offlinePage.goto(`${base}/frontend/search.html?value=cafe`, { waitUntil: 'domcontentloaded' });
        assert.equal(await offlinePage.locator('h1').innerText(), 'You’re offline');
        const cached = await offlinePage.evaluate(async () => (await Promise.all((await caches.keys()).map(async name => (await (await caches.open(name)).keys()).map(request => new URL(request.url).pathname)))).flat());
        assert.deepEqual(cached, ['/frontend/offline.html']);
        await offlineContext.setOffline(false);
        await offlinePage.getByRole('button', { name: 'Retry this page' }).click();
        await offlinePage.waitForURL('**/frontend/search.html?value=cafe');
        console.log('PASS: Installed service worker offline navigation and cache contains only the public offline page');
        await offlineContext.close();
    } finally { await browser.close(); }
})();
process.on('unhandledRejection', error => { console.error(error); process.exitCode = 1; });

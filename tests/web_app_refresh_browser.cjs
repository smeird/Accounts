// Run with NODE_PATH pointing to the bundled Playwright packages.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../frontend/js/web_app.js'), 'utf8');
(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        let revision = 'initial', signedIn = true, checks = 0;
        await page.route('https://accounts.test/**', route => {
            const path = new URL(route.request().url()).pathname;
            if (path.endsWith('/current_user.php')) {
                checks++;
                return route.fulfill({ status: signedIn ? 200 : 401, json: signedIn ? { username: 'Preview', statement_import_revision: revision } : {} });
            }
            if (path.endsWith('/web_app.js')) return route.fulfill({ contentType: 'application/javascript', body: source });
            return route.fulfill({ contentType: 'text/html', body: '<!doctype html><body><main><form data-preserve-edits><input id="draft" value="original"></form></main><script src="js/web_app.js"></script><script>document.body.setAttribute("data-phone-app", "");window.refreshes=0;document.addEventListener("app:resume",()=>window.refreshes++);</script>' });
        });
        await page.goto('https://accounts.test/frontend/phone.html');
        await page.evaluate(async () => { await fetch('../php_backend/public/current_user.php'); await new Promise(resolve => setTimeout(resolve, 20)); });
        async function resume() {
            const previous = checks;
            await page.evaluate(() => window.dispatchEvent(new Event('online')));
            await page.waitForFunction(() => !document.querySelector('.web-app-session').open);
            await new Promise(resolve => setTimeout(resolve, 100));
            assert.ok(checks > previous, 'Resume still checks authentication');
        }
        await resume();
        assert.equal(await page.evaluate(() => window.refreshes), 0);
        assert.equal(await page.locator('.web-app-connection').isVisible(), false, 'No refresh prompt for unchanged data');
        revision = 'import-1';
        await resume();
        assert.equal(await page.evaluate(() => window.refreshes), 1, 'Changed statement triggers a phone refresh');
        await resume();
        assert.equal(await page.evaluate(() => window.refreshes), 1, 'Same import does not trigger another refresh');
        await page.locator('#draft').fill('unfinished');
        revision = 'import-2';
        await resume();
        assert.equal(await page.evaluate(() => window.refreshes), 1, 'Import never reloads unfinished edits');
        assert.match(await page.locator('.web-app-connection').innerText(), /new statement.*unfinished edits/);
        await page.evaluate(() => AppWeb.markSaved(document.querySelector('form')));
        await resume();
        assert.equal(await page.evaluate(() => window.refreshes), 2, 'Pending import remains detectable after saving');
        await page.evaluate(() => document.addEventListener('app:resume', event => event.preventDefault(), { once: true }));
        revision = 'import-3';
        await resume();
        await resume();
        assert.equal(await page.evaluate(() => window.refreshes), 4, 'A phone editor can defer an import refresh without consuming it');
        signedIn = false;
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await page.waitForFunction(() => document.querySelector('.web-app-session').open);
        assert.equal(await page.inputValue('#draft'), 'unfinished', 'Session expiry keeps the document');
        console.log('PASS: Quiet unchanged resumes, import-triggered refresh, pending edits, deferred phone refresh and session checks');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

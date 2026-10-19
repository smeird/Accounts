// Exercise phone file selection, session recovery, downloads and evidence links.
const { chromium, webkit } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const engine = process.env.ACCOUNTS_TEST_BROWSER === 'webkit' ? webkit : chromium;
(async () => {
    const browser = await engine.launch({ headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, acceptDownloads: true, serviceWorkers: 'block' });
        const page = await context.newPage(), errors = [];
        let expired = false, imports = 0;
        page.on('pageerror', error => errors.push(error.message));
        await context.route('https://**', route => route.abort());
        await context.route('**/php_backend/public/**', route => {
            const file = new URL(route.request().url()).pathname.split('/').pop();
            if (file === 'current_user.php') return route.fulfill({ status: expired ? 401 : 200, json: expired ? { error: 'Authentication required' } : { username: 'Preview' } });
            if (file === 'session_timeout.php') return route.fulfill({ json: { minutes: 0 } });
            if (file === 'brand_settings.php') return route.fulfill({ json: { site_name: 'Preview' } });
            if (file === 'upload_ofx.php') {
                if (expired) return route.fulfill({ status: 401, json: { error: 'Authentication required' } });
                imports++;
                assert.match(route.request().postDataBuffer().toString(), /filename="phone\.ofx"/);
                // WebKit routing omits multipart file bytes; selection is checked
                // through the File object and Chromium also exposes the body.
                if (process.env.ACCOUNTS_TEST_BROWSER !== 'webkit') assert.match(route.request().postDataBuffer().toString(), /<OFX>/);
                return route.fulfill({ json: { status: 'success', message: 'Statement imported', totals: { inserted: 1 }, files: [{ file: 'phone.ofx', status: 'success', message: 'One transaction imported' }] } });
            }
            if (file === 'export_excel.php') return route.fulfill({ contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', headers: { 'Content-Disposition': 'attachment; filename="phone-workbook.xlsx"' }, body: Buffer.from('PK mocked workbook') });
            if (file === 'export_data.php') return route.fulfill({ json: [{ date: '2026-10-17', amount: -25, description: 'Cafe' }] });
            if (file === 'search_transactions.php') return route.fulfill({ json: { results: [{ id: 17, date: '2026-10-17', amount: -25, description: 'Cafe', group_name: 'Holiday', tag_name: 'Cafe' }] } });
            return route.fulfill({ json: {} });
        });
        const base = process.env.ACCOUNTS_TEST_URL || 'http://127.0.0.1:8018';
        await page.goto(`${base}/frontend/upload.html`, { waitUntil: 'networkidle' });
        await page.setInputFiles('#ofx-files', { name: 'phone.ofx', mimeType: 'application/x-ofx', buffer: Buffer.from('<OFX>test statement</OFX>') });
        assert.match(await page.locator('#upload-selection-summary').innerText(), /1 statement/);
        expired = true;
        await page.click('#upload-submit');
        await page.waitForFunction(() => document.querySelector('.web-app-session').open);
        assert.equal(imports, 0);
        assert.equal(await page.locator('#ofx-files').evaluate(element => element.files[0].name), 'phone.ofx');
        expired = false;
        await page.getByRole('button', { name: 'Continue after signing in' }).click();
        await page.waitForFunction(() => !document.querySelector('.web-app-session').open);
        assert.equal(imports, 0, 'Signing in must never automatically replay an import');
        await page.click('#upload-submit');
        await page.waitForFunction(() => document.getElementById('upload-total-inserted').textContent === '1');
        assert.equal(imports, 1);
        await page.goto(`${base}/frontend/export.html`, { waitUntil: 'networkidle' });
        const excelDownload = page.waitForEvent('download');
        await page.click('#export-excel');
        const workbook = await excelDownload;
        assert.equal(workbook.suggestedFilename(), 'phone-workbook.xlsx');
        assert.match(fs.readFileSync(await workbook.path(), 'utf8'), /^PK/);
        await page.selectOption('#format', 'csv');
        const csvDownload = page.waitForEvent('download');
        await page.click('#export-data');
        const csv = await csvDownload;
        assert.match(csv.suggestedFilename(), /\.csv$/);
        assert.match(fs.readFileSync(await csv.path(), 'utf8'), /Cafe/);
        await page.goto(`${base}/frontend/search.html?group_id=7&dimension=tag&dimension_id=3&start=2026-10-01&end=2026-10-31&label=Holiday`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => document.getElementById('search-status').textContent === '1 result found');
        assert.equal(new URL(page.url()).searchParams.get('group_id'), '7');
        assert.equal(await page.locator('#results-grid').getByText('Cafe').first().isVisible(), true);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.deepEqual(errors, []);
        console.log('PASS: Phone OFX selection, expired-session XHR recovery without replay, Excel/CSV downloads and filtered transaction evidence');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

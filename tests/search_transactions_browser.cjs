// Run with Playwright installed and the app served locally:
// ACCOUNTS_TEST_URL=http://localhost:8000 node tests/search_transactions_browser.cjs
// API responses and unavailable chart assets are mocked; no real ledger is touched.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        const requests = [], errors = [];
        let failApi = false;
        page.on('pageerror', error => errors.push(error.message));
        await page.route('https://code.highcharts.com/**', route => route.abort());
        await page.route('**/php_backend/public/**', route => {
            const url = new URL(route.request().url());
            let json = {};
            if (url.pathname.endsWith('/search_transactions.php')) {
                requests.push(url.searchParams);
                if (failApi) return route.fulfill({ status: 500, json: { error: 'Temporary search failure' } });
                json = { results: [{ id: 77, date: '2026-08-03', amount: -25, description: 'Taxi to airport', transfer_id: null, group_name: 'Holiday', tag_name: 'Taxis' }], total: -25 };
            }
            if (url.pathname.endsWith('/current_user.php')) json = { username: 'Preview' };
            return route.fulfill({ json });
        });
        const expected = { group_id: '7', dimension: 'tag', dimension_id: '3', start: '2026-08-01', end: '2026-08-31', direction: 'spending', transfer_scope: 'exclude', ignored_scope: 'exclude', label: 'Holiday' };
        await page.goto((process.env.ACCOUNTS_TEST_URL || 'http://localhost:8000') + '/frontend/search.html?' + new URLSearchParams(expected), { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => /found|failed/.test(document.getElementById('search-status').textContent));
        assert.equal(await page.locator('#search-status').innerText(), '1 result found');
        assert.equal(await page.locator('#search-error').isHidden(), true);
        assert.equal(await page.locator('#results-grid .tabulator-row').count(), 1);
        assert.equal(await page.locator('#search-hero-value').innerText(), '-£25.00');
        assert.match(await page.locator('#results-chart').innerText(), /Chart unavailable/);
        for (const [key, value] of Object.entries(expected)) assert.equal(requests[0].get(key), value);
        await page.fill('#term', 'taxi');
        await page.click('#search-submit');
        await page.waitForFunction(() => document.getElementById('search-status').textContent === '1 result found');
        assert.equal(requests.at(-1).get('group_id'), '7');
        assert.equal(requests.at(-1).get('value'), 'taxi');
        failApi = true;
        await page.click('#search-submit');
        await page.waitForFunction(() => document.getElementById('search-status').textContent === 'Search failed');
        failApi = false;
        await page.click('#search-submit');
        await page.waitForFunction(() => document.getElementById('search-status').textContent === '1 result found');
        assert.equal(await page.locator('#results-grid .tabulator-row').count(), 1, 'Retry rebuilds the table after an API failure');
        await page.click('#search-clear');
        assert.equal(new URL(page.url()).search, '');
        assert.equal(await page.locator('#search-filter-context').isHidden(), true);
        assert.match(await page.locator('#results-grid').innerText(), /Start with a search/);
        assert.deepEqual(errors, []);
        console.log('PASS: Group Analysis evidence filters, unavailable chart fallback, refinement, API retry and Clear');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

// Run against a local server with Playwright installed. APIs are mocked;
// this test never accesses or changes the user's ledger.
const { chromium, webkit } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
    for (const [name, engine] of Object.entries({ chromium, webkit })) {
        const browser = await engine.launch({ headless: true });
        try {
            for (const paper of [false, true]) {
                const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
                await page.route('https://code.highcharts.com/**', route => route.abort());
                await page.route('**/php_backend/public/**', route => {
                    const path = new URL(route.request().url()).pathname;
                    let json = {};
                    if (path.endsWith('/current_user.php')) json = { username: 'Preview' };
                    if (path.endsWith('/search_transactions.php')) json = { results: Array.from({ length: 100 }, (_, i) => ({
                        id: i + 1, date: '2026-10-01', amount: -25, description: `Example purchase ${i + 1}`,
                        category_name: 'Shopping', tag_name: 'Shop', segment_name: 'Living'
                    })) };
                    return route.fulfill({ json });
                });
                await page.goto((process.env.ACCOUNTS_TEST_URL || 'http://localhost:8000') + '/frontend/search.html', { waitUntil: 'domcontentloaded' });
                await page.waitForFunction(() => document.getElementById('search-status').textContent === '100 results found');
                await page.waitForFunction(() => document.querySelector('.app-shell-main') && document.getElementById('theme-professional-css')?.sheet);
                await page.evaluate(value => document.body.classList.toggle('theme-professional', value), paper);
                const main = page.locator('main');
                assert.ok(await main.evaluate(el => el.firstElementChild.getBoundingClientRect().top <= 13), 'Compact top gutter in both themes');
                // Allow the table's first layout and the shell's font pass to settle.
                await page.waitForTimeout(500);
                await page.mouse.move(1150, 700);
                await page.mouse.wheel(0, 8000);
                await page.waitForTimeout(700);
                const bottom = await main.evaluate(el => el.scrollTop);
                assert.ok(bottom > 700, 'Wheel scrolling reaches the lower workspace');
                await page.mouse.wheel(0, -400);
                await page.waitForTimeout(500);
                assert.ok(await main.evaluate(el => el.scrollTop) < bottom - 100, 'First upward wheel gesture leaves the bottom');
                await page.mouse.wheel(0, -400);
                await page.waitForTimeout(500);
                assert.ok(await main.evaluate(el => el.scrollTop) < bottom - 500, 'Further upward scrolling stays responsive');
                await page.close();
                console.log(`PASS: ${name}, ${paper ? 'Paper' : 'Glass'} reverses wheel scrolling from the bottom`);
            }
        } finally { await browser.close(); }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

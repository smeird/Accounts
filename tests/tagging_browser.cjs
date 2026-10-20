// Real tagging UI with isolated API responses; no live data or paid AI calls.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
    const root = path.resolve(__dirname, '..');
    const server = http.createServer((req, res) => {
        const file = path.resolve(root, '.' + new URL(req.url, 'http://localhost').pathname);
        if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
        const mime = { '.js': 'application/javascript', '.css': 'text/css', '.html': 'text/html', '.woff2': 'font/woff2' };
        res.setHeader('Content-Type', mime[path.extname(file)] || 'application/octet-stream');
        fs.createReadStream(file).pipe(res);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const errors = []; page.on('pageerror', error => { errors.push(error.message); console.error(error.message); });
        const tags = [
            { id: 1, name: 'Groceries', description: 'Food at home. Excludes restaurants.', origin: 'manual', transaction_count: 10, rule_count: 2 },
            { id: 2, name: 'Groceries shopping', description: 'Supermarket purchases.', origin: 'manual', transaction_count: 1, rule_count: 0 },
            { id: 3, name: 'Old merchant', description: 'Legacy tag', origin: 'legacy', transaction_count: 1, rule_count: 0 }
        ];
        let reviews = [
            { token: 'classification-token', kind: 'classification', tag_id: 1, tag_name: 'Groceries', description: 'TESCO REF 123', memo: 'Food', direction: 'outgoing', transactions: 2, reason: 'Needs review', confidence: 0.8 },
            { token: 'rule-token', kind: 'rule', tag_id: 1, tag_name: 'Groceries', description: 'TESCO REF 456', direction: 'outgoing', transactions: 1, alias: 'tesco', reason: 'Classification reviewed', confidence: null }
        ];
        const actions = []; let previewVersion = 0; let releaseRun; let runCalls = 0;
        await page.route('**/frontend/menu.php', route => route.fulfill({ contentType: 'text/html', body: '' }));
        await page.route('**/php_backend/public/**', async route => {
            const req = route.request(); const url = new URL(req.url());
            let json = {}; let status = 200;
            if (url.pathname.endsWith('current_user.php')) json = { username: 'Preview', user_id: 1 };
            if (url.pathname.endsWith('brand_settings.php')) json = { theme: 'glass', font: 'default' };
            if (url.pathname.endsWith('ai_debug.php')) json = { debug: true };
            if (url.pathname.endsWith('tags.php')) json = { tags, has_more: false };
            if (url.pathname.endsWith('tagging_workspace.php')) {
                if (url.searchParams.has('similar')) json = { tags: [tags[0]] };
                else if (req.method() === 'GET') json = { metrics: { active_tags: 3, coverage: 80 }, tags, inbox: [], categories: [], automation: { configured: true }, fresh_start: {} };
                else {
                    const body = req.postDataJSON(); actions.push(body);
                    if (body.action === 'approve_for_ai') tags.find(tag => tag.id === body.id).origin = 'manual';
                    json = { status: 'ok', result: {} };
                }
            }
            if (url.pathname.endsWith('ai_tags.php')) {
                if (req.method() === 'GET') json = { reviews };
                else {
                    const body = req.postDataJSON(); actions.push(body);
                    if (body.action === 'approve_classification' || body.action === 'approve_rule' || body.action === 'dismiss') {
                        if (body.action === 'approve_rule') { assert.equal(body.preview_token, `preview-${previewVersion}`); assert.equal(body.confirm_overlap, true); }
                        reviews = reviews.filter(review => review.token !== body.token);
                        json = { result: { processed: 2 }, reviews };
                    }
                    if (body.action === 'preview_rule') {
                        previewVersion++;
                        json = { result: { preview_token: `preview-${previewVersion}`, matching: 4, untagged: 2, apply_now: 2, other_tag_count: 1, existing_rule: null, overlaps: [{ alias: 'tesco fuel', tag_name: 'Fuel' }], samples: [{ description: 'TESCO MARKET', date: '2026-10-20', amount: -10, already_tagged: false }] }, reviews };
                    }
                    if (body.action === 'run') {
                        runCalls++;
                        if (runCalls === 1) await new Promise(resolve => { releaseRun = resolve; });
                        json = { processed: 2, reviewed: 3, tokens: 100, run_token: 'run-token', has_more: runCalls === 1, reviews, debug: { prompt: 'Approved catalogue', response: '{"transactions":[]}' } };
                    }
                }
            }
            return route.fulfill({ status, json });
        });
        const base = `http://127.0.0.1:${server.address().port}`;
        await page.goto(base + '/frontend/tagging.html#automation', { waitUntil: 'domcontentloaded' });
        await page.locator('#ai-review-list button').filter({ hasText: 'Review tag' }).click();
        await page.locator('#ai-review-submit').click();
        await page.waitForFunction(() => !document.getElementById('ai-review-dialog').open);
        assert.equal(actions.filter(action => action.action === 'approve_classification').length, 1);
        await page.locator('#ai-review-list button').filter({ hasText: 'Preview rule' }).click();
        assert.equal(await page.locator('#ai-rule-submit').isDisabled(), true);
        await page.locator('#ai-rule-preview').click();
        await page.waitForFunction(() => !document.getElementById('ai-rule-submit').disabled);
        assert.match(await page.locator('#ai-rule-evidence').innerText(), /different tag/);
        await page.locator('#ai-rule-alias').fill('tesco market');
        assert.equal(await page.locator('#ai-rule-submit').isDisabled(), true, 'Editing a phrase invalidates its preview');
        await page.locator('#ai-rule-preview').click();
        await page.waitForFunction(() => !document.getElementById('ai-rule-submit').disabled);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.locator('#ai-rule-dialog').evaluate(dialog => dialog.getBoundingClientRect().right <= innerWidth), true, 'Phone rule dialog stays inside the viewport');
        assert.equal(await page.locator('#ai-rule-alias').evaluate(input => parseFloat(getComputedStyle(input).fontSize) >= 16), true, 'Phone rule input avoids automatic input zoom');
        if (process.env.ACCOUNTS_TAGGING_SCREENSHOTS) await page.screenshot({ path: path.join(process.env.ACCOUNTS_TAGGING_SCREENSHOTS, 'tagging-rule-phone.png') });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.locator('#ai-rule-overlap').check();
        await page.locator('#ai-rule-submit').click();
        await page.waitForFunction(() => !document.getElementById('ai-rule-dialog').open);
        assert.equal(actions.filter(action => action.action === 'approve_rule').length, 1);
        await page.locator('#run-ai-tagging').click();
        await page.waitForFunction(() => !document.getElementById('stop-ai-tagging').hidden);
        for (let attempt = 0; !releaseRun && attempt < 100; attempt++) await new Promise(resolve => setTimeout(resolve, 10));
        assert.ok(releaseRun, 'Run request was dispatched');
        await page.locator('#stop-ai-tagging').click(); releaseRun();
        await page.waitForFunction(() => document.getElementById('run-ai-tagging').textContent === 'Continue smart tagging');
        assert.equal(runCalls, 1, 'Stop prevents dispatching the next batch');
        await page.locator('#run-ai-tagging').click();
        await page.waitForFunction(() => document.getElementById('run-ai-tagging').textContent === 'Run smart tagging' && !document.getElementById('run-ai-tagging').disabled);
        assert.equal(actions.filter(action => action.action === 'run')[1].run_token, 'run-token');
        assert.equal(await page.locator('#tagging-ai-debug').isVisible(), true);
        await page.locator('[data-tagging-tab="catalogue"]').click();
        await page.locator('#catalogue-consolidation summary').click();
        assert.match(await page.locator('#catalogue-suggestions').innerText(), /Groceries shopping/);
        await page.locator('#new-tag').click();
        await page.locator('#tag-edit-name').fill('Groceries household');
        await page.locator('#tag-similar button').filter({ hasText: 'Use Groceries' }).click();
        assert.equal(await page.locator('#tag-dialog').evaluate(dialog => dialog.open), false);
        assert.equal(actions.some(action => action.action === 'create_tag'), false, 'Reusing a suggested tag creates nothing');
        await page.locator('#new-tag').click();
        await page.locator('#tag-dialog button[value="cancel"]').click();
        assert.equal(await page.locator('#tag-dialog').evaluate(dialog => dialog.open), false, 'Cancel works with empty required fields');
        await page.locator('#catalogue-search').fill('');
        page.once('dialog', dialog => dialog.accept());
        await page.getByRole('button', { name: 'Approve Old merchant as a reusable AI tag' }).click();
        await page.waitForFunction(() => document.getElementById('catalogue-body').textContent.includes('AI selectable'));
        await page.locator('[data-tagging-tab="automation"]').click();
        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForTimeout(150);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Phone workspace has no horizontal overflow');
        await page.evaluate(() => document.body.classList.add('professional-view', 'theme-professional'));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Paper phone workspace stays within the viewport');
        if (process.env.ACCOUNTS_TAGGING_SCREENSHOTS) await page.screenshot({ path: path.join(process.env.ACCOUNTS_TAGGING_SCREENSHOTS, 'tagging-paper-phone.png') });
        assert.deepEqual(errors, []);
        console.log('Tagging browser flow passed: classification review, rule previews, stop/resume, reuse suggestions, approval and phone/Paper layouts.');
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });

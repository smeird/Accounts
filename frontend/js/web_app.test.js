// Exercise the service worker policy with real Request/Response objects.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
async function run() {
    const handlers = {}, stored = new Map(), fetches = [], deleted = [];
    let offline = false;
    const context = {
        URL, Request, Response,
        self: { registration: { scope: 'https://accounts.example/install/' }, clients: { claim: async () => {} }, skipWaiting: () => {}, addEventListener: (name, handler) => { handlers[name] = handler; } },
        caches: {
            open: async () => ({ add: async request => stored.set(request.url, new Response('Public offline screen')) }),
            keys: async () => ['unrelated-cache', 'accounts-public-offline-v0', 'accounts-public-offline-v2'],
            delete: async name => deleted.push(name),
            match: async url => stored.get(url)?.clone()
        },
        fetch: async request => { fetches.push(request); if (offline) throw new TypeError('Offline'); return new Response('Live page', { headers: { 'Cache-Control': 'no-store' } }); }
    };
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../../sw.js'), 'utf8'), context);
    let done;
    handlers.install({ waitUntil: promise => { done = promise; } }); await done;
    assert.deepEqual([...stored.keys()], ['https://accounts.example/install/frontend/offline.html']);
    handlers.activate({ waitUntil: promise => { done = promise; } }); await done;
    assert.deepEqual(deleted, ['accounts-public-offline-v0']);
    async function request(path, method = 'GET', mode = 'navigate') {
        let response;
        handlers.fetch({ request: { url: `https://accounts.example${path}`, method, mode }, respondWith: promise => { response = promise; } });
        return response ? await response : null;
    }
    // Request cannot be constructed from our fake navigation, so use a real
    // request and expose navigate through a getter for the fetch event.
    async function navigate(path) {
        const req = new Request(`https://accounts.example${path}`);
        Object.defineProperty(req, 'mode', { value: 'navigate' });
        let result;
        handlers.fetch({ request: req, respondWith: promise => { result = promise; } });
        return result ? await result : null;
    }
    const live = await navigate('/install/frontend/search.html?group_id=3');
    assert.equal(await live.text(), 'Live page');
    assert.equal(fetches.at(-1).cache, 'no-store');
    offline = true;
    assert.match(await (await navigate('/install/frontend/transaction.html?id=17')).text(), /Public offline/);
    for (const path of ['/install/php_backend/public/transactions.php', '/install/php_backend/public/export.php', '/install/logout.php', '/other/frontend/index.html', '/install/favicon.png']) {
        assert.equal(await navigate(path), null, `${path} must bypass the worker`);
    }
    assert.equal(await request('/install/frontend/transaction.html', 'POST'), null);
    assert.equal(await request('/install/frontend/search.html', 'GET', 'cors'), null);
    assert.equal(stored.size, 1, 'Never store financial pages or API responses');
    console.log('PASS: Service worker subdirectory scope, no-store navigation, offline fallback and financial-data exclusions');
}
run().catch(error => { console.error(error); process.exitCode = 1; });

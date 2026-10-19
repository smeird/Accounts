/* Online-first financial app. Only this public offline screen is stored.
 * Never put page shells, API responses, exports or financial data in Cache API.
 * Update this version when changing offline.html. */
'use strict';
const CACHE = 'accounts-public-offline-v2';
const OFFLINE = new URL('frontend/offline.html', self.registration.scope).href;
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.add(new Request(OFFLINE, { cache: 'reload' }))));
});
self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter(name => name.startsWith('accounts-public-offline-') && name !== CACHE).map(name => caches.delete(name)));
    await self.clients.claim();
  })());
});
self.addEventListener('message', event => {
  if (event.data?.type === 'ACTIVATE_UPDATE') self.skipWaiting();
});
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  const frontend = new URL('frontend/', self.registration.scope);
  // Exclude API calls, downloads and non-GETs, including navigations to exports.
  const publicAppPage = url.origin === frontend.origin && (
    (url.pathname.startsWith(frontend.pathname) && /\.html$/.test(url.pathname)) ||
    ['index.php', 'settings.php', 'users.php'].some(page => url.pathname === new URL(page, self.registration.scope).pathname)
  );
  if (request.method !== 'GET' || request.mode !== 'navigate' || !publicAppPage) return;
  event.respondWith(fetch(new Request(request, { cache: 'no-store' })).catch(async () => {
    const offline = await caches.match(OFFLINE);
    return offline || new Response('You are offline. Reconnect and try again.', { status: 503, headers: { 'Content-Type': 'text/plain' } });
  }));
});

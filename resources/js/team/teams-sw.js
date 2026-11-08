// Cache only this public explanation. Never cache authenticated pages or API responses.
const CACHE = 'teams-public-offline-v2';
const OFFLINE = '/teams/offline';
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.add(OFFLINE))));
self.addEventListener('activate', event => event.waitUntil(Promise.all([
  caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('teams-public-offline-') && key !== CACHE).map(key => caches.delete(key)))),
  self.clients.claim(),
])));
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.mode === 'navigate' && url.origin === self.location.origin && url.pathname.startsWith('/teams/')) {
    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE)));
  }
});

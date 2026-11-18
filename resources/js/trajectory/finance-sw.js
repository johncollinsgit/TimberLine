// Online-only finance app: never cache authenticated pages, balances or transactions.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('push', event => {
  let payload;
  try { payload = event.data?.json(); } catch { return; }
  if (!payload || typeof payload.title !== 'string' || typeof payload.body !== 'string') return;
  const target = new URL(payload.url || './', self.location.origin);
  if (target.origin !== self.location.origin || !target.pathname.startsWith('/trajectory/personal/')) return;
  event.waitUntil(self.registration.showNotification(payload.title, {
    body: payload.body, tag: String(payload.tag || 'trajectory-expense'),
    data: {url: target.href}, icon: '/trajectory-brand/personal-192.png',
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || './', self.location.origin);
  if (target.origin !== self.location.origin || !target.pathname.startsWith('/trajectory/personal/')) return;
  event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(async windows => {
    const open = windows.find(window => new URL(window.url).pathname.startsWith('/trajectory/personal/'));
    if (open) { await open.navigate(target.href); return open.focus(); }
    return self.clients.openWindow(target.href);
  }));
});

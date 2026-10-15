const VERSION='everbranch-retail-v2';
const CORE=['/retail/offline.html','/retail/app.mjs','/retail/commerce.mjs','/retail/app.css','/retail/manifest.webmanifest','/retail/assets/icon.svg'];
self.addEventListener('install',e=>e.waitUntil(caches.open(VERSION).then(c=>c.addAll(CORE)).then(()=>self.skipWaiting())));
self.addEventListener('activate',e=>e.waitUntil(Promise.all([caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('everbranch-retail-')&&k!==VERSION).map(k=>caches.delete(k)))),self.clients.claim()])));
self.addEventListener('message',e=>{if(e.data?.type==='PRELOAD')e.waitUntil(caches.open(VERSION).then(c=>c.addAll(e.data.assets.filter(p=>/^\/retail\/assets\/[a-z0-9.-]+$/.test(p)))));});
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);if(u.origin!==location.origin||e.request.method!=='GET'||u.pathname.includes('/api/')||u.pathname.includes('/preview/'))return;
 if(e.request.mode==='navigate'&&(u.pathname==='/retail/showroom'||u.pathname==='/retail/showroom/pricing'||u.pathname.startsWith('/retail/showroom/concepts/'))) {e.respondWith(fetch(e.request).catch(()=>caches.match('/retail/offline.html')));return;}
 if(CORE.includes(u.pathname)||u.pathname.startsWith('/retail/assets/')) e.respondWith(fetch(e.request).then(r=>{if(r.ok)e.waitUntil(caches.open(VERSION).then(c=>c.put(e.request,r.clone())));return r;}).catch(()=>caches.match(e.request)));
});

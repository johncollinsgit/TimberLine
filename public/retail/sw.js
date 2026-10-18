const VERSION='everbranch-retail-v17';
const CORE=['/retail/offline.html','/retail/app.mjs','/retail/connection.mjs','/retail/commerce.mjs','/retail/sales.mjs','/retail/voice.mjs','/retail/workbench.mjs','/retail/avery.mjs','/retail/avery-data.mjs','/retail/avery-workspace-data.mjs','/retail/standard-offer.mjs','/retail/pricing-examples.mjs','/retail/app.css','/retail/manifest.webmanifest','/retail/photos.json','/retail/assets/avery-logo.png','/retail/assets/icon.svg','/retail/assets/icon.svg?v=4','/retail/assets/icon-192.png?v=4','/retail/assets/icon-512.png?v=4','/retail/assets/icon-maskable-192.png?v=4','/retail/assets/icon-maskable-512.png?v=4'];
// An update may retire the previous cache only after its replacement contains
// every public demo photo, including images not visited on this device yet.
self.addEventListener('install',e=>e.waitUntil((async()=>{
 const response=await fetch(new Request('/retail/photos.json',{cache:'reload',credentials:'omit'}));
 if(!response.ok)throw Error('The demo photo list could not be downloaded.');
 const photos=await response.json();
 if(!Array.isArray(photos)||!photos.length||photos.some(path=>typeof path!=='string'||!/^\/retail\/assets\/[a-z0-9.-]+\.webp$/.test(path)))throw Error('Invalid public demo photo list.');
 const cache=await caches.open(VERSION);
 const previous=(await caches.keys()).filter(name=>name.startsWith('everbranch-retail-')&&name!==VERSION);
 const missing=[];
 for(const path of photos){
  let saved=await cache.match(path);
  if(!saved)for(const name of previous){const old=await(await caches.open(name)).match(path);if(old?.ok&&old.headers.get('Content-Type')?.startsWith('image/')){await cache.put(path,old);saved=old;break;}}
  if(!saved)missing.push(path);
 }
 await cache.addAll([...CORE,...missing].map(url=>new Request(url,{cache:'reload',credentials:'omit'})));
 await self.skipWaiting();
})()));
self.addEventListener('activate',e=>e.waitUntil(Promise.all([caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('everbranch-retail-')&&k!==VERSION).map(k=>caches.delete(k)))),self.clients.claim()])));
self.addEventListener('message',e=>{if(e.data?.type==='PRELOAD')e.waitUntil(caches.open(VERSION).then(c=>c.addAll(e.data.assets.filter(p=>/^\/retail\/assets\/[a-z0-9.-]+$/.test(p)))));});
async function connectedResponse(request){const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),4000);try{return await fetch(new Request(request,{signal:controller.signal}));}finally{clearTimeout(timer);}}
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);if(u.origin!==location.origin||e.request.method!=='GET'||u.pathname.includes('/api/')||u.pathname.includes('/preview/'))return;
 if(e.request.mode==='navigate'&&(u.pathname==='/retail/showroom'||u.pathname==='/retail/showroom/pricing'||u.pathname==='/retail/showroom/assistant'||u.pathname.startsWith('/retail/showroom/concepts/'))) {e.respondWith(connectedResponse(e.request).catch(()=>caches.match('/retail/offline.html')));return;}
 // Public shell/photos render immediately on weak Wi-Fi. Explicit preparation
 // still reloads them; authentication/API responses never enter this cache.
 if(u.pathname.startsWith('/retail/assets/')||CORE.includes(u.pathname)) e.respondWith(caches.open(VERSION).then(async cache=>{const saved=await cache.match(e.request);if(saved&&!['reload','no-store'].includes(e.request.cache))return saved;try{const response=await connectedResponse(e.request);if(response.ok)await cache.put(e.request,response.clone());return response;}catch(error){if(saved)return saved;throw error;}}));
});

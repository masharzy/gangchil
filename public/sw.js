/* No API, order, authentication response or staff page is cached. */
const SHELL_CACHE='gangchil-pwa-shell-v1';
const DEVICE_CACHE='gangchil-pwa-device';
const DEVICE_KEY='/__gangchil_push_state__';
const OFFLINE='/offline.html';
self.addEventListener('install',event=>event.waitUntil((async()=>{
 const cache=await caches.open(SHELL_CACHE);
 for(const url of [OFFLINE,'/pwa/icon-192.png','/pwa/icon-512.png']){
  const response=await fetch(new Request(url,{cache:'reload',credentials:'same-origin'}));
  if(!response.ok)throw Error('Offline assets unavailable');
  if(url===OFFLINE){if(!(await response.clone().text()).includes('data-gangchil-offline'))throw Error('Host returned a verification page instead of the offline page');}
  else if(!(response.headers.get('content-type')||'').includes('image/png'))throw Error('Host returned a verification page instead of an icon');
  await cache.put(url,response);
 }
 await self.skipWaiting();
})()));
self.addEventListener('activate',event=>event.waitUntil((async()=>{
 for(const name of await caches.keys())if(name.startsWith('gangchil-pwa-shell-')&&name!==SHELL_CACHE)await caches.delete(name);
 await self.clients.claim();
})()));
self.addEventListener('fetch',event=>{
 const url=new URL(event.request.url);if(url.origin!==self.location.origin)return;
 if(event.request.mode==='navigate'&&!/^\/(?:staff\/)?api(?:\/|$)/.test(url.pathname)&&!/^\/(?:staff\/)?(?:receipt|install)\.php$/.test(url.pathname)){
  event.respondWith(fetch(event.request).catch(async()=>{const cache=await caches.open(SHELL_CACHE);return await cache.match(OFFLINE)||new Response('Offline. Reconnect and retry.',{status:503,headers:{'Content-Type':'text/plain'}})}));
 }else if(['/pwa/icon-192.png','/pwa/icon-512.png'].includes(url.pathname))event.respondWith(caches.open(SHELL_CACHE).then(async cache=>await cache.match(url.pathname)||fetch(event.request)));
});
self.addEventListener('message',event=>{
 if(event.data?.type!=='GANGCHIL_PUSH_STATE')return;
 try{const source=new URL(event.source.url);if(source.origin!==self.location.origin||!source.pathname.startsWith('/staff'))return;}catch{return}
 event.waitUntil((async()=>{const cache=await caches.open(DEVICE_CACHE);const enabled=event.data.enabled===true&&Number.isSafeInteger(event.data.user_id)&&event.data.user_id>0;await cache.put(DEVICE_KEY,new Response(JSON.stringify({enabled,user_id:enabled?event.data.user_id:0}),{headers:{'Content-Type':'application/json'}}));if(!enabled)for(const notification of await self.registration.getNotifications())notification.close();event.ports?.[0]?.postMessage({ok:true});})());
});
self.addEventListener('push',event=>event.waitUntil((async()=>{
 const cache=await caches.open(DEVICE_CACHE),stored=await cache.match(DEVICE_KEY);let device;try{device=stored?await stored.json():null}catch{device=null}
 if(!device?.enabled)return;
 await self.registration.showNotification('GANGCHIL · New order',{
  body:'A new order is awaiting cash confirmation. Tap to open Orders.',
  icon:'/pwa/icon-192.png',badge:'/pwa/icon-192.png',tag:'gangchil-orders',renotify:true,
  data:{url:'/staff/#pending-payments'},timestamp:Date.now()
 });
 for(const client of await self.clients.matchAll({type:'window',includeUncontrolled:true}))if(new URL(client.url).pathname.startsWith('/staff/'))client.postMessage({type:'GANGCHIL_PUSH_ORDER'});
})()));
self.addEventListener('notificationclick',event=>{
 event.notification.close();event.waitUntil((async()=>{const target=new URL('/staff/#pending-payments',self.location.origin).href;for(const client of await self.clients.matchAll({type:'window',includeUncontrolled:true})){const url=new URL(client.url);if(url.origin===self.location.origin&&url.pathname.startsWith('/staff')){await client.navigate(target);return client.focus()}}return self.clients.openWindow(target)})());
});

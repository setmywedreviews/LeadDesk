const CACHE='leaddesk-v2';

self.addEventListener('install',e=>{
  self.skipWaiting();
});

self.addEventListener('activate',e=>{
  e.waitUntil(self.clients.claim());
});

self.addEventListener('fetch',e=>{
  // Let normal page navigation go directly to Railway.
  // This prevents intermittent ERR_FAILED on links such as ?view=admin.
  if(e.request.method!=='GET' || e.request.mode==='navigate') return;

  e.respondWith(
    fetch(e.request).catch(()=>caches.match(e.request))
  );
});

self.addEventListener('notificationclick',e=>{
  e.notification.close();
  e.waitUntil(
    clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{
      for(const c of cs){
        if('focus' in c) return c.focus();
      }
      if(clients.openWindow) return clients.openWindow('/');
    })
  );
});
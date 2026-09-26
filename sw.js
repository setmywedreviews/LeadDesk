const CACHE='leaddesk-v3';
self.addEventListener('install',e=>{self.skipWaiting();});
self.addEventListener('activate',e=>{e.waitUntil(self.clients.claim());});
self.addEventListener('fetch',e=>{
 if(e.request.method!=='GET'||e.request.mode==='navigate')return;
 e.respondWith(fetch(e.request).catch(()=>caches.match(e.request)));
});
self.addEventListener('push',e=>{
 let data={title:'SetMyWed LeadDesk',body:'You have a new LeadDesk notification.',url:'/'};
 try{data=Object.assign(data,e.data?e.data.json():{});}catch(_){}
 e.waitUntil(self.registration.showNotification(data.title,{body:data.body,icon:data.icon||'/icon.svg',badge:data.icon||'/icon.svg',data:{url:data.url||'/'},tag:'smw-push'}));
});
self.addEventListener('notificationclick',e=>{
 e.notification.close();
 const url=e.notification.data&&e.notification.data.url?e.notification.data.url:'/';
 e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{
  for(const c of cs){if('focus' in c){c.navigate(url);return c.focus();}}
  if(clients.openWindow)return clients.openWindow(url);
 }));
});

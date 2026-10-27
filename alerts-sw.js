/* Notifications are triggered by the open page. No background alarm scheduling. */
self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('notificationclick',event=>{
 event.notification.close();
 event.waitUntil(self.clients.matchAll({type:'window'}).then(windows=>{
  const page=windows.find(client=>client.url.startsWith(self.registration.scope));
  return page?page.focus():self.clients.openWindow(new URL('./?planner=day',self.registration.scope).href);
 }));
});

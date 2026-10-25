// Service worker for browser notifications (backlog item 25). It only shows pushes and opens their link;
// it caches nothing, so the site behaves exactly as without it.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'فهارس', body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(data.title || 'فهارس', {
        body: data.body || '',
        icon: data.icon || '/images/brand/shamseh-192.png',
        badge: '/images/brand/shamseh-64.png',
        tag: data.tag,
        dir: 'rtl',
        lang: 'fa',
        data: data.data || {},
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        for (const win of windows) {
            if (win.url === url && 'focus' in win) {
                return win.focus();
            }
        }
        return clients.openWindow(url);
    }));
});

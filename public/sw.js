self.addEventListener('push', function (event) {
    if (! event.data) return;

    let data = {};
    try {
        data = event.data.json();
    } catch (e) {
        data = { title: 'Notification', body: event.data.text() };
    }

    const options = {
        body: data.body || '',
        icon: '/favicon.ico',
        badge: '/favicon.ico',
        data: { url: data.url || '/' },
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'Notification', options)
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (const client of clientList) {
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});

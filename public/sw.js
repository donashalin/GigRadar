const CACHE = 'gigradar-v2';
const PRECACHE = ['/offline.html', '/icons/icon-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

// Only page navigations get an offline fallback; data and assets are never cached.
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }
    event.respondWith(fetch(event.request).catch(() => caches.match('/offline.html')));
});

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { body: event.data ? event.data.text() : '' };
    }
    const { title = 'GigRadar', body, icon, badge, tag, data } = payload;
    event.waitUntil(self.registration.showNotification(title, { body, icon, badge, tag, data, renotify: Boolean(tag) }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    let url = new URL(event.notification.data?.url ?? '/dashboard', self.location.origin);
    if (url.origin !== self.location.origin) {
        url = new URL('/dashboard', self.location.origin);
    }
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((client) => new URL(client.url).origin === url.origin);
            if (existing) {
                return existing
                    .focus()
                    .then((focused) => (focused || existing).navigate(url.href))
                    .catch(() => self.clients.openWindow(url.href));
            }
            return self.clients.openWindow(url.href);
        }),
    );
});

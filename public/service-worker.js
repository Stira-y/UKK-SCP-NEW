// Service Worker for SCP Sekolah SMKN 1 Bantul PWA
const CACHE_NAME = 'scp-smkn1bantul-v20261109';
const STATIC_ASSETS = [
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/logo-smkn1bantul.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    // Hanya tangani request GET http / https
    if (event.request.method !== 'GET') return;

    // Jangan cache endpoint API dinamis agar selalu live realtime
    if (event.request.url.includes('/api/')) return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response && response.status === 200 && response.type === 'basic') {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseClone));
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request);
            })
    );
});

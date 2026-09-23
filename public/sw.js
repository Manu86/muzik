const CACHE = 'muzik-v83';
const SHELL = [
  './',
  'manifest.json?v=3',
  'assets/css/app.css?v=62',
  'assets/js/app.js?v=77',
  'assets/js/core.js?v=77',
  'assets/js/favorites.js?v=77',
  'assets/js/views.js?v=77',
  'assets/js/player.js?v=77',
  'assets/js/account.js?v=77',
  'assets/icon-192.png?v=3',
  'assets/icon-512.png?v=3',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  // API et flux audio : jamais mis en cache
  if (url.pathname.includes('/api/') || url.pathname.includes('/data/')) {
    e.respondWith(fetch(e.request));
    return;
  }
  e.respondWith(
    fetch(e.request).then(res => {
      if (res && res.ok) {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy));
      }
      return res;
    }).catch(() => caches.match(e.request))
  );
});

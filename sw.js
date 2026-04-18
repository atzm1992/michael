/*
 * Service Worker für Jagdrevier Prad
 *
 * Zwei Aufgaben:
 *   1) App-Shell-Cache (index.html + logo + Leaflet), damit die Seite
 *      auch offline startet.
 *   2) Einträge, die offline erstellt werden, sollen nicht verloren
 *      gehen – sie werden via 'Background Sync' nachgereicht. Das
 *      eigentliche Queueing macht der Client in IndexedDB; wir helfen
 *      hier nur beim Offline-Erkennen und liefern eine passende
 *      Fehlerantwort, die der Client interpretieren kann.
 */

const CACHE_NAME = 'jagdrevier-prad-v27';
const APP_SHELL = [
  './',
  './index.html',
  './logo.jpg',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
];

// --------- Install: App-Shell in den Cache laden ---------
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return Promise.all(
        APP_SHELL.map(url =>
          cache.add(url).catch(err => console.warn('SW cache miss:', url, err))
        )
      );
    })
  );
  self.skipWaiting();
});

// --------- Activate: alte Caches wegräumen ---------
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// --------- Fetch: App-Shell stale-while-revalidate, API network-first ---------
self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // OSM-Tiles NICHT anfassen – der Browser macht das Tile-Caching
  // selber, und ein SW-Intercept kann zu opaquen Responses führen,
  // die dann auf Mobile nicht gerendert werden.
  if (url.hostname.endsWith('tile.openstreetmap.org')) {
    return; // Default: Browser macht normales Fetch
  }

  // API-Anfragen immer direkt ans Netz; der Client kümmert sich selbst
  // um Retry/Queue bei Offline.
  if (url.pathname.startsWith('/api/')) {
    return;
  }

  // Alles andere (App-Shell, Bilder, Leaflet-Assets): Cache-first mit
  // Fallback auf Netz, damit die App offline startet.
  if (req.method === 'GET') {
    event.respondWith(
      caches.match(req).then(cached => {
        if (cached) return cached;
        return fetch(req).then(res => {
          if (res && res.status === 200) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then(c => c.put(req, copy)).catch(() => {});
          }
          return res;
        }).catch(() => {
          if (req.mode === 'navigate') return caches.match('./index.html');
        });
      })
    );
  }
});

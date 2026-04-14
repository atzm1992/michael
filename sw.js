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

const CACHE_NAME = 'jagdrevier-prad-v2';
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
      // Cache.addAll schlägt fehl, wenn eine einzelne URL nicht erreichbar
      // ist – darum einzeln und Fehler ignorieren.
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

  // API-Anfragen immer direkt ans Netz; der Client kümmert sich selbst
  // um Retry/Queue bei Offline.
  if (url.pathname.startsWith('/api/')) {
    return; // Default: Browser macht normales Fetch
  }

  // OSM-Tiles: Network-first, mit Cache-Fallback
  if (url.hostname.endsWith('tile.openstreetmap.org')) {
    event.respondWith(
      fetch(req)
        .then(res => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(req, copy)).catch(() => {});
          return res;
        })
        .catch(() => caches.match(req))
    );
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
          // Offline-Fallback für Navigation: index.html liefern, damit
          // die App trotzdem geladen werden kann.
          if (req.mode === 'navigate') return caches.match('./index.html');
        });
      })
    );
  }
});

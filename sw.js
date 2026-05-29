// ════════════════════════════════════════════════════════════════
//  sw.js  —  Service Worker for FinOps Digital Solutions PWA
//  Strategy:
//    - Static assets (CSS, JS, icons) : Cache-first
//    - Pages (php)                    : Network-first with offline fallback
//    - API calls (api/)               : Network-only (always live data)
// ════════════════════════════════════════════════════════════════

const CACHE_NAME   = 'finops-v1';
const STATIC_CACHE = 'finops-static-v1';

// ── Files to pre-cache on install ────────────────────────────────
// ✏️  EDIT THIS LIST to match your project's actual CSS/JS files
const PRE_CACHE = [
  './login.php',
  './manifest.json',
  './icons/icon-192.png',
  './icons/icon-512.png',
  // Add your CSS files here:
  // './assets/css/style.css',
  // Add your JS files here:
  // './js/01-config.js',
  // './js/02-auth.js',
];

// ── Install: pre-cache static assets ─────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => cache.addAll(PRE_CACHE))
      .then(() => self.skipWaiting())
  );
});

// ── Activate: clean up old caches ─────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k => k !== CACHE_NAME && k !== STATIC_CACHE)
            .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: route requests ─────────────────────────────────────────
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Always go network for API calls — never serve stale data
  if (url.pathname.includes('api/') || url.pathname.includes('api.php')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Static assets: CSS, JS, images → cache-first
  const isStatic = /\.(css|js|png|jpg|svg|ico|woff2?)$/.test(url.pathname);
  if (isStatic) {
    event.respondWith(
      caches.match(event.request).then(cached =>
        cached || fetch(event.request).then(resp => {
          if (resp.ok) {
            const clone = resp.clone();
            caches.open(STATIC_CACHE).then(c => c.put(event.request, clone));
          }
          return resp;
        })
      )
    );
    return;
  }

  // PHP pages: network-first, fall back to cache
  event.respondWith(
    fetch(event.request)
      .then(resp => {
        if (resp.ok) {
          const clone = resp.clone();
          caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
        }
        return resp;
      })
      .catch(() => caches.match(event.request).then(cached =>
        cached || caches.match('./login.php')
      ))
  );
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Service worker de la aplicación instalable (1.7.0).
// Guarda SOLO la cáscara (la página, app.js, los íconos y la interfaz de los módulos): nunca respuestas de la API ni del Servidor PIM,
// así en el dispositivo no quedan datos de la institución. Siempre intenta primero la red; la copia se usa si el nodo no responde.
const CACHE = 'cascara-v1';
const ES_CASCARA = (u) => u.pathname.startsWith('/app/') || /^\/api\/modulos\/[a-z0-9_]+\/ui\.js$/.test(u.pathname);

self.addEventListener('install', (e) => { self.skipWaiting(); e.waitUntil(caches.open(CACHE).then((c) => c.addAll(['./', 'logo.png', 'icono-512.png'])).catch(() => {})); });
self.addEventListener('activate', (e) => e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())));
self.addEventListener('fetch', (e) => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== self.location.origin || !ES_CASCARA(u) || u.pathname.endsWith('/manifest.php')) return;
  e.respondWith(fetch(e.request).then((r) => { if (r.ok) { const copia = r.clone(); caches.open(CACHE).then((c) => c.put(e.request, copia)); } return r; })
    .catch(() => caches.match(e.request).then((r) => r || caches.match(e.request, { ignoreSearch: true })).then((r) => r || (e.request.mode === 'navigate' ? caches.match('./') : Response.error()))));
});

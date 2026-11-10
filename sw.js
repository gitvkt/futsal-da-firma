// Futsal da Firma — Service Worker (app instalável)
// Estratégia: a API (dados) é SEMPRE buscada da rede; a página e os ícones
// usam rede primeiro com cópia em cache, para abrir mesmo com sinal fraco.
const CACHE = 'futsal-v6.1.1';
const BASICOS = ['./', './index.html', './favicon.png', './icon-192.png', './icon-512.png', './manifest.webmanifest'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(BASICOS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  const url = new URL(req.url);
  // Só trata requisições GET do próprio site
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  // Dados e fotos: sempre da rede (nunca mostrar dados velhos)
  if (url.pathname.endsWith('/api.php') || url.pathname.includes('/fotos/') || url.pathname.includes('/install/') || url.pathname.includes('/app/')) return;

  e.respondWith(
    fetch(req).then(res => {
      if (res && res.ok) { const copia = res.clone(); caches.open(CACHE).then(c => c.put(req, copia)); }
      return res;
    }).catch(() => caches.match(req).then(r => r || caches.match('./index.html')))
  );
});

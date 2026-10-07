// Service Worker — offline fallback + background sync untuk Portal Warga
const CACHE = 'arya-green-v3';
const BASE  = '/arya-green-ipl';
const DB_NAME = 'arya-green-outbox';
const STORE = 'requests';

const OFFLINE_ASSETS = [
  BASE + '/offline.html',
  BASE + '/pages/portal.php',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
];

function openOutbox() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      if (!req.result.objectStoreNames.contains(STORE)) {
        req.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function outboxAdd(item) {
  const db = await openOutbox();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).add(item);
    tx.oncomplete = resolve;
    tx.onerror = () => reject(tx.error);
  });
}

async function outboxAll() {
  const db = await openOutbox();
  return new Promise((resolve, reject) => {
    const req = db.transaction(STORE).objectStore(STORE).getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function outboxDelete(id) {
  const db = await openOutbox();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).delete(id);
    tx.oncomplete = resolve;
    tx.onerror = () => reject(tx.error);
  });
}

async function notifyClients(message) {
  const clients = await self.clients.matchAll({ includeUncontrolled: true });
  clients.forEach(c => c.postMessage(message));
}

async function flushOutbox() {
  const items = await outboxAll();
  let sent = 0;
  for (const item of items) {
    const form = new FormData();
    item.fields.forEach(field => {
      if (field.mime && field.value.startsWith('data:')) {
        // base64 data URL → blob
        const arr = field.value.split(',');
        const mime = arr[0].match(/:(.*?);/)[1];
        const bstr = atob(arr[1]);
        let n = bstr.length;
        const u8arr = new Uint8Array(n);
        while (n--) u8arr[n] = bstr.charCodeAt(n);
        form.append(field.name, new Blob([u8arr], { type: mime }), field.filename);
      } else {
        form.append(field.name, field.value);
      }
    });
    try {
      const res = await fetch(item.url, {
        method: 'POST',
        credentials: 'include',
        body: form,
      });
      if (res.ok) {
        await outboxDelete(item.id);
        sent++;
      }
    } catch {
      break; // masih offline — sisakan untuk retry berikutnya
    }
  }
  if (sent) await notifyClients({ type: 'outbox-synced', count: sent });
}

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(OFFLINE_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('message', e => {
  if (e.data?.type === 'queue-request') {
    e.waitUntil(
      outboxAdd(e.data.item)
        .then(async () => {
          if (self.registration.sync) await self.registration.sync.register('outbox-sync');
          e.ports[0]?.postMessage({ type: 'outbox-queued' });
        })
        .catch(() => e.ports[0]?.postMessage({ type: 'outbox-error' }))
    );
  }
  if (e.data?.type === 'flush-outbox') e.waitUntil(flushOutbox());
});

self.addEventListener('sync', e => {
  if (e.tag === 'outbox-sync') e.waitUntil(flushOutbox());
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  const isStatic = url.hostname !== location.hostname;

  if (isStatic) {
    e.respondWith(
      caches.match(e.request).then(r => r || fetch(e.request).then(res => {
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, clone));
        return res;
      }).catch(() => caches.match(e.request)))
    );
    return;
  }

  if (e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request).catch(() =>
        caches.match(e.request).then(r => r || caches.match(BASE + '/offline.html'))
      )
    );
    return;
  }

  e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
});

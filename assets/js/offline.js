// ── Offline Outbox (aduan / polling saat offline) ──────────────────────────
// POST form di halaman aduan & polling disimpan ke IndexedDB lewat service
// worker saat offline, lalu dikirim ulang otomatis ketika koneksi kembali
// (online event, background sync, atau saat halaman dibuka kembali).
const OUTBOX_FORM_PATTERN = /\/(complaints|polls)\//;

function outboxBanner(msg, type = 'success') {
  let banner = document.getElementById('outboxBanner');
  if (banner) banner.remove();
  banner = document.createElement('div');
  banner.id = 'outboxBanner';
  banner.className = `alert alert-${type} alert-dismissible fade show`;
  banner.style.cssText = 'position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:2000;max-width:90%;box-shadow:0 4px 12px rgba(0,0,0,.15);';
  banner.innerHTML = `<i class="bi bi-cloud-${type === 'success' ? 'check' : 'arrow-up'} me-1"></i>${msg}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
  document.body.appendChild(banner);
  setTimeout(() => banner && banner.remove(), 6000);
}

function queueToServiceWorker(form) {
  return new Promise((resolve, reject) => {
    if (!navigator.serviceWorker?.controller) return reject(new Error('no-sw'));
    const fields = [];
    const filePromises = [];
    for (const [name, value] of new FormData(form).entries()) {
      if (typeof value === 'string') {
        fields.push({ name, value });
      } else {
        // File input — baca sebagai base64 agar aman disimpan di IndexedDB
        filePromises.push(
          new Promise((res) => {
            const reader = new FileReader();
            reader.onload = () => {
              fields.push({ name, value: reader.result, filename: value.name, mime: value.type });
              res();
            };
            reader.readAsDataURL(value);
          })
        );
      }
    }
    Promise.all(filePromises).then(() => {
      const channel = new MessageChannel();
      channel.port1.onmessage = e => (e.data.type === 'outbox-queued' ? resolve() : reject(new Error(e.data.type)));
      navigator.serviceWorker.controller.postMessage(
        { type: 'queue-request', item: { url: form.action, fields, queued_at: Date.now() } },
        [channel.port2]
      );
    });
  });
}

document.addEventListener('submit', e => {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || form.method.toUpperCase() !== 'POST') return;
  if (navigator.onLine) return;
  if (!OUTBOX_FORM_PATTERN.test(form.action)) return;

  e.preventDefault();
  queueToServiceWorker(form)
    .then(() => {
      const btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-cloud-arrow-up me-1"></i>Tersimpan offline'; }
      outboxBanner('Anda sedang offline. Data disimpan dan dikirim otomatis saat koneksi kembali.', 'info');
    })
    .catch(() => {
      outboxBanner('Gagal menyimpan data offline. Silakan coba lagi saat online.', 'danger');
    });
});

// Kirim ulang saat online kembali atau menerima pesan BG sync dari SW
window.addEventListener('online', () => {
  navigator.serviceWorker?.controller?.postMessage({ type: 'flush-outbox' });
});
navigator.serviceWorker?.addEventListener('message', e => {
  if (e.data?.type === 'outbox-synced') {
    outboxBanner(`${e.data.count} data tersimpan offline berhasil dikirim.`);
  }
});
// Flush sisa outbox saat halaman pertama dibuka dan sedang online
if (navigator.onLine) {
  navigator.serviceWorker?.controller?.postMessage({ type: 'flush-outbox' });
}

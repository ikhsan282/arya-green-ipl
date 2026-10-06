// Arya Green Pamulang — App JS

// ── Sidebar toggle (mobile) ────────────────────────────────────────────────
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sidebarOverlay');
const toggler  = document.getElementById('sidebarToggler');

if (toggler && sidebar) {
  toggler.addEventListener('click', () => {
    sidebar.classList.toggle('show');
    overlay && overlay.classList.toggle('show');
  });
}
if (overlay) {
  overlay.addEventListener('click', () => {
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
  });
}

// ── Auto-dismiss alerts ────────────────────────────────────────────────────
document.querySelectorAll('.alert-dismissible').forEach(el => {
  setTimeout(() => {
    const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
    bsAlert && bsAlert.close();
  }, 5000);
});

// ── Confirm delete ─────────────────────────────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => {
    if (!confirm(el.dataset.confirm || 'Yakin ingin menghapus data ini?')) {
      e.preventDefault();
    }
  });
});

// ── Preview uploaded image ─────────────────────────────────────────────────
const proofInput = document.getElementById('proof_file');
const proofPreview = document.getElementById('proofPreview');
if (proofInput && proofPreview) {
  proofInput.addEventListener('change', () => {
    const file = proofInput.files[0];
    if (!file) return;
    if (file.type.startsWith('image/')) {
      proofPreview.src = URL.createObjectURL(file);
      proofPreview.classList.remove('d-none');
    }
  });
}

// ── Rupiah input formatter ─────────────────────────────────────────────────
document.querySelectorAll('[data-rupiah]').forEach(el => {
  el.addEventListener('input', () => {
    let v = el.value.replace(/\D/g, '');
    el.value = v ? parseInt(v, 10).toLocaleString('id-ID') : '';
  });
});

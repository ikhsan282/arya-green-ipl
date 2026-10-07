# Status Fitur Arya Green IPL

**Versi:** 1.0.0  
**Status:** Production Ready  
**Update:** 7 Oktober 2026

## ✅ FITUR LENGKAP & BERFUNGSI

### 1. Data Master
- ✅ **Unit Hunian** (`pages/units/`) — CRUD lengkap, detail history, filter blok/status, 97 unit ter-seed
- ✅ **Tipe Unit** (`pages/unit_types/`) — CRUD lengkap, 5 tipe resmi (Ruko, Navulia, Fresia, Magnolia, Cattleya)
- ✅ **Data Warga** (`pages/residents/`) — CRUD lengkap, link ke unit, status pemilik/penyewa
- ✅ **Metode Pembayaran** (`pages/payment_methods/`) — CRUD dinamis, auto-verify flag, account info, QR support

### 2. Keuangan IPL
- ✅ **Generate Tagihan** (`pages/billing/`) — Batch generate per periode, filter tahun/bulan/status, summary card
- ✅ **Catat Pembayaran** (`pages/payments/form.php`) — Upload bukti, metode dinamis, auto-verify tunai
- ✅ **Verifikasi Pembayaran** (`pages/payments/verify.php`) — Update status + auto-entry kas dalam transaction
- ✅ **Unverify** (`pages/payments/unverify.php`) — Rollback verifikasi (super_admin/ketua only) + cascade kas
- ✅ **Print Kwitansi** (`pages/payments/print_receipt.php`) — Format resmi + terbilang, auto-print param
- ✅ **Detail Pembayaran** (`pages/payments/detail.php`) — History lengkap per payment

### 3. Kas & Keuangan
- ✅ **Buku Kas** (`pages/cashbook/`) — List pemasukan/pengeluaran, filter periode, saldo running
- ✅ **Approval Pengeluaran** (`pages/expense/`) — Request-approve flow, badge pending count

### 4. Laporan
- ✅ **Laporan IPL** (`pages/reports/index.php`) — Summary pembayaran per periode, export CSV UTF-8 BOM
- ✅ **Rekap Tunggakan** (`pages/reports/arrears.php`) — Multi-periode per unit, badge severity, export CSV
- ✅ **Arus Kas** (`pages/reports/cashflow.php`) — Monthly surplus/defisit, cumulative balance, top-10 kategori, export CSV

### 5. Komunikasi
- ✅ **Reminder Email** (`pages/billing/send_reminders.php`) — Batch kirim reminder tagihan belum bayar + terlambat, HTML template
- ✅ **Aduan Warga** (`pages/complaints/`) — CRUD complaint + reply thread, status baru/diproses/selesai, badge count
- ✅ **Polling** (`pages/polls/`) — Create poll + vote, result chart

### 6. Lingkungan RT
- ✅ **Kegiatan** (`pages/events/`) — Event management + absensi warga
- ✅ **Inventaris** (`pages/inventory/`) — Asset tracking, kondisi baik/rusak, lokasi, foto
- ✅ **Surat RT** (`pages/letters/`) — Generate surat pengantar, nomor otomatis, print preview

### 7. Pengaturan
- ✅ **Pengguna** (`pages/users/`) — CRUD user, 4 role (super_admin, ketua, bendahara, warga)
- ✅ **Roles & Akses** (`pages/roles/`) — View permissions per role (27+ permissions granular)
- ✅ **Lingkungan** (`pages/environments/`) — Multi RT/RW setup (Arya Green Pamulang default)
- ✅ **Onboarding** (`pages/onboarding/`) — 3-step wizard: info RT → import CSV warga → saldo kas awal

### 8. Transparansi Publik
- ✅ **Kas Publik** (`pages/public/kas.php`) — Transparency page tanpa login, aggregate balance + latest transactions

### 9. PWA & Mobile
- ✅ **Portal PWA** (`pages/portal.php`) — Standalone app, install prompt Android/iOS, bottom nav
- ✅ **Service Worker** (`sw.js`) — Cache assets, offline fallback, outbox sync untuk form offline
- ✅ **Manifest** (`manifest.json`) — Icon 192/512, theme color, display standalone

### 10. Keamanan & Quality
- ✅ **CSRF Protection** — Token di semua form POST
- ✅ **Prepared Statements** — MySQLi prepared statements 100% (no raw SQL interpolation)
- ✅ **Rate Limiting** — Login brute force protection (5 attempt per account, 20 per IP, 15 min lockout)
- ✅ **XSS Protection** — `htmlspecialchars()` / `e()` di semua output
- ✅ **Upload Validation** — MIME check, size limit 2MB, .htaccess protection
- ✅ **Transaction Wrapping** — Verify/unverify payment gunakan DB transaction untuk atomicity
- ✅ **Email Dedup** — 1 email per type+ref per day via `email_logs` table
- ✅ **Dark Mode** — Toggle dengan localStorage + anti-FOUC, CSS variables Bootstrap 5.3

## 📊 STATISTIK CODEBASE

- **Total Files**: 47 PHP pages
- **Total Menu**: 26 menu items (semua file exists)
- **Database Tables**: 27 tabel
- **Permissions**: 46 granular permissions
- **Roles**: 4 (super_admin > ketua > bendahara > warga)
- **Default Users**: 4 seeded (password: `P@ssw0rd`)
- **Unit Types**: 5 resmi (Ruko 300k, Navulia/Fresia/Magnolia/Cattleya 200k)
- **Units Seeded**: 97 unit dari siteplan e-Praya 8

## 🔐 DEFAULT CREDENTIALS

```
superadmin / P@ssw0rd  (Super Admin)
ketua      / P@ssw0rd  (Ketua RT)
bendahara  / P@ssw0rd  (Bendahara)
warga      / P@ssw0rd  (Warga)
```

## ✅ READY FOR PRODUCTION

Semua fitur lengkap dan berfungsi. Testing checklist ada di `DEPLOYMENT_CHECKLIST.md`.

### Yang Perlu Update Manual Setelah Import

1. **Luas tanah 0**: 15 unit (RUKO-21, G4, G6, G10, G12, G13, A11-A13, A17-A18) perlu update manual via form edit
2. **Blok B/E/F/J**: Tidak ter-inject karena label unit tidak jelas di siteplan — tambah manual jika diperlukan
3. **SMTP Config**: Edit `config/config.php` jika pakai SMTP relay (default: PHP mail())
4. **APP_URL**: Edit `config/config.php` sesuai domain cPanel (default: localhost)

## 🚀 DEPLOYMENT

```bash
# 1. Upload semua file ke cPanel
# 2. Import database/schema.sql via phpMyAdmin
# 3. Edit config/config.php (APP_URL + SMTP opsional)
# 4. Login dengan kredensial default
# 5. Ubah password default via profile
```

## 📝 DOKUMENTASI LENGKAP

- `README.md` — Overview lengkap proyek
- `database/README.md` — Schema info, kredensial default, security features
- `DEPLOYMENT_CHECKLIST.md` — Testing & troubleshooting guide

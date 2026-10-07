# Arya Green Pamulang — Sistem Manajemen IPL

Sistem pengelolaan Iuran Pemeliharaan Lingkungan (IPL) untuk perumahan — PHP Native + MySQLi + Bootstrap 5.

## Stack
- PHP 7.4+ (Native, no framework)
- MySQLi with prepared statements
- Bootstrap 5.3 + Bootstrap Icons (CDN)
- Chart.js 4.4 (CDN)
- MySQL / MariaDB
- PWA-ready (manifest.json + service worker)

## Instalasi

### 1. Import Database
```bash
mysql -u root -p < database/schema.sql
```

> Schema sudah lengkap di 1 file: 27 tabel, 4 role (super_admin/ketua/bendahara/warga), 4 default user dengan password `P@ssw0rd`.

### 2. Konfigurasi Database
Edit `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'db_arya_green_ipl');
```

### 3. Konfigurasi Aplikasi
Edit `config/config.php`:
```php
define('APP_URL', 'http://yourdomain.com/arya-green-ipl');
define('MAIL_FROM', 'noreply@aryagreen.id');

// Opsional: Jika menggunakan SMTP Relay pihak ketiga (Gmail / SendGrid / Mailgun / SMTP cPanel)
// Kosongkan SMTP_HOST jika ingin memakai php mail() bawaan hosting
define('SMTP_HOST', 'mail.yourdomain.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@yourdomain.com');
define('SMTP_PASS', 'your_smtp_password');
define('SMTP_SECURE', 'tls'); // 'tls', 'ssl', atau ''
```

### 4. Upload ke cPanel
- Upload semua file ke `public_html/arya-green-ipl/`
- Pastikan `.htaccess` ikut terupload
- Set permission folder `uploads/`: `755`, file: `644`
- Folder `uploads/payment_proofs/` sudah dilindungi `.htaccess` (larangan eksekusi PHP/CGI dan directory listing)

### 5. Login Default
| Username | Password | Role |
|---|---|---|
| `superadmin` | `P@ssw0rd` | Super Admin |
| `ketua` | `P@ssw0rd` | Ketua |
| `bendahara` | `P@ssw0rd` | Bendahara |
| `warga` | `P@ssw0rd` | Warga |

> **Catatan Keamanan:**
> - Sistem dilengkapi proteksi **Rate Limiting** (maksimal 5 kali percobaan login gagal dalam 15 menit).
> - Ganti password segera setelah login pertama!

---

## Struktur File
```
arya-green-ipl/
├── .htaccess                  # Apache config, security headers
├── index.php                  # Entry point → redirect
├── manifest.json              # PWA manifest
├── sw.js                      # Service worker + IndexedDB outbox sync
├── offline.html               # Halaman fallback saat tanpa koneksi
├── config/
│   ├── database.php           # DB constants
│   └── config.php             # App settings, session, upload config
├── includes/
│   ├── auth.php               # Login, logout, RBAC, email verify
│   ├── functions.php          # Helpers: CSRF, flash, paginate, e(), idr(), terbilang()
│   ├── email_notifications.php# Fungsi kirim email notifikasi & reminder
│   ├── header.php             # HTML head + navbar + PWA meta
│   ├── sidebar.php            # Sidebar navigasi (permission-aware)
│   └── footer.php             # Scripts, closing tags
├── assets/
│   ├── css/style.css          # Layout, sidebar, auth, badges, dark mode
│   ├── js/app.js              # Sidebar toggle, confirm dialogs, dark mode toggle
│   └── js/offline.js          # Outbox queue (aduan/polling) + SW messaging
├── auth/
│   ├── login.php
│   ├── logout.php
│   ├── forgot-password.php    # Request + reset flow (token 1 jam)
│   └── verify-email.php
├── pages/
│   ├── dashboard.php          # Statistik, chart tren, tagihan terlambat
│   ├── portal.php             # Portal warga (PWA, login via token)
│   ├── units/
│   │   ├── index.php          # Daftar unit + filter
│   │   ├── form.php           # Tambah/edit unit
│   │   └── detail.php         # Riwayat tagihan per unit
│   ├── unit_types/            # index, form (CRUD tipe unit)
│   ├── residents/             # index, form (CRUD warga)
│   ├── billing/
│   │   ├── index.php          # Generate & kelola tagihan
│   │   ├── detail.php         # Detail tagihan
│   │   └── send_reminders.php # Kirim reminder email massal
│   ├── payments/
│   │   ├── index.php          # Daftar pembayaran
│   │   ├── form.php           # Catat pembayaran + upload bukti
│   │   ├── verify.php         # Verifikasi + auto-entry kas
│   │   ├── detail.php         # Detail pembayaran
│   │   └── print_receipt.php  # Cetak kwitansi (print-friendly)
│   ├── payment_methods/       # Master metode pembayaran (CRUD + QR + auto-verify)
│   │   ├── index.php          # Daftar metode (aktif/nonaktif, QR, auto-verify)
│   │   └── form.php           # Tambah/edit metode (rekening, QR, instruksi, urutan)
│   ├── reports/
│   │   ├── index.php          # Laporan tagihan per periode
│   │   ├── export.php         # Export CSV laporan tagihan
│   │   ├── arrears.php        # Rekap tunggakan multi-periode
│   │   ├── arrears_export.php # Export CSV tunggakan
│   │   ├── cashflow.php       # Laporan arus kas gabungan + chart
│   │   └── cashflow_export.php# Export CSV arus kas
│   ├── cashbook/              # Buku kas pemasukan & pengeluaran
│   ├── kas/                   # Kas operasional / sub-kas
│   ├── expense/               # Approval pengeluaran
│   ├── inventory/
│   │   ├── index.php          # Daftar aset
│   │   └── detail.php         # Detail aset
│   ├── complaints/            # Pengaduan warga (CRUD + komentar balasan)
│   ├── events/                # Agenda & absensi warga
│   ├── letters/
│   │   ├── index.php          # Daftar surat keterangan
│   │   └── print.php          # Cetak surat (standalone HTML)
│   ├── onboarding/            # Import massal warga via CSV (preview + import)
│   ├── polls/                 # Polling + statistik hasil voting
│   ├── environments/          # Manajemen lingkungan/cluster
│   ├── users/                 # CRUD user
│   ├── roles/                 # Permission editor per role
│   ├── public/
│   │   └── kas.php            # Halaman publik rekap kas (tanpa login)
│   └── 403.php
├── uploads/
│   └── payment_proofs/        # Bukti pembayaran (jpg/png/webp/pdf)
└── database/
    ├── schema.sql             # DDL lengkap semua modul (27 tabel)
    └── README.md              # Database setup guide
```

## Peran Default (RBAC)
| Peran | Akses |
|---|---|
| Super Admin | Semua fitur |
| Ketua | Semua kecuali hapus user & edit role |
| Bendahara | Tagihan, pembayaran, laporan, kas |
| Warga | Read-only semua |

---

## Fitur Lengkap

### Dashboard
- Statistik real-time: total unit, total warga aktif, jumlah belum bayar, total terkumpul bulan ini
- Progress bar lunas vs belum vs terlambat untuk periode berjalan
- Tabel 8 tagihan terlambat + 8 pembayaran terbaru
- **Chart tren pembayaran 12 bulan** (Chart.js — terkumpul vs tunggakan)

### Master Data
- **Unit** — CRUD + halaman detail riwayat tagihan per unit
- **Tipe Unit** — CRUD; nama tipe, nominal IPL per bulan
- **Warga / Penghuni** — CRUD; nama, telepon, email, unit; status aktif/nonaktif
- **Lingkungan / Cluster** — CRUD; manajemen area perumahan
- **Metode Pembayaran** — CRUD dari UI; nama/kode metode, rekening atau tujuan, atas nama, QR/logo, instruksi, urutan, aktif/nonaktif, dan pengaturan auto-verifikasi

### Tagihan IPL
- Generate tagihan per periode (tahun + bulan + jatuh tempo): otomatis semua unit `dihuni`
- Status otomatis: `belum_bayar → terlambat` jika melewati jatuh tempo
- Filter per periode, status, pencarian unit/warga
- Denda manual di halaman detail
- Kirim reminder email massal ke warga belum bayar

### Pembayaran
- Catat pembayaran dari tagihan
- Metode dinamis dari master data (Tunai, Transfer, QRIS, dan metode custom yang ditambahkan admin)
- Instruksi bayar tampil otomatis sesuai metode yang dipilih
- Upload bukti bayar (jpg/png/webp/pdf, maks 2 MB)
- Alur verifikasi: auto-verifikasi untuk metode instan (tunai) atau `pending → verified / rejected` untuk metode manual
- Auto-entry ke buku kas saat verified
- **Cetak kwitansi** print-friendly dengan terbilang + kolom tanda tangan

### Keuangan
- **Buku Kas** — pemasukan & pengeluaran, auto-entry dari pembayaran IPL
- **Kas Operasional** — sub-kas / petty cash
- **Approval Pengeluaran** — ajukan → setujui/tolak → auto-catat ke kas
- **Rekap Kas Publik** — halaman publik tanpa login
- **Laporan Arus Kas Gabungan** — bar+line chart, breakdown per kategori, export CSV

### Laporan
- Laporan tagihan per periode + export CSV
- **Rekap tunggakan multi-periode** — warga nunggak lintas bulan, badge merah ≥3 bulan, export CSV
- Tren koleksi tahunan

### Komunitas & Warga
- **Pengaduan** — warga buat pengaduan, pengurus update status (open/in_progress/resolved), komentar balasan
- **Events & Absensi** — buat/edit/hapus event (rapat, kerja bakti), catat kehadiran warga
- **Polling / Survei** — voting warga + statistik hasil (Chart.js bar chart + persentase)
- **Surat Keterangan RT** — nomor otomatis, isi data pemohon, cetak surat standalone print-friendly
- **Inventaris Aset** — CRUD aset lingkungan (nama, kode, kategori, kondisi, lokasi, nilai), halaman detail

### Onboarding
- **Import massal warga via CSV** — upload → preview tabel → konfirmasi → INSERT IGNORE
- Wizard setup RT

### Portal Warga (PWA)
- Login warga via token/PIN (tanpa username/password)
- Lihat tagihan sendiri, status pembayaran
- Kirim pengaduan langsung dari portal
- Dapat diinstall sebagai PWA di HP
- Service worker untuk akses offline dasar

### Manajemen User & Role
- CRUD user; nama, username, email, peran
- Permission editor per role: centang/uncentang permission individual
- Toggle aktif/nonaktif; reset password oleh Super Admin

---

## Keamanan
- Semua query pakai MySQLi prepared statements
- Password di-hash dengan `password_hash()` bcrypt cost=12
- CSRF token di setiap form POST
- Output di-escape dengan `e()` → `htmlspecialchars(ENT_QUOTES, UTF-8)`
- Session `httponly` + `samesite=Strict`
- Upload divalidasi MIME type + ukuran maksimal 2 MB
- `.htaccess` blokir akses langsung ke `config/`, `includes/`, `database/`
- Validasi permission di setiap halaman (`require_permission()`)
- Forgot & reset password dengan token berumur 1 jam
- **Rate Limiting Login** — 5× gagal per akun/IP (15 menit), 20× gagal global per IP (mitigasi password spraying)
- **SMTP Socket Native** — RFC 5321 AUTH LOGIN, CRLF normalisasi, dot-stuffing, StartTLS, fallback otomatis ke `mail()` cPanel
- **Upload Protection** — `.htaccess` default-deny, whitelist hanya `.jpg/.jpeg/.png/.webp/.pdf`, dual Apache 2.2/2.4 syntax

### UX & PWA Lanjutan
- **Email Template HTML Responsif** — branding gradasi, tombol CTA (VML fallback Outlook), preheader inbox, footer link portal & kas publik, plain-text fallback multipart/alternative
- **PWA Install Prompt** — `beforeinstallprompt` handler di `portal.php` (Android/Chrome/Edge), panduan manual "Bagikan → Tambah ke Layar Utama" untuk iOS Safari, dismiss persisten di `localStorage`
- **Offline Fallback & Outbox Sync** — `offline.html` halaman ramah pengguna, `sw.js` IndexedDB outbox + Background Sync API, antrian POST aduan & polling saat offline dikirim otomatis saat online
- **Export CSV Encoding** — UTF-8 BOM + `Content-Disposition: attachment; filename*=UTF-8''...` (RFC 6266/5987) untuk nama file non-ASCII & spasi
- **Dark Mode Toggle** — CSS custom properties + `data-bs-theme`, persisten di `localStorage`, default mengikuti `prefers-color-scheme`, anti-FOUC inline script di `header.php`

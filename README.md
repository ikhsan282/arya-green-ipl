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

> Schema sudah lengkap di 1 file: 25 tabel, role `ketua`/`warga`, default user `superadmin` / `Admin@1234`.

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
```

### 4. Upload ke cPanel
- Upload semua file ke `public_html/arya-green-ipl/`
- Pastikan `.htaccess` ikut terupload
- Set permission folder `uploads/`: `755`, file: `644`

### 5. Login Default
| Username | Password | Peran |
|---|---|---|
| `superadmin` | `Admin@1234` | Super Admin |

> **Ganti password segera setelah login pertama!**

---

## Struktur File
```
arya-green-ipl/
├── .htaccess                  # Apache config, security headers
├── index.php                  # Entry point → redirect
├── manifest.json              # PWA manifest
├── sw.js                      # Service worker (PWA offline)
├── migrate.php                # One-time migration runner (hapus setelah pakai)
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
│   ├── css/style.css          # Layout, sidebar, auth, badges
│   └── js/app.js              # Sidebar toggle, confirm dialogs
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
    ├── schema.sql             # DDL v1
    ├── schema_v2.sql          # DDL lengkap semua modul (gunakan ini)
    └── migrations/            # Migration incremental
        └── 004_environments_permissions.sql
```

## Peran Default (RBAC)
| Peran | Akses |
|---|---|
| Super Admin | Semua fitur |
| Ketua | Semua kecuali hapus user & edit role |
| Petugas | Tagihan, pembayaran, laporan, kas |
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

### Tagihan IPL
- Generate tagihan per periode (tahun + bulan + jatuh tempo): otomatis semua unit `dihuni`
- Status otomatis: `belum_bayar → terlambat` jika melewati jatuh tempo
- Filter per periode, status, pencarian unit/warga
- Denda manual di halaman detail
- Kirim reminder email massal ke warga belum bayar

### Pembayaran
- Catat pembayaran dari tagihan
- Metode: tunai, transfer, QRIS, lainnya
- Upload bukti bayar (jpg/png/webp/pdf, maks 2 MB)
- Alur verifikasi: `pending → verified / rejected`
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

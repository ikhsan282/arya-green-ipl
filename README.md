# Arya Green Pamulang — Sistem Manajemen IPL

Sistem pengelolaan Iuran Pemeliharaan Lingkungan (IPL) untuk perumahan — PHP Native + MySQLi + Bootstrap 5.

## Stack
- PHP 7.4+ (Native, no framework)
- MySQLi with prepared statements
- Bootstrap 5.3 + Bootstrap Icons (CDN)
- MySQL / MariaDB
- PWA-ready (manifest.json + service worker)

## Instalasi

### 1. Import Database
```sql
mysql -u root -p < database/schema.sql
```

Atau jalankan migration incremental:
```bash
php migrate.php
```

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
| `superadmin` | `password` | Super Admin |

> **Ganti password segera setelah login pertama!**

---

## Struktur File
```
arya-green-ipl/
├── .htaccess                  # Apache config, security headers
├── index.php                  # Entry point → redirect
├── manifest.json              # PWA manifest
├── sw.js                      # Service worker (PWA offline)
├── migrate.php                # CLI migration runner
├── config/
│   ├── database.php           # DB constants
│   └── config.php             # App settings, session, upload config
├── includes/
│   ├── auth.php               # Login, logout, RBAC, email verify
│   ├── functions.php          # Helpers: CSRF, flash, paginate, e(), idr()
│   ├── header.php             # HTML head + navbar + PWA meta
│   ├── sidebar.php            # Sidebar navigasi (permission-aware)
│   └── footer.php             # Scripts, closing tags
├── assets/
│   ├── css/style.css          # Layout, sidebar, auth, badges
│   └── js/app.js              # Sidebar toggle, confirm dialogs
├── auth/
│   ├── login.php
│   ├── logout.php
│   ├── forgot-password.php
│   └── verify-email.php
├── pages/
│   ├── dashboard.php          # Statistik unit, tagihan, pembayaran terbaru
│   ├── portal.php             # Portal warga (PWA entry point)
│   ├── units/                 # index, form (create/edit)
│   ├── residents/             # index, form (create/edit)
│   ├── billing/               # index, detail (generate & kelola tagihan)
│   ├── payments/              # index, form, verify (catat & verifikasi bayar)
│   ├── reports/               # Laporan tagihan & pembayaran
│   ├── cashbook/              # Buku kas pemasukan & pengeluaran
│   ├── kas/                   # Kas operasional tambahan
│   ├── expense/               # Pengeluaran / belanja
│   ├── inventory/             # Inventaris aset perumahan
│   ├── complaints/            # Pengaduan warga (index, detail)
│   ├── events/                # Agenda & kegiatan warga (index, detail)
│   ├── letters/               # Surat keterangan & print
│   ├── onboarding/            # Import warga massal via template
│   ├── polls/                 # Voting & survei warga
│   ├── environments/          # Manajemen lingkungan / cluster
│   ├── wa/                    # Broadcast & template WhatsApp
│   ├── users/                 # index, form (CRUD user)
│   ├── roles/                 # index (permission editor per role)
│   ├── public/
│   │   └── kas.php            # Halaman publik rekap kas
│   └── 403.php                # Halaman akses ditolak
├── uploads/
│   └── payment_proofs/        # Bukti pembayaran (jpg/png/webp/pdf)
└── database/
    ├── schema.sql             # DDL + seed data (v1)
    ├── schema_v2.sql          # DDL lengkap (v2, semua modul)
    └── migrations/            # Migration incremental
        ├── 001_*.sql
        ├── 002_*.sql
        ├── 003_*.sql
        └── 004_environments_permissions.sql
```

## Peran Default (RBAC)
| Peran | Akses |
|---|---|
| Super Admin | Semua fitur |
| Admin | Semua kecuali hapus user & edit role |
| Bendahara | Tagihan, pembayaran, laporan, kas |
| Viewer | Read-only semua |

## Fitur

### Dashboard
- Statistik real-time: total unit, total warga aktif, jumlah belum bayar, total terkumpul bulan ini
- Progress bar lunas vs belum vs terlambat untuk periode berjalan
- Tabel 8 tagihan terlambat + 8 pembayaran terbaru

### Master Data
- **Unit** — CRUD; nomor unit, blok, tipe unit; status dihuni/kosong
- **Tipe Unit** — CRUD; nama tipe, nominal IPL per bulan
- **Warga / Penghuni** — CRUD; nama, telepon, email, unit; status aktif/nonaktif
- **Lingkungan / Cluster** — CRUD; manajemen area/cluster perumahan beserta permission

### Tagihan IPL
- Generate tagihan per periode (tahun + bulan + jatuh tempo): otomatis buat tagihan semua unit `dihuni`; idempotent
- Status otomatis: `belum_bayar → terlambat` jika melewati jatuh tempo
- Filter per periode, status, pencarian unit/warga; denda manual di halaman detail
- Pagination 15 baris per halaman

### Pembayaran
- Catat pembayaran dari tagihan atau menu pembayaran
- Metode: tunai, transfer, QRIS, lainnya; nomor referensi & nama bank
- Upload bukti bayar (jpg/png/webp/pdf, maks 2 MB)
- Alur verifikasi: `pending → verified / rejected`

### Keuangan
- **Buku Kas** — pemasukan & pengeluaran kas utama
- **Kas Operasional** — kas tambahan / petty cash
- **Pengeluaran** — pencatatan belanja & realisasi anggaran
- **Rekap Kas Publik** — halaman publik rekap kas tanpa login

### Inventaris
- Pencatatan aset perumahan: nama, kategori, kondisi, lokasi

### Warga & Komunitas
- **Pengaduan** — pengajuan & tracking keluhan warga (index + detail)
- **Agenda / Events** — kegiatan & event perumahan (index + detail)
- **Polling / Survei** — voting & survei warga
- **Surat Keterangan** — cetak surat keterangan domisili/warga
- **Onboarding** — import massal warga via template Excel/CSV

### Komunikasi
- **WhatsApp** — broadcast pesan & template WA ke warga

### Portal Warga (PWA)
- Entry point portal warga; dapat diinstall sebagai PWA di HP
- Service worker untuk akses offline dasar

### Laporan
- Laporan tagihan & rekap pembayaran dengan filter periode

### Manajemen User & Role
- CRUD user; nama, username, email, peran
- Permission editor per role: centang/uncentang permission individual
- Toggle aktif/nonaktif; reset password oleh Super Admin

## Keamanan
- Semua query pakai MySQLi prepared statements
- Password di-hash dengan `password_hash()` bcrypt cost=12
- CSRF token di setiap form POST
- Output di-escape dengan `e()` → `htmlspecialchars(ENT_QUOTES, UTF-8)`
- Session `httponly` + `samesite=Strict`
- Upload divalidasi MIME type + ukuran maksimal 2 MB
- `.htaccess` blokir akses langsung ke `config/`, `includes/`, `database/`
- Validasi permission di setiap halaman (`require_permission()`)
- Email verifikasi akun via `mail()`
- Forgot & reset password dengan token berumur 1 jam

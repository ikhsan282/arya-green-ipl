# Arya Green Pamulang — Sistem Manajemen IPL

Sistem pengelolaan Iuran Pemeliharaan Lingkungan (IPL) untuk perumahan — PHP Native + MySQLi + Bootstrap 5.

## Stack
- PHP 7.4+ (Native, no framework)
- MySQLi with prepared statements
- Bootstrap 5.3 + Bootstrap Icons (CDN)
- MySQL / MariaDB

## Instalasi

### 1. Import Database
```sql
mysql -u root -p < database/schema.sql
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
├── config/
│   ├── database.php           # DB constants
│   └── config.php             # App settings, session, upload config
├── includes/
│   ├── auth.php               # Login, logout, RBAC, email verify
│   ├── functions.php          # Helpers: CSRF, flash, paginate, e(), idr()
│   ├── header.php             # HTML head + navbar
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
│   ├── units/                 # index, form (create/edit)
│   ├── residents/             # index, form (create/edit)
│   ├── billing/               # index, detail (generate & kelola tagihan)
│   ├── payments/              # index, form, verify (catat & verifikasi bayar)
    ├── reports/               # Laporan tagihan & pembayaran
    ├── cashbook/              # Buku kas pemasukan & pengeluaran
    ├── users/                 # index, form (CRUD user)
    ├── roles/                 # index (permission editor per role)
    └── 403.php                # Halaman akses ditolak
├── uploads/
│   └── payment_proofs/        # Bukti pembayaran (jpg/png/webp/pdf)
└── database/
    └── schema.sql             # DDL + seed data
```

## Peran Default (RBAC)
| Peran | Akses |
|---|---|
| Super Admin | Semua fitur |
| Admin | Semua kecuali hapus user & edit role |
| Bendahara | Tagihan, pembayaran, laporan |
| Viewer | Read-only semua |

## Fitur

### Dashboard
- Statistik real-time: total unit, total warga aktif, jumlah belum bayar (belum + terlambat), total terkumpul bulan ini
- Progress bar lunas vs belum vs terlambat untuk periode berjalan, plus nominal terkumpul & tunggakan
- Tabel 8 tagihan terlambat (urut jatuh tempo) dengan link ke billing
- Tabel 8 pembayaran terbaru dengan status badge

### Master Data
- **Unit** — CRUD; nomor unit, blok, tipe unit; status dihuni/kosong
- **Tipe Unit** — CRUD; nama tipe, nominal IPL per bulan
- **Warga / Penghuni** — CRUD; nama, telepon, email, unit; status aktif/nonaktif

### Tagihan IPL
- **Generate tagihan** per periode (tahun + bulan + jatuh tempo): otomatis buat tagihan untuk semua unit berstatus `dihuni`; unit yang sudah punya tagihan dilewati (idempotent)
- Status tagihan diperbarui otomatis: `belum_bayar → terlambat` jika melewati jatuh tempo
- Filter tagihan per periode, status (belum/lunas/terlambat), dan pencarian unit/warga
- Ringkasan periode: total, lunas, belum, terlambat; nominal terkumpul & tunggakan
- Denda (`fine_amount`) bisa diset manual di halaman detail tagihan
- Pagination 15 baris per halaman

### Pembayaran
- Catat pembayaran dari halaman tagihan atau menu pembayaran
- Metode: tunai, transfer, QRIS, lainnya; field nomor referensi & nama bank
- Upload bukti bayar (jpg/png/webp/pdf, maks 2 MB); file disimpan di `uploads/payment_proofs/`
- Alur verifikasi: `pending → verified / rejected` oleh Admin/Bendahara
- Filter daftar: status verifikasi, metode, pencarian unit/warga/referensi

### Laporan
- Laporan tagihan & rekap pembayaran dengan filter periode

### Manajemen User & Role
- CRUD user; nama, username, email, peran
- Permission editor per role: centang/uncentang permission individual dari halaman roles
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

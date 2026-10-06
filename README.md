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
│   ├── reports/               # Laporan tagihan & pembayaran
│   ├── users/                 # index, form (CRUD user)
│   ├── roles/                 # index (permission editor per role)
│   └── 403.php                # Halaman akses ditolak
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
- ✅ Login / Logout dengan session
- ✅ CSRF protection di semua form
- ✅ Email verifikasi via `mail()`
- ✅ Forgot & reset password (token 1 jam)
- ✅ RBAC: roles + permissions + role_permissions
- ✅ Master data: unit, tipe unit, penghuni
- ✅ Generate tagihan IPL per periode (bulanan)
- ✅ Catat pembayaran + upload bukti bayar
- ✅ Verifikasi pembayaran oleh admin
- ✅ Status tagihan: belum, lunas, terlambat
- ✅ Laporan tagihan & rekap pembayaran
- ✅ Dashboard statistik real-time
- ✅ Pagination di semua list
- ✅ Responsive (Bootstrap 5)

## Keamanan
- Semua query pakai MySQLi prepared statements
- Password di-hash dengan `password_hash()` bcrypt cost=12
- CSRF token di setiap form POST
- Output di-escape dengan `e()` → `htmlspecialchars(ENT_QUOTES, UTF-8)`
- Session `httponly` + `samesite=Strict`
- Upload divalidasi MIME type + ukuran maksimal 2 MB
- `.htaccess` blokir akses langsung ke `config/`, `includes/`, `database/`
- Validasi permission di setiap halaman (`require_perm()`)

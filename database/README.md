# Database Setup

## Fresh Install

Import schema lengkap:

```bash
mysql -u root -p < database/schema.sql
```

Atau via phpMyAdmin: Import → pilih `schema.sql`

## Default Login

| Username | Password | Role | Email |
|---|---|---|---|
| `superadmin` | `P@ssw0rd` | Super Admin | admin@aryagreen.id |
| `ketua` | `P@ssw0rd` | Ketua | ketua@aryagreen.id |
| `bendahara` | `P@ssw0rd` | Bendahara | bendahara@aryagreen.id |
| `warga` | `P@ssw0rd` | Warga | warga@aryagreen.id |

**Wajib ganti password setelah login pertama.**

## Upgrade Database Existing

Jika Anda sudah import schema versi lama dan menemukan **menu tidak muncul** di sidebar (Sub-Kas, Pengeluaran, Aduan, Polling, Kegiatan, Inventaris, Surat RT, Lingkungan, dll), jalankan SQL berikut **sekali saja** di phpMyAdmin:

```sql
-- STEP 1: Insert permission yang belum ada
INSERT IGNORE INTO `permissions` (`name`, `label`, `module`) VALUES
  ('cashbook.view',         'Lihat Buku Kas',             'cashbook'),
  ('cashbook.manage',       'Kelola Buku Kas',            'cashbook'),
  ('payment_methods.view',  'Lihat Metode Pembayaran',    'payment_methods'),
  ('payment_methods.manage','Kelola Metode Pembayaran',   'payment_methods'),
  ('billing.send_reminder', 'Kirim Reminder Email',       'billing'),
  ('kas.view',              'Lihat Sub-Kas',              'kas'),
  ('kas.manage',            'Kelola Sub-Kas',             'kas'),
  ('expense.request',       'Ajukan Pengeluaran',         'expense'),
  ('expense.approve',       'Setujui/Tolak Pengeluaran',  'expense'),
  ('complaints.view',       'Lihat Aduan',                'complaints'),
  ('complaints.manage',     'Kelola Aduan',               'complaints'),
  ('polls.view',            'Lihat Polling',              'polls'),
  ('polls.manage',          'Kelola Polling',             'polls'),
  ('polls.vote',            'Vote Polling',               'polls'),
  ('events.view',           'Lihat Kegiatan',             'events'),
  ('events.manage',         'Kelola Kegiatan',            'events'),
  ('events.attendance',     'Kelola Absensi',             'events'),
  ('inventory.view',        'Lihat Inventaris',           'inventory'),
  ('inventory.manage',      'Kelola Inventaris',          'inventory'),
  ('letters.view',          'Lihat Surat RT',             'letters'),
  ('letters.manage',        'Buat/Cetak Surat RT',        'letters'),
  ('environments.view',     'Lihat Lingkungan',           'environments'),
  ('environments.create',   'Tambah Lingkungan',          'environments'),
  ('environments.edit',     'Edit Lingkungan',            'environments'),
  ('environments.delete',   'Hapus Lingkungan',           'environments');

-- STEP 2: Assign semua permission ke super_admin (role_id=1)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`;

-- STEP 3: Assign ke ketua (role_id=2) — semua kecuali environments
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions`
WHERE `name` NOT LIKE 'environments.%';

-- STEP 4: Assign ke bendahara (role_id=3)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, id FROM `permissions`
WHERE `name` IN (
  'dashboard.view',
  'cashbook.view','cashbook.manage',
  'billing.view','payments.view','payments.create','payments.verify',
  'payment_methods.view',
  'reports.view',
  'expense.request',
  'kas.view','kas.manage'
);

-- STEP 5: Assign ke warga (role_id=4)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, id FROM `permissions`
WHERE `name` IN (
  'dashboard.view',
  'payments.view','payments.create',
  'billing.view',
  'complaints.view',
  'polls.view','polls.vote',
  'events.view',
  'letters.view'
);
```

Setelah selesai, **refresh browser** → semua menu akan muncul sesuai role.

---

## Database Info

- Database: `db_arya_green_ipl`
- Charset: `utf8mb4_unicode_ci`
- Timezone: `+07:00` (WIB)
- Tables: 27

## Roles

1. **Super Admin** - Full access + multi-environment
2. **Ketua** - Semua fitur kecuali role management & delete user
3. **Bendahara** - View + input billing/payment/absensi
4. **Warga** - View tagihan sendiri, polling, aduan

## Fitur Utama

- Unit & warga management
- Billing & payment (dengan approval)
- Buku kas (multi rekening)
- Aduan warga
- Polling/voting
- Kegiatan & absensi
- Inventaris aset
- Surat RT
- **Payment Methods** — master metode pembayaran dinamis, bukan ENUM; metode aktif muncul otomatis di form pembayaran
- **Rate Limiting** — tabel `login_attempts` membatasi 5 kegagalan per akun dan 20 kegagalan per IP dalam 15 menit
- **SMTP opsional** — isi konfigurasi SMTP di `config/config.php`; jika kosong atau gagal, sistem memakai fallback `mail()` cPanel
- **PWA Lanjutan** — `offline.html` fallback, `sw.js` IndexedDB outbox + Background Sync, install prompt handler, dark mode toggle
- **Email HTML Responsif** — multipart/alternative (HTML + plain text), tombol CTA dengan VML fallback Outlook
- **Export CSV RFC 6266/5987** — UTF-8 BOM + filename* encoding untuk karakter non-ASCII

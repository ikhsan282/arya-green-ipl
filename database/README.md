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

Jika menu tidak muncul (Sub-Kas, Pengeluaran, Aduan, dll): **re-import `schema.sql` fresh** atau manual tambahkan permission yang hilang via SQL tab phpMyAdmin.

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

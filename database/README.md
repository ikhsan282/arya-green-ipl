# Database Setup

## Fresh Install

Import schema lengkap:

```bash
mysql -u root -p < database/schema.sql
```

Atau via phpMyAdmin: Import → pilih `schema.sql`

## Default Login

- Username: `superadmin`
- Password: `Admin@1234`
- Email: `admin@aryagreen.id`

**Wajib ganti password setelah login pertama.**

## Database Info

- Database: `db_arya_green_ipl`
- Charset: `utf8mb4_unicode_ci`
- Timezone: `+07:00` (WIB)
- Tables: 25

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
- **Database migration** — untuk instalasi baru gunakan `database/schema.sql`; database lama membutuhkan migration additif terpisah

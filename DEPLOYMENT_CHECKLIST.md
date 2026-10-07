# Deployment & Testing Checklist

## ❌ BLOCKER: Cannot test login locally
PHP CLI tidak tersedia di container development — testing login flow **harus dilakukan di cPanel production/staging**.

## Pre-Go-Live Checklist

### 1. Database Setup
- [ ] Import `database/schema.sql` ke database baru
- [ ] Verifikasi tabel `login_attempts` ada (27 tabel total)
- [ ] Test login default: username `superadmin`, password `Admin@1234`
- [ ] Jika login gagal dengan "Email belum diverifikasi":
  ```sql
  UPDATE users SET email_verified_at = NOW() WHERE username = 'superadmin';
  ```

### 2. Konfigurasi Produksi (`config/config.php`)
- [ ] `APP_URL` → URL produksi (https://domain.com)
- [ ] `APP_DEBUG` → `false`
- [ ] SMTP: isi `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS` atau kosongkan untuk pakai `mail()`
- [ ] `MAIL_FROM` → email valid domain

### 3. Konfigurasi Database (`config/database.php`)
- [ ] `DB_HOST` → sesuai cPanel (biasanya `localhost`)
- [ ] `DB_USER`, `DB_PASS`, `DB_NAME` → kredensial cPanel MySQL

### 4. Upload & Permissions
- [ ] Upload semua file ke `public_html/` atau subdirektori
- [ ] `chmod 755` untuk folder: `uploads/payment_proofs/`
- [ ] `chmod 644` untuk file: `.htaccess`, `uploads/payment_proofs/.htaccess`
- [ ] Verifikasi `.htaccess` aktif: coba akses `/uploads/payment_proofs/test.php` harus 403

### 5. Smoke Test (Test Manual di Browser)
1. **Login**
   - [ ] Login sebagai `superadmin`
   - [ ] Cek dashboard load tanpa error
   - [ ] Logout & login ulang

2. **Master Data**
   - [ ] Buat 1 unit baru
   - [ ] Buat 1 warga baru
   - [ ] Generate 1 tagihan bulan ini

3. **Payment Flow (Critical)**
   - [ ] Warga upload bukti bayar via portal (`/pages/portal.php`)
   - [ ] Bendahara verifikasi pembayaran → cek `cash_book` bertambah
   - [ ] Ketua batalkan verifikasi → cek `cash_book` terhapus, bill kembali `belum_bayar`
   - [ ] Verifikasi ulang → cek `cash_book` entry baru

4. **Email (jika SMTP dikonfigurasi)**
   - [ ] Test kirim reminder tagihan dari `/pages/billing/send_reminders.php`
   - [ ] Cek email warga: format HTML responsif, CTA button klik-able

5. **PWA (opsional)**
   - [ ] Akses dari mobile Chrome/Safari
   - [ ] Cek banner "Pasang Aplikasi" muncul
   - [ ] Install PWA, test offline mode

6. **Rate Limiting**
   - [ ] Login salah 5× → harus locked 15 menit
   - [ ] Cek tabel `login_attempts` bertambah

### 6. Security Hardening (Production-only)
- [ ] Session cookie: set `Secure` flag di `php.ini` atau `.htaccess`:
  ```
  php_value session.cookie_secure 1
  php_value session.cookie_httponly 1
  php_value session.cookie_samesite Strict
  ```
- [ ] Force HTTPS: tambahkan redirect di `.htaccess` root:
  ```apache
  RewriteEngine On
  RewriteCond %{HTTPS} off
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  ```

### 7. Backup Otomatis (cPanel Cron)
Setup cron job untuk backup database harian:
```bash
# Jalankan setiap hari jam 02:00 WIB
0 2 * * * mysqldump -u USER -pPASSWORD DB_NAME | gzip > /home/user/backups/db_$(date +\%Y\%m\%d).sql.gz
```

## Troubleshooting Login Gagal

### Gejala: "Username atau password salah" (padahal benar)
**Diagnosa:**
```sql
-- 1. Cek user ada dan aktif
SELECT id, username, email, is_active, email_verified_at, last_login FROM users WHERE username = 'superadmin';

-- 2. Cek password hash valid (harus ada $2y$ prefix)
SELECT LENGTH(password), LEFT(password, 4) FROM users WHERE username = 'superadmin';
-- Expected: 60, '$2y$'
```

**Fix jika password corrupt:**
```sql
-- Reset password ke Admin@1234
UPDATE users SET password = '$2y$12$fw.u3Yko8Qp2MDJlaZUx3Orz.SvJqr2Lz4qyp2Bvon6NTjuD1rwNu' WHERE username = 'superadmin';
```

### Gejala: "Email belum diverifikasi"
**Fix:**
```sql
UPDATE users SET email_verified_at = NOW() WHERE username = 'superadmin';
```

### Gejala: "Terlalu banyak percobaan login"
**Fix:**
```sql
-- Clear rate limit untuk IP tertentu
DELETE FROM login_attempts WHERE ip_address = 'YOUR_IP';
```

### Gejala: Session timeout terus
**Diagnosa:**
- Cek `session.save_path` writable di `phpinfo()`
- Cek error log: `/home/user/public_html/error_log` atau via cPanel Error Log

## Error Log Monitoring
Check file PHP error log di:
- cPanel → Metrics → Errors
- SSH: `tail -f ~/public_html/error_log`

Cari keyword:
- `DB connection failed` → database config salah
- `SMTP connection failed` → SMTP config salah / port blocked
- `Payment verification error` → transaction gagal, cek foreign key
- `CSRF token tidak valid` → session storage issue

## Performance Baseline
Setelah deployment, catat:
- [ ] Dashboard load time: ____ detik
- [ ] Payment list (100 rows): ____ detik
- [ ] Generate 100 tagihan: ____ detik
- [ ] Kas report export CSV: ____ detik

Jika >3 detik, optimasi query dengan `EXPLAIN`.

---

## Status Saat Ini (2026-10-07)
- ✅ Transaction handling payment verify/unverify
- ✅ Email HTML template + CTA
- ✅ PWA offline sync + install prompt
- ✅ Dark mode toggle
- ✅ CSV UTF-8 encoding RFC 5987
- ✅ Rate limiting login (5×/akun, 20×/IP)
- ✅ SMTP socket + fallback `mail()`
- ✅ Upload `.htaccess` hardening

**Uncommitted:** tidak ada.
**Ready untuk deploy.**

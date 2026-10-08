# Security Fixes - Isolasi Data Warga

## 8 Celah Diperbaiki (2026-10-08)

### KRITIS
1. **payments/form.php** - POST handler validasi kepemilikan bill_id
   - Warga tidak bisa submit pembayaran untuk tagihan unit lain via POST manipulation
   - Server-side check: `WHERE b.id=? AND r.user_id=?`

2. **billing/index.php** - Query filter untuk warga
   - Warga hanya lihat tagihan unit sendiri
   - Filter: `WHERE r.user_id=?` + summary stats per-user

3. **payments/index.php** - Query filter untuk warga
   - Warga hanya lihat riwayat pembayaran unit sendiri
   - Filter: `WHERE r.user_id=?`

### TINGGI
4. **billing/detail.php** - Guard akses per-resource
   - Warga tidak bisa akses `?id=X` tagihan unit lain
   - Validasi: `SELECT 1 FROM bills b LEFT JOIN residents r ... WHERE b.id=? AND r.user_id=?`

5. **payments/detail.php** - Guard akses per-resource
   - Warga tidak bisa akses `?id=X` pembayaran orang lain
   - Validasi sama: check ownership via residents.user_id

6. **payments/print_receipt.php** - Guard cetak kwitansi
   - Warga tidak bisa cetak kwitansi pembayaran orang lain
   - Validasi ownership sebelum render

### SEDANG
7. **dashboard.php** - Personalisasi untuk warga
   - Warga lihat stat personal (tagihan sendiri, pembayaran sendiri, tunggakan sendiri)
   - Admin tetap lihat summary global

### FITUR
8. **residents/form.php** - Link akun user ke resident
   - Dropdown `user_id` untuk assign akun warga
   - Load users dengan role 'warga' dari master

## Perubahan Model Data
- **Sebelum:** `users.resident_id` (dua arah, bingung)
- **Sesudah:** `residents.user_id` (one-way, jelas)
- Schema cleanup: hapus `users.resident_id` FK

## Cara Kerja
Semua halaman billing & payments cek role:
```php
$_role = auth_role();
$_uid  = auth_id();
if ($_role === 'warga') {
    // Filter query: WHERE r.user_id = $_uid
}
```

## Commit History
- `78641a2` - 8 celah keamanan diperbaiki
- `e690216` - Fix intelephense errors + cleanup model
- `219e968` - Fix billing summary stats

## Testing Checklist
- [ ] Login sebagai warga
- [ ] Cek billing/index.php - hanya tampil tagihan sendiri
- [ ] Cek payments/index.php - hanya tampil pembayaran sendiri
- [ ] Coba akses `billing/detail.php?id=X` orang lain → ditolak
- [ ] Coba akses `payments/detail.php?id=X` orang lain → ditolak
- [ ] Dashboard tampil stat personal (bukan global)
- [ ] Form pembayaran hanya tampil tagihan unit sendiri
- [ ] POST ke bill_id orang lain → ditolak server-side

## Admin Assignment
1. Admin buka residents/form.php
2. Pilih unit
3. Pilih user dari dropdown "Link Akun User"
4. Save → resident.user_id terisi
5. Warga bisa login dan hanya lihat data unitnya

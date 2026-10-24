# Arya Green Pamulang — Sistem Manajemen IPL

Sistem pengelolaan Iuran Pemeliharaan Lingkungan (IPL) untuk perumahan — PHP Native + MySQLi + Bootstrap 5.

## Stack
- PHP 8.1+ (Native, tanpa framework; diuji pada PHP 8.4)
- Ekstensi PHP wajib: `mysqli`, `curl`, `zip` (untuk export `.xlsx`), `mbstring`, `json`
- MySQLi dengan prepared statements
- MySQL / MariaDB
- Bootstrap 5.3.8 + Bootstrap Icons 1.13.2 (CDN)
- Chart.js 4.5.1 (CDN)
- Tom Select 2.3.1 (CDN)
- Vanilla JavaScript
- PWA (Web App Manifest + Service Worker)
- TCPDF untuk kwitansi PDF dan generator XLSX native tanpa Composer

## Instalasi

### 1. Import Database
```bash
mysql -u root -p < database/schema.sql
```

> Schema lengkap di 1 file: **31 tabel**, termasuk role Sekretaris. Fresh install membuat 3 akun staf contoh, 15 akun warga (username huruf kecil dari nama, email `username@aryagreenpamulang.my.id`) dengan password awal `P@ssw0rd`, dan 1 akun Sekretaris aktif serta terverifikasi. Akun contoh memakai password awal bersama `P@ssw0rd`; ubah password seluruh akun sebelum go-live. Akun `warga` generik dihapus. Wajib beri tahu warga dan minta ganti password awal; jangan gunakan seed ini tanpa meninjau data warga/email. Tidak ada file migrasi terpisah.

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
define('MAIL_FROM', 'noreply@aryagreenpamulang.my.id');

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

### 4b. Checklist Setelah Upload
- **Versi PHP**: set ke 8.1 atau lebih baru (cPanel → MultiPHP Manager)
- **Ekstensi aktif**: `mysqli`, `curl`, `zip`, `mbstring` (cPanel → Select PHP Version → Extensions). Tanpa `curl`, pembayaran QRIS tidak bisa jalan; tanpa `zip`, export Excel gagal
- **`config/config.php`**: ubah `APP_URL` menjadi domain sungguhan, mis. `https://aryagreenpamulang.my.id`
- **HTTPS**: aktifkan AutoSSL. Webhook gateway dan cookie sesi sebaiknya hanya lewat HTTPS
- **Token GoPay**: ambil pertama kali di server, bukan di lokal — login OTP dari **Channel Pembayaran → GoPay**
- **Cek cepat**: buka `/pages/dashboard.php`, lalu **Laporan → Rekap Pembayaran** untuk memastikan query berjalan
- **Folder `tests/`**: tidak perlu diunggah ke produksi; bila ikut terunggah, pastikan tidak dapat diakses dari browser (`_cli_only.php` sudah menolak akses web)

### 5. Login Default
| Username | Password | Role |
|---|---|---|
| `superadmin` | `P@ssw0rd` | Super Admin |
| `ketua` | `P@ssw0rd` | Ketua |
| `bendahara` | `P@ssw0rd` | Bendahara |
| `warga` | `P@ssw0rd` | Warga |

> **Catatan Keamanan:**
> - Sistem dilengkapi proteksi **Rate Limiting** (maksimal 5 kali percobaan login gagal dalam 15 menit).
> - **WAJIB sebelum go-live:** password default `P@ssw0rd` tertanam sebagai hash publik di `database/schema.sql` dan dipakai akun contoh staf serta 15 akun warga hasil seed. Hapus hash/akun contoh atau ubah password sebelum production; beri kredensial warga lewat kanal privat dan minta ganti password. Email warga hasil seed bersifat contoh — pastikan email benar sebelum kirim reset/notifikasi.
> - Aplikasi **tidak boleh** hidup tanpa HTTPS: cookie sesi memakai flag `Secure` hanya saat HTTPS terdeteksi, dan token portal GoPay/ShopeePay dikirim per-request.
> - `database/schema.sql` tidak boleh ikut terunggah ke server produksi (sudah diblokir `.htaccess`, tapi lebih aman tidak diunggah sama sekali).

---

## Struktur File
```
arya-green-ipl/
├── .htaccess                  # Apache config, security headers, blokir folder internal
├── index.php                  # Landing page publik (fitur, transparansi kas, login)
├── manifest.json              # PWA manifest
├── sw.js                      # Service worker + IndexedDB outbox sync
├── offline.html               # Halaman fallback saat tanpa koneksi
├── config/
│   ├── database.php           # DB constants
│   └── config.php             # App settings, session, upload config
├── includes/
│   ├── auth.php               # Login, logout, RBAC, email verify
│   ├── functions.php          # Helpers: CSRF, flash, paginate, e(), idr(), terbilang()
│   ├── payment_gateway.php    # Klien KlikQRIS: create, status, webhook, finalize
│   ├── payment_gateway_direct.php # GoPay/ShopeePay: QRIS dinamis EMVCo, klaim transaksi, cek pembayaran
│   ├── gopay_auth.php         # GoPay Merchant: login OTP, refresh token, cek sesi
│   ├── shopeepay_auth.php     # ShopeePay Partner: validasi token B:
│   ├── email_notifications.php# Fungsi kirim email notifikasi & reminder
│   ├── pdf.php                # Helper TCPDF bersama
│   ├── xlsx.php               # Generator XLSX native
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
│   ├── profile.php            # Profil & ganti password (setiap user, akun sendiri)
│   ├── units/
│   │   ├── index.php          # Daftar unit + filter
│   │   ├── form.php           # Tambah/edit unit
│   │   └── detail.php         # Riwayat tagihan per unit
│   ├── unit_types/            # index, form (CRUD tipe unit)
│   ├── residents/             # index, form (CRUD warga)
│   ├── ipl_components/        # Komponen IPL per tipe unit
│   ├── billing/
│   │   ├── index.php          # Generate & kelola tagihan
│   │   ├── detail.php         # Detail tagihan
│   │   └── send_reminders.php # Kirim reminder email massal
│   ├── payments/
│   │   ├── index.php          # Daftar pembayaran
│   │   ├── form.php           # Catat pembayaran manual + upload bukti
│   │   ├── verify.php         # Verifikasi + auto-entry kas
│   │   ├── unverify.php       # Batalkan verifikasi
│   │   ├── detail.php         # Detail pembayaran
│   │   ├── gateway_pay.php    # Halaman bayar QRIS (satu klik)
│   │   ├── gateway_status.php # Polling status transaksi QRIS
│   │   ├── gateway_webhook.php# Callback server-to-server KlikQRIS
│   │   ├── download_receipt_pdf.php # Kwitansi PDF A5 (TCPDF)
│   │   └── print_receipt.php  # Kwitansi cetak browser (A5)
│   ├── payment_channels/      # Kanal gateway (KlikQRIS: api_key, merchant, mode) — super_admin
│   ├── payment_methods/       # Master metode pembayaran (CRUD + QR + auto-verify) — super_admin
│   ├── reports/
│   │   ├── index.php          # Laporan tagihan per periode
│   │   ├── export.php         # Export CSV/XLSX laporan tagihan
│   │   ├── arrears.php        # Rekap tunggakan multi-periode
│   │   ├── arrears_export.php # Export CSV/XLSX tunggakan
│   │   ├── cashflow.php       # Laporan arus kas gabungan + chart
│   │   ├── cashflow_export.php# Export CSV/XLSX arus kas
│   │   ├── payments.php       # Rekap pembayaran: tagihan vs pembayaran vs order QRIS
│   │   └── export_summary.php # Export CSV rekap pembayaran
│   ├── cashbook/              # Buku kas pemasukan & pengeluaran
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
│   └── 403.php
├── tests/                     # Test integrasi (lihat bagian Pengujian)
├── uploads/
│   └── payment_proofs/        # Bukti pembayaran (jpg/png/webp/pdf)
└── database/
    ├── schema.sql             # DDL lengkap semua modul (31 tabel)
    └── README.md              # Database setup guide
```

## Peran Default (RBAC)
| Peran | Akses |
|---|---|
| Super Admin | Semua fitur, termasuk **Metode Pembayaran** & **Channel Pembayaran** |
| Ketua | Semua kecuali hapus user, edit role, serta pengaturan kanal & master metode pembayaran |
| Bendahara | Tagihan, pembayaran, laporan, kas |
| Sekretaris | Administrasi warga, pengaduan, polling, kegiatan, inventaris, surat, dan informasi lingkungan; tanpa kelola kas, verifikasi pembayaran, atau konfigurasi channel |
| Warga | Dashboard informasi kas lingkungan, agenda, tagihan & pembayaran sendiri |

> Menu **Metode Pembayaran** dan **Channel Pembayaran** khusus Super Admin — baik di sidebar maupun di level halaman (`require_permission('payment_methods.manage')` / `payment_channels.manage`). Mengetik URL langsung tetap ditolak untuk role lain.

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
- **Komponen IPL** — rincian komponen biaya per tipe unit
- **Warga / Penghuni** — CRUD; nama, telepon, email, unit; status aktif/nonaktif
- **Lingkungan / Cluster** — CRUD; manajemen area perumahan
- **Metode Pembayaran** — CRUD dari UI; nama/kode metode, rekening atau tujuan, atas nama, QR/logo, instruksi, urutan, aktif/nonaktif, dan pengaturan auto-verifikasi *(super_admin)*
- **Channel Pembayaran** — kredensial gateway (KlikQRIS, GoPay Merchant, ShopeePay), mode sandbox/production, token webhook, login OTP GoPay, cek token ShopeePay, aktif/nonaktif *(super_admin)*
- **Tanda tangan pengurus** — upload PNG/JPG pada Edit Pengguna untuk akun Ketua/Bendahara; otomatis tampil pada cetak surat dan kwitansi (cetak/PDF). File disimpan privat di `uploads/signatures/`.

### Tagihan IPL
- Generate tagihan per periode (tahun + bulan + jatuh tempo): otomatis semua unit `dihuni`; tanggal awal diambil dari **Lingkungan → Tanggal Default Jatuh Tempo** (default 20, dapat diubah saat generate; nilai 1–28). Database lama perlu menambah kolom `environments.due_day`.
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
- **Kwitansi** dalam dua format, keduanya A5 portrait satu halaman:
  - `print_receipt.php` — cetak langsung dari browser
  - `download_receipt_pdf.php` — PDF via TCPDF

### Pembayaran QRIS Online (KlikQRIS)
- Tombol bayar satu klik di atas form pembayaran manual — warga tidak perlu mengisi apa pun
- Order dibuat server-side; nominal = tagihan + biaya layanan
- **Biaya layanan** flat Rp 1.000 untuk tagihan di atas Rp 200.000 (gratis ≤ Rp 200.000)
- **Kode unik** dari gateway hanya penanda auto-match — **tidak dicairkan** ke saldo merchant, jadi **tidak dicatat sebagai pemasukan**. Yang masuk buku kas hanya tagihan + biaya layanan; kode unik disimpan di catatan pembayaran
- Webhook memicu verifikasi server-to-server (payload tidak dipercaya langsung), disertai pengecekan nominal
- Tagihan lunas otomatis saat pembayaran terkonfirmasi
- Metode gateway otomatis hilang dari dropdown manual selama kanal aktif — muncul kembali sebagai fallback bila kanal dimatikan

### QRIS Langsung GoPay / ShopeePay (tanpa server gateway)
Kedua provider ini membuat QRIS dinamis sendiri dari string QRIS statis merchant — tidak ada daemon Node, tidak ada server perantara, cukup PHP + `curl`.

**QRIS statis → dinamis**
- Parser EMVCo (TLV) + CRC16 lokal (`qris_make_dynamic`) mengubah QRIS statis merchant menjadi QRIS bernominal
- Nominal order = tagihan + biaya layanan + **kode unik** 100–500 (rentang `gq_unique_touch()`), supaya dua tagihan bernominal sama tidak pernah tertukar
- Kode unik ditetapkan saat order disimpan, bukan saat QR dibuat — jadi order berikutnya bisa menghindari bentrokan
- Satu transaksi gateway hanya bisa melunasi satu order (klaim atomik lewat ID transaksi)
- Filter waktu mencegah transaksi lama melunasi order baru

**GoPay Merchant — login OTP di panel**
- Buka **Channel Pembayaran → GoPay**, isi nomor HP GoBiz, klik **Kirim kode OTP**
- Kode masuk via SMS/WA (berlaku ~12 menit), lalu **Verifikasi & simpan sesi**
- Access + refresh token tersimpan otomatis; kartu sesi menampilkan outlet dan masa berlaku token
- Tombol **Cek / refresh sesi** untuk validasi manual
- **Token di-refresh otomatis saat pembayaran dicek**: kalau token mati (HTTP 401), sistem refresh sekali lalu mengulang pengecekan — pembayaran tidak macet tanpa ada yang membuka panel

**ShopeePay Partner — token manual**
- ShopeePay tidak menyediakan alur login OTP. Token diambil manual:
  buka `partner.shopee.co.id` → DevTools (F12) → tab Network → Request Payload → `data.metadata.token` (diawali `B:`)
- Tempel di field **Token**, lalu klik **Cek token** untuk validasi
- Token tanpa awalan `B:` ditolak sebelum request dikirim; token mati (401/403 atau `code 200020 invalid token`) dilaporkan jelas
- ShopeePay **tidak punya refresh token** — token mati berarti ambil ulang dari portal, tidak bisa diperpanjang otomatis

**Bentuk respons API yang dipakai (terverifikasi pada respons asli)**

| Provider | Daftar transaksi | Nominal | Status berhasil |
|---|---|---|---|
| GoPay | `transactions` di root | `gross_amount` **dalam sen** (`1000100` = Rp10.001) | `transaction_status` = `SETTLEMENT`/`CAPTURE` |
| ShopeePay | `data.list` (absen bila kosong, `data.total` = `0`) | `amount` string **bertitik ribuan** (`"10.002"`) | `status` = `3`, `transaction_type` = `1` |

> Kedua bentuk ini bukan tebakan: nominal 100× lipat (GoPay) atau terpotong jadi 10 (ShopeePay) adalah gejala klasik bila satuan/formatnya salah. Tes regresi: `tests/gopay_parse_test.py` dan `tests/shopeepay_parse_test.py`.

> **Catatan:** endpoint GoPay/ShopeePay di atas adalah endpoint portal merchant, bukan API resmi ber-SLA. Bila provider mengubahnya, login/pengecekan bisa berhenti dan perlu penyesuaian.

### Ringkasan Pembayaran (Laporan)
- Tombol **Rekap Pembayaran** di menu Laporan, per bulan
- Menampilkan empat angka sekaligus: total tagihan, terbayar lunas, tunggakan, dan **menunggu verifikasi**
- Tabel **pembayaran menurut status** — terverifikasi / menunggu / ditolak (semua status dihitung, bukan hanya yang terverifikasi)
- Tabel **order QRIS** — lunas / menunggu bayar / kedaluwarsa / gagal. Order dicocokkan lewat periode tagihan, bukan tanggal dibuat, supaya order akhir bulan tidak salah bulan
- Tabel **per metode bayar** dengan penanda QRIS vs non-QRIS
- **Peringatan selisih** otomatis bila order QRIS lunas tidak sama dengan pembayaran QRIS terverifikasi — penanda pembayaran tercatat ganda atau order lunas yang belum jadi pembayaran
- Export CSV dengan angka identik

### Keuangan
- **Buku Kas** — pemasukan & pengeluaran, auto-entry dari pembayaran IPL; akses hanya untuk user yang login (`cashbook.view`)
- **Approval Pengeluaran** — ajukan → setujui/tolak → auto-catat ke kas
- **Laporan Arus Kas Gabungan** — bar+line chart, breakdown per kategori, export CSV

### Laporan
- Laporan tagihan per periode + export CSV/Excel (`.xlsx`)
- **Rekap tunggakan multi-periode** — warga nunggak lintas bulan, badge merah ≥3 bulan, export CSV/Excel
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
- **Kas Lingkungan (ringkas)** — saldo kas total + pemasukan/pengeluaran bulan berjalan; tanpa detail transaksi (transparansi tanpa membuka data internal)
- Kirim pengaduan langsung dari portal
- Dapat diinstall sebagai PWA di HP
- Service worker untuk akses offline dasar

### Landing Page Publik
- Hero, daftar fitur, dan **section Transparansi Kas** (`#transparansi`) — saldo, total pemasukan, total pengeluaran, arus kas bulan berjalan
- Angka diambil dari `cash_book` sebagai agregat saja: tanpa detail transaksi, nama penyetor, atau catatan
- Menampilkan pesan "belum ada transaksi" bila buku kas masih kosong

### Profil & Password
- Setiap user mengubah nama, username, email, dan password sendiri dari `pages/profile.php`
- Akses lewat klik nama di footer sidebar
- Tanpa parameter `id` — selalu akun sendiri; tidak ada jalur untuk menyentuh user lain
- Password: wajib verifikasi password saat ini, minimal 8 karakter, konfirmasi harus cocok, tidak boleh sama dengan yang lama
- Setelah ganti password: sesi lain diputus (`session_regenerate_id`), token reset dibersihkan
- Ganti email → status verifikasi direset, token verifikasi baru dikirim

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
- `.htaccess` blokir akses langsung ke `config/`, `includes/`, `database/`, `tests/`, `docs/`, `logs/`
- Validasi permission di setiap halaman (`require_permission()`)
- Forgot & reset password dengan token berumur 1 jam
- **Rate Limiting Login** — 5× gagal per akun/IP (15 menit), 20× gagal global per IP (mitigasi password spraying)
- **SMTP Socket Native** — RFC 5321 AUTH LOGIN, CRLF normalisasi, dot-stuffing, StartTLS, fallback otomatis ke `mail()` cPanel
- **Upload Protection** — `.htaccess` default-deny, whitelist hanya `.jpg/.jpeg/.png/.webp/.pdf`, dual Apache 2.2/2.4 syntax
- **Webhook gateway** — token rahasia per kanal, verifikasi server-to-server, payload client tidak dipercaya

### UX & PWA Lanjutan
- **Email Template HTML Responsif** — branding gradasi, tombol CTA (VML fallback Outlook), preheader inbox, footer link portal, plain-text fallback multipart/alternative
- **PWA Install Prompt** — `beforeinstallprompt` handler di `portal.php` (Android/Chrome/Edge), panduan manual "Bagikan → Tambah ke Layar Utama" untuk iOS Safari, dismiss persisten di `localStorage`
- **Offline Fallback & Outbox Sync** — `offline.html` halaman ramah pengguna, `sw.js` IndexedDB outbox + Background Sync API, antrian POST aduan & polling saat offline dikirim otomatis saat online
- **Export CSV/Excel** — CSV memakai UTF-8 BOM + `Content-Disposition: attachment; filename*=UTF-8''...`; Excel dibuat sebagai OOXML `.xlsx` native tanpa ekstensi PHP tambahan
- **Dark Mode Toggle** — CSS custom properties + `data-bs-theme`, persisten di `localStorage`, default mengikuti `prefers-color-scheme`, anti-FOUC inline script di `header.php`

---

## Pengujian

Test integrasi ada di `tests/` (PHP CLI, tanpa framework). Beberapa memerlukan server lokal dan database yang sudah diimpor.

```bash
php tests/gateway_fee_test.php            # aturan biaya layanan QRIS
php tests/gateway_unique_code_test.php    # kode unik tidak masuk buku kas
php tests/gateway_pay_test.php            # alur buat order & bayar
php tests/gopay_otp_test.php              # login OTP GoPay (butuh tests/gobiz_mock.php)
php tests/auth_refresh_test.php           # auto-refresh token GoPay + validasi ShopeePay
php tests/rekap_pembayaran_test.php       # angka Rekap Pembayaran cocok dengan data
php tests/pdf_test.php                    # generate kwitansi PDF
php tests/xlsx_test.php                   # export XLSX
```

Tes parser tanpa database dan tanpa jaringan (bentuk respons asli provider):

```bash
python3 tests/gopay_parse_test.py         # transactions + gross_amount sen
python3 tests/shopeepay_parse_test.py     # data.list + amount "10.002" + status 3
python3 tests/gateway_polling_test.py     # error polling tidak berubah jadi PENDING
```

Beberapa suite memerlukan mock server yang berjalan lebih dulu:

```bash
php -S 127.0.0.1:8098 tests/gq_mock.php        # GoPay/ShopeePay
php -S 127.0.0.1:8097 tests/gobiz_mock.php     # login OTP GoBiz
php -S 127.0.0.1:8099 tests/klikqris_mock.php  # KlikQRIS (opsional)
```

> `tests/gateway_cash_test.php` memerlukan API key sandbox KlikQRIS yang valid; akan gagal dengan `Invalid Sandbox API Key` bila kredensial belum diisi — ini bukan kegagalan kode.
> Folder `tests/` dilindungi dua lapis: `.htaccess` (403 di Apache) dan penjaga `tests/_cli_only.php` yang mengembalikan 404 bila ada file test diakses lewat HTTP di server mana pun. Test hanya bisa dijalankan dari CLI.

## Deploy / Update di cPanel

```bash
cd public_html/arya-green-ipl
git pull origin master
```

Tidak ada migrasi otomatis. Bila rilis menyertakan perubahan skema, jalankan bagian terkait dari `database/schema.sql` secara manual melalui phpMyAdmin **setelah** backup database.

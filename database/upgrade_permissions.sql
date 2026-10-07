-- ============================================================
-- UPGRADE SCRIPT: Permissions untuk Database Existing
-- ============================================================
-- Jalankan sekali saja jika Anda sudah import schema versi lama
-- dan menemukan menu tidak muncul di sidebar.
--
-- Menu yang akan muncul setelah upgrade:
-- - Metode Pembayaran, Buku Kas, Sub-Kas, Pengeluaran
-- - Reminder Email, Aduan Warga, Polling, Kegiatan
-- - Inventaris, Surat RT, Lingkungan
--
-- AMAN: Pakai INSERT IGNORE — tidak akan error jika sudah ada
-- ============================================================

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

-- ============================================================
-- SELESAI
-- ============================================================
-- Setelah import, refresh browser → menu akan muncul sesuai role

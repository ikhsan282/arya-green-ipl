-- Migration 004: Granular permissions untuk environments
-- Mengganti environments.manage dengan view/create/edit/delete

INSERT IGNORE INTO `permissions` (`name`, `label`, `module`) VALUES
  ('environments.view',   'Lihat Lingkungan',   'environments'),
  ('environments.create', 'Tambah Lingkungan',  'environments'),
  ('environments.edit',   'Edit Lingkungan',    'environments'),
  ('environments.delete', 'Hapus Lingkungan',   'environments');

-- Beri ke superadmin (role_id=1) semua env permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`
WHERE `name` IN ('environments.view','environments.create','environments.edit','environments.delete');

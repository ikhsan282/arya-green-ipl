-- Rename role Admin → Ketua, Viewer → Warga
-- Jalankan di database existing

UPDATE `roles` SET `name` = 'ketua', `label` = 'Ketua' WHERE `name` = 'admin';
UPDATE `roles` SET `name` = 'warga', `label` = 'Warga' WHERE `name` = 'viewer';

-- Update permission grants yang hardcode role name
-- (tidak perlu update role_permissions, FK ke roles.id tetap valid)
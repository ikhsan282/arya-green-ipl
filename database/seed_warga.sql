INSERT IGNORE INTO `units` (`unit_type_id`,`unit_number`,`block`,`floor`,`area_sqm`,`status`,`notes`)
SELECT ut.id,s.u,s.b,1,s.a,'kosong','tambahan' FROM (SELECT id,name FROM unit_types) ut JOIN (
  SELECT 'Rumah Type Fresia','H3','H',0.0 UNION ALL
  SELECT 'Rumah Type Cattleya','H15','H',0.0 UNION ALL SELECT 'Rumah Type Cattleya','H16','H',0.0 UNION ALL
  SELECT 'Rumah Type Cattleya','H17','H',0.0 UNION ALL SELECT 'Rumah Type Cattleya','H18','H',0.0 UNION ALL
  SELECT 'Rumah Type Cattleya','H20','H',0.0 UNION ALL
  SELECT 'Rumah Type Cattleya','I2','I',0.0 UNION ALL SELECT 'Rumah Type Cattleya','I3','I',0.0 UNION ALL
  SELECT 'Rumah Type Cattleya','I4','I',0.0 UNION ALL SELECT 'Rumah Type Cattleya','I5','I',0.0 UNION ALL
  SELECT 'Rumah Type Cattleya','I6','I',0.0
) s(t,u,b,a) ON ut.name=s.t;

INSERT IGNORE INTO `residents` (`unit_id`,`name`,`phone`,`email`,`status`,`is_active`,`notes`)
SELECT u.id,s.wname,s.wphone,s.wemail,'pemilik',1,'seed' FROM units u JOIN (
  SELECT 'C','C6','Ikhsan','081398461650','ikhsanm282@gmail.com' UNION ALL
  SELECT 'G','G10','Andri','TEMP-G10',NULL UNION ALL
  SELECT 'G','G15','Anya','TEMP-G15',NULL UNION ALL
  SELECT 'G','G6','Danang','TEMP-G6',NULL UNION ALL
  SELECT 'H','H15','Raffi','TEMP-H15',NULL UNION ALL
  SELECT 'H','H16','Prima','TEMP-H16',NULL UNION ALL
  SELECT 'H','H17','Hadi','TEMP-H17',NULL UNION ALL
  SELECT 'H','H18','Jorgie','TEMP-H18',NULL UNION ALL
  SELECT 'H','H20','Angga','TEMP-H20',NULL UNION ALL
  SELECT 'H','H3','Doddy','TEMP-H3',NULL UNION ALL
  SELECT 'I','I2','Rizal','TEMP-I2',NULL UNION ALL
  SELECT 'I','I3','Anggit','TEMP-I3',NULL UNION ALL
  SELECT 'I','I4','Satria','TEMP-I4',NULL UNION ALL
  SELECT 'I','I5','Gandhi','TEMP-I5',NULL UNION ALL
  SELECT 'I','I6','Buyung','TEMP-I6',NULL
) s(wblock,wunit,wname,wphone,wemail) ON u.block=s.wblock AND u.unit_number=s.wunit;

UPDATE `units` u JOIN `residents` r ON r.unit_id=u.id SET u.status='dihuni';

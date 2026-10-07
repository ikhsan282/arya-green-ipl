-- ============================================================
-- Seed data Unit Arya Green Pamulang / e-Praya 8
-- Source: Siteplan PDF (e-Praya 8_231212_105020-2.pdf)
-- Status default: kosong (unit putih/sold tidak diinject)
-- area_sqm = 0 berarti tidak terbaca dari siteplan
-- ============================================================

INSERT IGNORE INTO `units`
  (`unit_type_id`, `unit_number`, `block`, `floor`, `area_sqm`, `status`, `notes`)
SELECT ut.id, s.unit_number, s.block, 1, s.area_sqm, 'kosong', s.notes
FROM (SELECT name, id FROM unit_types) ut
JOIN (

  -- ============ RUKO / SHOP HOUSES ============
  -- Nomor 4 dan 13 tidak ada di siteplan
  SELECT 'Ruko' t,'RUKO-1' u,'RUKO' b,75.0 a,'siteplan' n UNION ALL
  SELECT 'Ruko','RUKO-2','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-3','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-5','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-6','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-7','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-8','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-9','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-10','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-11','RUKO',85.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-12','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-14','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-15','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-16','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-17','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-18','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-19','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-20','RUKO',75.0,'siteplan' UNION ALL
  SELECT 'Ruko','RUKO-21','RUKO',0.0,'siteplan' UNION ALL

  -- ============ BLOK A — Fresia (A1-A5, kuning) ============
  SELECT 'Rumah Type Fresia','A1','A',94.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','A2','A',91.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','A3','A',107.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','A5','A',135.0,'siteplan' UNION ALL

  -- ============ BLOK A — Navulia (A6-A19, teal) ============
  SELECT 'Rumah Type Navulia','A6','A',62.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A7','A',63.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A8','A',63.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A9','A',64.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A10','A',65.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A11','A',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A12','A',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A13','A',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A14','A',142.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A15','A',142.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A17','A',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A18','A',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A19','A',124.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','A20','A',108.0,'siteplan' UNION ALL

  -- ============ BLOK C — Navulia (teal) ============
  SELECT 'Rumah Type Navulia','C1','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C2','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C3','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C5','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C6','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C7','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C8','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C9','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C10','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C11','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C12','C',90.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C14','C',159.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C15','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C16','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C17','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C18','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C19','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C20','C',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','C21','C',60.0,'siteplan' UNION ALL

  -- ============ BLOK D — Navulia (teal) ============
  SELECT 'Rumah Type Navulia','D8','D',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','D9','D',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','D10','D',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','D11','D',118.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','D12','D',127.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','D14','D',60.0,'siteplan' UNION ALL

  -- ============ BLOK G — Navulia (teal) ============
  SELECT 'Rumah Type Navulia','G1','G',141.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G2','G',63.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G3','G',62.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G4','G',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G5','G',61.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G6','G',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G7','G',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G8','G',96.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G9','G',106.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G10','G',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G11','G',75.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G12','G',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G13','G',0.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G14','G',77.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G15','G',77.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Navulia','G16','G',134.0,'siteplan' UNION ALL

  -- ============ BLOK H — Fresia (kuning) + Cattleya (merah) ============
  SELECT 'Rumah Type Fresia','H1','H',111.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','H4','H',76.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','H5','H',75.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','H6','H',75.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','H7','H',74.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','H8','H',73.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','H9','H',72.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Fresia','H10','H',66.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','H11','H',85.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','H12','H',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','H13','H',61.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','H14','H',61.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','H21','H',66.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','H22','H',106.0,'siteplan' UNION ALL

  -- ============ BLOK I — Cattleya (merah) ============
  SELECT 'Rumah Type Cattleya','I1','I',115.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','I8','I',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','I9','I',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','I10','I',60.0,'siteplan' UNION ALL
  SELECT 'Rumah Type Cattleya','I11','I',82.0,'siteplan'

) AS s ON ut.name = s.t;

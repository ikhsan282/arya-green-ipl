# Data Unit Arya Green Pamulang

79 unit dari siteplan e-Praya 8 (PDF vision extraction, 400 DPI).

## Import

```bash
# Via MySQL CLI
mysql -u root -p db_arya_green_ipl < database/units_seed.sql

# Via phpMyAdmin
# Import tab → pilih units_seed.sql → Go
```

## Summary

| Blok | Tipe | Unit | Rata² Luas |
|------|------|------|------------|
| RUKO | Ruko | 19 | 75-85 m² |
| A | Fresia | 4 | 91-135 m² |
| A | Navulia | 14 | 62-142 m² |
| C | Navulia | 19 | 60-159 m² |
| D | Navulia | 6 | 60-127 m² |
| G | Navulia | 16 | 60-141 m² |
| H | Fresia | 8 | 66-111 m² |
| H | Cattleya | 6 | 60-106 m² |
| I | Cattleya | 5 | 60-115 m² |
| **Total** | | **97** | |

**Tidak diinject**: unit putih/sold (H2-H3, H15-H20, I2-I7), blok B/E/F/J (partial/tidak terbaca jelas).

**area_sqm = 0**: RUKO-21, G4, G6, G10, G12, G13, A11-A13, A17-A18 — perlu update manual via form edit.

## Verifikasi

```sql
SELECT block, COUNT(*) cnt FROM units GROUP BY block ORDER BY block;
```

## Next

1. Import SQL ke database
2. Cek di `pages/units/index.php`
3. Update luas tanah yang masih 0 via form edit
4. Set status unit (dihuni/dijual) sesuai kondisi aktual

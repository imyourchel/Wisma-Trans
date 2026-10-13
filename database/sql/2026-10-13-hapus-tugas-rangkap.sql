-- Tugas rangkap staf Housekeeping kini dibaca dari peran (user_roles), bukan isian harian, sehingga kolom
-- staff_availabilities.secondary_duty tidak dipakai lagi (menyimpannya berarti data ganda).
-- Jalankan SETELAH 2026-10-13-batas-beban-hk.sql (langkah 1 membaca settings dari file itu). Aman dijalankan ulang.
-- Sebaiknya cadangkan tabel staff_availabilities dulu: kolom yang dihapus tidak bisa dikembalikan.

-- 1. Batas beban yang sama dengan batas otomatis staf itu bukan isian manual: kosongkan (NULL) supaya ikut settings.
--    Batas otomatis = hk_max_workload_multirole bila staf juga punya peran F&B atau Front Office, selain itu hk_max_workload.
UPDATE staff_availabilities sa
SET sa.max_workload = NULL
WHERE sa.max_workload IS NOT NULL
  AND sa.max_workload = (
      SELECT CAST(REPLACE(s.value, ',', '.') AS DECIMAL(6,2))
      FROM settings s
      WHERE s.setting_key = IF(
          EXISTS (
              SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = sa.user_id AND r.code IN ('fnb', 'front_office')
          ),
          'hk_max_workload_multirole',
          'hk_max_workload'
      )
  );

-- 2. Hapus kolom tugas rangkap.
ALTER TABLE staff_availabilities DROP COLUMN IF EXISTS secondary_duty;

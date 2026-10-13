-- Batas beban otomatis staf Housekeeping (Smart Cleaning, Workload Balancing).
-- Hanya mengubah baris data di settings (tanpa mengubah struktur). Aman dijalankan ulang.
-- Nilainya bisa diubah dari halaman Pengaturan (kelompok "Housekeeping").
-- Urutan pemakaian (App\Services\SmartCleaning\BatasBebanStaf): isian manager di Ketersediaan Staf, lalu multi-role
-- (HK + F&B dan/atau Front Office, dibaca dari user_roles), lalu HK murni.
-- Jalankan file ini SEBELUM 2026-10-13-hapus-tugas-rangkap.sql.
INSERT IGNORE INTO settings (setting_key, value, type, description) VALUES
    ('hk_max_workload', '4', 'decimal', 'Batas beban harian staf Housekeeping murni (poin)'),
    ('hk_max_workload_multirole', '2', 'decimal', 'Batas beban harian staf multi-role: Housekeeping + F&B dan/atau Front Office (poin)');

-- Versi pertama file ini memakai 3 untuk multi-role dan tingkat "merangkap hari ini"; keduanya diganti.
-- Hanya angka 3 bawaan lama yang diubah, angka lain berarti sudah diatur owner lewat Pengaturan.
UPDATE settings
SET value = '2', description = 'Batas beban harian staf multi-role: Housekeeping + F&B dan/atau Front Office (poin)'
WHERE setting_key = 'hk_max_workload_multirole' AND value IN ('3', '3.0', '3.00');

DELETE FROM settings WHERE setting_key = 'hk_max_workload_rangkap';

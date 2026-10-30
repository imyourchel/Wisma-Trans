-- Early check-in: tamu yang tiba di hari kedatangan mulai pukul 06.00 boleh langsung masuk bila kamarnya sudah Siap,
-- tanpa biaya tambahan. Kedatangan sebelum jam ini dihitung menginap malam sebelumnya.
-- Hanya menambah baris data (tanpa mengubah struktur). Aman dijalankan ulang.
-- Nilainya bisa diubah dari halaman Pengaturan (kelompok "Check-in dan check-out"); bila baris ini belum ada,
-- halaman Pengaturan memakai bawaan 06:00 dan membuat barisnya saat pertama disimpan.
INSERT IGNORE INTO settings (setting_key, value, type, description) VALUES
    ('early_check_in_from', '06:00', 'time', 'Jam mulai early check-in, hanya untuk kamar berstatus Siap');

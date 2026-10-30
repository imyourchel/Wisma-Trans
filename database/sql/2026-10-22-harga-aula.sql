-- Tarif dan kapasitas sewa aula.
-- Hanya menambah/mengubah baris data (tanpa mengubah struktur). Aman dijalankan ulang.
-- Harga dihitung per hari (full day); kisaran tampil di beranda dan halaman /aula, harga akhir tetap disepakati staf
-- dan ditetapkan Manager per pemesanan. Nilainya bisa diubah dari halaman Pengaturan (kelompok "Sewa aula").
INSERT IGNORE INTO settings (setting_key, value, type, description) VALUES
    ('hall_price_min', '1000000', 'decimal', 'Harga sewa aula terendah per hari (full day)'),
    ('hall_price_max', '2500000', 'decimal', 'Harga sewa aula tertinggi per hari (full day)');

-- Kapasitas maksimal aula 100 orang (Wisma Trans hanya punya satu aula)
UPDATE halls SET capacity = 100 WHERE is_active = 1;

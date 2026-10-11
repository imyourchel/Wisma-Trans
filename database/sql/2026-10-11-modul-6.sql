-- Modul 6 Smart Cleaning Priority (AHP-SAW) dan Workload Balancing (Greedy)
-- Jalankan sekali ke database utama (ta_3). Tidak ada tabel atau kolom baru.

-- 1. Satu kamar bisa dibersihkan dua kali dalam sehari (misalnya pagi setelah check-out, lalu sore
--    setelah tamu baru minta dibersihkan), jadi pasangan tanggal-kamar tidak lagi unik.
--    Indeks biasa dibuat lebih dulu supaya kolom room_id tetap punya indeks selama UNIQUE dilepas.
ALTER TABLE housekeeping_tasks ADD INDEX idx_hktasks_date_room (task_date, room_id);
ALTER TABLE housekeeping_tasks DROP INDEX uq_hktasks_date_room;

-- 2. Nama dan keterangan kriteria disamakan dengan naskah skripsi.
UPDATE cleaning_criteria SET name = 'Waktu menuju check-in tamu berikutnya', attribute_type = 'cost',
    description = 'Menit sampai jam check-in tamu berikutnya; tanpa kedatangan hari ini = 1440' WHERE code = 'C1';
UPDATE cleaning_criteria SET name = 'Status kamar', attribute_type = 'benefit',
    description = 'Kosong kotor = 3, terisi kotor = 2' WHERE code = 'C2';
UPDATE cleaning_criteria SET name = 'Durasi kamar sudah kotor', attribute_type = 'benefit',
    description = 'Menit sejak kamar menjadi kotor (rooms.dirty_since)' WHERE code = 'C3';

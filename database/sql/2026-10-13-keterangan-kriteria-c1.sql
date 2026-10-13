-- Keterangan kriteria C1 ditulis ulang supaya mudah dipahami staf (halaman Kriteria dan Bobot Kriteria).
-- Jalankan sekali ke database utama (ta_3). Hanya mengubah teks; nilai, sifat, dan bobot kriteria tidak berubah.
-- Aman dijalankan ulang.

UPDATE cleaning_criteria
SET description = 'Berapa menit lagi tamu berikutnya check-in di kamar ini; makin sedikit, makin mendesak. Bila hari ini tidak ada tamu yang akan masuk, dihitung 1.440 menit (24 jam).'
WHERE code = 'C1';

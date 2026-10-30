-- Extra bed TIDAK termasuk sarapan; sarapan tamu extra bed dipesan terpisah sebagai Sarapan tambahan.
-- Hanya mengubah baris data (tanpa mengubah struktur). Aman dijalankan ulang. Harga tidak diubah.
-- Halaman pemesanan memakai teks dari App\Services\Reservasi\TambahanKamar; keterangan ini untuk Data Master > Layanan.
UPDATE services SET description = 'Kasur tambahan, maksimal 1 per kamar. Belum termasuk sarapan.' WHERE code = 'EXB01';
UPDATE services SET description = 'Satu kali sarapan untuk 1 orang, disajikan setiap pagi selama menginap.' WHERE code = 'SRP01';

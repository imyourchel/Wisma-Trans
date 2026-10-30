-- Penomoran, tipe, dan jenis kasur kamar disamakan dengan daftar kamar Wisma Trans (32 kamar).
-- Setiap baris kamar tetap memakai id yang sama, jadi riwayat status, tugas housekeeping, laporan kerusakan, dan reservasinya
-- ikut kamar itu; yang berubah hanya nomor, tipe, jenis kasur, dan lantai.
--   Nomor lama yang tidak dipakai lagi: 113 -> 117, 114 -> 301, 116 -> 302, 204 -> 303, 211 -> 304, 212 -> 305, 213 -> 306.
--   Pelengkap sampai 32 kamar: 201 Family King, 202 Family Triple Single, 203 VIP King.
--   Hasil: Standard 11, Deluxe 12, Family 7, VIP 2.
-- Baris reservasi yang masih berjalan (dipesan atau menginap) ikut tipe kamar barunya; harga per malam tidak diubah.
-- Tiap UPDATE memeriksa nomor lama, jadi menjalankan ulang berkas ini tidak mengubah apa pun.
-- Jalankan sekali di ta_3 (buat cadangan dulu).

START TRANSACTION;

SET @std = (SELECT id FROM room_types WHERE code = 'STD');
SET @dlx = (SELECT id FROM room_types WHERE code = 'DLX');
SET @fam = (SELECT id FROM room_types WHERE code = 'FAM');
SET @vip = (SELECT id FROM room_types WHERE code = 'VIP');

-- Nomor tetap, tipe atau kasur disesuaikan
UPDATE rooms SET room_type_id = @std, bed_type = 'twin_single', floor = 1 WHERE room_number = '101';
UPDATE rooms SET room_type_id = @std, bed_type = 'twin_single', floor = 1 WHERE room_number = '102';
UPDATE rooms SET room_type_id = @std, bed_type = 'twin_single', floor = 1 WHERE room_number = '103';
UPDATE rooms SET room_type_id = @std, bed_type = 'twin_single', floor = 1 WHERE room_number = '104';
UPDATE rooms SET room_type_id = @vip, bed_type = 'king',        floor = 1 WHERE room_number = '105';
UPDATE rooms SET room_type_id = @fam, bed_type = 'king',        floor = 1 WHERE room_number = '106';
UPDATE rooms SET room_type_id = @dlx, bed_type = 'twin_double', floor = 1 WHERE room_number = '107';
UPDATE rooms SET room_type_id = @dlx, bed_type = 'twin_double', floor = 1 WHERE room_number = '108';
UPDATE rooms SET room_type_id = @fam, bed_type = 'triple_single', floor = 1 WHERE room_number = '109';
UPDATE rooms SET room_type_id = @std, bed_type = 'double',      floor = 1 WHERE room_number = '110';
UPDATE rooms SET room_type_id = @std, bed_type = 'queen',       floor = 1 WHERE room_number = '111';
UPDATE rooms SET room_type_id = @fam, bed_type = 'twin_queen',  floor = 1 WHERE room_number = '112';
UPDATE rooms SET room_type_id = @std, bed_type = 'double',      floor = 1 WHERE room_number = '115';
UPDATE rooms SET room_type_id = @fam, bed_type = 'king',        floor = 2 WHERE room_number = '201';
UPDATE rooms SET room_type_id = @fam, bed_type = 'triple_single', floor = 2 WHERE room_number = '202';
UPDATE rooms SET room_type_id = @vip, bed_type = 'king',        floor = 2 WHERE room_number = '203';
UPDATE rooms SET room_type_id = @std, bed_type = 'queen',       floor = 2 WHERE room_number = '205';
UPDATE rooms SET room_type_id = @std, bed_type = 'queen',       floor = 2 WHERE room_number = '206';
UPDATE rooms SET room_type_id = @std, bed_type = 'queen',       floor = 2 WHERE room_number = '207';
UPDATE rooms SET room_type_id = @dlx, bed_type = 'queen',       floor = 2 WHERE room_number = '208';
UPDATE rooms SET room_type_id = @dlx, bed_type = 'king',        floor = 2 WHERE room_number = '209';
UPDATE rooms SET room_type_id = @fam, bed_type = 'twin_queen',  floor = 2 WHERE room_number = '210';
UPDATE rooms SET room_type_id = @std, bed_type = 'queen',       floor = 2 WHERE room_number = '214';
UPDATE rooms SET room_type_id = @fam, bed_type = 'twin_queen',  floor = 2 WHERE room_number = '215';
UPDATE rooms SET room_type_id = @dlx, bed_type = 'king',        floor = 2 WHERE room_number = '216';

-- Nomor lama yang tidak dipakai lagi mendapat nomor baru
UPDATE rooms SET room_number = '117', room_type_id = @dlx, bed_type = 'king',        floor = 1 WHERE room_number = '113';
UPDATE rooms SET room_number = '301', room_type_id = @dlx, bed_type = 'twin_single', floor = 3 WHERE room_number = '114';
UPDATE rooms SET room_number = '302', room_type_id = @dlx, bed_type = 'twin_single', floor = 3 WHERE room_number = '116';
UPDATE rooms SET room_number = '303', room_type_id = @dlx, bed_type = 'twin_single', floor = 3 WHERE room_number = '204';
UPDATE rooms SET room_number = '304', room_type_id = @dlx, bed_type = 'twin_single', floor = 3 WHERE room_number = '211';
UPDATE rooms SET room_number = '305', room_type_id = @dlx, bed_type = 'twin_single', floor = 3 WHERE room_number = '212';
UPDATE rooms SET room_number = '306', room_type_id = @dlx, bed_type = 'king',        floor = 3 WHERE room_number = '213';

-- Reservasi yang masih berjalan ikut tipe kamar barunya (harga per malam tetap)
UPDATE reservation_rooms rr
JOIN rooms r ON r.id = rr.room_id
SET rr.room_type_id = r.room_type_id
WHERE rr.status IN ('booked', 'checked_in') AND rr.room_type_id <> r.room_type_id;

COMMIT;

-- Periksa: harus 32 kamar (STD 11, DLX 12, FAM 7, VIP 2)
SELECT t.code, COUNT(*) AS jumlah FROM rooms r JOIN room_types t ON t.id = r.room_type_id GROUP BY t.code ORDER BY t.code;

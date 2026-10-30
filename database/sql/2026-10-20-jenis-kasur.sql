-- Jenis kasur baru (rooms.bed_type) untuk pemilihan kasur saat memesan.
-- ta_3 sudah memakai nilai ini. Jalankan hanya pada basis data lain yang masih memakai
-- enum lama ('single','twin','double','king'), misalnya wisma_trans_manual_test.
-- Pembagian per nomor kamar sama dengan ta_3. Cadangkan dulu sebelum menjalankan.

-- 1. Perluas enum agar nilai lama dan baru sama-sama sah
ALTER TABLE rooms MODIFY bed_type ENUM('single','twin','double','king','twin_single','queen','twin_double','twin_queen','triple_single') NOT NULL DEFAULT 'double';

-- 2. Isi jenis kasur per kamar
UPDATE rooms SET bed_type = 'twin_single'   WHERE room_number IN ('101','104','107','110');
UPDATE rooms SET bed_type = 'double'        WHERE room_number IN ('102','105','108');
UPDATE rooms SET bed_type = 'queen'         WHERE room_number IN ('103','106','109');
UPDATE rooms SET bed_type = 'twin_double'   WHERE room_number IN ('111','113','115','201','203','205','207','209');
UPDATE rooms SET bed_type = 'king'          WHERE room_number IN ('112','114','116','202','204','206','208','210','213','215','216');
UPDATE rooms SET bed_type = 'twin_queen'    WHERE room_number IN ('211','214');
UPDATE rooms SET bed_type = 'triple_single' WHERE room_number = '212';
-- Kamar lain yang masih bernilai lama
UPDATE rooms SET bed_type = 'twin_single' WHERE bed_type IN ('single','twin');

-- 3. Batasi ke nilai baru
ALTER TABLE rooms MODIFY bed_type ENUM('twin_single','double','queen','twin_double','king','twin_queen','triple_single') NOT NULL DEFAULT 'double';

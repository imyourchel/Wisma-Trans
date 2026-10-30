-- Galeri foto tipe kamar: menghubungkan berkas di storage/app/public/kamar/ ke room_types.photos.
-- Hanya mengubah data, tanpa mengubah struktur. Aman dijalankan ulang.
-- Hanya mengisi tipe yang belum punya foto unggahan (nama data contoh seperti "std-1.jpg" tidak berisi "/"),
-- jadi galeri yang sudah diatur Admin tidak tertimpa.
--
-- Isi folder (nama berkas jenis kasur = nilai rooms.bed_type):
--   kamar/ruangan/{bed_type}.png    kamar tampak dari pintu
--   kamar/kasur/{bed_type}.png      kasurnya (juga tampil di kartu "Pilih jenis kasur")
--   kamar/fasilitas/*.png           sama untuk semua kamar
--   kamar/condiment/condiment_N.png N = kapasitas kamar (2, 3, atau 4); Family juga memuat condiment_3 untuk kamar Triple Single
-- Urutan galeri: foto pertama = foto utama. Jenis kasur per tipe sesuai pembagian kamar di 2026-10-20-jenis-kasur.sql.

UPDATE room_types SET photos = CONCAT('[',
    '"kamar/ruangan/twin_single.png","kamar/ruangan/double.png","kamar/ruangan/queen.png",',
    '"kamar/kasur/twin_single.png","kamar/kasur/double.png","kamar/kasur/queen.png",',
    '"kamar/fasilitas/kamar_mandi.png","kamar/fasilitas/shower.png","kamar/fasilitas/perlengkapan_mandi.png","kamar/fasilitas/tv_meja_lemari.png","kamar/fasilitas/kulkas.png",',
    '"kamar/condiment/condiment_', LEAST(GREATEST(max_capacity, 2), 4), '.png"]')
WHERE code = 'STD' AND (photos IS NULL OR photos NOT LIKE '%/%');

UPDATE room_types SET photos = CONCAT('[',
    '"kamar/ruangan/twin_double.png","kamar/ruangan/king.png",',
    '"kamar/kasur/twin_double.png","kamar/kasur/king.png",',
    '"kamar/fasilitas/kamar_mandi.png","kamar/fasilitas/shower.png","kamar/fasilitas/perlengkapan_mandi.png","kamar/fasilitas/tv_meja_lemari.png","kamar/fasilitas/kulkas.png",',
    '"kamar/condiment/condiment_', LEAST(GREATEST(max_capacity, 2), 4), '.png"]')
WHERE code = 'DLX' AND (photos IS NULL OR photos NOT LIKE '%/%');

UPDATE room_types SET photos = CONCAT('[',
    '"kamar/ruangan/twin_queen.png","kamar/ruangan/triple_single.png","kamar/ruangan/king.png",',
    '"kamar/kasur/twin_queen.png","kamar/kasur/triple_single.png","kamar/kasur/king.png",',
    '"kamar/fasilitas/kamar_mandi.png","kamar/fasilitas/shower.png","kamar/fasilitas/perlengkapan_mandi.png","kamar/fasilitas/tv_meja_lemari.png","kamar/fasilitas/kulkas.png",',
    '"kamar/condiment/condiment_', LEAST(GREATEST(max_capacity, 2), 4), '.png",',
    -- Kamar Triple Single (3 kasur single) untuk 3 orang
    '"kamar/condiment/condiment_3.png"]')
WHERE code = 'FAM' AND (photos IS NULL OR photos NOT LIKE '%/%');

UPDATE room_types SET photos = CONCAT('[',
    '"kamar/ruangan/king.png",',
    '"kamar/kasur/king.png",',
    '"kamar/fasilitas/kamar_mandi.png","kamar/fasilitas/shower.png","kamar/fasilitas/perlengkapan_mandi.png","kamar/fasilitas/tv_meja_lemari.png","kamar/fasilitas/kulkas.png",',
    '"kamar/condiment/condiment_', LEAST(GREATEST(max_capacity, 2), 4), '.png"]')
WHERE code = 'VIP' AND (photos IS NULL OR photos NOT LIKE '%/%');

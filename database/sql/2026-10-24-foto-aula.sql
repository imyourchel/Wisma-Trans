-- Galeri foto aula: menghubungkan berkas di storage/app/public/aula/ ke halls.photos.
-- Hanya mengubah data, tanpa mengubah struktur. Aman dijalankan ulang.
-- Hanya mengisi aula yang belum punya foto unggahan (nama data contoh seperti "aula-1.jpg" tidak berisi "/"),
-- jadi galeri yang sudah diatur Admin (Data Master > Aula) tidak tertimpa.
-- Urutan: foto pertama = foto utama (beranda dan halaman /aula).
--   kelas.png               susunan kelas (meja dan kursi), seluruh ruangan
--   teater.png              kursi berbaris menghadap depan
--   meja_bundar.png         meja bundar untuk syukuran/acara keluarga
--   panggung.png            area depan: meja pembicara, podium, layar proyektor, sound system
--   detail_meja_bundar.png  detail meja bundar dan sarung kursi

UPDATE halls
SET photos = '["aula/kelas.png","aula/teater.png","aula/meja_bundar.png","aula/panggung.png","aula/detail_meja_bundar.png"]'
WHERE name = 'Aula Utama' AND (photos IS NULL OR photos NOT LIKE '%/%');

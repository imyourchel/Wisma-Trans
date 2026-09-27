# Pengujian Black-Box Modul Master Kamar — SIM Wisma Trans

## Ringkasan

| Bagian | Jumlah kasus | Otomatis | Manual |
|---|---|---|---|
| A. Tipe Kamar | 17 | 16 | 1 |
| B. Kamar | 13 | 11 | 2 |
| C. Papan Status | 8 | 4 | 4 |
| D. Perpindahan Status | 17 | 16 | 1 |
| E. `dirty_since` | 4 | 4 | 0 |
| F. Riwayat | 4 | 3 | 1 |
| G. Hak Akses Halaman | 9 | 8 | 1 |
| **Jumlah** | **72** | **62** | **10** |

**Hasil `php artisan test`** (database tes `wisma_trans_test`, terpisah dari database utama):

```
Tests:    64 passed (172 assertions)
Duration: 32.00s
```

64 tes terdiri atas 62 tes modul ini dan 2 tes contoh bawaan Laravel. Nama tiap tes memakai ID kasus, misalnya `test_TK02_kode_tipe_kembar_ditolak`. Letaknya:

| Berkas tes | Kasus |
|---|---|
| `tests/Feature/TipeKamarTest.php` | TK-01 s.d. TK-16 |
| `tests/Feature/KamarTest.php` | KM-01, KM-03 s.d. KM-12 |
| `tests/Feature/PapanStatusKamarTest.php` | PS-01, PS-02, PS-07, PS-08, ST-04, ST-15, RW-02, RW-03 |
| `tests/Feature/RoomStatusServiceTest.php` | ST-01 s.d. ST-16 (kecuali ST-04 dan ST-15), DS-01 s.d. DS-04, RW-01 |
| `tests/Feature/HakAksesKamarTest.php` | HA-01 s.d. HA-08 |

**Data uji** mengikuti `SIM_Wisma_Trans_Struktur_dan_Data.sql`:
- Waktu acuan Selasa, 6 Oktober 2026 pukul 10.00 WITA.
- 4 tipe kamar dan 32 kamar.
- Status awal: 103 Perbaikan; 105, 112, dan 201 Kosong, kotor; 202 Sedang dibersihkan; 203 Terisi, perlu dibersihkan; 204–208 Terisi; sisanya Siap.

Semua akun berkata sandi `password`:

| Peran | Akun |
|---|---|
| Admin | admin@wismatrans.id (Hendra Wijaya) |
| Manager | manager@wismatrans.id (Lina Hartono) |
| Front Office | yusuf@wismatrans.id (Yusuf Ramadhan) |
| Housekeeping | rahmat@wismatrans.id (Rahmat Hidayat) |
| F&B | maya@wismatrans.id (Maya Sari) |
| Customer | citra@contoh.id (Citra Lestari) |

Tes otomatis juga memakai satu akun uji tambahan dengan peran Front Office + Housekeeping untuk ST-14.

**Catatan aturan:**
- Foto tipe kamar tidak dibatasi jumlah maupun ukurannya. Batas yang tersisa hanya dari server PHP (`upload_max_filesize` dan `post_max_size` = 40 MB per kiriman). Karena itu TK-11 dan TK-12 menguji bahwa foto besar dan lebih dari 5 foto **diterima**.
- Menu sidebar: **Data Master → Tipe Kamar / Kamar** (Admin) dan **Operasional → Papan Status Kamar** (Admin, Manager, FO, HK). Alamat halaman: `/staf/tipe-kamar`, `/staf/kamar`, `/staf/papan-status-kamar`.

---

## A. Tipe Kamar (UC-MK01)

| ID | Skenario | Langkah | Data uji | Hasil diharapkan | Hasil aktual | Status |
|---|---|---|---|---|---|---|
| TK-01 | Tambah tipe dengan data valid | Login Admin → Data Master → Tipe Kamar → Tambah tipe kamar → isi → Simpan | `SUP`, Superior, 420000, 2, AC + TV | Tersimpan dengan 2 fasilitas, muncul di daftar, tampil "Tipe kamar berhasil ditambahkan." | | |
| TK-02 | Kode kembar | Tambah tipe kamar → isi kode yang sudah ada → Simpan | `STD` | Gagal: "Kode tipe kamar sudah dipakai." | | |
| TK-03 | Kode huruf kecil | Tambah tipe kamar → ketik kode huruf kecil → Simpan | `sup` | Tersimpan sebagai `SUP` | | |
| TK-04 | Kolom wajib kosong | Tambah tipe kamar → kosongkan nama, harga, kapasitas → Simpan | nama, harga, kapasitas kosong | Gagal, pesan tampil di samping tiap kolom | | |
| TK-05 | Harga negatif | Tambah tipe kamar → isi harga negatif → Simpan | -1000 | Gagal validasi pada harga dasar | | |
| TK-06 | Kapasitas di luar batas | Tambah tipe kamar → isi kapasitas 0, lalu 11 → Simpan | 0 dan 11 | Keduanya gagal validasi pada kapasitas | | |
| TK-07 | Ubah harga dasar | Ubah DLX → harga 480000 → Simpan | DLX → 480000 | Harga berubah; 15 kamar DLX tidak berubah (tipe, status, aktif) | | |
| TK-08 | Ubah fasilitas | Ubah STD → centang Water Heater → Simpan | STD + Water Heater | Fasilitas STD menjadi 5 (AC, Kloset, TV, Water Heater, dan WiFi yang tetap dipertahankan) | | |
| TK-09 | Unggah foto valid | Ubah STD → Tambah foto → pilih 2 berkas → Simpan | 2 berkas jpg | Tersimpan di disk `public` (`storage/app/public/room-types`), daftar foto bertambah 2 | | |
| TK-10 | Unggah berkas bukan gambar | Ubah STD → Tambah foto → pilih PDF → Simpan | `nota.pdf` | Gagal: "Berkas harus berupa gambar." | | |
| TK-11 | Unggah foto besar | Ubah STD → Tambah foto → pilih foto 3 MB → Simpan | foto 3 MB | Diterima (tanpa batas ukuran) | | |
| TK-12 | Lebih dari 5 foto | Ubah tipe yang sudah punya 4 foto → tambah 2 foto → Simpan | 4 + 2 foto | Diterima, tipe punya 6 foto (tanpa batas jumlah) | | |
| TK-13 | Jadikan foto utama | Ubah tipe → tekan ★ pada foto ke-2 → Simpan | foto ke-2 | Foto itu pindah ke urutan pertama dan menjadi thumbnail di daftar | | |
| TK-14 | Hapus foto | Ubah tipe → tekan × pada foto ke-1 → Simpan | foto ke-1 | Foto hilang dari daftar dan berkasnya terhapus dari disk | | |
| TK-15 | Hapus tipe yang masih punya kamar | Klik Hapus pada STD | STD | Ditolak: "Tipe kamar ini masih dipakai oleh 10 kamar. Pindahkan atau nonaktifkan kamarnya dulu." Data tetap ada | | |
| TK-16 | Hapus tipe tanpa kamar | Klik Hapus pada SUP → konfirmasi Hapus | SUP dari TK-01 | Tipe, relasi fasilitas, dan berkas fotonya terhapus | | |
| TK-17 | Tampilan harga | Buka daftar Tipe Kamar | 360000 | Tampil `Rp360.000` | | |

## B. Kamar (UC-MK02)

| ID | Skenario | Langkah | Data uji | Hasil diharapkan | Hasil aktual | Status |
|---|---|---|---|---|---|---|
| KM-01 | Tambah kamar valid | Login Admin → Data Master → Kamar → Tambah kamar → isi → Simpan | 217, VIP, King | Tersimpan, status Siap, aktif, tampil "Kamar berhasil ditambahkan." | | |
| KM-02 | Lantai otomatis | Tambah kamar → ketik nomor | 305 | Kolom lantai terisi 3 | | |
| KM-03 | Nomor kembar | Tambah kamar → isi nomor yang sudah ada → Simpan | 101 | Gagal: "Nomor kamar sudah dipakai." | | |
| KM-04 | Nomor dengan simbol | Tambah kamar → isi nomor bersimbol → Simpan | `10-1` | Gagal: "Nomor kamar hanya boleh huruf dan angka." | | |
| KM-05 | Tipe tidak ada | Kirim formulir dengan tipe yang tidak ada (diubah lewat alat pengembang browser) | `room_type_id` = 999 | Gagal validasi pada tipe kamar | | |
| KM-06 | Jenis kasur tidak valid | Kirim formulir dengan jenis kasur di luar pilihan (lewat alat pengembang) | `queen` | Gagal validasi pada jenis kasur | | |
| KM-07 | Ubah tipe kamar | Ubah kamar 110 → pilih Deluxe Room → Simpan | 110: STD → DLX | Tipe berubah, status tetap Siap | | |
| KM-08 | Status tidak ikut terubah lewat form | Kirim formulir ubah 101 dengan tambahan `status=maintenance` | `status=maintenance` | Status 101 tetap Siap | | |
| KM-09 | Nonaktifkan kamar kosong | Klik Nonaktifkan pada 101 → konfirmasi | 101 | Kamar nonaktif, hilang dari Papan Status, tetap ada di daftar Kamar (saringan "Nonaktif") | | |
| KM-10 | Nonaktifkan kamar terisi | Klik Nonaktifkan pada 204 → konfirmasi | 204 (Terisi) | Ditolak: "Kamar sedang terisi tamu, tidak bisa dinonaktifkan." | | |
| KM-11 | Aktifkan lagi | Saring Nonaktif → klik Aktifkan pada 101 | 101 | Kamar aktif, status sama seperti sebelumnya | | |
| KM-12 | Tidak ada fitur hapus | Tidak ada tombol Hapus di daftar; kirim `DELETE /staf/kamar/1` | `DELETE /staf/kamar/1` | 404 atau 405, data tetap 32 kamar | | |
| KM-13 | Filter dan pencarian | Pilih lantai 2 dan status Kosong, kotor | lantai 2, `vacant_dirty` | Hanya kamar 201 yang tampil | | |

## C. Papan Status (UC-MK03)

| ID | Skenario | Langkah | Data uji | Hasil diharapkan | Hasil aktual | Status |
|---|---|---|---|---|---|---|
| PS-01 | Ringkasan per status | Login Admin → Operasional → Papan Status Kamar | data contoh | Siap 21, Terisi 5, Terisi perlu dibersihkan 1, Kosong kotor 3, Sedang dibersihkan 1, Perbaikan 1 | | |
| PS-02 | Kamar nonaktif tidak tampil | Nonaktifkan 101 di halaman Kamar → buka Papan Status | 101 nonaktif | 101 tidak ada di papan, "31 kamar aktif" | | |
| PS-03 | Pengelompokan lantai | Buka Papan Status | data contoh | Lantai 1: 101–116, Lantai 2: 201–216 | | |
| PS-04 | Lama kotor | Buka Papan Status pukul 10.00 pada 6 Okt 2026 | 105 kotor sejak 07.15 | Kotak 105 menampilkan "kotor 2 jam 45 mnt" | | |
| PS-05 | Saring per status | Klik kartu "Kosong, kotor"; klik lagi untuk melepas | kartu Kosong, kotor | Hanya 105, 112, 201; klik kedua menampilkan semua lagi | | |
| PS-06 | Muat ulang otomatis | Buka papan di dua browser → ubah status 105 di browser kedua | 105 | Papan di browser pertama berubah paling lambat 30 detik (tidak berubah selama panel detail terbuka) | | |
| PS-07 | Pilihan status sesuai aturan | Login HK → Papan Status → klik 105 | 105 (Kosong, kotor) | Pilihan hanya Sedang dibersihkan dan Perbaikan | | |
| PS-08 | Manager hanya melihat | Login Manager → Papan Status → klik 105 | 105 | Detail dan riwayat tampil, tanpa pilihan status dan tombol Simpan | | |

## D. Perpindahan Status (UC-MK04)

| ID | Skenario | Langkah | Data uji | Hasil diharapkan | Hasil aktual | Status |
|---|---|---|---|---|---|---|
| ST-01 | Mulai membersihkan | Login HK → Papan Status → klik 105 → pilih Sedang dibersihkan → Simpan | 105: Kosong, kotor → Sedang dibersihkan | Berhasil, 1 riwayat baru | | |
| ST-02 | Selesai, kamar kosong | Login HK → klik 105 → pilih Siap → Simpan | 105: Sedang dibersihkan → Siap | Berhasil | | |
| ST-03 | Selesai, tamu masih ada | Login HK → klik 203 → Sedang dibersihkan → Simpan → klik 203 → Terisi → Simpan | 203: Terisi perlu dibersihkan → Sedang dibersihkan → Terisi | Berhasil, 2 riwayat baru | | |
| ST-04 | Saran tujuan dari Sedang dibersihkan | Login HK → ubah 203 ke Sedang dibersihkan → klik 203 lagi | 203 | Terisi terpilih dan bertanda "disarankan" | | |
| ST-05 | Check-out manual | Login FO → klik 204 → Kosong, kotor → Simpan | 204: Terisi → Kosong, kotor | Berhasil | | |
| ST-06 | HK tidak boleh check-out | Login HK → klik 204 | 204: Terisi → Kosong, kotor | Pilihan Kosong, kotor tidak ada; bila dipaksa: "Perubahan status dari Terisi ke Kosong, kotor tidak diizinkan." | | |
| ST-07 | FO tidak boleh selesai bersih | Login FO → klik 202 | 202: Sedang dibersihkan → Siap | Tidak ada pilihan; bila dipaksa ditolak | | |
| ST-08 | Perpindahan tidak ada di tabel | Admin memaksa 101 ke Sedang dibersihkan | 101: Siap → Sedang dibersihkan | Ditolak: "Perubahan status dari Siap ke Sedang dibersihkan tidak diizinkan." | | |
| ST-09 | Status sama | Admin menyimpan status yang sama untuk 101 | 101: Siap → Siap | Tidak ada perubahan, tidak ada riwayat baru | | |
| ST-10 | Perbaikan tanpa catatan | Login HK → klik 101 → Perbaikan → catatan kosong → Simpan | 101: Siap → Perbaikan | Ditolak: "Catatan wajib diisi untuk status Perbaikan." | | |
| ST-11 | Perbaikan dengan catatan | Login HK → klik 101 → Perbaikan → catatan "AC bocor" → Simpan | 101, "AC bocor" | Berhasil, catatan tampil di riwayat | | |
| ST-12 | HK tidak boleh menutup perbaikan | Login HK → klik 103 | 103: Perbaikan → Siap | Tidak ada pilihan; bila dipaksa ditolak | | |
| ST-13 | FO menutup perbaikan | Login FO → klik 103 → Kosong, kotor → Simpan | 103: Perbaikan → Kosong, kotor | Berhasil, kotak 103 menampilkan lama kotor | | |
| ST-14 | Peran gabungan | Login staf FO + HK → 204 → Kosong, kotor → Sedang dibersihkan → Siap | 204 | Ketiganya berhasil | | |
| ST-15 | Manager lewat method Livewire | Manager memanggil method simpan langsung (alat pengembang / tes) | 105 | 403, status tidak berubah | | |
| ST-16 | Kamar nonaktif | Nonaktifkan 101 → coba ubah statusnya | 101 | Ditolak | | |
| ST-17 | Pesan sukses | Lakukan ST-01 | 105 | Tampil "Status kamar 105 diubah menjadi Sedang dibersihkan." | | |

## E. `dirty_since`

| ID | Skenario | Langkah | Data uji | Hasil diharapkan | Hasil aktual | Status |
|---|---|---|---|---|---|---|
| DS-01 | Terisi → perlu dibersihkan | FO ubah 204 ke Terisi, perlu dibersihkan | 204: Terisi → Terisi, perlu dibersihkan | `dirty_since` = waktu perubahan | | |
| DS-02 | Terisi kotor → kosong kotor | FO ubah 203 ke Kosong, kotor | 203 (kotor sejak 09.30) | `dirty_since` tetap 2026-10-06 09.30 | | |
| DS-03 | Kosong kotor → dibersihkan | HK ubah 105 ke Sedang dibersihkan | 105 | `dirty_since` kosong | | |
| DS-04 | Siap → kosong kotor | HK ubah 101 ke Kosong, kotor | 101 | `dirty_since` terisi waktu perubahan | | |

## F. Riwayat (UC-MK05)

| ID | Skenario | Langkah | Data uji | Hasil diharapkan | Hasil aktual | Status |
|---|---|---|---|---|---|---|
| RW-01 | Isi log | Lakukan ST-01, lalu buka kembali 105 | 105 | Baris teratas: Sedang dibersihkan, oleh pengguna yang mengubah, waktu perubahan | | |
| RW-02 | Urutan dan batas | Buat 12 perubahan pada satu kamar → buka kamar itu | 12 perubahan | Tampil 10 terbaru, terbaru di atas | | |
| RW-03 | Pengubah terhapus | Hapus akun staf yang pernah mengubah status → buka kamarnya | pengguna dihapus | Riwayat tetap ada, nama pengubah tampil "Sistem" | | |
| RW-04 | Format waktu | Buka detail 103 | riwayat 103 | Waktu tampil seperti `05 Okt 2026, 20.10` (WITA) | | |

## G. Hak Akses Halaman

| ID | Skenario | Langkah | Data uji | Hasil diharapkan | Hasil aktual | Status |
|---|---|---|---|---|---|---|
| HA-01 | Belum login | Buka `/staf/tipe-kamar`, `/staf/kamar`, `/staf/papan-status-kamar` tanpa login | tamu | Diarahkan ke halaman masuk | | |
| HA-02 | Admin | Login Admin → buka ketiga halaman | Admin | Semua terbuka (200) | | |
| HA-03 | Manager ke data master | Login Manager → buka Tipe Kamar dan Kamar | Manager | 403 | | |
| HA-04 | Manager ke papan | Login Manager → buka Papan Status | Manager | Terbuka (200) | | |
| HA-05 | FO, HK ke data master | Login FO lalu HK → buka Tipe Kamar dan Kamar | FO, HK | 403 | | |
| HA-06 | FO, HK ke papan | Login FO lalu HK → buka Papan Status | FO, HK | Terbuka (200) | | |
| HA-07 | F&B, Customer ke papan | Login F&B lalu Customer → buka Papan Status | F&B, Customer | 403 | | |
| HA-08 | F&B kirim tambah kamar langsung | Login F&B → kirim POST `/staf/kamar` | F&B | 403, jumlah kamar tetap 32 | | |
| HA-09 | Menu sidebar | Login tiap peran → lihat sidebar | semua peran | Admin: Data Master (Tipe Kamar, Kamar) dan Operasional; Manager, FO, HK: Operasional; F&B: tanpa menu modul kamar | | |

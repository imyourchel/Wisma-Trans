# Data Demo SIM Wisma Trans

`database/seeders/DemoSeeder.php` mengisi riwayat sekitar 75 hari dan keadaan "hari ini" yang siap didemokan.
Datanya dibuat lewat service aplikasi, bukan lewat INSERT, jadi semua isinya konsisten satu sama lain: nota, pembayaran, status kamar, log, tagihan bulanan, dan pengingat.
Selama seeding tidak ada WhatsApp, email, atau Midtrans sungguhan yang terkirim.

## Cara menjalankan

1. **Salin database utama** menjadi `wisma_trans_demo` lewat phpMyAdmin: buka database utama, pilih **Operations → Copy database to**, centang *Structure and data*, lalu klik Go.
   Seeder **menolak** berjalan di database yang namanya tidak berakhiran `_demo`. Ini supaya database utama tidak terhapus.
2. Di `.env`, ubah `DB_DATABASE=wisma_trans_demo`, lalu jalankan `php artisan config:clear`.
3. Jalankan `php artisan db:seed --class=DemoSeeder`. Prosesnya sekitar 1–3 menit.
4. Untuk kembali ke database utama, ubah lagi `DB_DATABASE` di `.env`, lalu jalankan `php artisan config:clear`.

> Jalankan seeder **di pagi hari demo**. Semua tanggal dihitung relatif terhadap saat seeder dijalankan, jadi kalau dijalankan kemarin, "kedatangan hari ini" sudah menjadi kedatangan kemarin.
> Seeder bisa dijalankan ulang kapan saja. Data transaksi lama di database demo dihapus lalu dibuat baru.
> Data master (pengguna, kamar, menu, aula, pengaturan, bobot AHP) dipakai apa adanya.

Akun yang dipakai tetap akun dari data master. Contohnya Manager `manager@wismatrans.id`, FO `yusuf@wismatrans.id`, F&B `maya@wismatrans.id`, HK `rahmat@wismatrans.id`, dan tamu `citra@contoh.id` / `budi@contoh.id`.

## Yang bisa didemokan hari ini (H = hari seeder dijalankan)

| Modul | Keadaan | Untuk demo |
|---|---|---|
| Check-in | **Citra Lestari** datang H (Deluxe, DP lunas) | Kartu CRM: "Siapkan Kamar 205 (3 dari 4 kunjungan)"; kamar 205 diusulkan |
| Check-in instansi | **Dinas Kesehatan**, 3 kamar (207, 209, 213), datang H | Verifikasi kode bukti; kamar 207 masih kotor, jadi ditangani lewat Smart Cleaning dulu |
| Kartu FO | **Yudi Kurniawan** dijadwalkan H-1, belum datang | Kartu "Kedatangan" diberi tanda awas: "termasuk 1 dari kemarin" |
| Check-out | **Hasan Basri** (111) dan **Lukas Wibowo** (112) keluar H | Folio berisi kamar, makanan, dan laundry; Hasan belum bayar sama sekali (walk-in) |
| Penghalang check-out | **Rizky** (105): laundry belum ditimbang | Contoh penghalang di layar check-out |
| Menginap | Rombongan **PT Vale** 6 kamar (H-2 s.d. H+3), Budi (203), Agus (210) | Kode kamar, pesanan antar ke kamar, tagihan sementara |
| Tamu login | **Budi** (`budi@contoh.id`) sedang menginap | Pesanan Saya: kode kamar, salin/bagikan, pesan makanan dari Resto |
| Papan Status | 101 dan 102 terisi-kotor; 109, 114, 207 kosong-kotor; 214 sedang dibersihkan; 103 dan 208 perbaikan | Smart Cleaning: hitung prioritas lalu bagi tugas (Rina izin hari ini; Anto dan Dewi multi-role HK + F&B, batas otomatis 2 poin) |
| Dapur | Pesanan Vale (101) dimasak lebih dari 20 menit; pesanan Budi siap diantar; pesanan kasir baru | Papan Pesanan, termasuk penanda terlambat |
| Laundry | Laundry tamu luar sudah ditimbang dan belum dibayar; laundry Hasan siap diantar | Bayar tunai, serahkan |
| Maintenance | AC 103 (baru, menahan kamar); kloset 208 dikerjakan vendor; kran dapur (area umum); AC 116 jalur cepat belum dilengkapi biayanya | Penanganan, jalur cepat, kartu dashboard |
| Perawatan rutin | AC Lantai 2 terlambat 10 hari; AC Lantai 1 jatuh tempo H+5 | Jadwal Rutin, pengingat |
| Reservasi | **Dian** menunggu uang muka; 5 reservasi mendatang (H+1 s.d. H+11); **Abdul** batal H-2 | Kalender, Daftar Reservasi |
| Pengajuan instansi | **PLN** mengajukan 4 kamar untuk H+7 | Kartu "Pola rombongan" (PLN sudah 2 kali datang) |
| Keuangan | Refund Abdul (50% uang muka) menunggu dikembalikan | Pembayaran → Perlu pengembalian dana |
| Piutang | Nota Dinkes lewat jatuh tempo sekitar 40 hari; nota PLN jatuh tempo H+4; tagihan bulanan Vale terbit dan belum dibayar | Piutang Instansi, umur piutang, Pengingat Penagihan (2 pengingat menunggu diperiksa) |
| Tagihan bulanan | Satu periode Vale lunas, satu periode terbit | Tagihan Bulanan, PDF |
| Aula | Pengajuan "Syukuran pernikahan" H+12 menunggu harga; rapat Vale H+5 terkonfirmasi | Pemesanan Aula, Kalender Aula |
| CRM | Citra 4 kunjungan, Budi 3 kunjungan (selalu 203), Vale/Dinkes/PLN beberapa rombongan | Daftar Pelanggan, Instansi, Riwayat Tamu |
| Laporan dan Dashboard | Riwayat sekitar 240 reservasi, 460 pesanan, dan 540 pembayaran | Semua laporan dengan preset "bulan ini" dan "bulan lalu" |

Sebelum demo, jalankan juga `php artisan penagihan:pengingat`, supaya pengingat hari ini tercatat dengan jam demo.

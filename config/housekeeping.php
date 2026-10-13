<?php

/*
 * Workload Balancing (Greedy) Modul 6. Angka di bawah mengikuti keterangan pemilik Wisma Trans
 * dan bisa diubah di sini tanpa mengubah kode.
 */
return [
    // Poin beban per kamar menurut status kotornya: kosong kotor dibersihkan penuh, terisi kotor hanya dirapikan
    'poin' => [
        'vacant_dirty' => 1.0,
        'occupied_dirty' => 0.5,
    ],

    // Batas beban per staf per hari tidak ada di sini: dibaca dari settings hk_max_workload (HK murni) dan
    // hk_max_workload_multirole (halaman Pengaturan) lewat App\Services\SmartCleaning\BatasBebanStaf.

    // Greedy memilih staf dengan rasio (beban + poin) / batas terkecil, bukan beban terkecil.
    // Masih menunggu konfirmasi dosen; ubah ke true bila disetujui.
    'greedy_rasio' => false,
];

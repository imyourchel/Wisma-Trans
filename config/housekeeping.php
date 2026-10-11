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

    // Batas beban per staf per hari, dipakai bila staf tidak punya baris staff_availabilities pada hari itu
    // atau max_workload-nya kosong
    'batas_default' => 4.0,
];

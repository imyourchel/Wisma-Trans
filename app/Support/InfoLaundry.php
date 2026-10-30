<?php

namespace App\Support;

/**
 * Teks informasi halaman /laundry (tamu.laundry): langkah pesan, jenis cucian, jam layanan, dan tanya jawab.
 * Harga per kg dan berat minimal TIDAK di sini: dibaca dari layanan laundry (tabel services) dan LaundryService::MIN_KG.
 *
 * DATA SEMENTARA — konfirmasi ke pihak wisma.
 */
class InfoLaundry
{
    /** [ikon x-tamu.ikon, judul, keterangan] */
    public const LANGKAH = [
        ['formulir', 'Pesan', 'Isi formulir. Tamu menginap cukup pilih kamar; tamu luar isi nama dan WhatsApp.'],
        ['tas', 'Serahkan cucian', 'Kami ambil ke kamar Anda, atau antar sendiri ke resepsionis.'],
        ['timbangan', 'Ditimbang', 'Tagihan dihitung dari berat dan dikirim ke WhatsApp Anda.'],
        ['antar', 'Selesai', 'Diantar ke kamar, atau diambil di resepsionis.'],
    ];

    // DATA SEMENTARA — konfirmasi ke pihak wisma
    public const DITERIMA = [
        'Kaos dan kemeja',
        'Celana dan rok',
        'Pakaian dalam dan kaus kaki',
        'Handuk dan sarung pribadi',
    ];

    // DATA SEMENTARA — konfirmasi ke pihak wisma
    public const TIDAK_DITERIMA = [
        'Jas, blazer, dan pakaian dry clean',
        'Bahan kulit dan bulu',
        'Sepatu dan tas',
        'Selimut atau bed cover tebal',
    ];

    public const CATATAN_JENIS = 'Ragu dengan pakaian Anda? Tanyakan dulu ke resepsionis.';

    // DATA SEMENTARA — konfirmasi ke pihak wisma. [layanan, hari, jam]
    public const JAM_LAYANAN = [
        ['Penerimaan cucian', 'Setiap hari', '07.00–20.00 WITA'],
        ['Pengantaran ke kamar', 'Setiap hari', '08.00–21.00 WITA'],
    ];

    /**
     * DATA SEMENTARA — konfirmasi ke pihak wisma. [pertanyaan, jawaban]
     * Jawaban pembayaran mengikuti LaundryService: tamu luar bayar setelah ditimbang (online lewat tautan WhatsApp
     * atau tunai di resepsionis, lunas sebelum cucian diserahkan); tamu menginap memilih bayar sekarang atau tagihan kamar.
     */
    public const TANYA_JAWAB = [
        ['Berapa lama cucian selesai?', '1×24 jam sejak cucian ditimbang.'],
        ['Kapan saya membayar?', 'Setelah cucian ditimbang. Tamu luar membayar lewat tautan pembayaran online yang dikirim ke WhatsApp, atau tunai di resepsionis sebelum cucian diambil. Tamu menginap bisa membayar langsung atau menggabungkannya ke tagihan kamar.'],
        ['Bagaimana dengan noda atau pakaian yang mudah luntur?', 'Beri tahu kami di catatan pesanan. Pakaian yang mudah luntur sebaiknya dipisahkan.'],
        ['Apakah bisa layanan kilat?', 'Silakan tanyakan ke resepsionis untuk ketersediaannya.'],
    ];
}

<?php

namespace App\Support;

/** Kelas warna lencana pesanan makanan di sisi staf (Papan Pesanan, Riwayat, detail). */
class GayaPesanan
{
    public const TAHAP = [
        'pending' => 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]',
        'in_progress' => 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]',
        'ready' => 'bg-[rgba(92,158,103,.18)] text-status-available',
        'delivered' => 'bg-[rgba(155,139,120,.20)] text-[#6E604F]',
        'cancelled' => 'bg-[rgba(212,81,74,.12)] text-status-dirty',
    ];

    public const BAYAR = [
        'lunas' => 'bg-[rgba(92,158,103,.16)] text-status-available',
        'tagihan-kamar' => 'wt-lencana-netral',
        'belum-dibayar' => 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]',
        'dibatalkan' => 'bg-[rgba(212,81,74,.12)] text-status-dirty',
    ];

    /** Warna aksen kolom papan. */
    public const AKSEN = ['pending' => '#D69A2E', 'in_progress' => '#5B86B8', 'ready' => '#5C9E67'];
}

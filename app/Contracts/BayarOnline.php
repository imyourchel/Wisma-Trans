<?php

namespace App\Contracts;

use App\Models\Customer;
use App\Models\Invoice;
use Carbon\CarbonInterface;

/**
 * Sesuatu yang dibayar tamu lewat Midtrans: pesanan makanan (lunas) atau reservasi kamar (uang muka).
 * Dipakai App\Services\Keuangan\PembayaranMidtrans dan komponen x-bayar-online.
 */
interface BayarOnline
{
    /** Token akses acak milik data ini; dipakai sebagai kunci cache tagihan agar tidak tertukar. */
    public function kunciBayar(): string;

    /** Awalan order_id Midtrans, misalnya WT-ORD202609-0132 atau WT-RSV0016-DP. Akhiran acak ditambahkan per percobaan. */
    public function awalanMidtrans(): string;

    /** Jumlah yang ditagihkan (rupiah, bulat). */
    public function jumlahBayar(): int;

    public function batasBayar(): CarbonInterface;

    /** Rincian untuk Midtrans; jumlahnya harus sama dengan jumlahBayar(). */
    public function rincianBayar(): array;

    /** Keterangan singkat di tagihan Mandiri, misalnya "Pesanan ORD/2026/10/0011". */
    public function keteranganBayar(): string;

    public function pembayar(): ?Customer;

    public function notaBayar(): ?Invoice;

    public function sudahDibayar(): bool;
}

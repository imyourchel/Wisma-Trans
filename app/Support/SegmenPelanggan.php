<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Satu-satunya definisi B2B/B2C (laporan, dan nanti modul CRM):
 * B2B = nota yang punya institution_id, atau nota/menginap milik reservasi instansi
 * (reservations.institution_id terisi atau berasal dari pengajuan instansi). Selain itu B2C.
 */
class SegmenPelanggan
{
    /**
     * Ekspresi SQL bernilai 1 bila B2B, untuk query yang memuat alias invoices (i) dan reservations (r, left join).
     */
    public static function ekspresiNota(string $nota = 'i', string $reservasi = 'r'): string
    {
        return "CASE WHEN {$nota}.institution_id IS NOT NULL OR {$reservasi}.institution_id IS NOT NULL"
            ." OR EXISTS (SELECT 1 FROM reservation_requests rq WHERE rq.reservation_id = {$reservasi}.id) THEN 1 ELSE 0 END";
    }

    /** Ekspresi SQL B2B untuk query yang hanya memuat reservations (alias r). */
    public static function ekspresiReservasi(string $reservasi = 'r'): string
    {
        return "CASE WHEN {$reservasi}.institution_id IS NOT NULL"
            ." OR EXISTS (SELECT 1 FROM reservation_requests rq WHERE rq.reservation_id = {$reservasi}.id) THEN 1 ELSE 0 END";
    }

    /**
     * Ekspresi SQL B2B untuk satu pelanggan (alias tabel customers $pelanggan): narahubung instansi (institution_id terisi)
     * atau punya minimal satu reservasi B2B menurut ekspresiReservasi(). Segmen per kunjungan tetap memakai ekspresiReservasi().
     */
    public static function ekspresiPelanggan(string $pelanggan = 'c'): string
    {
        return "CASE WHEN {$pelanggan}.institution_id IS NOT NULL OR EXISTS (SELECT 1 FROM reservations rp WHERE rp.customer_id = {$pelanggan}.id"
            .' AND ('.self::ekspresiReservasi('rp').') = 1) THEN 1 ELSE 0 END';
    }

    /** Asal pemesanan reservasi: instansi, online, atau walk_in (alias r). */
    public static function ekspresiAsal(string $reservasi = 'r'): string
    {
        return "CASE WHEN {$reservasi}.institution_id IS NOT NULL OR EXISTS (SELECT 1 FROM reservation_requests rq WHERE rq.reservation_id = {$reservasi}.id)"
            ." THEN 'instansi' WHEN {$reservasi}.booking_channel = 'walk_in' THEN 'walk_in' ELSE 'online' END";
    }

    public const ASAL = ['online' => 'Online (perorangan)', 'walk_in' => 'Datang langsung', 'instansi' => 'Pengajuan instansi'];

    /** Tambahkan join reservasi yang dibutuhkan ekspresiNota(). */
    public static function joinReservasi(Builder $q, string $nota = 'i', string $reservasi = 'r'): Builder
    {
        return $q->leftJoin("reservations as {$reservasi}", "{$reservasi}.id", '=', "{$nota}.reservation_id");
    }
}

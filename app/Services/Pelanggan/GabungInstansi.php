<?php

namespace App\Services\Pelanggan;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Menggabungkan instansi duplikat ke instansi tujuan (Manager, Pelanggan › Instansi). Semua tabel yang mereferensikan
 * institutions (reservations, customers, invoices, billing_statements) dipindah dalam satu transaksi; pengingat penagihan
 * dan pemesanan aula ikut lewat nota, tagihan bulanan, dan narahubungnya. Instansi sumber lalu dihapus.
 */
class GabungInstansi
{
    /** Tabel dengan kolom institution_id (FK ke institutions). */
    public const TABEL = ['reservations', 'customers', 'invoices', 'billing_statements'];

    /**
     * Data instansi sumber yang akan ikut pindah.
     *
     * @return array{reservasi: int, narahubung: int, nota: int, tagihan: int, pengingat: int, aula: int}
     */
    public function ringkasan(Institution $sumber): array
    {
        $nota = DB::table('invoices')->where('institution_id', $sumber->id)->pluck('id');
        $tagihan = DB::table('billing_statements')->where('institution_id', $sumber->id)->pluck('id');
        $narahubung = DB::table('customers')->where('institution_id', $sumber->id)->pluck('id');

        return [
            'reservasi' => DB::table('reservations')->where('institution_id', $sumber->id)->count(),
            'narahubung' => $narahubung->count(),
            'nota' => $nota->count(),
            'tagihan' => $tagihan->count(),
            'pengingat' => DB::table('billing_reminders')
                ->where(fn ($q) => $q->whereIn('invoice_id', $nota)->orWhereIn('billing_statement_id', $tagihan))->count(),
            'aula' => DB::table('hall_bookings')
                ->where(fn ($q) => $q->whereIn('customer_id', $narahubung)->orWhereIn('invoice_id', $nota))->count(),
        ];
    }

    /** @return array{reservasi: int, narahubung: int, nota: int, tagihan: int, pengingat: int, aula: int} yang dipindah */
    public function gabung(Institution $sumber, Institution $tujuan, User $oleh): array
    {
        if ($sumber->is($tujuan)) {
            throw ValidationException::withMessages(['tujuan' => 'Instansi tidak bisa digabung ke dirinya sendiri.']);
        }

        return DB::transaction(function () use ($sumber, $tujuan, $oleh) {
            $sumber = Institution::lockForUpdate()->findOrFail($sumber->id);
            $tujuan = Institution::lockForUpdate()->findOrFail($tujuan->id);

            // Tagihan bulanan unik per instansi dan periode: periode yang sama di kedua instansi harus diselesaikan dulu
            $bentrok = DB::table('billing_statements as a')
                ->join('billing_statements as b', fn ($j) => $j->on('a.period_start', '=', 'b.period_start')->on('a.period_end', '=', 'b.period_end'))
                ->where('a.institution_id', $sumber->id)->where('b.institution_id', $tujuan->id)
                ->pluck('a.statement_number');
            if ($bentrok->isNotEmpty()) {
                throw ValidationException::withMessages(['tujuan' => 'Kedua instansi punya tagihan bulanan untuk periode yang sama ('
                    .$bentrok->join(', ').'). Batalkan salah satunya dulu di Tagihan Bulanan.']);
            }

            $ringkasan = $this->ringkasan($sumber);
            $narahubung = DB::table('customers')->where('institution_id', $sumber->id)->pluck('id');
            foreach (self::TABEL as $tabel) {
                DB::table($tabel)->where('institution_id', $sumber->id)->update(['institution_id' => $tujuan->id]);
            }

            // Tujuan tanpa narahubung utama mengambil narahubung utama sumber (dilepas dulu: satu orang hanya utama di satu instansi)
            $utama = $sumber->primary_contact_id;
            $sumber->update(['primary_contact_id' => null]);
            if (! $tujuan->primary_contact_id && $utama) {
                $tujuan->update(['primary_contact_id' => $utama]);
            }
            $sumber->delete();

            Log::info("Instansi {$sumber->name} digabung ke {$tujuan->name} oleh {$oleh->name}", ['sumber' => $sumber->id, 'tujuan' => $tujuan->id] + $ringkasan);
            Cache::forget(ProfilPelanggan::kunciInstansi($sumber->id));
            Cache::forget(ProfilPelanggan::kunciInstansi($tujuan->id));
            $narahubung->each(fn ($id) => ProfilPelanggan::lupakan($id));

            return $ringkasan;
        });
    }
}

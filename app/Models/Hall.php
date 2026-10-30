<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Hall extends Model
{
    /** Yang sudah termasuk harga sewa aula (full day). */
    public const TERMASUK = ['AC', 'Sound system', 'Kursi dan meja', 'Proyektor'];

    /** Yang tidak termasuk harga sewa: dekorasi tidak disediakan, konsumsi bisa dipesan terpisah dengan harga sesuai menu dan jumlah peserta. */
    public const CATATAN_HARGA = 'Belum termasuk dekorasi dan konsumsi. Konsumsi bisa dipesan terpisah, harganya menyesuaikan menu dan jumlah peserta.';

    /**
     * Teks promosi aula untuk halaman tamu (beranda, /aula). Hanya tampilan: batas peserta saat memesan tetap kolom capacity.
     */
    public const KAPASITAS_PROMOSI = '75+';

    public const ACARA_PROMOSI = 'Rapat · Seminar · Syukuran';

    /** Keterangan di samping harga mulai (Hall::tarif()['min']); harga akhir disepakati per acara. */
    public const KETERANGAN_HARGA = 'harga menyesuaikan acara';

    protected $fillable = ['name', 'capacity', 'description', 'photos', 'is_active'];

    /**
     * Kisaran harga sewa aula per hari (full day) dari Pengaturan (baris dari database/sql/2026-10-22-harga-aula.sql).
     * Harga akhir tetap disepakati staf dan ditetapkan Manager per pemesanan.
     *
     * @return array{min: float, maks: float}
     */
    public static function tarif(): array
    {
        $min = (float) Setting::get('hall_price_min', 1000000);

        return ['min' => $min, 'maks' => max($min, (float) Setting::get('hall_price_max', 2500000))];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'photos' => 'array'];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class);
    }

    /** URL foto yang berkasnya benar-benar ada, sesuai urutan galeri. Nama berkas data contoh belum punya berkas. */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])
            ->filter(fn ($p) => is_string($p) && str_contains($p, '/') && Storage::disk('public')->exists($p))
            ->map(fn ($p) => asset('storage/'.$p))
            ->values()
            ->all();
    }

    public function fotoUtama(): string
    {
        return $this->photoUrls()[0] ?? asset('ds/img/bangunan_depan.png');
    }
}

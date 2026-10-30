<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Tanggal menginap dan susunan tamu dari pencarian tamu (query ?masuk=&keluar=&tamu=).
 * tamu berisi dewasa-anak per kamar, dipisah garis bawah: "2-0_3-1" = kamar 1 dua dewasa, kamar 2 tiga dewasa satu anak.
 * Tanpa tanggal lengkap, halaman kamar menampilkan harga "mulai dari" tanpa ketersediaan.
 */
final class PencarianInap
{
    public const MAKS_MALAM = 30;

    public const MAKS_KAMAR = 10;

    /** Batas wajar tamu per kamar; kapasitas sebenarnya mengikuti tipe kamar. */
    public const MAKS_TAMU = 10;

    /** Batas jumlah tamu satu pencarian di halaman kamar (dewasa dan anak dihitung); rombongan lebih besar lewat instansi. */
    public const MAKS_TAMU_TOTAL = 40;

    /** Susunan bawaan bila tamu belum memilih: satu kamar dua dewasa, sama seperti beranda. */
    public const BAWAAN = [['dewasa' => 2, 'anak' => 0]];

    public readonly int $jumlah;

    /**
     * @param  list<array{dewasa: int, anak: int}>  $kamar
     */
    private function __construct(
        public readonly ?CarbonImmutable $masuk,
        public readonly ?CarbonImmutable $keluar,
        public readonly array $kamar,
        public readonly ?string $galat,
    ) {
        $this->jumlah = count($kamar);
    }

    public static function dari(?string $masuk, ?string $keluar, mixed $tamu): self
    {
        $kamar = self::susunan($tamu);
        $m = self::tanggal($masuk);
        $k = self::tanggal($keluar);
        if (! $m || ! $k) {
            return new self(null, null, $kamar, null);
        }

        $galat = match (true) {
            $m->lt(today()) => 'Tanggal check-in tidak boleh sebelum hari ini.',
            $k->lte($m) => 'Tanggal check-out harus setelah check-in.',
            $m->diffInDays($k) > self::MAKS_MALAM => 'Maksimal '.self::MAKS_MALAM.' malam per pemesanan. Hubungi kami untuk menginap lebih lama.',
            $m->gt(today()->addYear()) => 'Pemesanan paling lambat satu tahun ke depan.',
            default => null,
        };

        return new self($m, $k, $kamar, $galat);
    }

    /**
     * Teks "2-0_3-1" menjadi daftar kamar. Isian yang rusak kembali ke susunan bawaan.
     *
     * @return list<array{dewasa: int, anak: int}>
     */
    public static function susunan(mixed $tamu): array
    {
        if (! is_string($tamu) || ! preg_match('/^\d{1,2}-\d{1,2}(_\d{1,2}-\d{1,2})*$/', $tamu)) {
            return self::BAWAAN;
        }

        return collect(explode('_', $tamu))->take(self::MAKS_KAMAR)->map(function ($k) {
            [$d, $a] = array_map('intval', explode('-', $k));
            $d = max(1, min(self::MAKS_TAMU, $d));

            return ['dewasa' => $d, 'anak' => max(0, min(self::MAKS_TAMU - $d, $a))];
        })->all();
    }

    /**
     * Jumlah dewasa dan anak dari ?tamu= untuk halaman kamar, yang tidak lagi menanyakan jumlah kamar. Susunan per kamar
     * lama ("2-0_4-0") dijumlahkan, jadi tautan lama tetap terbuka. Isian rusak kembali ke 2 dewasa.
     *
     * @return array{dewasa: int, anak: int}
     */
    public static function total(mixed $tamu): array
    {
        if (! is_string($tamu) || ! preg_match('/^\d{1,2}-\d{1,2}(_\d{1,2}-\d{1,2})*$/', $tamu)) {
            return self::BAWAAN[0];
        }
        $d = $a = 0;
        foreach (array_slice(explode('_', $tamu), 0, self::MAKS_KAMAR) as $k) {
            [$kd, $ka] = array_map('intval', explode('-', $k));
            $d += $kd;
            $a += $ka;
        }
        $d = max(1, min(self::MAKS_TAMU_TOTAL, $d));

        return ['dewasa' => $d, 'anak' => max(0, min(self::MAKS_TAMU_TOTAL - $d, $a))];
    }

    /** Kebalikan total(): "4-2"; 2 dewasa tanpa anak (bawaan) menjadi teks kosong. */
    public static function teksTotal(array $total): string
    {
        return [$total['dewasa'], $total['anak']] === [2, 0] ? '' : $total['dewasa'].'-'.$total['anak'];
    }

    /** Kebalikan susunan(); susunan bawaan menjadi teks kosong agar tidak muncul di URL. */
    public static function teks(array $kamar): string
    {
        return $kamar === self::BAWAAN ? '' : implode('_', array_map(fn ($k) => $k['dewasa'].'-'.$k['anak'], $kamar));
    }

    private static function tanggal(?string $nilai): ?CarbonImmutable
    {
        if (! is_string($nilai) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $nilai));

        return checkdate($m, $d, $y) ? CarbonImmutable::create($y, $m, $d)->startOfDay() : null;
    }

    /** Tanggal lengkap dan valid, jadi ketersediaan bisa dihitung. */
    public function aktif(): bool
    {
        return $this->masuk !== null && $this->galat === null;
    }

    public function malam(): int
    {
        return $this->masuk && $this->keluar ? (int) $this->masuk->diffInDays($this->keluar) : 0;
    }

    /**
     * Jumlah penghuni tiap kamar (dewasa dan anak dihitung), sesuai urutan kamar.
     *
     * @return list<int>
     */
    public function penghuni(): array
    {
        return array_map(fn ($k) => $k['dewasa'] + $k['anak'], $this->kamar);
    }

    /** Penghuni terbanyak dalam satu kamar. */
    public function tamuTerbanyak(): int
    {
        return max($this->penghuni());
    }

    /** Misalnya "2 kamar · 4 dewasa, 1 anak". */
    public function ringkasTamu(): string
    {
        $dewasa = array_sum(array_column($this->kamar, 'dewasa'));
        $anak = array_sum(array_column($this->kamar, 'anak'));

        return $this->jumlah.' kamar · '.$dewasa.' dewasa'.($anak ? ', '.$anak.' anak' : '');
    }

    /** Rentang ringkas, misalnya "6–9 Okt 2026" atau "30 Sep – 2 Okt 2026". */
    public function rentang(): string
    {
        if (! $this->masuk) {
            return '';
        }
        $m = $this->masuk;
        $k = $this->keluar;

        return match (true) {
            $m->isSameMonth($k) => $m->day.'–'.$k->translatedFormat('j M Y'),
            $m->isSameYear($k) => $m->translatedFormat('j M').' – '.$k->translatedFormat('j M Y'),
            default => $m->translatedFormat('j M Y').' – '.$k->translatedFormat('j M Y'),
        };
    }

    /**
     * Parameter URL untuk meneruskan pencarian ke halaman lain. $kamar (urutan kamar, mulai 0) membatasi ke kamar
     * tertentu saja, misalnya hanya kamar yang muat di satu tipe.
     *
     * @param  list<int>|null  $kamar
     */
    public function query(?array $kamar = null): array
    {
        $susunan = $kamar === null ? $this->kamar : array_values(array_intersect_key($this->kamar, array_flip($kamar)));

        return array_filter([
            'masuk' => $this->masuk?->toDateString(),
            'keluar' => $this->keluar?->toDateString(),
            'tamu' => self::teks($susunan),
        ], fn ($v) => $v !== null && $v !== '');
    }
}

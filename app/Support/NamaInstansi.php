<?php

namespace App\Support;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

/**
 * Pencocokan nama instansi yang ditulis pemohon dengan instansi terdaftar. Satu-satunya tempat aturan normalisasi:
 * "PT. Vale" → "vale", "PT Vale Indonesia Tbk" → "vale indonesia", "PT PLN (Persero) UP3 Palopo" → "pln up3 palopo".
 * Data instansi sedikit, jadi dicocokkan di PHP.
 */
class NamaInstansi
{
    /** Kata badan usaha yang dibuang di awal atau akhir nama. */
    public const BADAN_USAHA = ['pt', 'cv', 'ud', 'tbk', 'persero', 'perum'];

    /** Selalu dibuang di mana pun letaknya, misalnya "PT PLN (Persero) UP3". */
    public const SISIPAN = ['persero'];

    /** Huruf kecil, tanpa tanda baca dan kata badan usaha, spasi dirapikan. */
    public static function normalisasi(string $nama): string
    {
        return implode(' ', self::kata($nama));
    }

    /** @return list<string> */
    private static function kata(string $nama): array
    {
        $kata = array_values(array_filter(
            preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($nama)))),
            fn ($k) => $k !== '' && ! in_array($k, self::SISIPAN, true),
        ));
        while ($kata !== [] && in_array($kata[0], self::BADAN_USAHA, true)) {
            array_shift($kata);
        }
        while ($kata !== [] && in_array($kata[count($kata) - 1], self::BADAN_USAHA, true)) {
            array_pop($kata);
        }

        return $kata;
    }

    /** Instansi terdaftar yang nama ternormalkannya sama persis; null bila tidak ada. */
    public static function sama(string $nama, ?int $kecuali = null): ?Institution
    {
        $n = self::normalisasi($nama);

        return $n === '' ? null : Institution::orderBy('id')->get()
            ->first(fn (Institution $i) => $i->id !== $kecuali && self::normalisasi($i->name) === $n);
    }

    /**
     * Saran instansi untuk nama tulisan pemohon: nama ternormalkan sama, atau kata-katanya mengandung / dikandung kata
     * nama itu ("vale" ⊂ "vale indonesia"). Yang sama persis dulu, lalu yang selisih katanya paling sedikit.
     *
     * @return EloquentCollection<int, Institution>
     */
    public static function saran(string $nama, int $maks = 3, ?int $kecuali = null): EloquentCollection
    {
        $kata = self::kata($nama);
        if ($kata === []) {
            return new EloquentCollection;
        }

        return new EloquentCollection(Institution::orderBy('name')->get()
            ->reject(fn (Institution $i) => $i->id === $kecuali)
            ->map(function (Institution $i) use ($kata) {
                $lain = self::kata($i->name);
                $cocok = $lain !== [] && (array_diff($kata, $lain) === [] || array_diff($lain, $kata) === []);

                return $cocok ? ['instansi' => $i, 'selisih' => abs(count($lain) - count($kata))] : null;
            })
            ->filter()->sortBy('selisih')->take($maks)->pluck('instansi')->values()->all());
    }

    /**
     * Pencarian di daftar instansi: tiap kata yang diketik harus muncul di nama ternormalkan atau kodenya.
     * Kata kosong = semua.
     *
     * @return EloquentCollection<int, Institution>
     */
    public static function cari(string $kata, int $maks = 8, ?int $kecuali = null): EloquentCollection
    {
        $dicari = self::kata($kata);

        return Institution::orderBy('name')->get()
            ->reject(fn (Institution $i) => $i->id === $kecuali)
            ->filter(function (Institution $i) use ($dicari) {
                $nama = self::normalisasi($i->name).' '.Str::lower((string) $i->code);

                return collect($dicari)->every(fn ($k) => str_contains($nama, $k));
            })
            ->take($maks)->values();
    }
}

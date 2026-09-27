<?php

namespace App\Support;

class PersonName
{
    /**
     * Nama orang: huruf (termasuk beraksen), spasi, dan tanda . ' - , untuk nama
     * seperti "Muh. Rizky", "Nur'aini", atau "Dr. H. Ahmad, S.T.". Minimal satu huruf.
     * Tanpa pembatas agar bisa dipakai juga oleh JavaScript di formulir (flag u).
     */
    public const PATTERN = "^(?=.*\\p{L})[\\p{L}\\p{M}\\s.'\\-,]+$";

    public const MESSAGE = "Hanya huruf dan tanda . ' - ,";

    /**
     * Seragamkan apostrof dari keyboard ponsel (’ ‘ `) menjadi ' dan rapikan spasi.
     * Kapitalisasi dibiarkan apa adanya.
     */
    public static function normalize(string $name): string
    {
        $name = str_replace(['’', '‘', 'ʼ', '`'], "'", $name);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public static function rule(): string
    {
        return 'regex:/'.self::PATTERN.'/u';
    }
}

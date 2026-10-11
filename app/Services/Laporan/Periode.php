<?php

namespace App\Services\Laporan;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Periode laporan dan periode pembandingnya.
 *   hari-ini   → kemarin
 *   7-hari     → 7 hari sebelumnya
 *   bulan-ini  → tanggal yang sama bulan lalu (1–12 Okt dibanding 1–12 Sep)
 *   bulan-lalu → bulan sebelumnya, satu bulan penuh
 *   rentang    → panjang yang sama, tepat sebelumnya
 * Grafik per hari, atau per bulan bila rentang lebih dari BATAS_HARIAN hari.
 */
final class Periode
{
    public const PRESET = ['hari-ini' => 'Hari ini', '7-hari' => '7 hari terakhir', 'bulan-ini' => 'Bulan ini', 'bulan-lalu' => 'Bulan lalu', 'rentang' => 'Rentang bebas'];

    public const BATAS_HARIAN = 62;

    public function __construct(public readonly CarbonImmutable $mulai, public readonly CarbonImmutable $akhir, public readonly string $preset = 'rentang') {}

    public static function dariRequest(Request $request): self
    {
        return self::buat((string) $request->query('periode', 'bulan-ini'), (string) $request->query('dari', ''), (string) $request->query('sampai', ''));
    }

    public static function buat(string $preset, string $dari = '', string $sampai = ''): self
    {
        $hari = CarbonImmutable::today();

        return match ($preset) {
            'hari-ini' => new self($hari, $hari, $preset),
            '7-hari' => new self($hari->subDays(6), $hari, $preset),
            'bulan-lalu' => new self($hari->startOfMonth()->subMonth(), $hari->startOfMonth()->subDay(), $preset),
            'rentang' => self::rentang($dari, $sampai) ?? new self($hari->startOfMonth(), $hari, 'bulan-ini'),
            default => new self($hari->startOfMonth(), $hari, 'bulan-ini'),
        };
    }

    private static function rentang(string $dari, string $sampai): ?self
    {
        $baca = function (string $t) {
            $d = $t !== '' ? rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $t), null, false) : null;

            return $d && $d->format('Y-m-d') === $t ? $d : null;
        };
        [$m, $a] = [$baca($dari), $baca($sampai)];
        if (! $m || ! $a) {
            return null;
        }
        if ($a->lt($m)) {
            [$m, $a] = [$a, $m];
        }
        // Batas wajar supaya laporan tidak menghitung bertahun-tahun sekaligus
        if ($m->diffInDays($a) > 731) {
            $m = $a->subDays(731);
        }

        return new self($m, $a, 'rentang');
    }

    public function sebelumnya(): self
    {
        return match ($this->preset) {
            'hari-ini' => new self($this->mulai->subDay(), $this->akhir->subDay(), 'pembanding'),
            'bulan-ini' => new self($this->mulai->subMonthNoOverflow(), $this->akhir->subMonthNoOverflow(), 'pembanding'),
            'bulan-lalu' => new self($this->mulai->subMonthNoOverflow()->startOfMonth(), $this->mulai->subDay(), 'pembanding'),
            default => new self($this->mulai->subDays($this->jumlahHari()), $this->mulai->subDay(), 'pembanding'),
        };
    }

    public function jumlahHari(): int
    {
        return (int) $this->mulai->diffInDays($this->akhir) + 1;
    }

    /** @return Collection<int, CarbonImmutable> setiap tanggal dalam periode */
    public function hari(): Collection
    {
        return collect(range(0, $this->jumlahHari() - 1))->map(fn ($i) => $this->mulai->addDays($i));
    }

    public function bulanan(): bool
    {
        return $this->jumlahHari() > self::BATAS_HARIAN;
    }

    /** Kunci kelompok grafik untuk satu tanggal: Y-m-d, atau Y-m bila bulanan. */
    public function kunci(CarbonImmutable|string $tanggal): string
    {
        $t = is_string($tanggal) ? $tanggal : $tanggal->toDateString();

        return $this->bulanan() ? substr($t, 0, 7) : substr($t, 0, 10);
    }

    /** @return array<string, string> kunci kelompok => label sumbu */
    public function kelompok(): array
    {
        return $this->hari()->mapWithKeys(fn (CarbonImmutable $d) => [
            $this->kunci($d) => $this->bulanan() ? $d->translatedFormat('M Y') : $d->translatedFormat('j M'),
        ])->all();
    }

    public function label(): string
    {
        if ($this->mulai->isSameDay($this->akhir)) {
            return $this->mulai->translatedFormat('j M Y');
        }

        return $this->mulai->translatedFormat($this->mulai->isSameYear($this->akhir) ? 'j M' : 'j M Y').' – '.$this->akhir->translatedFormat('j M Y');
    }

    /** Parameter query untuk tautan CSV/PDF dengan penyaring yang sama. */
    public function query(): array
    {
        return $this->preset === 'rentang'
            ? ['periode' => 'rentang', 'dari' => $this->mulai->toDateString(), 'sampai' => $this->akhir->toDateString()]
            : ['periode' => $this->preset];
    }

    public function berisi(string $tanggal): bool
    {
        return $tanggal >= $this->mulai->toDateString() && $tanggal <= $this->akhir->toDateString();
    }
}

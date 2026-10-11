@php
    $desimal = fn ($n, $d = 6) => number_format((float) $n, $d, ',', '.');
    // Nilai perbandingan ditulis seperti di kuesioner: 3, 1/3, 5, 1/5
    $pecahan = function (float $n) {
        if (abs($n - round($n)) < 1e-9) {
            return (string) (int) round($n);
        }
        $balik = 1 / $n;

        return abs($balik - round($balik)) < 1e-9 ? '1/'.(int) round($balik) : number_format($n, 4, ',', '.');
    };
    $kode = $kriteria->pluck('code', 'id');
    $cr = $sesi ? (float) $sesi->consistency_ratio : null;
@endphp

<x-layouts.staf title="Bobot Kriteria">
<div>
    <div class="mb-4 flex items-start gap-3 rounded-md border border-cream-300 bg-cream-100 px-4 py-3 text-[13px] text-ink-900">
        <x-tamu.ikon nama="info" ukuran="18" class="mt-px text-clay-500" />
        <span>Bobot dari penelitian terdahulu. Bobot dipakai untuk menghitung prioritas pembersihan (AHP–SAW) dan tidak diubah dari halaman ini.</span>
    </div>

    @include('staf.partials.tabel-kriteria')

    @if ($sesi && $hitung && $hitung['kriteria'])
        <div class="mt-6 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="wt-card overflow-x-auto p-4">
                <h2 class="mb-3 mt-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Matriks perbandingan berpasangan</h2>
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th class="wt-th"></th>
                            @foreach ($hitung['kriteria'] as $id)<th class="wt-th text-center">{{ $kode[$id] ?? $id }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hitung['matriks'] as $i => $baris)
                            <tr>
                                <th class="wt-td text-left font-semibold">{{ $kode[$hitung['kriteria'][$i]] ?? '' }}</th>
                                @foreach ($baris as $nilai)<td class="wt-td text-center tabular-nums">{{ $pecahan($nilai) }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mb-0 mt-3 text-xs text-ink-500">Baris dibandingkan dengan kolom (skala Saaty 1–9). Bobot = rata-rata baris matriks yang dinormalisasi per kolom.</p>
            </div>

            <div class="wt-card p-4">
                <h2 class="mb-3 mt-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Uji konsistensi</h2>
                <dl class="m-0 grid grid-cols-2 gap-3 text-[13px]">
                    @foreach ([['λ maks', $desimal($sesi->lambda_max)], ['Consistency Index (CI)', $desimal($sesi->consistency_index)], ['Random Index (RI)', $desimal($sesi->random_index, 2)], ['Consistency Ratio (CR)', $desimal($sesi->consistency_ratio)]] as [$label, $nilai])
                        <div class="rounded-md bg-cream-100 px-3 py-2">
                            <dt class="text-[11px] font-semibold uppercase tracking-[.08em] text-ink-500">{{ $label }}</dt>
                            <dd class="m-0 mt-0.5 text-base font-bold tabular-nums">{{ $nilai }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p @class(['mb-0 mt-3 text-[13px] font-semibold', 'text-status-available' => $cr < 0.1, 'text-status-dirty' => $cr >= 0.1])>
                    {{ $cr < 0.1 ? 'Konsisten (CR < 0,1)' : 'Tidak konsisten (CR ≥ 0,1)' }}
                </p>
                <p class="mb-0 mt-1 text-xs text-ink-500">
                    Sumber: {{ $sesi->session_name ?: 'Bobot dari penelitian terdahulu' }}. Hitung ulang dari matriks:
                    λ maks {{ $desimal($hitung['lambda_max']) }}, CR {{ $desimal($hitung['cr']) }}.
                </p>
            </div>
        </div>
    @elseif (! $sesi)
        <p class="mt-3 text-xs text-ink-500">Belum ada bobot AHP aktif di basis data.</p>
    @endif
</div>
</x-layouts.staf>

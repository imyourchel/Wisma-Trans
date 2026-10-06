@php
    use App\Models\CleaningCriterion;

    $desimal = fn ($n, $d = 6) => number_format((float) $n, $d, ',', '.');
    $konsisten = $sesi && (float) $sesi->consistency_ratio <= 0.1;
@endphp

<x-layouts.staf title="Kriteria Pembersihan">
<div>
    <div class="mb-4 flex items-start gap-3 rounded-md border border-cream-300 bg-cream-100 px-4 py-3 text-[13px] text-ink-900">
        <x-tamu.ikon nama="info" ukuran="18" class="mt-px text-clay-500" />
        <span>Kriteria dan bobotnya sudah ditetapkan dari penelitian terdahulu, sehingga tidak dapat ditambah, diubah, atau dinonaktifkan. Halaman ini hanya untuk dilihat.</span>
    </div>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[760px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Nama</th>
                    <th class="wt-th">Sifat</th>
                    <th class="wt-th text-right">Bobot AHP</th>
                    <th class="wt-th">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($kriteria as $k)
                    @php $b = $bobot[$k->id] ?? null; @endphp
                    <tr class="hover:bg-row-hover">
                        <td class="wt-td font-semibold">{{ $k->code }}</td>
                        <td class="wt-td">{{ $k->name }}</td>
                        <td class="wt-td"><span class="wt-lencana wt-lencana-netral">{{ CleaningCriterion::TYPES[$k->attribute_type] ?? $k->attribute_type }}</span></td>
                        <td class="wt-td text-right tabular-nums">
                            @if ($b !== null)
                                <span class="font-semibold">{{ $desimal($b) }}</span>
                                <span class="ml-1 text-xs text-ink-500">({{ number_format((float) $b * 100, 1, ',', '.') }}%)</span>
                            @else
                                <span class="text-ink-500">—</span>
                            @endif
                        </td>
                        <td class="wt-td max-w-[380px] text-ink-500">{{ $k->description ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="wt-td py-10 text-center text-sm text-ink-500">Data kriteria belum ada di basis data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($sesi)
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([['λ maks', $desimal($sesi->lambda_max)], ['Consistency Index (CI)', $desimal($sesi->consistency_index)], ['Random Index (RI)', $desimal($sesi->random_index, 2)], ['Consistency Ratio (CR)', $desimal($sesi->consistency_ratio)]] as [$label, $nilai])
                <div class="wt-card px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[.08em] text-ink-500">{{ $label }}</div>
                    <div class="mt-1 text-lg font-bold tabular-nums">{{ $nilai }}</div>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-ink-500">
            {{ $sesi->session_name }} · CR {{ $konsisten ? '≤ 0,1, bobot konsisten dan dipakai' : '> 0,1, bobot tidak konsisten sehingga tidak dipakai' }} pada perhitungan prioritas pembersihan (AHP–SAW).
        </p>
    @else
        <p class="mt-3 text-xs text-ink-500">Belum ada bobot AHP aktif di basis data.</p>
    @endif
</div>
</x-layouts.staf>

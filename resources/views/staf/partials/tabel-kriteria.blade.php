{{-- Tabel kriteria Smart Cleaning dan bobot AHP-nya; dipakai halaman Kriteria (Admin) dan Bobot Kriteria (Manager) --}}
@php
    $desimalKriteria = fn ($n, $d = 6) => number_format((float) $n, $d, ',', '.');
    $persenKriteria = fn ($n) => number_format((float) $n * 100, 1, ',', '.').'%';
    $sifatKriteria = [
        'cost' => 'bg-[rgba(181,113,74,.14)] text-clay-600',
        'benefit' => 'bg-[rgba(92,158,103,.16)] text-status-available',
    ];
    $totalBobot = collect($kriteria)->sum(fn ($k) => (float) ($bobot[$k->id] ?? 0));
@endphp
    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[820px] table-fixed border-collapse">
            <colgroup>
                <col class="w-[84px]">
                <col class="w-[26%]">
                <col class="w-[170px]">
                <col class="w-[220px]">
                <col>
            </colgroup>
            <thead>
                <tr>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Kriteria</th>
                    <th class="wt-th">Sifat</th>
                    <th class="wt-th">Bobot AHP</th>
                    <th class="wt-th">Cara mengukur</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($kriteria as $k)
                    @php
                        $b = $bobot[$k->id] ?? null;
                        $sifat = $sifatKriteria[$k->attribute_type] ?? 'wt-lencana-netral';
                    @endphp
                    <tr class="align-top hover:bg-row-hover">
                        <td class="wt-td">
                            <span class="inline-flex h-7 min-w-9 items-center justify-center rounded bg-brown-800 px-2 font-display text-xs font-bold tracking-[.04em] text-cream-50">{{ $k->code }}</span>
                        </td>
                        <td class="wt-td pt-[14px] font-semibold text-ink-900">{{ $k->name }}</td>
                        <td class="wt-td">
                            <span class="wt-lencana {{ $sifat }}">{{ \App\Models\CleaningCriterion::TYPES[$k->attribute_type] ?? $k->attribute_type }}</span>
                        </td>
                        <td class="wt-td">
                            @if ($b !== null)
                                <div class="flex items-baseline justify-between gap-3 tabular-nums">
                                    <span class="font-semibold">{{ $desimalKriteria($b) }}</span>
                                    <span class="text-xs font-semibold text-ink-500">{{ $persenKriteria($b) }}</span>
                                </div>
                                {{-- Porsi bobot terhadap total (1,0) --}}
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-cream-200" aria-hidden="true">
                                    <div class="h-full rounded-full bg-clay-500" style="width: {{ round((float) $b * 100, 2) }}%"></div>
                                </div>
                            @else
                                <span class="text-ink-500">—</span>
                            @endif
                        </td>
                        <td class="wt-td pt-[14px] leading-relaxed text-ink-500">{{ $k->description ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="wt-td py-10 text-center text-sm text-ink-500">Data kriteria belum ada di basis data.</td></tr>
                @endforelse
            </tbody>
            @if (collect($kriteria)->isNotEmpty() && $totalBobot > 0)
                <tfoot>
                    <tr class="bg-cream-100">
                        <td class="wt-td"></td>
                        <td class="wt-td font-semibold" colspan="2">Total bobot</td>
                        <td class="wt-td">
                            <div class="flex items-baseline justify-between gap-3 font-semibold tabular-nums">
                                <span>{{ $desimalKriteria($totalBobot) }}</span>
                                <span class="text-xs text-ink-500">{{ $persenKriteria($totalBobot) }}</span>
                            </div>
                        </td>
                        <td class="wt-td"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

{{-- Tabel kriteria Smart Cleaning dan bobot AHP-nya; dipakai halaman Kriteria (Admin) dan Bobot Kriteria (Manager) --}}
@php
    $desimalKriteria = fn ($n, $d = 6) => number_format((float) $n, $d, ',', '.');
@endphp
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
                        <td class="wt-td"><span class="wt-lencana wt-lencana-netral">{{ \App\Models\CleaningCriterion::TYPES[$k->attribute_type] ?? $k->attribute_type }}</span></td>
                        <td class="wt-td text-right tabular-nums">
                            @if ($b !== null)
                                <span class="font-semibold">{{ $desimalKriteria($b) }}</span>
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

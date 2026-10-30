@php
    use App\Services\SmartCleaning\NilaiKriteria;

    $d = fn ($n, $k = 8) => number_format((float) $n, $k, ',', '.');
    $mentah = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ',');
    $skor = fn ($item) => $item->scores->keyBy('criteria_id');
    $c1 = $kriteria->firstWhere('code', 'C1');
    $c2 = $kriteria->firstWhere('code', 'C2');
    $c3 = $kriteria->firstWhere('code', 'C3');
    $statusTugas = function ($t) {
        if (! $t) {
            return ['Belum terbagi', 'text-status-dirty'];
        }

        return match ($t->status) {
            'assigned' => [$t->namaStaf() ?: 'Belum terbagi', 'text-ink-900'],
            'in_progress' => ['Sedang · '.$t->namaStaf(), 'text-[#3F6D9E]'],
            default => ['Selesai', 'text-status-available'],
        };
    };
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <button type="button" wire:click="hitung" wire:loading.attr="disabled" wire:target="hitung"
                class="inline-flex h-10 items-center gap-2 rounded border-0 bg-clay-500 px-4 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">
            <span wire:loading.remove wire:target="hitung">Hitung prioritas sekarang</span><span wire:loading wire:target="hitung">Menghitung…</span>
        </button>
        <span class="text-[13px] text-ink-500">
            @if ($batch)
                Dihitung {{ $batch->generated_at->translatedFormat('j M Y, H.i') }} WITA oleh {{ $batch->generatedBy?->name ?? 'sistem' }} · {{ $batch->note }}
            @else
                Belum ada perhitungan hari ini.
            @endif
        </span>
        @if ($batch)
            <a href="{{ route('staf.housekeeping.pembagian') }}" class="ml-auto inline-flex h-10 items-center rounded bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900 hover:text-cream-50 hover:no-underline">Lanjut ke pembagian tugas</a>
        @endif
    </div>

    @error('batch')
        <p class="mb-4 rounded-md border border-[rgba(212,81,74,.3)] bg-[rgba(212,81,74,.06)] px-4 py-3 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>
    @enderror

    @if (count($kotorBaru))
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-md border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] px-4 py-3 text-[13px] text-[#7A520C]" role="status">
            <span class="flex-1">Ada {{ count($kotorBaru) }} kamar kotor sejak perhitungan terakhir ({{ implode(', ', $kotorBaru) }}). Hitung ulang?</span>
            <button type="button" wire:click="hitung" class="wt-btn-sm">Hitung ulang</button>
        </div>
    @endif

    @if ($items->isEmpty())
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">
            {{ $batch ? 'Tidak ada kamar pada perhitungan terakhir.' : 'Tekan "Hitung prioritas sekarang" untuk menyusun urutan kamar yang perlu dibersihkan.' }}
        </div>
    @else
        <div class="wt-card overflow-x-auto">
            <table class="wt-tabel-kartu w-full min-w-[1000px] border-collapse">
                <thead>
                    <tr>
                        <th class="wt-th">No</th>
                        <th class="wt-th">Kamar</th>
                        <th class="wt-th">{{ $c1?->name }}</th>
                        <th class="wt-th">{{ $c2?->name }}</th>
                        <th class="wt-th">{{ $c3?->name }}</th>
                        <th class="wt-th text-right">Nilai V</th>
                        <th class="wt-th">Tugas hari ini</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        @php
                            $s = $skor($item);
                            $ket = $keterangan[$item->room_id] ?? null;
                            [$teksTugas, $warnaTugas] = $statusTugas($tugas[$item->room_id] ?? null);
                        @endphp
                        <tr wire:key="prioritas-{{ $item->id }}" class="hover:bg-row-hover">
                            <td class="wt-td font-semibold tabular-nums" data-label="Prioritas">#{{ $item->priority_rank }}</td>
                            <td class="wt-td wt-kartu-judul whitespace-nowrap">
                                <div class="font-display text-[15px] font-extrabold tabular-nums">{{ $item->room->room_number }}</div>
                                <div class="text-[11px] text-ink-500">{{ $item->room->roomType?->name }}</div>
                            </td>
                            <td class="wt-td" data-label="{{ $c1?->name }}">
                                <div>{{ $ket['C1'] ?? '—' }}</div>
                                <div class="text-[11px] tabular-nums text-ink-500">{{ $mentah($s[$c1?->id]?->raw_value ?? 0) }} menit</div>
                            </td>
                            <td class="wt-td" data-label="{{ $c2?->name }}">
                                <div>{{ $ket['C2'] ?? $item->room->status->label() }}</div>
                                <div class="text-[11px] tabular-nums text-ink-500">nilai {{ $mentah($s[$c2?->id]?->raw_value ?? 0) }}</div>
                            </td>
                            <td class="wt-td whitespace-nowrap" data-label="{{ $c3?->name }}">{{ NilaiKriteria::durasi((int) ($s[$c3?->id]?->raw_value ?? 0)) }}</td>
                            <td class="wt-td text-right font-semibold tabular-nums" data-label="Nilai V">{{ $d($item->preference_score, 4) }}</td>
                            <td class="wt-td whitespace-nowrap text-[13px] font-semibold {{ $warnaTugas }}" data-label="Tugas hari ini">{{ $teksTugas }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button type="button" wire:click="$toggle('lihatPerhitungan')" class="wt-btn-sm mt-4">{{ $lihatPerhitungan ? 'Sembunyikan perhitungan' : 'Lihat perhitungan' }}</button>

        @if ($lihatPerhitungan)
            {{-- Tiga tabel untuk dicocokkan dengan hitungan manual --}}
            @foreach ([
                ['Matriks keputusan (nilai mentah)', 'raw_value', null],
                ['Matriks ternormalisasi', 'normalized_value', 8],
                ['Nilai terbobot dan preferensi V', 'weighted_value', 8],
            ] as [$judul, $kolom, $desimal])
                <div class="wt-card mt-4 overflow-x-auto p-4">
                    <h3 class="mb-3 mt-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">{{ $judul }}</h3>
                    <table class="w-full min-w-[640px] border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="wt-th">Kamar</th>
                                @foreach ($kriteria as $k)
                                    <th class="wt-th text-right">
                                        {{ $k->code }}<span class="block text-[10px] font-normal normal-case tracking-normal">{{ $k->name }}@if ($kolom === 'normalized_value') ({{ $k->attribute_type }}: {{ $k->attribute_type === 'cost' ? 'min ÷ x' : 'x ÷ max' }})@endif</span>
                                    </th>
                                @endforeach
                                @if ($kolom === 'weighted_value')<th class="wt-th text-right">V</th><th class="wt-th text-right">Peringkat</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                @php $s = $skor($item); @endphp
                                <tr wire:key="hitung-{{ $kolom }}-{{ $item->id }}">
                                    <td class="wt-td font-semibold tabular-nums">{{ $item->room->room_number }}</td>
                                    @foreach ($kriteria as $k)
                                        <td class="wt-td text-right tabular-nums">{{ $desimal ? $d($s[$k->id]?->{$kolom} ?? 0, $desimal) : $mentah($s[$k->id]?->{$kolom} ?? 0) }}</td>
                                    @endforeach
                                    @if ($kolom === 'weighted_value')
                                        <td class="wt-td text-right font-semibold tabular-nums">{{ $d($item->preference_score) }}</td>
                                        <td class="wt-td text-right tabular-nums">{{ $item->priority_rank }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
            <p class="mt-2 text-xs text-ink-500">Bobot: {{ $kriteria->map(fn ($k) => $k->code)->implode(', ') }} dari sesi AHP aktif (lihat Bobot Kriteria). V = Σ bobot × nilai ternormalisasi.</p>
        @endif
    @endif
</div>

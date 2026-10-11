@php
    use App\Services\Laporan\Format;
    use App\Services\Laporan\Periode;

    $judulHalaman = \Illuminate\Support\Str::ucfirst($jenis);
    $q = $p->query();
@endphp

<x-layouts.staf :title="$judulHalaman">
<div class="flex flex-col gap-5">
    {{-- Penyaring periode (GET, sehingga tautan CSV/PDF memakai penyaring yang sama) --}}
    <form method="GET" action="{{ route('staf.laporan.'.$jenis) }}" class="flex flex-wrap items-end gap-3" x-data="{ periode: @js($p->preset) }">
        <label class="block">
            <span class="wt-field-label mb-1.5 block">Periode</span>
            <select name="periode" x-model="periode" @change="periode !== 'rentang' && $el.form.submit()" class="wt-input w-[170px]">
                @foreach (Periode::PRESET as $v => $l)<option value="{{ $v }}" @selected($p->preset === $v)>{{ $l }}</option>@endforeach
            </select>
        </label>
        <template x-if="periode === 'rentang'">
            <div class="flex items-end gap-2">
                <label class="block"><span class="wt-field-label mb-1.5 block">Dari</span><input type="date" name="dari" value="{{ $p->mulai->toDateString() }}" class="wt-input w-[150px]"></label>
                <label class="block"><span class="wt-field-label mb-1.5 block">Sampai</span><input type="date" name="sampai" value="{{ $p->akhir->toDateString() }}" class="wt-input w-[150px]"></label>
                <button class="h-10 rounded border-0 bg-brown-800 px-4 text-xs font-bold text-cream-50 hover:bg-brown-900">Terapkan</button>
            </div>
        </template>
        <div class="text-[13px] text-ink-500">
            <div class="font-semibold text-ink-900">{{ $p->label() }}</div>
            dibanding {{ $p->sebelumnya()->label() }}
        </div>
        <div class="ml-auto flex gap-2">
            <a href="{{ route('staf.laporan.'.$jenis, [...$q, 'format' => 'pdf']) }}" target="_blank" rel="noopener" class="wt-btn-sm h-10 hover:no-underline">Cetak/PDF</a>
            <a href="{{ route('staf.laporan.'.$jenis, [...$q, 'format' => 'csv']) }}" class="wt-btn-sm h-10 hover:no-underline">CSV</a>
        </div>
    </form>

    {{-- Ringkasan dengan pembanding --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
        @foreach ($d['ringkasan'] as $k)
            @php $u = Format::perubahan($k['nilai'], $k['sebelum']); $teks = Format::teksPerubahan($k['nilai'], $k['sebelum']); @endphp
            <div class="wt-card px-5 py-4 shadow-wt-xs" data-kartu="{{ $k['label'] }}">
                <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">{{ $k['label'] }}</div>
                <div class="mt-1.5 font-display text-[22px] font-extrabold leading-none tabular-nums">{{ Format::tampil($k['nilai'], $k['format']) }}</div>
                <div class="mt-2 flex flex-wrap items-baseline gap-x-2 text-[12px]">
                    @if ($teks)
                        <span @class(['font-semibold', 'text-status-available' => $u['arah'] === 'naik', 'text-status-dirty' => $u['arah'] === 'turun', 'text-ink-500' => $u['arah'] === 'tetap'])
                              title="Sebelumnya {{ Format::tampil($k['sebelum'], $k['format']) }}">{{ $teks }}</span>
                    @endif
                    @if ($k['ket'])<span class="text-ink-500">{{ $k['ket'] }}</span>@endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Grafik batang CSS (bertumpuk per seri) --}}
    @foreach ($d['grafik'] as $g)
        @php
            $jumlahLabel = count($g['label']);
            $totalPer = collect(range(0, $jumlahLabel - 1))->map(fn ($i) => collect($g['seri'])->sum(fn ($s) => max(0, (float) ($s['nilai'][$i] ?? 0))));
            $maks = max((float) ($g['maks'] ?? 0), (float) $totalPer->max(), 1);
        @endphp
        <section class="wt-card p-5 shadow-wt-xs" aria-label="{{ $g['judul'] }}">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">{{ $g['judul'] }}</h2>
                @if (count($g['seri']) > 1)
                    <div class="flex flex-wrap gap-3 text-[12px] text-ink-500">
                        @foreach ($g['seri'] as $s)<span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background: {{ $s['warna'] }}"></span>{{ $s['nama'] }}</span>@endforeach
                    </div>
                @endif
            </div>
            <div class="overflow-x-auto">
                <div class="flex h-48 items-end gap-1 border-b border-cream-300" style="min-width: {{ max(320, $jumlahLabel * 26) }}px">
                    @foreach ($g['label'] as $i => $label)
                        <div class="flex h-full min-w-0 flex-1 flex-col justify-end" title="{{ $label }}: {{ collect($g['seri'])->map(fn ($s) => $s['nama'].' '.Format::tampil($s['nilai'][$i] ?? 0, $g['format']))->join(', ') }}">
                            @foreach (array_reverse($g['seri']) as $s)
                                @php $v = max(0, (float) ($s['nilai'][$i] ?? 0)); @endphp
                                @if ($v > 0)<div style="height: {{ round($v / $maks * 100, 2) }}%; background: {{ $s['warna'] }}" class="w-full first:rounded-t-sm"></div>@endif
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <div class="flex gap-1 pt-1" style="min-width: {{ max(320, $jumlahLabel * 26) }}px">
                    @foreach ($g['label'] as $i => $label)
                        <div class="min-w-0 flex-1 truncate text-center text-[10px] text-ink-500">{{ $jumlahLabel > 16 && $i % 2 ? '' : $label }}</div>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    {{-- Tabel --}}
    <div class="grid gap-5 xl:grid-cols-2">
        @foreach ($d['tabel'] as $t)
            <section @class(['wt-card overflow-hidden shadow-wt-xs', 'xl:col-span-2' => count($t['kolom']) > 4]) aria-label="{{ $t['judul'] }}">
                <h2 class="m-0 border-b border-cream-300 px-5 py-3 font-display text-[13px] font-bold uppercase tracking-[.06em]">{{ $t['judul'] }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead><tr>@foreach ($t['kolom'] as [$label, $format])<th @class(['wt-th', 'text-right' => $format !== 'teks'])>{{ $label }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse ($t['baris'] as $baris)
                                <tr>@foreach ($baris as $i => $v)<td @class(['wt-td', 'text-right tabular-nums' => ($t['kolom'][$i][1] ?? 'teks') !== 'teks'])>{{ Format::tampil($v, $t['kolom'][$i][1] ?? 'teks') }}</td>@endforeach</tr>
                            @empty
                                <tr><td colspan="{{ count($t['kolom']) }}" class="wt-td py-6 text-center text-ink-500">Belum ada data pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                        @isset($t['total'])
                            <tfoot><tr class="bg-cream-100 font-semibold">@foreach ($t['total'] as $i => $v)<td @class(['wt-td', 'text-right tabular-nums' => ($t['kolom'][$i][1] ?? 'teks') !== 'teks'])>{{ Format::tampil($v, $t['kolom'][$i][1] ?? 'teks') }}</td>@endforeach</tr></tfoot>
                        @endisset
                    </table>
                </div>
            </section>
        @endforeach
    </div>

    @if ($d['catatan'])
        <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[12px] text-ink-500" data-catatan>
            @foreach ($d['catatan'] as $c)<li>· {{ $c }}</li>@endforeach
        </ul>
    @endif
</div>
</x-layouts.staf>

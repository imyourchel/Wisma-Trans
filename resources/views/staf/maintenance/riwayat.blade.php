@php
    use App\Models\MaintenanceRequest;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $adaSaringan = collect($saring)->filter(fn ($v) => $v !== '')->isNotEmpty();
@endphp

<x-layouts.staf title="Riwayat Perawatan">
<div>
    @if ($judul)
        <h2 class="mb-3 mt-0 font-display text-[15px] font-extrabold uppercase tracking-[.06em]">{{ $judul }}</h2>
    @endif

    {{-- Saringan lewat query string; total biaya mengikuti saringan --}}
    <form method="GET" class="wt-card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[repeat(5,minmax(0,1fr))_auto_auto] lg:items-end">
        <label class="block"><span class="wt-field-label mb-1.5 block">Kamar</span>
            <x-staf.pilih name="kamar" label="Kamar" :nilai="$saring['kamar']" :pilihan="['' => 'Semua kamar'] + $daftarKamar->mapWithKeys(fn ($n, $id) => [$id => 'Kamar '.$n])->all()" />
        </label>
        <label class="block"><span class="wt-field-label mb-1.5 block">Unit fasilitas</span>
            <x-staf.pilih name="unit" label="Unit fasilitas" :nilai="$saring['unit']" :pilihan="['' => 'Semua unit'] + $daftarUnit->all()" />
        </label>
        <label class="block"><span class="wt-field-label mb-1.5 block">Area</span>
            <x-staf.pilih name="area" label="Area" :nilai="$saring['area']" :pilihan="['' => 'Semua area'] + $daftarArea->mapWithKeys(fn ($a) => [$a => $a])->all()" />
        </label>
        <label class="block"><span class="wt-field-label mb-1.5 block">Jenis</span>
            <x-staf.pilih name="jenis" label="Jenis" :nilai="$saring['jenis']" :pilihan="['' => 'Semua jenis'] + MaintenanceRequest::JENIS" />
        </label>
        <label class="block"><span class="wt-field-label mb-1.5 block">Cara selesai</span>
            <x-staf.pilih name="jalur" label="Cara selesai" :nilai="$saring['jalur']" :pilihan="['' => 'Semua', 'ya' => 'Jalur cepat']" />
        </label>
        <div class="grid grid-cols-2 gap-2 sm:col-span-2 lg:col-span-1">
            <label class="block"><span class="wt-field-label mb-1.5 block">Dari</span><input type="date" name="dari" value="{{ $saring['dari'] }}" class="wt-input"></label>
            <label class="block"><span class="wt-field-label mb-1.5 block">Sampai</span><input type="date" name="sampai" value="{{ $saring['sampai'] }}" class="wt-input"></label>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="h-10 rounded border-0 bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900">Terapkan</button>
            @if ($adaSaringan)<a href="{{ route('staf.maintenance.riwayat') }}" class="whitespace-nowrap text-[13px] font-semibold">Hapus</a>@endif
        </div>
    </form>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-[460px]">
        <div class="wt-card px-4 py-3"><div class="font-display text-2xl font-extrabold tabular-nums">{{ $jumlah }}</div><div class="text-[12px] text-ink-500">Perawatan selesai</div></div>
        <div class="wt-card px-4 py-3"><div class="font-display text-2xl font-extrabold tabular-nums">{{ $rp($totalBiaya) }}</div><div class="text-[12px] text-ink-500">Total biaya{{ $adaSaringan ? ' sesuai saringan' : '' }}</div></div>
    </div>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[960px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Selesai</th>
                    <th class="wt-th">Jenis</th>
                    <th class="wt-th">Lokasi</th>
                    <th class="wt-th">Pekerjaan</th>
                    <th class="wt-th">Dikerjakan oleh</th>
                    <th class="wt-th text-right">Biaya</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($riwayat as $r)
                    <tr class="hover:bg-row-hover">
                        <td class="wt-td whitespace-nowrap font-semibold tabular-nums">
                            {{ $r->request_code }}
                            @if ($r->isJalurCepat())<div class="mt-1"><x-staf.lencana-mnt jalur-cepat /></div>@endif
                        </td>
                        <td class="wt-td whitespace-nowrap">{{ $r->completed_at?->translatedFormat('d M Y') }}</td>
                        <td class="wt-td"><span class="wt-lencana {{ $r->maintenance_type === 'preventive' ? 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]' : 'wt-lencana-netral' }}">{{ MaintenanceRequest::JENIS[$r->maintenance_type] }}</span></td>
                        <td class="wt-td whitespace-nowrap">
                            @if ($r->unit)
                                <a href="{{ route('staf.maintenance.riwayat', ['unit' => $r->facility_unit_id]) }}">{{ $r->lokasi() }}</a>
                            @elseif ($r->room)
                                <a href="{{ route('staf.maintenance.riwayat', ['kamar' => $r->room_id]) }}">{{ $r->lokasi() }}</a>
                            @else
                                {{ $r->lokasi() }}
                            @endif
                        </td>
                        <td class="wt-td max-w-[320px]">
                            <div class="line-clamp-2">{{ $r->damage_description }}</div>
                            @if ($r->resolution_note)<div class="mt-0.5 line-clamp-2 text-[11px] text-ink-500">{{ $r->resolution_note }}</div>@endif
                        </td>
                        <td class="wt-td text-ink-500">{{ $r->pengerja() ?? '—' }}</td>
                        <td class="wt-td whitespace-nowrap text-right tabular-nums">
                            @if ($r->cost !== null)
                                {{ $rp($r->cost) }}
                            @elseif ($r->isJalurCepat())
                                <span class="text-ink-500">Belum diisi</span>
                                <a href="{{ route('staf.maintenance.index', ['tab' => 'completed', 'jalur' => 'belum', 'cari' => $r->request_code]) }}" class="block text-[12px] font-semibold">Lengkapi</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="wt-td py-10 text-center text-sm text-ink-500">Belum ada perawatan selesai{{ $adaSaringan ? ' yang cocok dengan saringan' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $riwayat->links('partials.halaman') }}
</div>
</x-layouts.staf>

@php
    use App\Services\Laporan\Format;

    $kelasKartu = 'wt-card block p-5 text-ink-900 hover:border-cream-400 hover:bg-row-hover hover:text-ink-900 hover:no-underline';
@endphp

{{-- Dimuat ulang tiap 60 detik, berhenti saat tab tidak terlihat --}}
<div wire:poll.60s.visible class="flex flex-col gap-6">
    @if ($frontOffice)
        <section aria-labelledby="judul-fo">
            <div class="mb-2.5 flex items-baseline justify-between gap-3">
                <h2 id="judul-fo" class="m-0 font-display text-sm font-bold uppercase tracking-[.08em]">Front Office hari ini</h2>
                <span class="text-[12px] text-ink-500">Diperbarui {{ now()->format('H.i') }} WITA</span>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                @foreach ($frontOffice as $k)
                    <a href="{{ $k['url'] }}" class="{{ $kelasKartu }}" data-kartu="{{ $k['kunci'] }}" @if ($k['awas']) data-awas @endif>
                        <div class="text-xs font-semibold uppercase tracking-[.08em] text-ink-500">{{ $k['label'] }}</div>
                        <div @class(['mt-1 font-display text-[22px] font-extrabold tabular-nums', 'text-status-dirty' => $k['awas']])>{{ $k['nilai'] }}</div>
                        <div @class(['mt-1 text-[12px]', 'font-semibold text-status-dirty' => $k['awas'], 'text-ink-500' => ! $k['awas']])>{{ $k['ket'] }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($manager)
        <section aria-labelledby="judul-hari-ini">
            <div class="mb-2.5 flex items-baseline justify-between gap-3">
                <h2 id="judul-hari-ini" class="m-0 font-display text-sm font-bold uppercase tracking-[.08em]">Hari ini</h2>
                <span class="text-[12px] text-ink-500">Diperbarui {{ now()->format('H.i') }} WITA</span>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                @foreach ($manager['hariIni'] as $k)
                    <a href="{{ $k['url'] }}" class="{{ $kelasKartu }}" @isset($k['kunci']) data-kartu="{{ $k['kunci'] }}" @endisset>
                        <div class="text-xs font-semibold uppercase tracking-[.08em] text-ink-500">{{ $k['label'] }}</div>
                        <div class="mt-1 font-display text-[22px] font-extrabold tabular-nums">{{ $k['nilai'] }}</div>
                        <div class="mt-1 text-[12px] text-ink-500">{{ $k['ket'] }}</div>
                    </a>
                @endforeach
            </div>
            <p class="mb-0 mt-2 text-[12px] text-ink-500">Kamar terisi dan okupansi dihitung dari reservasi, sama dengan Laporan Hunian.</p>
        </section>

        <section aria-labelledby="judul-bulan-ini">
            <div class="mb-2.5 flex items-baseline justify-between gap-3">
                <h2 id="judul-bulan-ini" class="m-0 font-display text-sm font-bold uppercase tracking-[.08em]">Bulan ini</h2>
                <span class="text-[12px] text-ink-500">1–{{ today()->translatedFormat('j M') }}, dibanding tanggal yang sama bulan lalu</span>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                @foreach ($manager['bulanIni'] as $k)
                    @php $u = Format::perubahan($k['nilai'], $k['sebelum']); $teks = Format::teksPerubahan($k['nilai'], $k['sebelum']); @endphp
                    <a href="{{ $k['url'] }}" class="{{ $kelasKartu }}" data-kartu="{{ $k['label'] }}">
                        <div class="text-xs font-semibold uppercase tracking-[.08em] text-ink-500">{{ $k['label'] }}</div>
                        <div class="mt-1 font-display text-[22px] font-extrabold tabular-nums">{{ Format::tampil($k['nilai'], $k['format']) }}</div>
                        @if ($teks)
                            <div @class(['mt-1 text-[12px] font-semibold', 'text-status-available' => $u['arah'] === 'naik', 'text-status-dirty' => $u['arah'] === 'turun', 'text-ink-500' => $u['arah'] === 'tetap'])
                                 title="Bulan lalu {{ Format::tampil($k['sebelum'], $k['format']) }}">{{ $teks }}</div>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($piutang)
        <section aria-label="Piutang instansi">
            <h2 class="mb-2.5 mt-0 font-display text-sm font-bold uppercase tracking-[.08em]">Piutang instansi</h2>
            @include('livewire.staf.keuangan.partials.kartu-piutang', ['ringkasan' => $piutang])
        </section>
    @endif
</div>

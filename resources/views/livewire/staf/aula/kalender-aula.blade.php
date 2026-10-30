@php
    $gaya = [
        'pending' => 'bg-[rgba(232,172,69,.2)] text-[#7A520C] ring-1 ring-inset ring-[rgba(232,172,69,.5)]',
        'confirmed' => 'bg-brown-800 text-cream-50',
        'completed' => 'bg-cream-300 text-ink-500',
    ];
    $nama = ['pending' => 'Menunggu persetujuan', 'confirmed' => 'Terkonfirmasi', 'completed' => 'Selesai'];
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="geser(-1)" class="wt-btn-sm" aria-label="Bulan sebelumnya">‹</button>
        <button type="button" wire:click="geser(0)" class="wt-btn-sm">Bulan ini</button>
        <button type="button" wire:click="geser(1)" class="wt-btn-sm" aria-label="Bulan berikutnya">›</button>
        <h2 class="m-0 ml-2 font-display text-lg font-extrabold uppercase tracking-[.06em]">{{ $awal->translatedFormat('F Y') }}</h2>
        <div class="ml-auto flex flex-wrap items-center gap-3 text-xs text-ink-500">
            @foreach ($lihatSaja ? array_diff_key($nama, ['pending' => 1]) : $nama as $s => $l)
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-5 rounded-sm {{ $gaya[$s] }}"></span>{{ $l }} ({{ $jumlah[$s] ?? 0 }})</span>
            @endforeach
            <a href="{{ route('staf.aula-pemesanan') }}" class="wt-btn-sm hover:no-underline">Daftar pemesanan</a>
        </div>
    </div>

    {{-- Layar sempit (< 768 px): daftar per hari yang ada pemesanannya, pengganti kisi bulan --}}
    @php $adaAcara = collect($hari)->filter(fn ($h) => count($h['pemesanan']) && $h['tanggal']->isSameMonth($awal)); @endphp
    <div class="wt-card divide-y divide-cream-300 md:hidden" data-kalender-daftar>
        @forelse ($adaAcara as $h)
            @php $d = $h['tanggal']; @endphp
            <div wire:key="d-{{ $d->toDateString() }}" class="flex gap-3 px-4 py-3">
                <div @class(['flex h-11 w-11 flex-none flex-col items-center justify-center rounded-md leading-none', 'bg-clay-500 text-cream-50' => $d->isToday(), 'bg-cream-200 text-ink-900' => ! $d->isToday()])>
                    <span class="font-display text-[15px] font-extrabold tabular-nums">{{ $d->day }}</span>
                    <span class="mt-0.5 text-[10px] font-semibold uppercase">{{ $d->translatedFormat('D') }}</span>
                </div>
                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    @foreach ($h['pemesanan'] as $b)
                        <a href="{{ route('staf.aula-pemesanan', ['tab' => $b->status === 'pending' ? 'menunggu' : ($b->status === 'confirmed' ? 'terkonfirmasi' : 'riwayat'), 'buka' => $b->id]) }}"
                           class="flex min-h-11 flex-col justify-center rounded px-3 py-2 text-[13px] leading-snug hover:no-underline {{ $gaya[$b->status] }}">
                            <span class="font-semibold">{{ $b->start_at->isSameDay($d) ? $b->start_at->format('H.i') : 'Lanjutan' }} · {{ $b->purpose ?: $b->booking_code }}</span>
                            <span class="truncate text-[12px] opacity-80">{{ $b->booking_code }} · {{ $b->customer?->full_name }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="m-0 px-4 py-10 text-center text-sm text-ink-500">Belum ada pemesanan aula bulan ini.</p>
        @endforelse
    </div>

    <div class="wt-card overflow-x-auto max-md:hidden">
        <div class="grid min-w-[840px] grid-cols-7">
            @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $h)
                <div @class(['border-b border-cream-300 bg-cream-200 px-3 py-2 font-display text-[11px] font-bold uppercase tracking-[.1em]', 'text-clay-600' => $h === 'Min', 'text-ink-500' => $h !== 'Min'])>{{ $h }}</div>
            @endforeach
            @foreach ($hari as $h)
                @php $d = $h['tanggal']; $luar = ! $d->isSameMonth($awal); @endphp
                <div wire:key="h-{{ $d->toDateString() }}" @class(['min-h-[112px] border-b border-r border-cream-300 p-2 [&:nth-child(7n)]:border-r-0', 'bg-cream-100/60' => $luar])>
                    <div @class(['mb-1.5 flex h-6 w-6 items-center justify-center rounded-full text-[12px] font-semibold tabular-nums',
                        'bg-clay-500 text-cream-50' => $d->isToday(), 'text-ink-500' => $luar && ! $d->isToday()])>{{ $d->day }}</div>
                    <div class="flex flex-col gap-1">
                        @foreach ($h['pemesanan'] as $b)
                            <a href="{{ route('staf.aula-pemesanan', ['tab' => $b->status === 'pending' ? 'menunggu' : ($b->status === 'confirmed' ? 'terkonfirmasi' : 'riwayat'), 'buka' => $b->id]) }}"
                               title="{{ $b->booking_code }} · {{ $b->purpose }} · {{ $b->customer?->full_name }}"
                               class="block truncate rounded px-1.5 py-1 text-[11px] font-semibold leading-tight hover:no-underline hover:opacity-90 {{ $gaya[$b->status] }}">
                                {{ $b->start_at->isSameDay($d) ? $b->start_at->format('H.i') : 'lanjutan' }} · {{ $b->purpose ?: $b->booking_code }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

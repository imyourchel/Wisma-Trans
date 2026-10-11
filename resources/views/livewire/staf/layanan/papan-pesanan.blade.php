@php
    use App\Services\PesananMakananService as PM;
    use App\Support\GayaPesanan;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $judulKolom = ['pending' => 'Antre', 'in_progress' => 'Dimasak', 'ready' => 'Siap'];
    $tombol = ['pending' => ['mulai', 'Mulai masak'], 'in_progress' => ['siap', 'Siap'], 'ready' => ['serahkan', 'Serahkan']];
    $ringkasan = [
        ['Antre', $kolom['pending']->count(), GayaPesanan::AKSEN['pending'], false],
        ['Dimasak', $kolom['in_progress']->count(), GayaPesanan::AKSEN['in_progress'], false],
        ['Siap', $kolom['ready']->count(), GayaPesanan::AKSEN['ready'], false],
        ['Terlambat', $terlambat, '#D4514A', $terlambat > 0],
        ['Selesai hari ini', $selesaiHariIni, '#9B8B78', false],
    ];
@endphp

<div>
    @if ($perluRefund > 0)
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-[rgba(212,81,74,.4)] bg-[rgba(212,81,74,.07)] px-4 py-3 text-[13px]" role="alert">
            <span class="font-semibold text-status-dirty">Perlu pengembalian dana: {{ $perluRefund }} pembayaran</span>
            <span class="text-ink-500">Pembayaran online masuk untuk pesanan yang sudah lunas atau dibatalkan. Pengembalian diurus lewat Keuangan.</span>
        </div>
    @endif

    {{-- Ringkasan --}}
    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($ringkasan as [$label, $nilai, $warna, $awas])
            <div @class(['wt-card relative overflow-hidden px-5 py-4 shadow-wt-xs', 'border-[rgba(212,81,74,.45)] bg-[rgba(212,81,74,.05)]' => $awas])>
                <span class="absolute inset-y-0 left-0 w-1" style="background-color: {{ $warna }}"></span>
                <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">{{ $label }}</div>
                <div @class(['mt-1.5 font-display text-[30px] font-extrabold leading-none tabular-nums', 'text-status-dirty' => $awas])>{{ $nilai }}</div>
            </div>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3 text-[12px] text-ink-500">
        <span>Terlambat bila belum siap lebih dari {{ $batas }} menit sejak masuk dapur.</span>
        {{-- Muat ulang otomatis, berhenti selama detail pesanan terbuka --}}
        @unless ($o)
            <span wire:poll.15s wire:key="poll-papan" class="ml-auto">Diperbarui {{ now()->format('H.i') }} WITA</span>
        @endunless
    </div>

    {{-- Kolom dapur --}}
    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($kolom as $s => $daftar)
            <section wire:key="kolom-{{ $s }}" class="flex min-h-[220px] flex-col rounded-md border border-cream-300 bg-cream-150/60 lg:min-h-[calc(100vh-420px)]" aria-label="{{ $judulKolom[$s] }}">
                <header class="flex items-center gap-2.5 border-b border-cream-300 px-4 py-3">
                    <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ GayaPesanan::AKSEN[$s] }}"></span>
                    <h2 class="m-0 flex-1 font-display text-[12px] font-bold uppercase tracking-[.08em]">{{ $judulKolom[$s] }}</h2>
                    <span class="rounded-full bg-cream-50 px-2 py-0.5 text-[12px] font-semibold tabular-nums text-ink-500">{{ $daftar->count() }}</span>
                </header>
                <div class="flex flex-1 flex-col gap-2.5 p-3">
                    @forelse ($daftar as $x)
                        @php
                            $telat = PM::terlambat($x);
                            [$kunciBayar, $labelBayar] = PM::pembayaran($x);
                        @endphp
                        <article wire:key="psn-{{ $x->id }}" @class(['rounded-md border bg-cream-50 shadow-wt-xs transition hover:shadow-wt-md',
                            'border-[rgba(212,81,74,.55)]' => $telat, 'border-cream-300' => ! $telat])>
                            <button type="button" wire:click="open({{ $x->id }})" class="block w-full cursor-pointer border-0 bg-transparent p-4 pb-3 text-left">
                                <div class="flex items-center justify-between gap-2 text-[11px]">
                                    <span class="font-semibold tabular-nums tracking-[.02em] text-ink-500">{{ $x->order_code }}</span>
                                    <span class="flex items-center gap-1.5 tabular-nums">
                                        @if ($telat)<span class="wt-lencana bg-status-dirty text-cream-50">Terlambat</span>@endif
                                        <span @class(['font-semibold text-status-dirty' => $telat, 'text-ink-500' => ! $telat])>
                                            {{ PM::mulaiAntre($x)->format('H.i') }} · {{ PM::durasi(PM::menitTunggu($x)) }}
                                        </span>
                                    </span>
                                </div>
                                <div class="mt-2 font-display text-[15px] font-extrabold">{{ PM::jenis($x) }}</div>
                                <div class="truncate text-[13px] text-ink-500">{{ $x->customer?->full_name }}</div>

                                <ul class="m-0 mt-3 flex list-none flex-col gap-1.5 border-t border-dashed border-cream-300 p-0 pt-3 text-[13px]">
                                    @foreach ($x->items as $i)
                                        <li>
                                            <span class="font-display font-extrabold tabular-nums">{{ (int) $i->quantity }}×</span> {{ $i->service?->name }}
                                            @if ($i->note)
                                                <span class="mt-0.5 block w-fit rounded bg-[rgba(232,172,69,.2)] px-2 py-0.5 text-[12px] font-semibold text-[#8A5E10]">{{ $i->note }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($x->note)
                                    <p class="m-0 mt-2.5 rounded bg-cream-100 px-2.5 py-1.5 text-[12px] italic text-ink-900">“{{ $x->note }}”</p>
                                @endif
                            </button>
                            <div class="flex items-center gap-2 px-4 pb-4">
                                <span class="wt-lencana {{ GayaPesanan::BAYAR[$kunciBayar] }}">{{ $labelBayar }}</span>
                                @if ($bolehKelola)
                                    <button type="button" wire:click="{{ $tombol[$s][0] }}({{ $x->id }})" wire:loading.attr="disabled" wire:target="{{ $tombol[$s][0] }}({{ $x->id }})"
                                            class="ml-auto h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-60">{{ $tombol[$s][1] }}</button>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="flex flex-1 items-center justify-center rounded-md border border-dashed border-cream-400 px-4 py-8 text-center text-[13px] text-ink-500">Tidak ada pesanan.</div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        {{-- Menunggu pembayaran: hanya informasi, belum boleh dimasak --}}
        <details class="wt-card group p-4" @if ($menungguBayar->isNotEmpty()) open @endif>
            <summary class="flex cursor-pointer list-none items-center gap-2">
                <h2 class="m-0 flex-1 font-display text-[13px] font-bold uppercase tracking-[.06em]">Menunggu pembayaran ({{ $menungguBayar->count() }})</h2>
                <span class="text-ink-500 transition group-open:rotate-180" aria-hidden="true">▾</span>
            </summary>
            <p class="mb-0 mt-1 text-xs text-ink-500">Pesanan 24 jam terakhir yang belum lunas. Masuk kolom Antre otomatis setelah dibayar.</p>
            @if ($menungguBayar->isEmpty())
                <p class="mb-0 mt-3 text-[13px] text-ink-500">Tidak ada.</p>
            @else
                <ul class="m-0 mt-3 flex list-none flex-col p-0 text-[13px]">
                    @foreach ($menungguBayar as $x)
                        <li wire:key="bayar-{{ $x->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-cream-300 py-2 first:border-t-0">
                            <button type="button" wire:click="open({{ $x->id }})" class="border-0 bg-transparent p-0 font-semibold tabular-nums text-brown-800 hover:underline">{{ $x->order_code }}</button>
                            <span class="text-ink-500">{{ $x->customer?->full_name }} · {{ PM::ringkasMenu($x) }}</span>
                            <span class="ml-auto tabular-nums">{{ $rp(PM::total($x)) }}</span>
                            <span class="text-[11px] text-ink-500">batas {{ $x->batasBayar()->format('H.i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </details>

        {{-- Menu sedang habis, supaya tidak terlupa ditandai tersedia lagi --}}
        <div class="wt-card p-4">
            <div class="flex items-center gap-2">
                <h2 class="m-0 flex-1 font-display text-[13px] font-bold uppercase tracking-[.06em]">Menu sedang habis ({{ $menuHabis->count() }})</h2>
                <a href="{{ route('staf.fnb.ketersediaan') }}" class="text-[12px]">Ketersediaan menu</a>
            </div>
            @if ($menuHabis->isEmpty())
                <p class="mb-0 mt-3 text-[13px] text-ink-500">Semua menu tersedia.</p>
            @else
                <ul class="m-0 mt-3 flex list-none flex-col p-0 text-[13px]">
                    @foreach ($menuHabis as $m)
                        <li wire:key="habis-{{ $m->id }}" class="flex items-center gap-3 border-t border-cream-300 py-2 first:border-t-0">
                            <span class="h-2 w-2 flex-none rounded-full bg-status-dirty"></span>
                            <span class="flex-1 font-semibold">{{ $m->name }}</span>
                            @if ($bolehKelola)
                                <button type="button" wire:click="tersediaLagi({{ $m->id }})" class="wt-btn-sm h-8">Tersedia lagi</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($o)
        @include('livewire.staf.layanan.partials.detail-pesanan', ['aksiDapur' => $bolehKelola])
    @endif
</div>

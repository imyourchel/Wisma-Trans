@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
@endphp

{{-- konfirmasi: menu yang akan ditandai habis ({id, nama}); null = dialog tertutup --}}
<div x-data="{ konfirmasi: null }" @keydown.escape.window="konfirmasi = null">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="wt-geser inline-flex max-w-full rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Saring menu">
            @foreach (['semua' => "Semua ({$jumlah})", 'habis' => "Sedang habis ({$jumlahHabis})"] as $v => $l)
                <button type="button" wire:click="$set('saring', '{{ $v }}')" role="tab" aria-selected="{{ $saring === $v ? 'true' : 'false' }}"
                        @class(['h-8 rounded-md border-0 px-3.5 text-[13px] font-semibold transition-colors',
                            'bg-brown-800 text-cream-50' => $saring === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $saring !== $v])>{{ $l }}</button>
            @endforeach
        </div>
        <label class="relative block min-w-0 flex-[1_1_220px] sm:max-w-[300px]">
            <span class="sr-only">Cari menu</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.300ms="cari" placeholder="Cari menu…" class="wt-input pl-9">
        </label>
        {{-- Info: muncul saat ikon disorot, difokus (keyboard), atau diketuk; Esc menutup --}}
        <div class="relative flex-none" x-data="{ buka: false }" @mouseenter="buka = true" @mouseleave="buka = false" @keydown.escape="buka = false">
            <button type="button" @click="buka = !buka" @focus="buka = true" @blur="buka = false" :aria-expanded="buka"
                    aria-label="Info tanda habis" aria-describedby="info-tanda-habis" data-info-habis
                    class="flex h-8 w-8 items-center justify-center rounded-full border-0 bg-transparent text-ink-500 transition hover:bg-cream-200 hover:text-brown-800 focus-visible:bg-cream-200 focus-visible:text-brown-800">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 16.5v-5"/><path d="M12 8h.01"/></svg>
            </button>
            <div id="info-tanda-habis" role="tooltip" x-show="buka" x-cloak
                 x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="absolute left-1/2 top-full z-30 mt-2 w-[280px] -translate-x-1/2 rounded-md bg-brown-800 px-3.5 py-2.5 text-[12.5px] leading-relaxed text-cream-50 shadow-wt-lg sm:left-0 sm:translate-x-[-12px]">
                Tanda habis hanya berlaku untuk pesanan baru. Untuk menghapus menu dari daftar, minta Admin menonaktifkannya di Data Master.
            </div>
        </div>
    </div>

    @forelse ($grup as $nama => $isi)
        <section wire:key="grup-{{ \Illuminate\Support\Str::slug($nama) }}" class="mb-5">
            <h2 class="mb-2.5 mt-0 font-display text-[12px] font-bold uppercase tracking-[.08em] text-ink-500">{{ $nama }} <span class="font-normal">· {{ $isi->count() }}</span></h2>
            <div class="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($isi as $m)
                    <div wire:key="menu-{{ $m->id }}" @class(['wt-card flex items-center gap-3 p-3 shadow-wt-xs', 'border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.04)]' => $m->habis()])>
                        @if ($foto = $m->fotoUrl())
                            <img src="{{ $foto }}" alt="" class="h-12 w-12 flex-none rounded-md object-cover" loading="lazy">
                        @else
                            <span class="flex h-12 w-12 flex-none items-center justify-center rounded-md bg-cream-200 text-clay-500" aria-hidden="true"><x-staf.ikon nama="layanan" ukuran="20" /></span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div @class(['truncate text-[14px] font-semibold', 'text-ink-500' => $m->habis()])>{{ $m->name }}</div>
                            <div class="flex items-center gap-2 text-[12px]">
                                <span class="tabular-nums text-ink-500">{{ $m->price !== null ? $rp($m->price) : 'Harga sesuai ukuran' }}</span>
                                <span @class(['wt-lencana', 'bg-[rgba(212,81,74,.12)] text-status-dirty' => $m->habis(), 'wt-lencana-aktif' => ! $m->habis()])>{{ $m->habis() ? 'Habis' : 'Tersedia' }}</span>
                            </div>
                        </div>
                        @if ($bolehKelola)
                            @if ($m->habis())
                                <button type="button" wire:click="tersediaLagi({{ $m->id }})" wire:loading.attr="disabled" class="h-9 flex-none rounded border-0 bg-status-available px-3 text-[12px] font-semibold text-cream-50">Tersedia lagi</button>
                            @else
                                <button type="button" @click="konfirmasi = { id: {{ $m->id }}, nama: @js($m->name) }" wire:loading.attr="disabled" class="wt-btn-sm h-9 flex-none">Tandai habis</button>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">{{ $saring === 'habis' ? 'Tidak ada menu yang sedang habis.' : 'Menu tidak ditemukan.' }}</div>
    @endforelse
    {{-- Konfirmasi sebelum menandai habis --}}
    <div x-show="konfirmasi" x-cloak class="fixed inset-0 z-[340] flex items-center justify-center p-4" data-konfirmasi-habis>
        <div @click="konfirmasi = null" x-show="konfirmasi" x-transition.opacity class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
        <div x-show="konfirmasi" x-transition role="alertdialog" aria-modal="true" aria-labelledby="judul-konfirmasi-habis" aria-describedby="isi-konfirmasi-habis"
             x-effect="konfirmasi && $nextTick(() => $refs.yaHabis.focus())"
             class="relative w-[420px] max-w-full rounded-xl border border-cream-300 bg-cream-50 p-6 shadow-wt-lg">
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-[rgba(212,81,74,.1)] text-status-dirty" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            </span>
            <h2 id="judul-konfirmasi-habis" class="mb-0 mt-4 font-display text-[17px] font-extrabold leading-snug">
                Apakah Anda yakin menandai <span x-text="konfirmasi?.nama"></span> habis?
            </h2>
            <p id="isi-konfirmasi-habis" class="mb-0 mt-2 text-[13px] leading-relaxed text-ink-500">
                Menu ini tidak bisa dipesan tamu maupun lewat Kasir sampai ditandai tersedia lagi. Pesanan yang sudah masuk tetap diproses.
            </p>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" @click="konfirmasi = null" class="wt-btn-sm h-10 px-4">Batal</button>
                <button type="button" x-ref="yaHabis" @click="$wire.tandaiHabis(konfirmasi.id); konfirmasi = null"
                        class="h-10 rounded border-0 bg-status-dirty px-4 font-display text-xs font-bold text-cream-50 hover:opacity-90">Ya, tandai habis</button>
            </div>
        </div>
    </div>
</div>

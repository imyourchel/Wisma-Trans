@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Saring menu">
            @foreach (['semua' => "Semua ({$jumlah})", 'habis' => "Habis saja ({$jumlahHabis})"] as $v => $l)
                <button type="button" wire:click="$set('saring', '{{ $v }}')" role="tab" aria-selected="{{ $saring === $v ? 'true' : 'false' }}"
                        @class(['h-8 rounded-md border-0 px-3.5 text-[13px] font-semibold transition-colors',
                            'bg-brown-800 text-cream-50' => $saring === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $saring !== $v])>{{ $l }}</button>
            @endforeach
        </div>
        <label class="relative block min-w-0 flex-[1_1_220px] sm:max-w-[300px]">
            <span class="sr-only">Cari menu</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.300ms="cari" placeholder="Cari nama menu" class="wt-input pl-9">
        </label>
        <span class="text-[12px] text-ink-500">Menandai habis tidak memengaruhi pesanan yang sudah masuk. Menu nonaktif diatur Admin di Data Master.</span>
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
                                <button type="button" wire:click="tandaiHabis({{ $m->id }})" wire:loading.attr="disabled" class="wt-btn-sm h-9 flex-none">Tandai habis</button>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">{{ $saring === 'habis' ? 'Tidak ada menu yang sedang habis.' : 'Menu tidak ditemukan.' }}</div>
    @endforelse
</div>

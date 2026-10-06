@props(['menu', 'besar' => false])
{{-- Tombol tambah ke keranjang; berubah menjadi pengatur jumlah setelah ditambahkan. Menu yang ditandai habis tidak bisa dipesan. --}}
@if ($menu->habis())
    <span class="flex-none rounded-full bg-[rgba(212,81,74,.1)] px-2.5 py-1 font-display text-[10px] font-bold uppercase tracking-[.12em] text-status-dirty">Habis</span>
@elseif ($menu->price === null)
    <span class="flex-none text-right text-[11px] leading-tight text-ink-500">Pesan<br>di tempat</span>
@else
    <div class="flex-none">
        <button type="button" x-show="!jumlah({{ $menu->id }})" @click="tambah({{ $menu->id }})" aria-label="Tambah {{ $menu->name }} ke pesanan"
                @class(['flex items-center justify-center gap-1.5 rounded-full border-0 bg-clay-500 font-display font-semibold text-cream-50 shadow-wt-xs hover:bg-clay-600',
                    'h-9 px-4 text-xs' => $besar, 'h-9 w-9' => ! $besar])>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            @if ($besar)<span>Tambah</span>@endif
        </button>
        <div x-show="jumlah({{ $menu->id }})" x-cloak class="flex items-center gap-0.5 rounded-full border border-cream-300 bg-cream-50 p-0.5">
            <button type="button" @click="kurang({{ $menu->id }})" aria-label="Kurangi {{ $menu->name }}"
                    class="flex h-8 w-8 items-center justify-center rounded-full border-0 bg-transparent text-brown-800 hover:bg-cream-200">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg>
            </button>
            <span class="min-w-6 text-center font-display text-sm font-bold tabular-nums" x-text="jumlah({{ $menu->id }})"></span>
            <button type="button" @click="tambah({{ $menu->id }})" aria-label="Tambah {{ $menu->name }}"
                    class="flex h-8 w-8 items-center justify-center rounded-full border-0 bg-clay-500 text-cream-50 hover:bg-clay-600">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
        </div>
    </div>
@endif

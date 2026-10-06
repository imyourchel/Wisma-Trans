@props(['tipe', 'tautan' => null, 'layarPenuh' => false, 'redup' => false])
{{-- Foto tipe kamar yang bisa digeser. Tanpa foto: panel pengganti bernama tipe.
     tautan: foto menjadi tautan (kartu daftar); layarPenuh: ketuk foto untuk galeri layar penuh (halaman detail). --}}
@php
    $foto = $tipe->photoUrls();
    $n = count($foto);
@endphp
<div x-data="{
        i: 0, n: {{ $n }}, layar: false,
        ke(k) { const el = this.$refs.jalur; if (el) el.scrollTo({ left: el.clientWidth * Math.max(0, Math.min(this.n - 1, k)), behavior: 'smooth' }); },
        bukaLayar() { this.layar = true; this.$nextTick(() => { const el = this.$refs.jalurLayar; if (el) el.scrollLeft = el.clientWidth * this.i; }); },
     }"
     {{ $attributes->merge(['class' => 'group/galeri relative isolate overflow-hidden bg-brown-800']) }}>
    @if ($n)
        <div x-ref="jalur" @scroll.debounce.60ms="i = Math.round($el.scrollLeft / $el.clientWidth)"
             class="flex h-full snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($foto as $url)
                @if ($tautan)
                    <a href="{{ $tautan }}" class="block h-full w-full flex-none snap-center" tabindex="{{ $loop->first ? 0 : -1 }}" aria-label="Lihat detail {{ $tipe->name }}">
                        <img src="{{ $url }}" alt="Foto {{ $tipe->name }} {{ $loop->iteration }}" loading="lazy" @class(['h-full w-full object-cover', 'grayscale' => $redup])>
                    </a>
                @elseif ($layarPenuh)
                    <button type="button" @click="bukaLayar()" class="block h-full w-full flex-none snap-center border-0 bg-transparent p-0" aria-label="Lihat foto {{ $loop->iteration }} layar penuh">
                        <img src="{{ $url }}" alt="Foto {{ $tipe->name }} {{ $loop->iteration }}" @class(['h-full w-full object-cover', 'grayscale' => $redup])>
                    </button>
                @else
                    <img src="{{ $url }}" alt="Foto {{ $tipe->name }} {{ $loop->iteration }}" loading="lazy" @class(['h-full w-full flex-none snap-center object-cover', 'grayscale' => $redup])>
                @endif
            @endforeach
        </div>

        @if ($n > 1)
            {{-- Panah muncul saat disorot (layar lebar); di ponsel cukup digeser --}}
            @foreach ([['kiri', -1, 'left-2', 'Foto sebelumnya'], ['kanan', 1, 'right-2', 'Foto berikutnya']] as [$ikon, $arah, $posisi, $label])
                <button type="button" @click.prevent="ke(i + {{ $arah }})" aria-label="{{ $label }}"
                        x-show="{{ $arah < 0 ? 'i > 0' : 'i < n - 1' }}"
                        class="absolute {{ $posisi }} top-1/2 z-10 hidden h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full border-0 bg-[rgba(255,253,246,.92)] text-brown-800 opacity-0 shadow-wt-xs transition group-hover/galeri:opacity-100 sm:flex">
                    <x-tamu.ikon :nama="$ikon" ukuran="18" />
                </button>
            @endforeach
            @if ($layarPenuh)
                <span class="pointer-events-none absolute bottom-3 right-3 z-10 rounded-full bg-[rgba(40,27,18,.72)] px-2.5 py-1 text-xs font-semibold tabular-nums text-cream-50" x-text="(i + 1) + '/' + n"></span>
            @else
                <div class="pointer-events-none absolute inset-x-0 bottom-2.5 z-10 flex justify-center gap-1.5">
                    @for ($k = 0; $k < $n; $k++)
                        <span class="h-1.5 rounded-full bg-cream-50 transition-all" :class="i === {{ $k }} ? 'w-4 opacity-100' : 'w-1.5 opacity-60'"></span>
                    @endfor
                </div>
            @endif
        @endif
        @if ($layarPenuh)
            <button type="button" @click="bukaLayar()" aria-label="Lihat foto layar penuh"
                    class="absolute right-3 top-3 z-10 flex h-9 w-9 items-center justify-center rounded-full border-0 bg-[rgba(40,27,18,.6)] text-cream-50 hover:bg-[rgba(40,27,18,.8)]">
                <x-tamu.ikon nama="perbesar" ukuran="16" />
            </button>

            {{-- Galeri layar penuh --}}
            <template x-teleport="body">
                <div x-show="layar" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-label="Foto {{ $tipe->name }}"
                     @keydown.escape.window="layar = false" @keydown.arrow-right.window="layar && $refs.jalurLayar.scrollBy({ left: $refs.jalurLayar.clientWidth, behavior: 'smooth' })"
                     @keydown.arrow-left.window="layar && $refs.jalurLayar.scrollBy({ left: -$refs.jalurLayar.clientWidth, behavior: 'smooth' })"
                     class="fixed inset-0 z-[400] flex flex-col bg-[rgba(20,13,8,.96)]">
                    <div class="flex flex-none items-center justify-between px-4 py-3 text-cream-50 sm:px-6">
                        <span class="font-display text-xs font-semibold uppercase tracking-[.16em]">{{ $tipe->name }}</span>
                        <span class="text-sm tabular-nums text-[rgba(242,235,220,.8)]" x-text="(i + 1) + ' / ' + n"></span>
                        <button type="button" @click="layar = false" aria-label="Tutup galeri"
                                class="flex h-10 w-10 items-center justify-center rounded-full border-0 bg-[rgba(242,235,220,.12)] text-cream-50 hover:bg-[rgba(242,235,220,.2)]">
                            <x-tamu.ikon nama="tutup" ukuran="18" />
                        </button>
                    </div>
                    <div x-ref="jalurLayar" @scroll.debounce.60ms="i = Math.round($el.scrollLeft / $el.clientWidth)"
                         class="flex min-h-0 flex-1 snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        @foreach ($foto as $url)
                            <div class="flex h-full w-full flex-none snap-center items-center justify-center p-3 sm:p-8">
                                <img src="{{ $url }}" alt="Foto {{ $tipe->name }} {{ $loop->iteration }}" class="max-h-full max-w-full object-contain">
                            </div>
                        @endforeach
                    </div>
                    <div class="flex flex-none justify-center gap-3 pb-6 pt-3">
                        <button type="button" @click="$refs.jalurLayar.scrollBy({ left: -$refs.jalurLayar.clientWidth, behavior: 'smooth' })" aria-label="Foto sebelumnya"
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-[rgba(242,235,220,.3)] bg-transparent text-cream-50 hover:bg-[rgba(242,235,220,.12)]"><x-tamu.ikon nama="kiri" ukuran="20" /></button>
                        <button type="button" @click="$refs.jalurLayar.scrollBy({ left: $refs.jalurLayar.clientWidth, behavior: 'smooth' })" aria-label="Foto berikutnya"
                                class="flex h-11 w-11 items-center justify-center rounded-full border border-[rgba(242,235,220,.3)] bg-transparent text-cream-50 hover:bg-[rgba(242,235,220,.12)]"><x-tamu.ikon nama="kanan" ukuran="20" /></button>
                    </div>
                </div>
            </template>
        @endif
    @else
        @if ($tautan)<a href="{{ $tautan }}" class="block h-full hover:no-underline" aria-label="Lihat detail {{ $tipe->name }}">@endif
        <div class="flex h-full flex-col items-center justify-center gap-3 bg-[radial-gradient(circle_at_30%_20%,rgba(228,185,143,.22),transparent_55%),radial-gradient(circle_at_80%_90%,rgba(181,113,74,.30),transparent_50%)] text-sand-300">
            <span class="flex h-16 w-16 items-center justify-center rounded-full border border-[rgba(228,185,143,.4)]">
                <x-tamu.ikon nama="kasur" ukuran="28" />
            </span>
            <span class="px-4 text-center font-display text-[11px] font-semibold uppercase tracking-[.28em] text-[rgba(242,235,220,.82)]">{{ $tipe->name }}</span>
        </div>
        @if ($tautan)</a>@endif
    @endif
    <div class="pointer-events-none absolute inset-2.5 border border-[rgba(255,253,246,.28)]" aria-hidden="true"></div>
</div>
